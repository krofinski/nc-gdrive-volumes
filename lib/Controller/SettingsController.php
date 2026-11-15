<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Controller;

use InvalidArgumentException;
use OCA\GDriveVolumes\AppInfo\Application;
use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCA\GDriveVolumes\Service\ParentDirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Throwable;

class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private readonly ConfigService $configService,
        private readonly GoogleDriveClient $driveClient,
        private readonly MetadataCacheService $cacheService,
        private readonly ParentDirectoryService $parentDirectoryService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Get full UI state for admin or personal settings panel.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function getState(string $scope): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);

            $availableGroups = [];
            $availableUsers = [];
            if ($validScope === ConfigService::SCOPE_ADMIN) {
                $availableGroups = array_map(
                    static fn (IGroup $g): array => ['gid' => $g->getGID(), 'displayName' => $g->getDisplayName()],
                    $this->groupManager->search('', 100)
                );
                $availableUsers = array_map(
                    static fn (IUser $u): array => ['uid' => $u->getUID(), 'displayName' => $u->getDisplayName()],
                    $this->userManager->search('', 100)
                );
            }

            return new DataResponse([
                'scope' => $validScope,
                'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.auth.callback'),
                'authorize_url' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.auth.authorize', ['scope' => $validScope]),
                'account' => $this->configService->getPublicAccountSummary($validScope, $ownerUid),
                'general' => [
                    'default_common_local_dir' => $this->configService->getDefaultCommonLocalDir($validScope, $ownerUid),
                    'default_remote_prefix' => $this->configService->getDefaultRemotePrefix($validScope, $ownerUid),
                    'cache_ttl' => $this->configService->getCacheTtl($validScope, $ownerUid),
                    'allow_personal_mounts' => $this->configService->isPersonalMountsAllowed(),
                ],
                'volumes' => $this->configService->getVolumes($validScope, $ownerUid),
                'groups' => $availableGroups,
                'users' => $availableUsers,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Save OAuth 2.0 client credentials or Service Account JSON for the given scope.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function saveAccount(string $scope): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $params = $this->request->getParams();

            $this->configService->saveAccount($validScope, $ownerUid, [
                'auth_type' => $params['auth_type'] ?? ConfigService::AUTH_OAUTH,
                'client_id' => $params['client_id'] ?? '',
                'client_secret' => $params['client_secret'] ?? '',
                'service_account_json' => $params['service_account_json'] ?? '',
                'impersonate_email' => $params['impersonate_email'] ?? '',
            ]);

            $this->cacheService->invalidateNamespace(MetadataCacheService::scopeNamespace($validScope, $ownerUid));

            return new DataResponse([
                'account' => $this->configService->getPublicAccountSummary($validScope, $ownerUid),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function disconnectAccount(string $scope): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $this->configService->disconnectAccount($validScope, $ownerUid);
            $this->cacheService->invalidateNamespace(MetadataCacheService::scopeNamespace($validScope, $ownerUid));

            return new DataResponse([
                'account' => $this->configService->getPublicAccountSummary($validScope, $ownerUid),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function testAccount(string $scope): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $about = $this->driveClient->getAboutInfo($validScope, $ownerUid, false);

            $userEmail = (string)($about['user']['emailAddress'] ?? '');
            $userName = (string)($about['user']['displayName'] ?? '');
            if ($userEmail !== '' || $userName !== '') {
                $this->configService->saveAccount($validScope, $ownerUid, [
                    'connected_email' => $userEmail,
                    'connected_name' => $userName,
                ]);
            }

            return new DataResponse([
                'ok' => true,
                'user' => $about['user'] ?? [],
                'storageQuota' => $about['storageQuota'] ?? [],
                'account' => $this->configService->getPublicAccountSummary($validScope, $ownerUid),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function saveGeneral(string $scope): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $params = $this->request->getParams();

            $this->configService->saveGeneralSettings($validScope, $ownerUid, [
                'default_common_local_dir' => $params['default_common_local_dir'] ?? 'Gdrive',
                'default_remote_prefix' => $params['default_remote_prefix'] ?? 'NextCloud',
                'cache_ttl' => $params['cache_ttl'] ?? 60,
                'allow_personal_mounts' => $params['allow_personal_mounts'] ?? true,
            ]);

            return new DataResponse([
                'general' => [
                    'default_common_local_dir' => $this->configService->getDefaultCommonLocalDir($validScope, $ownerUid),
                    'default_remote_prefix' => $this->configService->getDefaultRemotePrefix($validScope, $ownerUid),
                    'cache_ttl' => $this->configService->getCacheTtl($validScope, $ownerUid),
                    'allow_personal_mounts' => $this->configService->isPersonalMountsAllowed(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function listDriveFolders(string $scope, string $parentId = 'root'): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $targetParent = trim($parentId) !== '' ? trim($parentId) : 'root';

            $folders = $this->driveClient->listSubfolders($validScope, $ownerUid, $targetParent);
            $sharedDrives = $targetParent === 'root'
                ? $this->driveClient->listSharedDrives($validScope, $ownerUid)
                : [];

            return new DataResponse([
                'parentId' => $targetParent,
                'folders' => $folders,
                'sharedDrives' => $sharedDrives,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function listVolumes(string $scope): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            return new DataResponse([
                'volumes' => $this->configService->getVolumes($validScope, $ownerUid),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function createVolume(string $scope): DataResponse {
        return $this->saveVolumeInternal($scope, null);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function updateVolume(string $scope, string $id): DataResponse {
        return $this->saveVolumeInternal($scope, $id);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function deleteVolume(string $scope, string $id): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $deleted = $this->configService->deleteVolume($validScope, $ownerUid, $id);
            if (!$deleted) {
                return new DataResponse(['message' => 'Volume not found.'], Http::STATUS_NOT_FOUND);
            }
            $this->cacheService->invalidateNamespace(MetadataCacheService::volumeNamespace($id));
            return new DataResponse(['deleted' => true]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function testVolume(string $scope, string $id): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $volume = $this->configService->getVolumeById($id, $validScope, $ownerUid);
            if ($volume === null) {
                return new DataResponse(['message' => 'Volume not found.'], Http::STATUS_NOT_FOUND);
            }

            $folder = $this->driveClient->resolveVolumeRootFolder($volume);
            $children = $this->driveClient->listChildren($validScope, $ownerUid, (string)$folder['id']);
            $this->cacheService->invalidateNamespace(MetadataCacheService::volumeNamespace($id));

            $currentUser = $this->userSession->getUser();
            $this->parentDirectoryService->ensureForVolume($volume, $currentUser?->getUID());

            return new DataResponse([
                'ok' => true,
                'folder' => [
                    'id' => $folder['id'],
                    'name' => $folder['name'],
                ],
                'children_count' => count($children),
                'volume' => $this->configService->getVolumeById($id, $validScope, $ownerUid),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function clearCache(string $scope): DataResponse {
        try {
            [$validScope, $ownerUid] = $this->assertScopeAccess($scope);
            $this->cacheService->invalidateNamespace(MetadataCacheService::scopeNamespace($validScope, $ownerUid));
            foreach ($this->configService->getVolumes($validScope, $ownerUid) as $vol) {
                $this->cacheService->invalidateNamespace(MetadataCacheService::volumeNamespace((string)$vol['id']));
            }
            return new DataResponse(['cleared' => true]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function saveVolumeInternal(string $scope, ?string $id): DataResponse {
        try {
            [$validScope, $ownerUid, $currentUid] = $this->assertScopeAccess($scope);
            $params = $this->request->getParams();

            $volume = $this->configService->saveVolume($validScope, $ownerUid, $params, $id);
            $this->cacheService->invalidateNamespace(MetadataCacheService::volumeNamespace((string)$volume['id']));

            $account = $this->configService->getPublicAccountSummary($validScope, $ownerUid);
            $remoteWarning = null;
            if (!empty($volume['enabled']) && !empty($account['connected'])) {
                try {
                    $this->driveClient->resolveVolumeRootFolder($volume);
                    $volume = $this->configService->getVolumeById((string)$volume['id'], $validScope, $ownerUid) ?? $volume;
                } catch (Throwable $e) {
                    $remoteWarning = $e->getMessage();
                }
            }

            $this->parentDirectoryService->ensureForVolume($volume, $currentUid);

            return new DataResponse([
                'volume' => $volume,
                'warning' => $remoteWarning,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Validate scope and check Nextcloud user/admin permissions.
     *
     * @return array{0: string, 1: ?string, 2: string} [scope, ownerUid, currentUid]
     */
    private function assertScopeAccess(string $scope): array {
        $validScope = $this->configService->validateScope($scope);
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new InvalidArgumentException('Authentication required.', Http::STATUS_UNAUTHORIZED);
        }
        $uid = $user->getUID();

        if ($validScope === ConfigService::SCOPE_ADMIN) {
            if (!$this->groupManager->isAdmin($uid)) {
                throw new InvalidArgumentException('Administrator privileges required.', Http::STATUS_FORBIDDEN);
            }
            return [$validScope, null, $uid];
        }

        if (!$this->configService->isPersonalMountsAllowed() && !$this->groupManager->isAdmin($uid)) {
            throw new InvalidArgumentException('Personal Google Drive mounts are disabled by the administrator.', Http::STATUS_FORBIDDEN);
        }

        if (!$this->configService->isPersonalMountsAllowed()) {
            throw new InvalidArgumentException('Personal Google Drive mounts are currently disabled.', Http::STATUS_FORBIDDEN);
        }

        return [$validScope, $uid, $uid];
    }

    private function errorResponse(Throwable $e, int $defaultStatus = Http::STATUS_BAD_REQUEST): DataResponse {
        $code = (int)$e->getCode();
        $status = ($code >= 400 && $code < 600) ? $code : $defaultStatus;
        return new DataResponse(['message' => $e->getMessage()], $status);
    }
}
