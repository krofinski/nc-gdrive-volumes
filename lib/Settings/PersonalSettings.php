<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Settings;

use OCA\GDriveVolumes\AppInfo\Application;
use OCA\GDriveVolumes\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
    public function __construct(
        private readonly ConfigService $configService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
    ) {
    }

    public function getForm(): TemplateResponse {
        $user = $this->userSession->getUser();
        $redirectUri = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.auth.callback');

        return new TemplateResponse(Application::APP_ID, 'settings', [
            'scope' => ConfigService::SCOPE_PERSONAL,
            'uid' => $user?->getUID() ?? '',
            'redirectUri' => $redirectUri,
            'personalMountsAllowed' => $this->configService->isPersonalMountsAllowed(),
        ], TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
