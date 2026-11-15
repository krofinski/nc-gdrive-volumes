<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Storage;

use OC\Files\Storage\Common;
use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCP\Files\FileInfo;
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

    public function filetype($path): string|false {
        $normalized = $this->normalizePath((string)$path);
        if ($normalized === '') {
            return $this->getRootFolderId() !== null ? 'dir' : false;
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
                    return false;
                }
                $mtime = (int)($rootMeta['mtime'] ?? time());
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
            if (empty($item['isDir']) && !empty($item['isGoogleDoc']) && !$item['hasExplicitSize']) {
                $size = $this->resolveGoogleDocExportSize($item);
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
                return false;
            }
            return sha1(($root['id'] ?? 'root') . ':' . ($root['mtime'] ?? 0));
        }

        $item = $this->resolveItem($normalized);
        if ($item === null) {
            return false;
        }
        return sha1(
            (string)$item['id']
            . ':' . (string)($item['version'] ?? '')
            . ':' . (string)($item['md5Checksum'] ?? '')
            . ':' . (string)($item['mtime'] ?? 0)
        );
    }

    public function hasUpdated($path, $time): bool {
        $mtime = $this->filemtime($path);
        if ($mtime === false) {
            return true;
        }
        return $mtime > $time;
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

        try {
            if ($this->quotaBytes !== null && $this->quotaBytes > 0) {
                $used = $this->getVolumeUsedBytes();
                return max(0, $this->quotaBytes - $used);
            }

            $about = $this->driveClient->getAboutInfo($this->scope, $this->ownerUid, true);
            $quota = $about['storageQuota'] ?? [];
            if (!isset($quota['limit']) || (int)$quota['limit'] <= 0) {
                return FileInfo::SPACE_UNLIMITED;
            }
            $limit = (int)$quota['limit'];
            $usage = (int)($quota['usage'] ?? 0);
            return max(0, $limit - $usage);
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
        $cached = $this->cacheService->get($this->cacheNs, 'children:' . $folderId);
        if (is_array($cached)) {
            return $cached;
        }

        $rawChildren = $this->driveClient->listChildren($this->scope, $this->ownerUid, $folderId);
        $map = [];

        foreach ($rawChildren as $item) {
            $mime = (string)$item['mimeType'];
            if (GoogleDriveClient::isGoogleWorkspaceFile($mime)) {
                $export = GoogleDriveClient::getGoogleExportTarget($mime, $this->exportMode);
                if ($export === null) {
                    continue;
                }
                $baseName = (string)$item['name'];
                $suffix = '.' . $export['ext'];
                $virtualName = str_ends_with(strtolower($baseName), $suffix)
                    ? $baseName
                    : ($baseName . $suffix);

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
            $name = (string)$item['name'];
            if ($name !== '' && !isset($map[$name])) {
                $map[$name] = $item;
            }
        }

        $ttl = $this->getCacheTtl();
        $this->cacheService->set($this->cacheNs, 'children:' . $folderId, $map, $ttl);
        $this->cacheService->set($this->cacheNs, 'children_path:' . $folderPath, array_keys($map), $ttl);
        return $map;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveGoogleDocExportSize(array $item): int {
        $key = 'export_size:' . $item['id'] . ':' . ($item['version'] ?? '0') . ':' . $this->exportMode;
        $cached = $this->cacheService->get($this->cacheNs, $key);
        if (is_int($cached)) {
            return $cached;
        }

        $exportMime = (string)($item['exportMimeType'] ?? '');
        if ($exportMime === '') {
            return 0;
        }

        $tmp = fopen('php://temp/maxmemory:4194304', 'w+b');
        if ($tmp === false) {
            return 0;
        }

        try {
            $size = $this->driveClient->downloadToStream(
                $this->scope,
                $this->ownerUid,
                (string)$item['id'],
                $tmp,
                $exportMime
            );
            $this->cacheService->set($this->cacheNs, $key, $size, 86400);
            return $size;
        } catch (\Throwable) {
            return 0;
        } finally {
            fclose($tmp);
        }
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

        $rootId = $this->getRootFolderId();
        if ($rootId === null) {
            return 0;
        }

        $used = $this->driveClient->calculateFolderUsageBytes($this->scope, $this->ownerUid, $rootId);
        $this->cacheService->set($this->cacheNs, 'usage:' . $this->cacheNs, $used, max(120, $this->getCacheTtl()));
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
