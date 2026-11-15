<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Listener;

use OCA\GDriveVolumes\Service\ParentDirectoryService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\User\Events\PostLoginEvent;

/**
 * Ensures configured common local parent directories (e.g. /Gdrive) exist in the
 * user's home folder upon login or web UI rendering.
 *
 * @template-implements IEventListener<Event>
 */
class EnsureParentDirectoryListener implements IEventListener {
    public function __construct(
        private readonly ParentDirectoryService $parentDirectoryService,
        private readonly IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof PostLoginEvent) {
            $this->parentDirectoryService->ensureForUser($event->getUser());
            return;
        }

        if ($event instanceof BeforeTemplateRenderedEvent) {
            $user = $this->userSession->getUser();
            if ($user !== null) {
                $this->parentDirectoryService->ensureForUser($user);
            }
        }
    }
}
