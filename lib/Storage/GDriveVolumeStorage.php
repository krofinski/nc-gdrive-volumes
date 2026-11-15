<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Storage;

use OC\Files\Storage\Common;
use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCP\Constants;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\IWatcher;
use OCP\Files\FileInfo;
use OCP\Files\Storage\IStorage;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud storage backend that maps a single Google Drive subdirectory
 * (e.g., NextCloud/Volume_1) as an independent virtual external disk.
 */
class GDriveVolumeStorage extends Common {
    private GoogleDriveClient $driveClient;
    private MetadataCacheService $cacheService;
    private ConfigService $configService;
    private LoggerInterface $logger;

    /** @var array<string, mixed> */
    private array $volume;
    private string $volumeId;
    private string $scope;
    private ?string $ownerUid;
    private bool $readOnly;
    private ?int $quotaBytes;
    private string $exportMode;
    private bool $trashOnDelete;
    private string $cacheNs;
    private ?string $resolvedRootId = null;
    private bool $rootCacheSeeded = false;
    private int|float|null $cachedFreeSpace = null;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params) {
        parent::__construct($params);

        $this->volume = is_array($params['volume'] ?? null) ? $params['volume'] : [];
        $this->volumeId = (string)($this->volume['id'] ?? 'unknown');
        $this->scope = (string)($this->volume['scope'] ?? ConfigService::SCOPE_ADMIN);
        $uid = (string)($this->volume['owner_uid'] ?? '');
        $this->ownerUid = $uid !== '' ? $uid : null;
        $this->readOnly = (bool)($this->volume['read_only'] ?? false);
        $this->quotaBytes = isset($this->volume['quota_bytes']) && (int)$this->volume['quota_bytes'] > 0
            ? (int)$this->volume['quota_bytes']
            : null;
        $this->exportMode = (string)($this->volume['export_google_docs'] ?? ConfigService::EXPORT_OFFICE);
        $this->trashOnDelete = (bool)($this->volume['trash_on_delete'] ?? true);
        $this->cacheNs = MetadataCacheService::volumeNamespace($this->volumeId);

        $server = \OC::$server;
        $this->driveClient = $params['drive_client'] ?? $server->get(GoogleDriveClient::class);
        $this->cacheService = $params['cache_service'] ?? $server->get(MetadataCacheService::class);
        $this->configService = $params['config_service'] ?? $server->get(ConfigService::class);
        $this->logger = $params['logger'] ?? $server->get(LoggerInterface::class);
    }

    public function getId(): string {
        $identity = $this->scope . ':' . ($this->ownerUid ?? '') . ':' . $this->volumeId;
        return 'gdrive_vol::' . substr(hash('sha256', $identity), 0, 48);
    }

    /**
     * Ensure an unscanned root entry exists in oc_filecache so UserMountCache always
     * records subdirectory mounts (e.g. /Gdrive/Volume_1) in oc_mounts.
     *
     * @param string $path
     * @param ?IStorage $storage
     */
    public function getCache($path = '', $storage = null): ICache {
        $cache = parent::getCache($path, $storage);
        if (!$this->rootCacheSeeded) {
            $this->rootCacheSeeded = true;
            try {
                if ($cache->getId('') === -1) {
                    $now = time();
                    $cache->put('', [
                        'mimetype' => 'httpd/unix-directory',
                        'mtime' => $now,
                        'storage_mtime' => 0,
                        'size' => -1,
                        'etag' => substr(md5($this->getId() . ':init'), 0, 16),
                        'permissions' => $this->readOnly
                            ? (Constants::PERMISSION_READ | Constants::PERMISSION_SHARE)
                            : Constants::PERMISSION_ALL,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Unable to seed root cache entry for Google Drive volume "{id}": {msg}', [
                    'id' => $this->volumeId,
                    'msg' => $e->getMessage(),
                ]);
            }
        }
        return $cache;
    }

    public function getWatcher($path = '', $storage = null): IWatcher {
        $watcher = parent::getWatcher($path, $storage);
        $watcher->setPolicy(IWatcher::CHECK_ONCE);
        return $watcher;
    }

    public function test(): bool {
        try {
            $root = $this->driveClient->resolveVolumeRootFolder($this->volume);
            if (!empty($root['id'])) {
                $this->resolvedRootId = (string)$root['id'];
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('Google Drive volume "{id}" test failed: {msg}', [
                'id' => $this->volumeId,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function isUpdatable($path): bool {
        if ($this->readOnly) {
            return false;
        }
        $item = $this->resolveItem((string)$path);
        if (is_array($item) && !empty($item['isGoogleDoc'])) {
            return false;
        }
        return parent::isUpdatable($path);
    }

    public function isCreatable($path): bool {
        if ($this->readOnly) {
            return false;
        }
        return parent::isCreatable($path);
    }

    public function isDeletable($path): bool {
        if ($this->readOnly) {
            return false;
        }
        return parent::isDeletable($path);
    }

    public function isSharable($path): bool {
        return true;
    }

    public function mkdir($path): bool {
        if ($this->readOnly) {
            return false;
        }
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return false;
        }

        try {
            [$parentPath, $name] = $this->splitParentAndName($normalized);
            $parentId = $this->resolveFolderId($parentPath);
            if ($parentId === null) {
                return false;
            }
            if ($this->resolveItem($normalized) !== null) {
                return false;
            }

            $this->driveClient->createFolder($this->scope, $this->ownerUid, $parentId, $name);
            $this->cacheService->invalidatePath($this->cacheNs, $normalized, $parentId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive mkdir failed for "{path}": {msg}', [
                'path' => $normalized,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function rmdir($path): bool {
        if ($this->readOnly) {
            return false;
        }
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return false;
        }

        try {
            $item = $this->resolveItem($normalized);
            if ($item === null || empty($item['isDir'])) {
                return false;
            }

            $children = $this->driveClient->listChildren($this->scope, $this->ownerUid, (string)$item['id']);
            if ($children !== []) {
                return false;
            }

            $this->driveClient->deleteItem($this->scope, $this->ownerUid, (string)$item['id'], $this->trashOnDelete);
            [$parentPath] = $this->splitParentAndName($normalized);
            $parentId = $this->resolveFolderId($parentPath);
            $this->cacheService->invalidatePath($this->cacheNs, $normalized, $parentId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive rmdir failed for "{path}": {msg}', [
                'path' => $normalized,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function opendir($path) {
        $normalized = $this->normalizePath((string)$path);

        try {
            $folderId = $this->resolveFolderId($normalized);
            if ($folderId === null) {
                return false;
            }

            $entries = $this->getFolderChildrenMap($normalized, $folderId);
            return DirectoryStreamWrapper::open(array_keys($entries));
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive opendir failed for "{path}": {msg}', [
                'path' => $normalized,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getDirectoryContent($directory): \Traversable {
        $normalized = $this->normalizePath((string)$directory);
        $folderId = $this->resolveFolderId($normalized);
        if ($folderId === null) {
            return;
        }

        $entries = $this->getFolderChildrenMap($normalized, $folderId);
        $ttl = $this->getCacheTtl();

        foreach ($entries as $name => $item) {
            $childName = (string)$name;
            if ($childName === '') {
                continue;
            }
            $childPath = $normalized === '' ? $childName : ($normalized . '/' . $childName);
            $this->cacheService->set($this->cacheNs, 'item:' . $childPath, ['found' => true, 'item' => $item], $ttl);

            $isDir = !empty($item['isDir']);
            $isGoogleDoc = !empty($item['isGoogleDoc']);
            $mtime = (int)($item['mtime'] ?? time());

            if ($isDir) {
                $mimetype = 'httpd/unix-directory';
                $size = -1;
            } else {
                $mimetype = !empty($item['exportMimeType'])
                    ? (string)$item['exportMimeType']
                    : (!empty($item['mimeType']) ? (string)$item['mimeType'] : $this->detectMimeType($childName));
                $size = $isGoogleDoc && empty($item['hasExplicitSize'])
                    ? $this->getCachedGoogleDocExportSize($item)
                    : (int)($item['size'] ?? 0);
            }

            if ($this->readOnly) {
                $permissions = Constants::PERMISSION_READ | Constants::PERMISSION_SHARE;
            } elseif ($isDir) {
                $permissions = Constants::PERMISSION_ALL;
            } elseif ($isGoogleDoc) {
                $permissions = Constants::PERMISSION_READ | Constants::PERMISSION_SHARE;
            } else {
                $permissions = Constants::PERMISSION_READ
                    | Constants::PERMISSION_UPDATE
                    | Constants::PERMISSION_DELETE
                    | Constants::PERMISSION_SHARE;
            }

            yield [
                'name' => $childName,
                'mimetype' => $mimetype,
                'mtime' => $mtime,
                'storage_mtime' => $mtime,
                'size' => $size,
                'etag' => sha1(
                    (string)($item['id'] ?? '')
                    . ':' . (string)($item['version'] ?? '')
                    . ':' . (string)($item['md5Checksum'] ?? '')
                    . ':' . $mtime
                ),
                'permissions' => $permissions,
            ];
        }
    }

    public function filetype($path): string|false {
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return 'dir';
        }

        $item = $this->resolveItem($normalized);
        if ($item === null) {
            return false;
        }
        return !empty($item['isDir']) ? 'dir' : 'file';
    }

    public function file_exists($path): bool {
        return $this->filetype($path) !== false;
    }

    public function is_dir($path): bool {
        return $this->filetype($path) === 'dir';
    }

    public function is_file($path): bool {
        return $this->filetype($path) === 'file';
    }

    public function filesize($path): int|false {
        $stat = $this->stat($path);
        return is_array($stat) ? $stat['size'] : false;
    }

    public function filemtime($path): int|false {
        $stat = $this->stat($path);
        return is_array($stat) ? $stat['mtime'] : false;
    }

    public function stat($path): array|false {
        $normalized = $this->normalizePath((string)$path);

        try {
            if ($normalized === '') {
                $rootMeta = $this->getRootFolderMetadata();
                if ($rootMeta === null) {
                    return [
                        'size' => 0,
                        'mtime' => 0,
                        'atime' => 0,
                        'ctime' => 0,
                    ];
                }
                $mtime = max(1, (int)($rootMeta['mtime'] ?? time()));
                return [
                    'size' => 0,
                    'mtime' => $mtime,
                    'atime' => $mtime,
                    'ctime' => (int)($rootMeta['ctime'] ?? $mtime),
                ];
            }

            $item = $this->resolveItem($normalized);
            if ($item === null) {
                return false;
            }

            $size = (int)($item['size'] ?? 0);
            if (empty($item['isDir']) && !empty($item['isGoogleDoc']) && empty($item['hasExplicitSize'])) {
                $size = $this->getCachedGoogleDocExportSize($item);
            }

            $mtime = (int)($item['mtime'] ?? time());
            return [
                'size' => $size,
                'mtime' => $mtime,
                'atime' => $mtime,
                'ctime' => (int)($item['ctime'] ?? $mtime),
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('Google Drive stat failed for "{path}": {msg}', [
                'path' => $normalized,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getMimeType($path): string|false {
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return 'httpd/unix-directory';
        }

        $item = $this->resolveItem($normalized);
        if ($item === null) {
            return false;
        }
        if (!empty($item['isDir'])) {
            return 'httpd/unix-directory';
        }
        if (!empty($item['exportMimeType'])) {
            return (string)$item['exportMimeType'];
        }
        if (!empty($item['mimeType'])) {
            return (string)$item['mimeType'];
        }
        return parent::getMimeType($path);
    }

    public function getETag($path): string|false {
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            $root = $this->getRootFolderMetadata();
            if ($root === null) {
                return substr(sha1($this->getId() . ':root'), 0, 16);
            }
            return $this->computeDirectoryEtag('', (string)$root['id'], (int)($root['mtime'] ?? 0));
        }

        $item = $this->resolveItem($normalized);
        if ($item === null) {
            return false;
        }
        if (!empty($item['isDir'])) {
            return $this->computeDirectoryEtag($normalized, (string)$item['id'], (int)($item['mtime'] ?? 0));
        }
        return sha1(
            (string)$item['id']
            . ':' . (string)($item['version'] ?? '')
            . ':' . (string)($item['md5Checksum'] ?? '')
            . ':' . (string)($item['mtime'] ?? 0)
        );
    }

    public function hasUpdated($path, $time): bool {
        if ((int)$time <= 0) {
            return true;
        }

        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            $folderId = $this->getRootFolderId();
            if ($folderId === null) {
                return false;
            }
            return $this->hasDirectoryChildrenChanged('', $folderId);
        }

        $item = $this->resolveItem($normalized);
        if ($item === null) {
            return true;
        }

        if (!empty($item['isDir'])) {
            return $this->hasDirectoryChildrenChanged($normalized, (string)$item['id']);
        }

        return (int)($item['mtime'] ?? 0) > (int)$time;
    }

    public function touch($path, $mtime = null): bool {
        if ($this->readOnly) {
            return false;
        }
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return false;
        }

        try {
            $timestamp = $mtime !== null ? (int)$mtime : time();
            $item = $this->resolveItem($normalized);

            if ($item !== null) {
                $this->driveClient->updateMetadata($this->scope, $this->ownerUid, (string)$item['id'], [
                    'modifiedTime' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
                ]);
                [$parentPath] = $this->splitParentAndName($normalized);
                $this->cacheService->invalidatePath($this->cacheNs, $normalized, $this->resolveFolderId($parentPath));
                return true;
            }

            [$parentPath, $name] = $this->splitParentAndName($normalized);
            $parentId = $this->resolveFolderId($parentPath);
            if ($parentId === null) {
                return false;
            }

            $emptyStream = fopen('php://temp', 'w+b');
            if ($emptyStream === false) {
                return false;
            }
            try {
                $this->driveClient->uploadFromStream(
                    $this->scope,
                    $this->ownerUid,
                    $emptyStream,
                    $parentId,
                    $name,
                    $this->detectMimeType($name)
                );
            } finally {
                fclose($emptyStream);
            }

            $this->cacheService->invalidatePath($this->cacheNs, $normalized, $parentId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive touch failed for "{path}": {msg}', [
                'path' => $normalized,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function fopen($path, $mode) {
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return false;
        }

        $cleanMode = str_replace(['b', 't'], '', (string)$mode);
        $isReadOnlyMode = $cleanMode === 'r';

        try {
            if ($isReadOnlyMode) {
                $item = $this->resolveItem($normalized);
                if ($item === null || !empty($item['isDir'])) {
                    return false;
                }

                $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
                if ($stream === false) {
                    return false;
                }

                $exportMime = !empty($item['isGoogleDoc']) ? (string)($item['exportMimeType'] ?? '') : null;
                if (!empty($item['isGoogleDoc']) && ($exportMime === null || $exportMime === '')) {
                    fclose($stream);
                    return false;
                }

                $written = $this->driveClient->downloadToStream(
                    $this->scope,
                    $this->ownerUid,
                    (string)$item['id'],
                    $stream,
                    $exportMime !== '' ? $exportMime : null
                );

                if (!empty($item['isGoogleDoc']) && $written > 0) {
                    $this->cacheExportedSize($item, $written);
                }

                rewind($stream);
                return $stream;
            }

            if ($this->readOnly) {
                return false;
            }

            [$parentPath, $fileName] = $this->splitParentAndName($normalized);
            $parentId = $this->resolveFolderId($parentPath);
            if ($parentId === null) {
                return false;
            }

            $existingItem = $this->resolveItem($normalized);
            if (str_starts_with($cleanMode, 'x') && $existingItem !== null) {
                return false;
            }
            if ($existingItem !== null && (!empty($existingItem['isDir']) || !empty($existingItem['isGoogleDoc']))) {
                return false;
            }

            $tempStream = fopen('php://temp/maxmemory:8388608', 'w+b');
            if ($tempStream === false) {
                return false;
            }

            if (
                $existingItem !== null
                && (str_starts_with($cleanMode, 'a') || str_starts_with($cleanMode, 'c') || $cleanMode === 'r+')
            ) {
                $this->driveClient->downloadToStream(
                    $this->scope,
                    $this->ownerUid,
                    (string)$existingItem['id'],
                    $tempStream
                );
                if (str_starts_with($cleanMode, 'a')) {
                    fseek($tempStream, 0, SEEK_END);
                } else {
                    rewind($tempStream);
                }
            }

            return UploadStreamWrapper::open(
                $tempStream,
                function ($stream) use ($normalized, $parentId, $fileName, $existingItem): void {
                    $stat = fstat($stream);
                    $newSize = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
                    $oldSize = $existingItem !== null ? (int)($existingItem['size'] ?? 0) : 0;
                    $this->assertQuotaAvailable($newSize - $oldSize);

                    $this->driveClient->uploadFromStream(
                        $this->scope,
                        $this->ownerUid,
                        $stream,
                        $parentId,
                        $fileName,
                        $this->detectMimeType($fileName),
                        $existingItem !== null ? (string)$existingItem['id'] : null
                    );

                    $this->cacheService->invalidatePath($this->cacheNs, $normalized, $parentId);
                },
                (string)$mode
            );
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive fopen({mode}) failed for "{path}": {msg}', [
                'mode' => $mode,
                'path' => $normalized,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function unlink($path): bool {
        if ($this->readOnly) {
            return false;
        }
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return false;
        }

        try {
            $item = $this->resolveItem($normalized);
            if ($item === null) {
                return false;
            }
            if (!empty($item['isDir'])) {
                return $this->rmdir($normalized);
            }

            $this->driveClient->deleteItem($this->scope, $this->ownerUid, (string)$item['id'], $this->trashOnDelete);
            [$parentPath] = $this->splitParentAndName($normalized);
            $parentId = $this->resolveFolderId($parentPath);
            $this->cacheService->invalidatePath($this->cacheNs, $normalized, $parentId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive unlink failed for "{path}": {msg}', [
                'path' => $normalized,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function rename($path1, $path2): bool {
        if ($this->readOnly) {
            return false;
        }
        $src = $this->normalizePath((string)$path1);
        $dst = $this->normalizePath((string)$path2);
        if ($src === '' || $dst === '' || $src === $dst) {
            return false;
        }

        try {
            $srcItem = $this->resolveItem($src);
            if ($srcItem === null) {
                return false;
            }

            [$srcParentPath] = $this->splitParentAndName($src);
            [$dstParentPath, $dstName] = $this->splitParentAndName($dst);

            $srcParentId = $this->resolveFolderId($srcParentPath);
            $dstParentId = $this->resolveFolderId($dstParentPath);
            if ($srcParentId === null || $dstParentId === null) {
                return false;
            }

            $existingTarget = $this->resolveItem($dst);
            if ($existingTarget !== null && $existingTarget['id'] !== $srcItem['id']) {
                if (!empty($existingTarget['isDir'])) {
                    return false;
                }
                $this->driveClient->deleteItem($this->scope, $this->ownerUid, (string)$existingTarget['id'], $this->trashOnDelete);
            }

            $this->driveClient->moveAndRename(
                $this->scope,
                $this->ownerUid,
                (string)$srcItem['id'],
                $srcParentId,
                $dstParentId,
                $dstName
            );

            $this->cacheService->invalidatePath($this->cacheNs, $src, $srcParentId);
            $this->cacheService->invalidatePath($this->cacheNs, $dst, $dstParentId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive rename "{src}" -> "{dst}" failed: {msg}', [
                'src' => $src,
                'dst' => $dst,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function copy($path1, $path2): bool {
        if ($this->readOnly) {
            return false;
        }
        $src = $this->normalizePath((string)$path1);
        $dst = $this->normalizePath((string)$path2);
        if ($src === '' || $dst === '' || $src === $dst) {
            return false;
        }

        try {
            $srcItem = $this->resolveItem($src);
            if ($srcItem === null) {
                return false;
            }

            if (!empty($srcItem['isDir'])) {
                return parent::copy($src, $dst);
            }

            [$dstParentPath, $dstName] = $this->splitParentAndName($dst);
            $dstParentId = $this->resolveFolderId($dstParentPath);
            if ($dstParentId === null) {
                return false;
            }

            $this->assertQuotaAvailable((int)($srcItem['size'] ?? 0));

            $existingTarget = $this->resolveItem($dst);
            if ($existingTarget !== null && empty($existingTarget['isDir'])) {
                $this->driveClient->deleteItem($this->scope, $this->ownerUid, (string)$existingTarget['id'], $this->trashOnDelete);
            }

            $this->driveClient->copyFile(
                $this->scope,
                $this->ownerUid,
                (string)$srcItem['id'],
                $dstParentId,
                $dstName
            );

            $this->cacheService->invalidatePath($this->cacheNs, $dst, $dstParentId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Google Drive copy "{src}" -> "{dst}" failed: {msg}', [
                'src' => $src,
                'dst' => $dst,
                'msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function free_space($path): int|float {
        if ($this->readOnly) {
            return 0;
        }
        if ($this->cachedFreeSpace !== null) {
            return $this->cachedFreeSpace;
        }

        try {
            if ($this->quotaBytes !== null && $this->quotaBytes > 0) {
                $used = $this->getVolumeUsedBytes();
                $this->cachedFreeSpace = max(0, $this->quotaBytes - $used);
                return $this->cachedFreeSpace;
            }

            $about = $this->driveClient->getAboutInfo($this->scope, $this->ownerUid, true);
            $quota = $about['storageQuota'] ?? [];
            if (!isset($quota['limit']) || (int)$quota['limit'] <= 0) {
                $this->cachedFreeSpace = FileInfo::SPACE_UNLIMITED;
                return $this->cachedFreeSpace;
            }
            $limit = (int)$quota['limit'];
            $usage = (int)($quota['usage'] ?? 0);
            $this->cachedFreeSpace = max(0, $limit - $usage);
            return $this->cachedFreeSpace;
        } catch (\Throwable) {
            return FileInfo::SPACE_UNKNOWN;
        }
    }

    /**
     * Resolve relative path (e.g., "docs/readme.txt") to normalized Google Drive item metadata.
     *
     * @return array<string, mixed>|null
     */
    private function resolveItem(string $relativePath): ?array {
        $normalized = $this->normalizePath($relativePath);
        if ($normalized === '') {
            return $this->getRootFolderMetadata();
        }

        $cached = $this->cacheService->get($this->cacheNs, 'item:' . $normalized);
        if (is_array($cached)) {
            return $cached['found'] ? $cached['item'] : null;
        }

        $segments = explode('/', $normalized);
        $currentFolderId = $this->getRootFolderId();
        if ($currentFolderId === null) {
            return null;
        }

        $currentPath = '';
        $resolved = null;
        $count = count($segments);

        foreach ($segments as $idx => $segment) {
            $currentPath = $currentPath === '' ? $segment : ($currentPath . '/' . $segment);
            $childrenMap = $this->getFolderChildrenMap($currentPath === $segment ? '' : substr($currentPath, 0, (int)strrpos($currentPath, '/')), $currentFolderId);

            if (!isset($childrenMap[$segment])) {
                $this->cacheService->set($this->cacheNs, 'item:' . $normalized, ['found' => false], $this->getCacheTtl());
                return null;
            }

            $resolved = $childrenMap[$segment];
            if ($idx < $count - 1) {
                if (empty($resolved['isDir'])) {
                    $this->cacheService->set($this->cacheNs, 'item:' . $normalized, ['found' => false], $this->getCacheTtl());
                    return null;
                }
                $currentFolderId = (string)$resolved['id'];
            }
        }

        if ($resolved !== null) {
            $this->cacheService->set($this->cacheNs, 'item:' . $normalized, ['found' => true, 'item' => $resolved], $this->getCacheTtl());
        }
        return $resolved;
    }

    private function resolveFolderId(string $relativePath): ?string {
        $normalized = $this->normalizePath($relativePath);
        if ($normalized === '') {
            return $this->getRootFolderId();
        }
        $item = $this->resolveItem($normalized);
        if ($item === null || empty($item['isDir'])) {
            return null;
        }
        return (string)$item['id'];
    }

    private function getRootFolderId(): ?string {
        if ($this->resolvedRootId !== null) {
            return $this->resolvedRootId;
        }
        $meta = $this->getRootFolderMetadata();
        return $meta !== null ? (string)$meta['id'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRootFolderMetadata(): ?array {
        $cached = $this->cacheService->get($this->cacheNs, 'root_meta');
        if (is_array($cached) && !empty($cached['id'])) {
            $this->resolvedRootId = (string)$cached['id'];
            return $cached;
        }

        try {
            $root = $this->driveClient->resolveVolumeRootFolder($this->volume);
            if (!empty($root['id'])) {
                $this->resolvedRootId = (string)$root['id'];
                $this->cacheService->set($this->cacheNs, 'root_meta', $root, max(60, $this->getCacheTtl()));
                return $root;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed resolving root folder for Google Drive volume "{id}": {msg}', [
                'id' => $this->volumeId,
                'msg' => $e->getMessage(),
            ]);
        }
        return null;
    }

    /**
     * Fetch and map virtual child entries for a folder ID, preserving unique names for exported Google Docs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getFolderChildrenMap(string $folderPath, string $folderId): array {
        $ttl = $this->getCacheTtl();
        $cached = $this->cacheService->get($this->cacheNs, 'children:' . $folderId);
        if (is_array($cached)) {
            $this->populateChildItemCache($folderPath, $cached, $ttl);
            return $cached;
        }

        $rawChildren = $this->driveClient->listChildren($this->scope, $this->ownerUid, $folderId);
        $map = [];

        foreach ($rawChildren as $item) {
            $mime = (string)$item['mimeType'];
            $sanitizedBase = str_replace(['/', '\\'], '_', (string)$item['name']);
            if ($sanitizedBase === '' || $sanitizedBase === '.' || $sanitizedBase === '..') {
                continue;
            }

            if (GoogleDriveClient::isGoogleWorkspaceFile($mime)) {
                $export = GoogleDriveClient::getGoogleExportTarget($mime, $this->exportMode);
                if ($export === null) {
                    continue;
                }
                $suffix = '.' . $export['ext'];
                $virtualName = str_ends_with(strtolower($sanitizedBase), $suffix)
                    ? $sanitizedBase
                    : ($sanitizedBase . $suffix);

                $item['isGoogleDoc'] = true;
                $item['exportMimeType'] = $export['mime'];
                $item['virtualName'] = $virtualName;
                if (!isset($map[$virtualName])) {
                    $map[$virtualName] = $item;
                }
                continue;
            }

            $item['isGoogleDoc'] = false;
            $item['exportMimeType'] = null;
            if (!isset($map[$sanitizedBase])) {
                $map[$sanitizedBase] = $item;
            }
        }

        $this->cacheService->set($this->cacheNs, 'children:' . $folderId, $map, $ttl);
        $this->cacheService->set($this->cacheNs, 'children_path:' . $folderPath, array_keys($map), $ttl);
        $this->populateChildItemCache($folderPath, $map, $ttl);
        return $map;
    }

    /**
     * Populate per-path item cache entries so subsequent resolveItem() lookups avoid re-walking parents.
     *
     * @param array<string, array<string, mixed>> $entries
     */
    private function populateChildItemCache(string $folderPath, array $entries, int $ttl): void {
        foreach ($entries as $name => $entry) {
            $childName = (string)$name;
            if ($childName === '') {
                continue;
            }
            $childPath = $folderPath === '' ? $childName : ($folderPath . '/' . $childName);
            if ($this->cacheService->get($this->cacheNs, 'item:' . $childPath) === null) {
                $this->cacheService->set($this->cacheNs, 'item:' . $childPath, ['found' => true, 'item' => $entry], $ttl);
            }
        }
    }

    private function computeDirectoryEtag(string $normalizedPath, string $folderId, int $folderMtime): string {
        $parts = [$folderId . ':' . $folderMtime];
        try {
            $children = $this->getFolderChildrenMap($normalizedPath, $folderId);
            foreach ($children as $name => $child) {
                $parts[] = (string)$name
                    . ':' . (string)($child['id'] ?? '')
                    . ':' . (string)($child['version'] ?? '')
                    . ':' . (string)($child['mtime'] ?? 0)
                    . ':' . (string)($child['size'] ?? 0);
            }
        } catch (\Throwable) {
        }
        return sha1(implode('|', $parts));
    }

    private function hasDirectoryChildrenChanged(string $normalizedPath, string $folderId): bool {
        try {
            $childrenMap = $this->getFolderChildrenMap($normalizedPath, $folderId);
            $cache = $this->getCache($normalizedPath);
            $cachedEntry = $cache->get($normalizedPath);
            if (!$cachedEntry || (int)($cachedEntry['storage_mtime'] ?? 0) <= 0) {
                return true;
            }

            $cachedChildren = $cache->getFolderContentsById($cachedEntry->getId());
            if (count($cachedChildren) !== count($childrenMap)) {
                return true;
            }

            $cachedByName = [];
            foreach ($cachedChildren as $cachedChild) {
                $name = (string)($cachedChild['name'] ?? '');
                if ($name !== '') {
                    $cachedByName[$name] = $cachedChild;
                }
            }

            foreach ($childrenMap as $name => $item) {
                $key = (string)$name;
                if (!isset($cachedByName[$key])) {
                    return true;
                }
                $remoteMtime = (int)($item['mtime'] ?? 0);
                $cachedStorageMtime = (int)($cachedByName[$key]['storage_mtime'] ?? 0);
                if ($remoteMtime > $cachedStorageMtime) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function getCachedGoogleDocExportSize(array $item): int {
        $key = 'export_size:' . $item['id'] . ':' . ($item['version'] ?? '0') . ':' . $this->exportMode;
        $cached = $this->cacheService->get($this->cacheNs, $key);
        if (is_int($cached)) {
            return $cached;
        }
        return (int)($item['size'] ?? 0);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function cacheExportedSize(array $item, int $size): void {
        $key = 'export_size:' . $item['id'] . ':' . ($item['version'] ?? '0') . ':' . $this->exportMode;
        $this->cacheService->set($this->cacheNs, $key, $size, 86400);
    }

    private function getVolumeUsedBytes(): int {
        $cached = $this->cacheService->get($this->cacheNs, 'usage:' . $this->cacheNs);
        if (is_int($cached)) {
            return $cached;
        }

        $used = 0;
        try {
            $cache = $this->getCache('');
            $rootEntry = $cache->get('');
            if ($rootEntry) {
                if ((int)($rootEntry['size'] ?? -1) >= 0) {
                    $used = (int)$rootEntry['size'];
                } else {
                    foreach ($cache->getFolderContentsById($rootEntry->getId()) as $child) {
                        $used += max(0, (int)($child['size'] ?? 0));
                    }
                }
            }
        } catch (\Throwable) {
            $used = 0;
        }

        $this->cacheService->set($this->cacheNs, 'usage:' . $this->cacheNs, $used, max(60, $this->getCacheTtl()));
        return $used;
    }

    private function assertQuotaAvailable(int $additionalBytes): void {
        if ($this->quotaBytes === null || $this->quotaBytes <= 0 || $additionalBytes <= 0) {
            return;
        }
        $used = $this->getVolumeUsedBytes();
        if (($used + $additionalBytes) > $this->quotaBytes) {
            throw new \RuntimeException('Google Drive volume disk quota exceeded.');
        }
    }

    private function getCacheTtl(): int {
        return $this->configService->getCacheTtl($this->scope, $this->ownerUid);
    }

    private function normalizePath(string $path): string {
        $trimmed = trim(str_replace('\\', '/', $path), '/');
        return $trimmed === '.' ? '' : $trimmed;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitParentAndName(string $normalizedPath): array {
        $pos = strrpos($normalizedPath, '/');
        if ($pos === false) {
            return ['', $normalizedPath];
        }
        return [substr($normalizedPath, 0, $pos), substr($normalizedPath, $pos + 1)];
    }

    private function detectMimeType(string $fileName): string {
        try {
            $detected = \OC::$server->getMimeTypeDetector()->detectPath($fileName);
            if ($detected !== '') {
                return $detected;
            }
        } catch (\Throwable) {
        }
        return 'application/octet-stream';
    }
}
