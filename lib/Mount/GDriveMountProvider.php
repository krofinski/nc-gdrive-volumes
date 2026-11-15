<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Mount;

use OC\Files\Mount\MountPoint;
use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCA\GDriveVolumes\Storage\GDriveVolumeStorage;
use OCP\Files\Config\IMountProvider;
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

        return $mounts;
    }
}
