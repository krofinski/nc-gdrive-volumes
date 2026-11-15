<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Command;

use OCA\GDriveVolumes\Client\GoogleDriveClient;
use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCA\GDriveVolumes\Service\ParentDirectoryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class SyncCacheCommand extends Command {
    public function __construct(
        private readonly ConfigService $configService,
        private readonly GoogleDriveClient $driveClient,
        private readonly MetadataCacheService $cacheService,
        private readonly ParentDirectoryService $parentDirectoryService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('gdrive-volumes:sync-cache')
            ->setDescription('Clear metadata cache, verify remote Google Drive folders, and ensure local parent directories')
            ->addOption('volume', null, InputOption::VALUE_OPTIONAL, 'Specific volume ID to verify and clear cache for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $targetId = $input->getOption('volume');
        $volumes = $this->configService->getAllVolumes();

        if (is_string($targetId) && $targetId !== '') {
            $volumes = array_values(array_filter(
                $volumes,
                static fn (array $v): bool => $v['id'] === $targetId
            ));
            if ($volumes === []) {
                $output->writeln('<error>Volume "' . $targetId . '" not found.</error>');
                return Command::FAILURE;
            }
        }

        $this->cacheService->invalidateNamespace(MetadataCacheService::scopeNamespace(ConfigService::SCOPE_ADMIN, null));
        foreach ($this->configService->getUsersWithPersonalVolumes() as $uid) {
            $this->cacheService->invalidateNamespace(MetadataCacheService::scopeNamespace(ConfigService::SCOPE_PERSONAL, $uid));
        }

        $hasFailures = false;
        foreach ($volumes as $vol) {
            $volId = (string)$vol['id'];
            $this->cacheService->invalidateNamespace(MetadataCacheService::volumeNamespace($volId));
            $this->parentDirectoryService->ensureForVolume($vol, $vol['owner_uid'] !== '' ? (string)$vol['owner_uid'] : null);

            if (empty($vol['enabled'])) {
                $output->writeln(sprintf('<comment>[SKIP] %s (%s) is disabled</comment>', $vol['name'], $volId));
                continue;
            }

            try {
                $folder = $this->driveClient->resolveVolumeRootFolder($vol);
                $owner = $vol['owner_uid'] !== '' ? (string)$vol['owner_uid'] : null;
                $children = $this->driveClient->listChildren((string)$vol['scope'], $owner, (string)$folder['id']);
                $this->parentDirectoryService->warmVolumeMount($vol, $owner);
                $output->writeln(sprintf(
                    '<info>[OK] %s (%s) -> remote ID %s mounted at /%s (%d items in root)</info>',
                    $vol['name'],
                    $volId,
                    $folder['id'],
                    $vol['local_mount_path'],
                    count($children)
                ));
            } catch (Throwable $e) {
                $hasFailures = true;
                $output->writeln(sprintf('<error>[FAIL] %s (%s): %s</error>', $vol['name'], $volId, $e->getMessage()));
            }
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
