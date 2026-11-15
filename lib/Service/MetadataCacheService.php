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
    private const MAX_MEMORY_ENTRIES = 4096;
    private const TRIM_MEMORY_ENTRIES = 2048;

    private ICache $cache;
    /** @var array<string, mixed> */
    private array $memoryCache = [];
    /** @var array<string, int> */
    private array $memoryGenerations = [];

    public function __construct(ICacheFactory $cacheFactory) {
        if ($cacheFactory->isAvailable()) {
            $this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
        } elseif ($cacheFactory->isLocalCacheAvailable()) {
            $this->cache = $cacheFactory->createLocal(self::CACHE_PREFIX);
        } else {
            $this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
        }
    }

    /**
     * Get the current invalidation generation number for a scope or volume.
     */
    public function getNamespaceGeneration(string $namespace): int {
        if (isset($this->memoryGenerations[$namespace])) {
            return $this->memoryGenerations[$namespace];
        }
        $gen = $this->cache->get('gen:' . $namespace);
        $resolved = is_int($gen) ? $gen : 1;
        $this->memoryGenerations[$namespace] = $resolved;
        return $resolved;
    }

    /**
     * Bump generation to invalidate all cached entries under a namespace (e.g. volume ID or scope).
     */
    public function invalidateNamespace(string $namespace): void {
        $next = $this->getNamespaceGeneration($namespace) + 1;
        $this->memoryGenerations[$namespace] = $next;
        $prefix = $namespace . ':';
        foreach (array_keys($this->memoryCache) as $cacheKey) {
            if (str_starts_with((string)$cacheKey, $prefix)) {
                unset($this->memoryCache[$cacheKey]);
            }
        }
        $this->cache->set('gen:' . $namespace, $next, 86400 * 30);
    }

    public function get(string $namespace, string $key): mixed {
        $gen = $this->getNamespaceGeneration($namespace);
        $fullKey = $namespace . ':' . $gen . ':' . sha1($key);
        if (array_key_exists($fullKey, $this->memoryCache)) {
            return $this->memoryCache[$fullKey];
        }
        $val = $this->cache->get($fullKey);
        if ($val !== null) {
            $this->storeInMemory($fullKey, $val);
        }
        return $val;
    }

    public function set(string $namespace, string $key, mixed $value, int $ttl): void {
        $gen = $this->getNamespaceGeneration($namespace);
        $fullKey = $namespace . ':' . $gen . ':' . sha1($key);
        $this->storeInMemory($fullKey, $value);
        $this->cache->set($fullKey, $value, max(1, $ttl));
    }

    public function delete(string $namespace, string $key): void {
        $gen = $this->getNamespaceGeneration($namespace);
        $fullKey = $namespace . ':' . $gen . ':' . sha1($key);
        unset($this->memoryCache[$fullKey]);
        $this->cache->remove($fullKey);
    }

    private function storeInMemory(string $fullKey, mixed $value): void {
        if (!array_key_exists($fullKey, $this->memoryCache) && count($this->memoryCache) >= self::MAX_MEMORY_ENTRIES) {
            $this->memoryCache = array_slice($this->memoryCache, -self::TRIM_MEMORY_ENTRIES, null, true);
        }
        $this->memoryCache[$fullKey] = $value;
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
