<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Mount;

use OC\Files\Mount\MountPoint;
use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCA\GDriveVolumes\Storage\GDriveVolumeStorage;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Provides Nextcloud mount points for each enabled Google Drive subdirectory volume,
 * mounting them as independent external disks under /$uid/files/<local_parent>/<local_sub>.
 */
class GDriveMountProvider implements IMountProvider {
    public function __construct(
        private readonly ConfigService $configService,
        private readonly GoogleDriveClient $driveClient,
        private readonly MetadataCacheService $cacheService,
        private readonly IUserMountCache $userMountCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return IMountPoint[]
     */
    public function getMountsForUser(IUser $user, IStorageFactory $storageFactory): array {
        $volumes = $this->configService->getActiveVolumesForUser($user);
        if ($volumes === []) {
            return [];
        }

        $mounts = [];
        $uid = $user->getUID();

        foreach ($volumes as $volume) {
            $localMountPath = trim((string)($volume['local_mount_path'] ?? ''), '/');
            if ($localMountPath === '') {
                continue;
            }

            $scope = (string)($volume['scope'] ?? ConfigService::SCOPE_ADMIN);
            $ownerUid = $scope === ConfigService::SCOPE_PERSONAL ? (string)($volume['owner_uid'] ?? $uid) : null;
            $account = $this->configService->getPublicAccountSummary($scope, $ownerUid);
            if (empty($account['connected'])) {
                continue;
            }

            $mountPointPath = '/' . $uid . '/files/' . $localMountPath;
            $storageArguments = [
                'volume' => $volume,
                'drive_client' => $this->driveClient,
                'cache_service' => $this->cacheService,
                'config_service' => $this->configService,
                'logger' => $this->logger,
                'read_only' => (bool)($volume['read_only'] ?? false),
            ];

            $mounts[] = new MountPoint(
                GDriveVolumeStorage::class,
                $mountPointPath,
                $storageArguments,
                $storageFactory,
                [
                    'encrypt' => true,
                    'previews' => true,
                    'enable_sharing' => true,
                    'readonly' => (bool)($volume['read_only'] ?? false),
                    'filesystem_check_changes' => 1,
                ],
                null,
                self::class
            );
        }

        $this->reconcileUserMountCache($user, $mounts);
        return $mounts;
    }

    /**
     * Remove stale oc_mounts rows whose root_id or storage_id changed before SetupManager::registerMounts() runs.
     *
     * @param IMountPoint[] $mounts
     */
    private function reconcileUserMountCache(IUser $user, array $mounts): void {
        if ($mounts === []) {
            return;
        }

        try {
            $uid = $user->getUID();
            $cachedMounts = $this->userMountCache->getMountsForUser($user);
            $cachedByMountPoint = [];
            foreach ($cachedMounts as $cachedMount) {
                $cachedByMountPoint[$cachedMount->getMountPoint()] = $cachedMount;
            }

            $needsClear = false;
            foreach ($mounts as $mp) {
                $storage = $mp->getStorage();
                if ($storage === null) {
                    continue;
                }
                // Ensure '' root cache entry exists in oc_filecache before reading root_id/storage_id
                $storage->getCache('');
                $rootId = $mp->getStorageRootId();
                $numericStorageId = $mp->getNumericStorageId();
                $mountPoint = $mp->getMountPoint();

                if (isset($cachedByMountPoint[$mountPoint])) {
                    $existing = $cachedByMountPoint[$mountPoint];
                    if ($existing->getRootId() !== $rootId || $existing->getStorageId() !== $numericStorageId) {
                        $this->userMountCache->removeUserStorageMount($existing->getStorageId(), $uid);
                        if (method_exists($this->userMountCache, 'removeMount')) {
                            $this->userMountCache->removeMount($mountPoint, $user);
                        }
                        $needsClear = true;
                    }
                }
            }

            if ($needsClear) {
                $this->userMountCache->clear();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to reconcile oc_mounts for user "{uid}": {msg}', [
                'uid' => $user->getUID(),
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
