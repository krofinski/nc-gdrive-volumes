<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Caches Google Drive folder listings, path-to-ID resolutions, file metadata,
 * exported document sizes, and account/volume quota calculations with generation-based invalidation.
 */
class MetadataCacheService {
    private const CACHE_PREFIX = 'gdrive_volumes_meta_v1';

    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        $this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
    }

    /**
     * Get the current invalidation generation number for a scope or volume.
     */
    public function getNamespaceGeneration(string $namespace): int {
        $gen = $this->cache->get('gen:' . $namespace);
        return is_int($gen) ? $gen : 1;
    }

    /**
     * Bump generation to invalidate all cached entries under a namespace (e.g. volume ID or scope).
     */
    public function invalidateNamespace(string $namespace): void {
        $next = $this->getNamespaceGeneration($namespace) + 1;
        $this->cache->set('gen:' . $namespace, $next, 86400 * 30);
    }

    public function get(string $namespace, string $key): mixed {
        $gen = $this->getNamespaceGeneration($namespace);
        $fullKey = $namespace . ':' . $gen . ':' . sha1($key);
        return $this->cache->get($fullKey);
    }

    public function set(string $namespace, string $key, mixed $value, int $ttl): void {
        $gen = $this->getNamespaceGeneration($namespace);
        $fullKey = $namespace . ':' . $gen . ':' . sha1($key);
        $this->cache->set($fullKey, $value, max(1, $ttl));
    }

    public function delete(string $namespace, string $key): void {
        $gen = $this->getNamespaceGeneration($namespace);
        $fullKey = $namespace . ':' . $gen . ':' . sha1($key);
        $this->cache->remove($fullKey);
    }

    /**
     * Invalidate cached children and item metadata for a specific path and its parent directory.
     */
    public function invalidatePath(string $namespace, string $relativePath, ?string $parentFolderId = null): void {
        $normalized = trim($relativePath, '/');
        $this->delete($namespace, 'item:' . $normalized);

        $parentPath = str_contains($normalized, '/')
            ? substr($normalized, 0, (int)strrpos($normalized, '/'))
            : '';
        $this->delete($namespace, 'children_path:' . $parentPath);
        if ($parentFolderId !== null && $parentFolderId !== '') {
            $this->delete($namespace, 'children:' . $parentFolderId);
        }
        $this->delete($namespace, 'usage:' . $namespace);
    }

    public static function scopeNamespace(string $scope, ?string $uid): string {
        return $scope === ConfigService::SCOPE_ADMIN
            ? 'scope_admin'
            : 'scope_personal_' . sha1((string)$uid);
    }

    public static function volumeNamespace(string $volumeId): string {
        return 'vol_' . $volumeId;
    }
}
