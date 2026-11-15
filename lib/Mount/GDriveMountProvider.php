<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Mount;

use OC\Files\Mount\MountPoint;
use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCA\GDriveVolumes\Storage\GDriveVolumeStorage;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Mount\IMountManager;
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
        private readonly IMountManager $mountManager,
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
        $homeMount = $this->mountManager->find('/' . $uid . '/files');
        $ensuredParents = [];

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

            $parentDir = trim((string)($volume['local_parent_dir'] ?? ''), '/');
            if ($parentDir !== '' && !isset($ensuredParents[$parentDir]) && $homeMount !== null) {
                $ensuredParents[$parentDir] = true;
                $this->ensureHomeParentDirectory($homeMount, '/' . $uid . '/files/' . $parentDir);
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
                ],
                null,
                self::class
            );
        }

        return $mounts;
    }

    private function ensureHomeParentDirectory(IMountPoint $homeMount, string $fullParentPath): void {
        try {
            $homeStorage = $homeMount->getStorage();
            if ($homeStorage === null) {
                return;
            }

            $internalParent = trim($homeMount->getInternalPath($fullParentPath), '/');
            if ($internalParent === '') {
                return;
            }

            if (!$homeStorage->is_dir($internalParent)) {
                $homeStorage->mkdir($internalParent);
            }

            if ($homeStorage->getCache()->getId($internalParent) === -1) {
                $homeStorage->getScanner()->scanFile($internalParent);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to ensure home parent directory "{path}": {msg}', [
                'path' => $fullParentPath,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
