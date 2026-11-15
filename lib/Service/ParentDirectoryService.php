<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Ensures common local parent directories (e.g. /Gdrive) exist inside users'
 * Nextcloud home folders so mounted volume subdirectories (e.g. Gdrive/Volume_1,
 * Gdrive/Volume_2) appear cleanly in Files and WebDAV.
 */
class ParentDirectoryService {
    /** @var array<string, bool> */
    private array $ensuredUsersInRequest = [];

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly ConfigService $configService,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ensure all required common local parent directories exist for a user.
     */
    public function ensureForUser(IUser $user): void {
        $uid = $user->getUID();
        if (isset($this->ensuredUsersInRequest[$uid])) {
            return;
        }
        $this->ensuredUsersInRequest[$uid] = true;

        $volumes = $this->configService->getActiveVolumesForUser($user);
        foreach ($volumes as $volume) {
            $parentDir = (string)($volume['local_parent_dir'] ?? '');
            if ($parentDir !== '') {
                $this->ensurePathForUid($uid, $parentDir);
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
     * /Gdrive and /Gdrive/Volume_1 are immediately registered in Nextcloud's filecache and oc_mounts.
     *
     * @param array<string, mixed> $volume
     */
    public function warmVolumeMount(array $volume, ?string $actingUid = null): void {
        if (empty($volume['enabled'])) {
            return;
        }

        $parentDir = trim((string)($volume['local_parent_dir'] ?? ''), '/');
        $mountPath = trim((string)($volume['local_mount_path'] ?? ''), '/');

        foreach ($this->collectTargetUids($volume, $actingUid) as $uid) {
            try {
                if ($parentDir !== '') {
                    $this->ensurePathForUid($uid, $parentDir);
                }
                $userFolder = $this->rootFolder->getUserFolder($uid);
                if ($parentDir !== '' && $userFolder->nodeExists($parentDir)) {
                    $userFolder->get($parentDir);
                }
                if ($mountPath !== '' && $userFolder->nodeExists($mountPath)) {
                    $userFolder->get($mountPath);
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
