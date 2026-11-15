<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Service;

use OCA\GDriveVolumes\Mount\GDriveMountProvider;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Cache\IScanner;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\InvalidateMountCacheEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Storage\IStorageFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Ensures common local parent directories (e.g. /Gdrive) exist inside users'
 * Nextcloud home folders and synchronizes/warms mounted Google Drive volumes so
 * subdirectories (e.g. Gdrive/Volume_1, Gdrive/Volume_2) appear cleanly in Files and WebDAV.
 */
class ParentDirectoryService {
    /** @var array<string, bool> */
    private array $ensuredUsersInRequest = [];

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly ConfigService $configService,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly IMountManager $mountManager,
        private readonly IUserMountCache $userMountCache,
        private readonly IStorageFactory $storageFactory,
        private readonly GDriveMountProvider $mountProvider,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ensure all required common local parent directories exist for a user and keep mounts synced.
     */
    public function ensureForUser(IUser $user, bool $forceScan = false): void {
        $uid = $user->getUID();
        if (!$forceScan && isset($this->ensuredUsersInRequest[$uid])) {
            return;
        }
        $this->ensuredUsersInRequest[$uid] = true;

        $this->syncAndWarmUserMounts($user, $forceScan);
    }

    /**
     * Synchronize GDrive mount points into oc_mounts (repairing stale root_id/storage_id rows)
     * and perform shallow root scans when unscanned or forced.
     */
    public function syncAndWarmUserMounts(IUser $user, bool $forceScan = false): void {
        $uid = $user->getUID();
        $volumes = $this->configService->getActiveVolumesForUser($user);
        $mounts = [];
        $repairedMountPoints = [];

        try {
            // Reconcile oc_mounts before ensuring local parent folders so SetupManager
            // never registers mounts against stale root_id/storage_id rows.
            $mounts = $this->mountProvider->getMountsForUser($user, $this->storageFactory);
            if ($mounts !== []) {
                $cachedMounts = $this->userMountCache->getMountsForUser($user);
                $cachedByMountPoint = [];
                foreach ($cachedMounts as $cachedMount) {
                    $cachedByMountPoint[$cachedMount->getMountPoint()] = $cachedMount;
                }

                $cacheNeedsClear = false;
                foreach ($mounts as $mp) {
                    $mountPoint = $mp->getMountPoint();
                    try {
                        $storage = $mp->getStorage();
                        if ($storage === null) {
                            continue;
                        }
                        // Ensure '' root cache entry exists in oc_filecache before reading root_id/storage_id
                        $storage->getCache('');
                        $rootId = $mp->getStorageRootId();
                        $numericStorageId = $mp->getNumericStorageId();

                        if (!isset($cachedByMountPoint[$mountPoint])) {
                            $repairedMountPoints[$mountPoint] = true;
                        } else {
                            $existingCached = $cachedByMountPoint[$mountPoint];
                            if ($existingCached->getRootId() !== $rootId || $existingCached->getStorageId() !== $numericStorageId) {
                                $this->userMountCache->removeUserStorageMount($existingCached->getStorageId(), $uid);
                                if (method_exists($this->userMountCache, 'removeMount')) {
                                    $this->userMountCache->removeMount($mountPoint, $user);
                                }
                                $cacheNeedsClear = true;
                                $repairedMountPoints[$mountPoint] = true;
                            }
                        }

                        $this->mountManager->addMount($mp);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Unable to prepare Google Drive mount "{mount}" for user "{uid}": {msg}', [
                            'mount' => $mountPoint,
                            'uid' => $uid,
                            'msg' => $e->getMessage(),
                        ]);
                    }
                }

                if ($cacheNeedsClear) {
                    $this->userMountCache->clear();
                }

                $this->userMountCache->registerMounts($user, $mounts, [GDriveMountProvider::class]);

                if ($repairedMountPoints !== [] || $forceScan) {
                    $this->eventDispatcher->dispatchTyped(new InvalidateMountCacheEvent($user));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to synchronize Google Drive mounts for user "{uid}": {msg}', [
                'uid' => $uid,
                'msg' => $e->getMessage(),
            ]);
        }

        foreach ($volumes as $volume) {
            $parentDir = (string)($volume['local_parent_dir'] ?? '');
            if ($parentDir !== '') {
                $this->ensurePathForUid($uid, $parentDir);
            }
        }

        foreach ($mounts as $mp) {
            $mountPoint = $mp->getMountPoint();
            try {
                $storage = $mp->getStorage();
                if ($storage === null) {
                    continue;
                }
                $rootEntry = $storage->getCache('')->get('');
                $needsInitialScan = !$rootEntry
                    || (int)($rootEntry['storage_mtime'] ?? 0) <= 0
                    || isset($repairedMountPoints[$mountPoint]);

                if ($forceScan || $needsInitialScan) {
                    $storage->getScanner()->scan('', IScanner::SCAN_SHALLOW);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Unable to shallow-scan Google Drive mount "{mount}" for user "{uid}": {msg}', [
                    'mount' => $mountPoint,
                    'uid' => $uid,
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ensure parent directory exists for all target users of a given volume.
     *
     * @param array<string, mixed> $volume
     */
    public function ensureForVolume(array $volume, ?string $actingUid = null): void {
        $parentDir = (string)($volume['local_parent_dir'] ?? '');
        if ($parentDir === '' || empty($volume['enabled'])) {
            return;
        }

        foreach ($this->collectTargetUids($volume, $actingUid) as $uid) {
            $this->ensurePathForUid($uid, $parentDir);
        }
    }

    /**
     * Warm the parent directory and mounted volume node for target users so both
     * /Gdrive and /Gdrive/Volume_1 are immediately registered and scanned in Nextcloud.
     *
     * @param array<string, mixed> $volume
     */
    public function warmVolumeMount(array $volume, ?string $actingUid = null): void {
        if (empty($volume['enabled'])) {
            return;
        }

        $mountPath = trim((string)($volume['local_mount_path'] ?? ''), '/');

        foreach ($this->collectTargetUids($volume, $actingUid) as $uid) {
            try {
                $user = $this->userManager->get($uid);
                if ($user !== null) {
                    $this->syncAndWarmUserMounts($user, true);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Unable to warm volume mount "{path}" for user "{uid}": {msg}', [
                    'path' => $mountPath,
                    'uid' => $uid,
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $volume
     * @return list<string>
     */
    private function collectTargetUids(array $volume, ?string $actingUid = null): array {
        if (($volume['scope'] ?? ConfigService::SCOPE_ADMIN) === ConfigService::SCOPE_PERSONAL) {
            $ownerUid = (string)($volume['owner_uid'] ?? '');
            return $ownerUid !== '' ? [$ownerUid] : [];
        }

        $targetUids = [];
        if ($actingUid !== null && $actingUid !== '') {
            $targetUids[$actingUid] = true;
        }

        foreach (($volume['allowed_users'] ?? []) as $uid) {
            $targetUids[(string)$uid] = true;
        }

        foreach (($volume['allowed_groups'] ?? []) as $groupId) {
            $group = $this->groupManager->get((string)$groupId);
            if ($group !== null) {
                foreach ($group->getUsers() as $member) {
                    $targetUids[$member->getUID()] = true;
                }
            }
        }

        // If open to all users, proactively prepare up to 100 local users; remaining users
        // are ensured automatically on login or when opening Files.
        if (empty($volume['allowed_users']) && empty($volume['allowed_groups'])) {
            $users = $this->userManager->search('', 100);
            foreach ($users as $user) {
                $targetUids[$user->getUID()] = true;
            }
        }

        return array_map('strval', array_keys($targetUids));
    }

    /**
     * Create nested relative directories inside /$uid/files/ if missing.
     */
    public function ensurePathForUid(string $uid, string $relativeDir): void {
        $trimmed = trim($relativeDir, '/');
        if ($trimmed === '') {
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $segments = array_values(array_filter(explode('/', $trimmed), static fn (string $s): bool => $s !== ''));
            $current = $userFolder;

            foreach ($segments as $segment) {
                if ($current->nodeExists($segment)) {
                    $node = $current->get($segment);
                    if ($node instanceof Folder) {
                        $current = $node;
                        continue;
                    }
                    return;
                }
                $current = $current->newFolder($segment);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to ensure local parent directory "{dir}" for user "{uid}": {msg}', [
                'dir' => $trimmed,
                'uid' => $uid,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
