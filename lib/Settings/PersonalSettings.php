<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Settings;

use OCA\GDriveVolumes\AppInfo\Application;
use OCA\GDriveVolumes\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
    public function __construct(
        private readonly ConfigService $configService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
    ) {
    }

    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'settings');
        Util::addStyle(Application::APP_ID, 'settings');

        $user = $this->userSession->getUser();
        $redirectUri = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.auth.callback');
        $stateUrl = $this->urlGenerator->linkToRoute(Application::APP_ID . '.settings.getState', [
            'scope' => ConfigService::SCOPE_PERSONAL,
        ]);
        $apiBaseUrl = (string)preg_replace('#/api/settings/(admin|personal)$#', '', $stateUrl);

        return new TemplateResponse(Application::APP_ID, 'settings', [
            'scope' => ConfigService::SCOPE_PERSONAL,
            'uid' => $user?->getUID() ?? '',
            'redirectUri' => $redirectUri,
            'apiBaseUrl' => $apiBaseUrl,
            'personalMountsAllowed' => $this->configService->isPersonalMountsAllowed(),
        ], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
