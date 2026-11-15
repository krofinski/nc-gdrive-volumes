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

class MountVolumeCommand extends Command {
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
            ->setName('gdrive-volumes:mount')
            ->setDescription('Create and mount a Google Drive subdirectory as an independent Nextcloud volume')
            ->addOption('scope', null, InputOption::VALUE_OPTIONAL, 'Configuration scope: admin or personal', 'admin')
            ->addOption('owner', null, InputOption::VALUE_OPTIONAL, 'Owner UID (required when scope=personal)', '')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Display name for the volume', '')
            ->addOption('remote-path', null, InputOption::VALUE_OPTIONAL, 'Google Drive subdirectory path (e.g. NextCloud/Volume_1)', '')
            ->addOption('remote-id', null, InputOption::VALUE_OPTIONAL, 'Google Drive Folder ID (optional)', '')
            ->addOption('local-parent', null, InputOption::VALUE_OPTIONAL, 'Common local parent folder in Nextcloud (e.g. Gdrive)', 'Gdrive')
            ->addOption('local-sub', null, InputOption::VALUE_REQUIRED, 'Local subdirectory name for this disk (e.g. Volume_1)')
            ->addOption('auto-create', null, InputOption::VALUE_NONE, 'Automatically create missing subdirectories in Google Drive')
            ->addOption('read-only', null, InputOption::VALUE_NONE, 'Mount volume in read-only mode')
            ->addOption('quota', null, InputOption::VALUE_OPTIONAL, 'Optional disk quota (e.g. 50G, 100G, or 0 for unlimited)', '')
            ->addOption('export', null, InputOption::VALUE_OPTIONAL, 'Google Docs export format: office, pdf, or none', 'office')
            ->addOption('users', null, InputOption::VALUE_OPTIONAL, 'Comma-separated Nextcloud user IDs (admin scope)', '')
            ->addOption('groups', null, InputOption::VALUE_OPTIONAL, 'Comma-separated Nextcloud group IDs (admin scope)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $scope = $this->configService->validateScope((string)$input->getOption('scope'));
            $owner = trim((string)$input->getOption('owner'));
            $uid = $scope === ConfigService::SCOPE_PERSONAL ? $owner : null;

            $volume = $this->configService->saveVolume($scope, $uid, [
                'name' => (string)$input->getOption('name'),
                'remote_path' => (string)$input->getOption('remote-path'),
                'remote_folder_id' => (string)$input->getOption('remote-id'),
                'auto_create_remote' => (bool)$input->getOption('auto-create'),
                'local_parent_dir' => (string)$input->getOption('local-parent'),
                'local_sub_dir' => (string)$input->getOption('local-sub'),
                'read_only' => (bool)$input->getOption('read-only'),
                'quota_bytes' => (string)$input->getOption('quota'),
                'export_google_docs' => (string)$input->getOption('export'),
                'allowed_users' => (string)$input->getOption('users'),
                'allowed_groups' => (string)$input->getOption('groups'),
                'enabled' => true,
            ]);

            $this->cacheService->invalidateNamespace(MetadataCacheService::volumeNamespace((string)$volume['id']));

            $account = $this->configService->getPublicAccountSummary($scope, $uid);
            if (!empty($account['connected'])) {
                $folder = $this->driveClient->resolveVolumeRootFolder($volume);
                $output->writeln(sprintf('<info>Resolved remote Google Drive folder ID: %s</info>', $folder['id']));
            } else {
                $output->writeln('<comment>Volume saved, but Google Drive account for this scope is not connected yet.</comment>');
            }

            $this->parentDirectoryService->ensureForVolume($volume, $uid);
            $output->writeln(sprintf(
                '<info>Mounted volume "%s" (%s) -> /%s</info>',
                $volume['name'],
                $volume['id'],
                $volume['local_mount_path']
            ));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
