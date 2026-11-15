<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Controller;

use OCA\GDriveVolumes\AppInfo\Application;
use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Throwable;

/**
 * Handles Google OAuth 2.0 authorization redirect and callback with one-time state nonce verification.
 */
class AuthController extends Controller {
    private ICache $stateCache;

    public function __construct(
        IRequest $request,
        private readonly ConfigService $configService,
        private readonly GoogleDriveClient $driveClient,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IURLGenerator $urlGenerator,
        ICacheFactory $cacheFactory,
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->stateCache = $cacheFactory->createDistributed('gdrive_volumes_oauth_state');
    }

    #[NoAdminRequired]
    public function authorize(string $scope): RedirectResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
        }

        $uid = $user->getUID();
        $validScope = $this->configService->validateScope($scope);
        $this->assertAuthorized($validScope, $uid);

        $ownerUid = $validScope === ConfigService::SCOPE_PERSONAL ? $uid : null;
        $account = $this->configService->getAccount($validScope, $ownerUid);

        if ($account['client_id'] === '' || $account['client_secret'] === '') {
            return $this->redirectToSettings($validScope, 'error', 'Client ID and Client Secret must be saved before connecting.');
        }

        $nonce = bin2hex(random_bytes(24));
        $this->stateCache->set('state:' . $nonce, [
            'scope' => $validScope,
            'uid' => $uid,
            'created' => time(),
        ], 600);

        $redirectUri = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.auth.callback');
        $authUrl = $this->driveClient->buildAuthorizationUrl((string)$account['client_id'], $redirectUri, $nonce);

        return new RedirectResponse($authUrl);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function callback(string $code = '', string $state = '', string $error = ''): RedirectResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
        }
        $uid = $user->getUID();

        $stored = $state !== '' ? $this->stateCache->get('state:' . $state) : null;
        if ($state !== '') {
            $this->stateCache->remove('state:' . $state);
        }

        if (!is_array($stored) || ($stored['uid'] ?? '') !== $uid) {
            return $this->redirectToSettings(ConfigService::SCOPE_PERSONAL, 'error', 'Invalid or expired OAuth state parameter.');
        }

        $scope = (string)($stored['scope'] ?? ConfigService::SCOPE_PERSONAL);
        try {
            $this->assertAuthorized($scope, $uid);
        } catch (Throwable $e) {
            return $this->redirectToSettings($scope, 'error', $e->getMessage());
        }

        if ($error !== '') {
            return $this->redirectToSettings($scope, 'error', 'Google authorization declined: ' . $error);
        }

        if ($code === '') {
            return $this->redirectToSettings($scope, 'error', 'Missing authorization code from Google.');
        }

        try {
            $ownerUid = $scope === ConfigService::SCOPE_PERSONAL ? $uid : null;
            $redirectUri = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.auth.callback');
            $this->driveClient->exchangeAuthorizationCode($scope, $ownerUid, $code, $redirectUri);
            return $this->redirectToSettings($scope, 'success', 'Google Drive connected successfully.');
        } catch (Throwable $e) {
            return $this->redirectToSettings($scope, 'error', $e->getMessage());
        }
    }

    private function assertAuthorized(string $scope, string $uid): void {
        if ($scope === ConfigService::SCOPE_ADMIN && !$this->groupManager->isAdmin($uid)) {
            throw new \RuntimeException('Administrator privileges required.');
        }
        if ($scope === ConfigService::SCOPE_PERSONAL && !$this->configService->isPersonalMountsAllowed()) {
            throw new \RuntimeException('Personal mounts are disabled by the administrator.');
        }
    }

    private function redirectToSettings(string $scope, string $status, string $message): RedirectResponse {
        $route = $scope === ConfigService::SCOPE_ADMIN
            ? 'settings.AdminSettings.index'
            : 'settings.PersonalSettings.index';

        $baseUrl = $this->urlGenerator->linkToRoute($route, ['section' => Application::APP_ID]);
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        $target = $baseUrl . $separator . http_build_query([
            'gdrive_oauth' => $status,
            'gdrive_msg' => $message,
        ]);

        return new RedirectResponse($target);
    }
}
