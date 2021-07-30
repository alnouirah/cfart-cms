<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\assetpreviews\Image as ImagePreview;
use craft\assetpreviews\Pdf;
use craft\assetpreviews\Text;
use craft\assetpreviews\Video;
use craft\base\AssetPreviewHandlerInterface;
use craft\base\LocalVolumeInterface;
use craft\base\VolumeInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\User;
use craft\errors\AssetException;
use craft\errors\AssetOperationException;
use craft\errors\AssetTransformException;
use craft\errors\ImageException;
use craft\errors\VolumeException;
use craft\errors\VolumeObjectExistsException;
use craft\errors\VolumeObjectNotFoundException;
use craft\events\AssetPreviewEvent;
use craft\events\AssetThumbEvent;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineAssetUrlEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Image;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\image\Raster;
use craft\models\AssetTransform;
use craft\models\FolderCriteria;
use craft\models\VolumeFolder;
use craft\queue\jobs\GeneratePendingTransforms;
use craft\records\VolumeFolder as VolumeFolderRecord;
use craft\volumes\Temp;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * Assets service.
 * An instance of the Assets service is globally accessible in Craft via [[\craft\base\ApplicationTrait::getAssets()|`Craft::$app->assets`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 *
 * @property-read VolumeFolder $currentUserTemporaryUploadFolder
 */
class Assets extends Component
{
    /**
     * @event ReplaceAssetEvent The event that is triggered before an asset is replaced.
     */
    public const EVENT_BEFORE_REPLACE_ASSET = 'beforeReplaceFile';

    /**
     * @event ReplaceAssetEvent The event that is triggered after an asset is replaced.
     */
    public const EVENT_AFTER_REPLACE_ASSET = 'afterReplaceFile';

    /**
     * @event DefineAssetUrlEvent The event that is triggered when a transform is being generated for an asset.
     * @see getAssetUrl()
     * @since 4.0.0
     */
    public const EVENT_DEFINE_ASSET_URL = 'defineAssetUrl';

    /**
     * @event DefineAssetThumbUrlEvent The event that is triggered when a thumbnail is being generated for an asset.
     * @see getThumbUrl()
     * @since 4.0.0
     */
    public const EVENT_DEFINE_THUMB_URL = 'defineThumbUrl';

    /**
     * @event AssetThumbEvent The event that is triggered when a thumbnail path is requested.
     * @see getThumbPath()
     * @since 4.0.0
     */
    public const EVENT_DEFINE_THUMB_PATH = 'defineThumbPath';

    /**
     * @event AssetPreviewEvent The event that is triggered when determining the preview handler for an asset.
     * @since 3.4.0
     */
    public const EVENT_REGISTER_PREVIEW_HANDLER = 'registerPreviewHandler';

    /**
     * @var array
     */
    private array $_foldersById = [];

    /**
     * @var array
     */
    private array $_foldersByUid = [];

    /**
     * @var bool Whether a Generate Pending Transforms job has already been queued up in this request
     */
    private bool $_queuedGeneratePendingTransformsJob = false;

    /**
     * Returns a file by its ID.
     *
     * @param int $assetId
     * @param int|null $siteId
     * @return Asset|null
     */
    public function getAssetById(int $assetId, ?int $siteId = null): ?Asset
    {
        /* @noinspection PhpIncompatibleReturnTypeInspection */
        return Craft::$app->getElements()->getElementById($assetId, Asset::class, $siteId);
    }

    /**
     * Gets the total number of assets that match a given criteria.
     *
     * @param mixed $criteria
     * @return int
     */
    public function getTotalAssets($criteria = null): int
    {
        if ($criteria instanceof AssetQuery) {
            $query = $criteria;
        } else {
            $query = Asset::find();
            if ($criteria) {
                Craft::configure($query, $criteria);
            }
        }

        return $query->count();
    }

    /**
     * Replace an Asset's file.
     *
     * Replace an Asset's file by it's id, a local file and the filename to use.
     *
     * @param Asset $asset
     * @param string $pathOnServer
     * @param string $filename
     */
    public function replaceAssetFile(Asset $asset, string $pathOnServer, string $filename): void
    {
        // Fire a 'beforeReplaceFile' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_REPLACE_ASSET)) {
            $event = new ReplaceAssetEvent([
                'asset' => $asset,
                'replaceWith' => $pathOnServer,
                'filename' => $filename,
            ]);
            $this->trigger(self::EVENT_BEFORE_REPLACE_ASSET, $event);
            $filename = $event->filename;
        }

        $asset->tempFilePath = $pathOnServer;
        $asset->newFilename = $filename;
        $asset->uploaderId = Craft::$app->getUser()->getId();
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_REPLACE);
        Craft::$app->getElements()->saveElement($asset);

        // Fire an 'afterReplaceFile' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_REPLACE_ASSET)) {
            $this->trigger(self::EVENT_AFTER_REPLACE_ASSET, new ReplaceAssetEvent([
                'asset' => $asset,
                'filename' => $filename,
            ]));
        }
    }

    /**
     * Move or rename an Asset.
     *
     * @param Asset $asset The asset whose file should be renamed
     * @param VolumeFolder $folder The Volume Folder to move the Asset to.
     * @param string $filename The new filename
     * @return bool Whether the asset was renamed successfully
     */
    public function moveAsset(Asset $asset, VolumeFolder $folder, string $filename = ''): bool
    {
        $asset->newFolderId = $folder->id;

        // If the filename hasn’t changed, then we can use the `move` scenario
        if ($filename === '' || $filename === $asset->getFilename()) {
            $asset->setScenario(Asset::SCENARIO_MOVE);
        } else {
            $asset->newFilename = $filename;
            $asset->setScenario(Asset::SCENARIO_FILEOPS);
        }

        return Craft::$app->getElements()->saveElement($asset);
    }

    /**
     * Save an Asset folder.
     *
     * @param VolumeFolder $folder
     * @throws VolumeObjectExistsException if a folder already exists with such a name
     * @throws VolumeException if unable to create the directory on volume
     * @throws AssetException if invalid folder provided
     */
    public function createFolder(VolumeFolder $folder): void
    {
        $parent = $folder->getParent();

        if (!$parent) {
            throw new AssetException('Folder ' . $folder->id . ' doesn’t have a parent.');
        }

        $existingFolder = $this->findFolder([
            'parentId' => $folder->parentId,
            'name' => $folder->name,
        ]);

        if ($existingFolder && (!$folder->id || $folder->id !== $existingFolder->id)) {
            throw new VolumeObjectExistsException(Craft::t('app',
                'A folder with the name “{folderName}” already exists in the volume.',
                ['folderName' => $folder->name]));
        }

        $volume = $parent->getVolume();
        $path = rtrim($folder->path, '/');

        $volume->createDirectory($path);

        $this->storeFolderRecord($folder);
    }

    /**
     * Rename a folder by it's id.
     *
     * @param int $folderId
     * @param string $newName
     * @return string The new folder name after cleaning it.
     * @throws AssetOperationException If the folder to be renamed can't be found or trying to rename the top folder.
     * @throws VolumeObjectExistsException
     * @throws VolumeObjectNotFoundException
     */
    public function renameFolderById(int $folderId, string $newName): string
    {
        $newName = AssetsHelper::prepareAssetName($newName, false);
        $folder = $this->getFolderById($folderId);

        if (!$folder) {
            throw new AssetOperationException(Craft::t('app',
                'No folder exists with the ID “{id}”',
                ['id' => $folderId]));
        }

        if (!$folder->parentId) {
            throw new AssetOperationException(Craft::t('app',
                'It’s not possible to rename the top folder of a Volume.'));
        }

        $conflictingFolder = $this->findFolder([
            'parentId' => $folder->parentId,
            'name' => $newName,
        ]);

        if ($conflictingFolder) {
            throw new VolumeObjectExistsException(Craft::t('app',
                'A folder with the name “{folderName}” already exists in the folder.',
                ['folderName' => $folder->name]));
        }

        $parentFolderPath = dirname($folder->path);
        $newFolderPath = (($parentFolderPath && $parentFolderPath !== '.') ? $parentFolderPath . '/' : '') . $newName . '/';

        $volume = $folder->getVolume();

        $volume->renameDirectory(rtrim($folder->path, '/'), $newName);
        $descendantFolders = $this->getAllDescendantFolders($folder);

        foreach ($descendantFolders as $descendantFolder) {
            $descendantFolder->path = preg_replace('#^' . $folder->path . '#', $newFolderPath, $descendantFolder->path);
            $this->storeFolderRecord($descendantFolder);
        }

        // Now change the affected folder
        $folder->name = $newName;
        $folder->path = $newFolderPath;
        $this->storeFolderRecord($folder);

        return $newName;
    }

    /**
     * Deletes a folder by its ID.
     *
     * @param array|int $folderIds
     * @param bool $deleteDir Should the volume directory be deleted along the record, if applicable. Defaults to true.
     * @throws InvalidConfigException if the volume cannot be fetched from folder.
     */
    public function deleteFoldersByIds($folderIds, bool $deleteDir = true): void
    {
        $folders = [];

        foreach ((array)$folderIds as $folderId) {
            $folder = $this->getFolderById($folderId);
            $folders[] = $folder;

            if ($folder && $deleteDir) {
                $volume = $folder->getVolume();
                try {
                    $volume->deleteDirectory($folder->path);
                } catch (VolumeException $exception) {
                    Craft::$app->getErrorHandler()->logException($exception);
                    // Carry on.
                }
            }
        }

        $assets = Asset::find()->folderId($folderIds)->all();

        $elementService = Craft::$app->getElements();

        foreach ($assets as $asset) {
            $asset->keepFileOnDelete = !$deleteDir;
            $elementService->deleteElement($asset, true);
        }

        foreach ($folders as $folder) {
            $descendants = $this->getAllDescendantFolders($folder);
            usort($descendants, static fn($a, $b) => substr_count($a->path, '/') < substr_count($b->path, '/'));

            foreach ($descendants as $descendant) {
                VolumeFolderRecord::deleteAll(['id' => $descendant->id]);
            }
            VolumeFolderRecord::deleteAll(['id' => $folder->id]);
        }
    }

    /**
     * Get the folder tree for Assets by volume ids
     *
     * @param array $allowedVolumeIds
     * @param array $additionalCriteria additional criteria for filtering the tree
     * @return array
     */
    public function getFolderTreeByVolumeIds(array $allowedVolumeIds, array $additionalCriteria = []): array
    {
        static $volumeFolders = [];

        $tree = [];

        // Get the tree for each source
        foreach ($allowedVolumeIds as $volumeId) {
            // Add additional criteria but prevent overriding volumeId and order.
            $criteria = array_merge($additionalCriteria, [
                'volumeId' => $volumeId,
                'order' => 'path',
            ]);
            $cacheKey = md5(Json::encode($criteria));

            // If this has not been yet fetched, fetch it.
            if (empty($volumeFolders[$cacheKey])) {
                $folders = $this->findFolders($criteria);
                $subtree = $this->_getFolderTreeByFolders($folders);
                $volumeFolders[$cacheKey] = reset($subtree);
            }

            $tree[$volumeId] = $volumeFolders[$cacheKey];
        }

        AssetsHelper::sortFolderTree($tree);

        return $tree;
    }

    /**
     * Get the folder tree for Assets by a folder id.
     *
     * @param int $folderId
     * @return array
     */
    public function getFolderTreeByFolderId(int $folderId): array
    {
        if (($parentFolder = $this->getFolderById($folderId)) === null) {
            return [];
        }

        $childFolders = $this->getAllDescendantFolders($parentFolder);

        return $this->_getFolderTreeByFolders([$parentFolder] + $childFolders);
    }

    /**
     * Returns a folder by its ID.
     *
     * @param int $folderId
     * @return VolumeFolder|null
     */
    public function getFolderById(int $folderId): ?VolumeFolder
    {
        if (isset($this->_foldersById) && array_key_exists($folderId, $this->_foldersById)) {
            return $this->_foldersById[$folderId];
        }

        $result = $this->_createFolderQuery()
            ->where(['id' => $folderId])
            ->one();

        if (!$result) {
            return $this->_foldersById[$folderId] = null;
        }

        return $this->_foldersById[$folderId] = new VolumeFolder($result);
    }

    /**
     * Returns a folder by its UID.
     *
     * @param string $folderUid
     * @return VolumeFolder|null
     */
    public function getFolderByUid(string $folderUid): ?VolumeFolder
    {
        if (isset($this->_foldersByUid) && array_key_exists($folderUid, $this->_foldersByUid)) {
            return $this->_foldersByUid[$folderUid];
        }

        $result = $this->_createFolderQuery()
            ->where(['uid' => $folderUid])
            ->one();

        if (!$result) {
            return $this->_foldersByUid[$folderUid] = null;
        }

        return $this->_foldersByUid[$folderUid] = new VolumeFolder($result);
    }

    /**
     * Finds folders that match a given criteria.
     *
     * @param mixed $criteria
     * @return VolumeFolder[]
     */
    public function findFolders($criteria = null): array
    {
        if (!($criteria instanceof FolderCriteria)) {
            $criteria = new FolderCriteria($criteria);
        }

        $query = $this->_createFolderQuery();

        $this->_applyFolderConditions($query, $criteria);

        if ($criteria->order) {
            $query->orderBy($criteria->order);
        }

        if ($criteria->offset) {
            $query->offset($criteria->offset);
        }

        if ($criteria->limit) {
            $query->limit($criteria->limit);
        }

        $results = $query->all();
        $folders = [];

        foreach ($results as $result) {
            $folder = new VolumeFolder($result);
            $this->_foldersById[$folder->id] = $folder;
            $folders[$folder->id] = $folder;
        }

        return $folders;
    }

    /**
     * Returns all of the folders that are descendants of a given folder.
     *
     * @param VolumeFolder $parentFolder
     * @param string $orderBy
     * @return VolumeFolder[]
     */
    public function getAllDescendantFolders(VolumeFolder $parentFolder, string $orderBy = 'path'): array
    {
        $query = $this->_createFolderQuery()
            ->where([
                'and',
                ['like', 'path', $parentFolder->path . '%', false],
                ['volumeId' => $parentFolder->volumeId],
                ['not', ['parentId' => null]],
            ]);

        if ($orderBy) {
            $query->orderBy($orderBy);
        }

        $results = $query->all();
        $descendantFolders = [];

        foreach ($results as $result) {
            $folder = new VolumeFolder($result);
            $this->_foldersById[$folder->id] = $folder;
            $descendantFolders[$folder->id] = $folder;
        }

        return $descendantFolders;
    }

    /**
     * Finds the first folder that matches a given criteria.
     *
     * @param mixed $criteria
     * @return VolumeFolder|null
     */
    public function findFolder($criteria = null): ?VolumeFolder
    {
        if (!($criteria instanceof FolderCriteria)) {
            $criteria = new FolderCriteria($criteria);
        }

        $criteria->limit = 1;
        $folder = $this->findFolders($criteria);

        if (is_array($folder) && !empty($folder)) {
            return array_pop($folder);
        }

        return null;
    }

    /**
     * Returns the root folder for a given volume ID.
     *
     * @param int $volumeId The volume ID
     * @return VolumeFolder|null The root folder in that volume, or null if the volume doesn’t exist
     */
    public function getRootFolderByVolumeId(int $volumeId): ?VolumeFolder
    {
        return $this->findFolder([
            'volumeId' => $volumeId,
            'parentId' => ':empty:',
        ]);
    }

    /**
     * Gets the total number of folders that match a given criteria.
     *
     * @param mixed $criteria
     * @return int
     */
    public function getTotalFolders($criteria): int
    {
        if (!($criteria instanceof FolderCriteria)) {
            $criteria = new FolderCriteria($criteria);
        }

        $query = (new Query())
            ->from([Table::VOLUMEFOLDERS]);

        $this->_applyFolderConditions($query, $criteria);

        return (int)$query->count('[[id]]');
    }

    // File and folder managing
    // -------------------------------------------------------------------------

    /**
     * Returns the URL for an asset, possibly with a given transform applied.
     *
     * @param Asset $asset
     * @param AssetTransform|string|array|null $transform
     * @param bool|null $generateNow Whether the transformed image should be generated immediately if it doesn’t exist. If `null`, it will be left
     * up to the `generateTransformsBeforePageLoad` config setting.
     * @return string|null
     * @throws VolumeException
     * @throws AssetTransformException
     */
    public function getAssetUrl(Asset $asset, $transform = null, ?bool $generateNow = null): ?string
    {
        // Maybe a plugin wants to do something here
        $event = new DefineAssetUrlEvent([
            'transform' => $transform,
            'asset' => $asset,
        ]);
        $this->trigger(self::EVENT_DEFINE_ASSET_URL, $event);

        // If a plugin set the url, we'll just use that.
        if ($event->url !== null) {
            return $event->url;
        }

        if ($transform === null || !Image::canManipulateAsImage(pathinfo($asset->getFilename(), PATHINFO_EXTENSION))) {
            $volume = $asset->getVolume();

            return AssetsHelper::generateUrl($volume, $asset);
        }

        // Get the transform index model
        $assetTransforms = Craft::$app->getAssetTransforms();
        $index = $assetTransforms->getTransformIndex($asset, $transform);

        // Does the file actually exist?
        if ($index->fileExists) {
            // For local volumes, really make sure
            $volume = $asset->getVolume();
            $transformPath = $asset->folderPath . $assetTransforms->getTransformSubpath($asset, $index);

            if ($volume instanceof LocalVolumeInterface && !$volume->fileExists($transformPath)) {
                $index->fileExists = false;
            } else {
                return $assetTransforms->getUrlForTransformByAssetAndTransformIndex($asset, $index);
            }
        }

        if ($generateNow === null) {
            $generateNow = Craft::$app->getConfig()->getGeneral()->generateTransformsBeforePageLoad;
        }

        if ($generateNow) {
            try {
                return $assetTransforms->ensureTransformUrlByIndexModel($index);
            } catch (\Exception $exception) {
                Craft::$app->getErrorHandler()->logException($exception);
                return null;
            }
        }

        // Queue up a new Generate Pending Transforms job
        if (!$this->_queuedGeneratePendingTransformsJob) {
            Queue::push(new GeneratePendingTransforms(), 2048);
            $this->_queuedGeneratePendingTransformsJob = true;
        }

        // Return the temporary transform URL
        return UrlHelper::actionUrl('assets/generate-transform', ['transformId' => $index->id], null, false);
    }

    /**
     * Returns the control panel thumbnail URL for a given asset.
     *
     * @param Asset $asset asset to return a thumb for
     * @param int $width width of the returned thumb
     * @param int|null $height height of the returned thumb (defaults to $width if null)
     * @param bool $generate whether to generate a thumb in none exists yet
     * @return string
     */
    public function getThumbUrl(Asset $asset, int $width, ?int $height = null, bool $generate = false): string
    {
        if ($height === null) {
            $height = $width;
        }

        // Maybe a plugin wants to do something here
        if ($this->hasEventHandlers(self::EVENT_DEFINE_THUMB_URL)) {
            $event = new DefineAssetThumbUrlEvent([
                'asset' => $asset,
                'width' => $width,
                'height' => $height,
                'generate' => $generate,
            ]);
            $this->trigger(self::EVENT_DEFINE_THUMB_URL, $event);

            // If a plugin set the url, we'll just use that.
            if ($event->url !== null) {
                return $event->url;
            }
        }

        return UrlHelper::actionUrl('assets/thumb', [
            'uid' => $asset->uid,
            'width' => $width,
            'height' => $height,
            'v' => $asset->dateModified->getTimestamp(),
        ], null, false);
    }

    /**
     * Returns the control panel thumbnail path for a given asset.
     *
     * @param Asset $asset asset to return a thumb for
     * @param int $width width of the returned thumb
     * @param int|null $height height of the returned thumb (defaults to $width if null)
     * @param bool $generate whether to generate a thumb in none exists yet
     * @param bool $fallbackToIcon whether to return the path to a generic icon if a thumbnail can't be generated
     * @return string|false thumbnail path, or `false` if it doesn't exist and $generate is `false`
     * @throws InvalidConfigException
     * @throws NotSupportedException if the asset can't have a thumbnail, and $fallbackToIcon is `false`
     * @throws VolumeException
     * @throws VolumeObjectNotFoundException
     * @see getThumbUrl()
     */
    public function getThumbPath(Asset $asset, int $width, ?int $height = null, bool $generate = true, bool $fallbackToIcon = true)
    {
        // Maybe a plugin wants to do something here
        $event = new AssetThumbEvent([
            'asset' => $asset,
            'width' => $width,
            'height' => $height,
            'generate' => $generate,
        ]);
        $this->trigger(self::EVENT_DEFINE_THUMB_PATH, $event);

        // If a plugin set the url, we'll just use that.
        if ($event->path !== null) {
            return $event->path;
        }

        $ext = $asset->getExtension();

        // If it's not an image, return a generic file extension icon
        if (!Image::canManipulateAsImage($ext)) {
            if (!$fallbackToIcon) {
                throw new NotSupportedException("A thumbnail can't be generated for the asset.");
            }

            return $this->getIconPath($asset);
        }

        if ($height === null) {
            $height = $width;
        }

        // Make the thumb a JPG if the image format isn't safe for web
        $ext = in_array($ext, Image::webSafeFormats(), true) ? $ext : 'jpg';

        // Should we be rasteriszing the thumb?
        $rasterize = strtolower($ext) === 'svg' && Craft::$app->getConfig()->getGeneral()->rasterizeSvgThumbs;
        if ($rasterize) {
            $ext = 'png';
        }

        $dir = Craft::$app->getPath()->getAssetThumbsPath() . DIRECTORY_SEPARATOR . $asset->id;
        $path = $dir . DIRECTORY_SEPARATOR . "thumb-{$width}x{$height}.{$ext}";

        if (!file_exists($path) || $asset->dateModified->getTimestamp() > filemtime($path)) {
            // Bail if we're not ready to generate it yet
            if (!$generate) {
                return false;
            }

            // Generate it
            FileHelper::createDirectory($dir);
            $imageSource = Craft::$app->getAssetTransforms()->getLocalImageSource($asset);

            // hail Mary
            try {
                $image = Craft::$app->getImages()->loadImage($imageSource, $rasterize, max($width, $height));

                // Prevent resize of all layers
                if ($image instanceof Raster) {
                    $image->disableAnimation();
                }

                $image->scaleAndCrop($width, $height);
                $image->saveAs($path);
            } catch (ImageException $exception) {
                Craft::warning("Unable to generate a thumbnail for asset $asset->id: {$exception->getMessage()}", __METHOD__);
                return $this->getIconPath($asset);
            }
        }

        return $path;
    }

    /**
     * Returns a generic file extension icon path, that can be used as a fallback
     * for assets that don't have a normal thumbnail.
     *
     * @param Asset $asset
     * @return string
     */
    public function getIconPath(Asset $asset): string
    {
        $ext = $asset->getExtension();
        $path = Craft::$app->getPath()->getAssetsIconsPath() . DIRECTORY_SEPARATOR . strtolower($ext) . '.svg';

        if (file_exists($path)) {
            return $path;
        }

        $svg = file_get_contents(Craft::getAlias('@appicons/file.svg'));

        $extLength = strlen($ext);
        if ($extLength <= 3) {
            $textSize = '20';
        } else if ($extLength === 4) {
            $textSize = '17';
        } else {
            if ($extLength > 5) {
                $ext = substr($ext, 0, 4) . '…';
            }
            $textSize = '14';
        }

        $textNode = "<text x=\"50\" y=\"73\" text-anchor=\"middle\" font-family=\"sans-serif\" fill=\"#9aa5b1\" font-size=\"{$textSize}\">" . strtoupper($ext) . '</text>';
        $svg = str_replace('<!-- EXT -->', $textNode, $svg);

        FileHelper::writeToFile($path, $svg);
        return $path;
    }

    /**
     * Find a replacement for a filename
     *
     * @param string $originalFilename the original filename for which to find a replacement.
     * @param int $folderId The folder in which to find the replacement
     * @return string If a suitable filename replacement cannot be found.
     * @throws AssetOperationException If a suitable filename replacement cannot be found.
     * @throws InvalidConfigException
     * @throws VolumeException
     */
    public function getNameReplacementInFolder(string $originalFilename, int $folderId): string
    {
        $folder = $this->getFolderById($folderId);

        if (!$folder) {
            throw new InvalidArgumentException('Invalid folder ID: ' . $folderId);
        }

        $volume = $folder->getVolume();

        // A potentially conflicting filename is one that shares the same stem and extension

        // Check for potentially conflicting files in index.
        $baseFileName = pathinfo($originalFilename, PATHINFO_FILENAME);
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        $dbFileList = (new Query())
            ->select(['assets.filename'])
            ->from(['assets' => Table::ASSETS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[assets.id]]')
            ->where([
                'assets.folderId' => $folderId,
                'elements.dateDeleted' => null,
            ])
            ->andWhere(['like', 'assets.filename', $baseFileName . '%.' . $extension, false])
            ->column();

        $potentialConflicts = [];

        foreach ($dbFileList as $filename) {
            $potentialConflicts[StringHelper::toLowerCase($filename)] = true;
        }

        // Check whether a filename we'd want to use does not exist
        $canUse = static function($filenameToTest) use ($potentialConflicts, $volume, $folder) {
            return !isset($potentialConflicts[mb_strtolower($filenameToTest)]) && !$volume->fileExists($folder->path . $filenameToTest);
        };

        if ($canUse($originalFilename)) {
            return $originalFilename;
        }

        // If the file already ends with something that looks like a timestamp, use that instead.
        if (preg_match('/.*_\d{4}-\d{2}-\d{2}-\d{6}$/', $baseFileName, $matches)) {
            $base = $baseFileName;
        } else {
            $timestamp = DateTimeHelper::currentUTCDateTime()->format('Y-m-d-His');
            $base = $baseFileName . '_' . $timestamp;
        }

        // Append a random string at the end too, to avoid race-conditions
        $base .= '_' . StringHelper::randomString(4);

        $increment = 0;

        while (true) {
            // Add the increment (if > 0) and keep the full filename w/ increment & extension from going over 255 chars
            $suffix = ($increment ? "_$increment" : '') . ".$extension";
            $newFilename = substr($base, 0, 255 - mb_strlen($suffix)) . $suffix;

            if ($canUse($newFilename)) {
                break;
            }

            if ($increment === 50) {
                throw new AssetOperationException(Craft::t('app', 'Could not find a suitable replacement filename for “{filename}”.', [
                    'filename' => $originalFilename,
                ]));
            }

            $increment++;
        }

        return $newFilename;
    }

    /**
     * Ensure a folder entry exists in the DB for the full path and return it's id. Depending on the use, it's possible to also ensure a physical folder exists.
     *
     * @param string $fullPath The path to ensure the folder exists at.
     * @param VolumeInterface $volume
     * @param bool $justRecord If set to false, will also make sure the physical folder exists on Volume.
     * @return VolumeFolder
     * @throws VolumeException if something went catastrophically wrong creating the folder.
     */
    public function ensureFolderByFullPathAndVolume(string $fullPath, VolumeInterface $volume, bool $justRecord = true): VolumeFolder
    {
        $parentFolder = Craft::$app->getVolumes()->ensureTopFolder($volume);
        $folderModel = $parentFolder;
        $parentId = $parentFolder->id;

        if ($fullPath) {
            // If we don't have a folder matching these, create a new one
            $parts = explode('/', trim($fullPath, '/'));

            // creep up the folder path
            $path = '';

            while (($part = array_shift($parts)) !== null) {
                $path .= $part . '/';

                $parameters = new FolderCriteria([
                    'path' => $path,
                    'volumeId' => $volume->id,
                ]);

                // Create the record for current segment if needed.
                if (($folderModel = $this->findFolder($parameters)) === null) {
                    $folderModel = new VolumeFolder();
                    $folderModel->volumeId = $volume->id;
                    $folderModel->parentId = $parentId;
                    $folderModel->name = $part;
                    $folderModel->path = $path;
                    $this->storeFolderRecord($folderModel);
                }

                // Ensure a physical folder exists, if needed.
                if (!$justRecord) {
                    $volume->createDirectory($path);
                }

                // Set the variables for next iteration.
                $folderId = $folderModel->id;
                $parentId = $folderId;
            }
        }

        return $folderModel;
    }

    /**
     * Store a folder by model
     *
     * @param VolumeFolder $folder
     */
    public function storeFolderRecord(VolumeFolder $folder): void
    {
        if (!$folder->id) {
            $record = new VolumeFolderRecord();
        } else {
            $record = VolumeFolderRecord::findOne(['id' => $folder->id]);
        }

        $record->parentId = $folder->parentId;
        $record->volumeId = $folder->volumeId;
        $record->name = $folder->name;
        $record->path = $folder->path;
        $record->save();

        $folder->id = $record->id;
        $folder->uid = $record->uid;
    }

    /**
     * Returns the given user's temporary upload folder.
     *
     * If no user is provided, the currently-logged in user will be used (if there is one), or a folder named after
     * the current session ID.
     *
     * @param User|null $user
     * @return VolumeFolder
     * @throws VolumeException If no correct volume provided.
     */
    public function getUserTemporaryUploadFolder(?User $user = null): VolumeFolder
    {
        if ($user === null) {
            // Default to the logged-in user, if there is one
            $user = Craft::$app->getUser()->getIdentity();
        }

        if ($user) {
            $folderName = 'user_' . $user->id;
        } else if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            // For console requests, just make up a folder name.
            $folderName = 'temp_' . sha1(time());
        } else {
            // A little obfuscation never hurt anyone
            $folderName = 'user_' . sha1(Craft::$app->getSession()->id);
        }

        // Is there a designated temp uploads volume?
        $assetSettings = Craft::$app->getProjectConfig()->get('assets');
        if (isset($assetSettings['tempVolumeUid'])) {
            $volume = Craft::$app->getVolumes()->getVolumeByUid($assetSettings['tempVolumeUid']);
            if (!$volume) {
                throw new VolumeException(Craft::t('app', 'The volume set for temp asset storage is not valid.'));
            }
            $path = (isset($assetSettings['tempSubpath']) ? $assetSettings['tempSubpath'] . '/' : '') .
                $folderName;
            return $this->ensureFolderByFullPathAndVolume($path, $volume, false);
        }

        $volumeTopFolder = $this->findFolder([
            'volumeId' => ':empty:',
            'parentId' => ':empty:',
        ]);

        // Unlikely, but would be very awkward if this happened without any contingency plans in place.
        if (!$volumeTopFolder) {
            $volumeTopFolder = new VolumeFolder();
            $tempVolume = new Temp();
            $volumeTopFolder->name = $tempVolume->name;
            $this->storeFolderRecord($volumeTopFolder);
        }

        $folder = $this->findFolder([
            'name' => $folderName,
            'parentId' => $volumeTopFolder->id,
        ]);

        if (!$folder) {
            $folder = new VolumeFolder();
            $folder->parentId = $volumeTopFolder->id;
            $folder->name = $folderName;
            $folder->path = $folderName . '/';
            $this->storeFolderRecord($folder);
        }

        try {
            FileHelper::createDirectory(Craft::$app->getPath()->getTempAssetUploadsPath() . DIRECTORY_SEPARATOR . $folderName);
        } catch (Exception $exception) {
            throw new VolumeException(Craft::t('app', 'Unable to create directory for temporary volume.'));
        }

        return $folder;
    }

    /**
     * Returns the asset preview handler for a given asset, or `null` if the asset is not previewable.
     *
     * @param Asset $asset
     * @return AssetPreviewHandlerInterface|null
     * @since 3.4.0
     */
    public function getAssetPreviewHandler(Asset $asset): ?AssetPreviewHandlerInterface
    {
        // Give plugins a chance to register their own preview handlers
        if ($this->hasEventHandlers(self::EVENT_REGISTER_PREVIEW_HANDLER)) {
            $event = new AssetPreviewEvent(['asset' => $asset]);
            $this->trigger(self::EVENT_REGISTER_PREVIEW_HANDLER, $event);
            if ($event->previewHandler instanceof AssetPreviewHandlerInterface) {
                return $event->previewHandler;
            }
        }

        // These are our default preview handlers if one is not supplied
        switch ($asset->kind) {
            case Asset::KIND_IMAGE:
                return new ImagePreview($asset);
            case Asset::KIND_PDF:
                return new Pdf($asset);
            case Asset::KIND_VIDEO:
                return new Video($asset);
            case Asset::KIND_HTML:
            case Asset::KIND_JAVASCRIPT:
            case Asset::KIND_JSON:
            case Asset::KIND_PHP:
            case Asset::KIND_TEXT:
            case Asset::KIND_XML:
                return new Text($asset);
        }

        return null;
    }

    /**
     * Returns a DbCommand object prepped for retrieving assets.
     *
     * @return Query
     */
    private function _createFolderQuery(): Query
    {
        return (new Query())
            ->select(['id', 'parentId', 'volumeId', 'name', 'path', 'uid'])
            ->from([Table::VOLUMEFOLDERS]);
    }

    /**
     * Return the folder tree form a list of folders.
     *
     * @param VolumeFolder[] $folders
     * @return array
     */
    private function _getFolderTreeByFolders(array $folders): array
    {
        $tree = [];
        $referenceStore = [];

        foreach ($folders as $folder) {
            // We'll be adding all of the children in this loop, anyway, so we set
            // the children list to an empty array so that folders that have no children don't
            // trigger any queries, when asked for children
            $folder->setChildren([]);
            if ($folder->parentId && isset($referenceStore[$folder->parentId])) {
                $referenceStore[$folder->parentId]->addChild($folder);
            } else {
                $tree[] = $folder;
            }

            $referenceStore[$folder->id] = $folder;
        }

        return $tree;
    }

    /**
     * Applies WHERE conditions to a DbCommand query for folders.
     *
     * @param Query $query
     * @param FolderCriteria $criteria
     */
    private function _applyFolderConditions(Query $query, FolderCriteria $criteria): void
    {
        if ($criteria->id) {
            $query->andWhere(Db::parseNumericParam('id', $criteria->id));
        }

        if ($criteria->volumeId) {
            $query->andWhere(Db::parseNumericParam('volumeId', $criteria->volumeId));
        }

        if ($criteria->parentId) {
            $query->andWhere(Db::parseNumericParam('parentId', $criteria->parentId));
        }

        if ($criteria->name) {
            $query->andWhere(Db::parseParam('name', $criteria->name));
        }

        if ($criteria->uid) {
            $query->andWhere(Db::parseParam('uid', $criteria->uid));
        }

        if ($criteria->path !== null) {
            // Does the path have a comma in it?
            if (strpos($criteria->path, ',') !== false) {
                // Escape the comma.
                $query->andWhere(Db::parseParam('path', str_replace(',', '\,', $criteria->path)));
            } else {
                $query->andWhere(Db::parseParam('path', $criteria->path));
            }
        }
    }
}
