<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\AppInfo;

use OCA\GDriveVolumes\Listener\EnsureParentDirectoryListener;
use OCA\GDriveVolumes\Mount\GDriveMountProvider;
use OCA\GDriveVolumes\Service\ConfigService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Files\Config\IMountProviderCollection;
use OCP\User\Events\PostLoginEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'gdrive_volumes';
    public const APP_VERSION = '1.0.6';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(PostLoginEvent::class, EnsureParentDirectoryListener::class);
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, EnsureParentDirectoryListener::class);
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(function (
            IMountProviderCollection $mountProviderCollection,
            GDriveMountProvider $mountProvider,
            ConfigService $configService,
        ): void {
            $configService->ensureAppMetadataSynced();
            $mountProviderCollection->registerProvider($mountProvider);
        });
    }
}
