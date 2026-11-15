<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\AppInfo;

use OCA\GDriveVolumes\Listener\EnsureParentDirectoryListener;
use OCA\GDriveVolumes\Mount\GDriveMountProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Files\Config\IMountProviderCollection;
use OCP\User\Events\PostLoginEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'gdrive_volumes';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(PostLoginEvent::class, EnsureParentDirectoryListener::class);
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, EnsureParentDirectoryListener::class);
    }

    public function boot(IBootContext $context): void {
        $server = $context->getServerContainer();
        /** @var IMountProviderCollection $mountProviderCollection */
        $mountProviderCollection = $server->get(IMountProviderCollection::class);
        $mountProviderCollection->registerMountProvider(
            $server->get(GDriveMountProvider::class)
        );
    }
}
