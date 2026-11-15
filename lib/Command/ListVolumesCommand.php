<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Command;

use OCA\GDriveVolumes\Service\ConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListVolumesCommand extends Command {
    public function __construct(
        private readonly ConfigService $configService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('gdrive-volumes:list')
            ->setDescription('List all configured Google Drive subdirectory volume mounts')
            ->addOption('scope', 's', InputOption::VALUE_OPTIONAL, 'Filter by scope (admin or personal)')
            ->addOption('user', 'u', InputOption::VALUE_OPTIONAL, 'Filter personal volumes by Nextcloud user ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $scopeFilter = $input->getOption('scope');
        $userFilter = $input->getOption('user');

        $volumes = $this->configService->getAllVolumes();
        if (is_string($scopeFilter) && $scopeFilter !== '') {
            $volumes = array_values(array_filter(
                $volumes,
                static fn (array $v): bool => $v['scope'] === $scopeFilter
            ));
        }
        if (is_string($userFilter) && $userFilter !== '') {
            $volumes = array_values(array_filter(
                $volumes,
                static fn (array $v): bool => $v['owner_uid'] === $userFilter
            ));
        }

        if ($volumes === []) {
            $output->writeln('<comment>No Google Drive volumes configured.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders([
            'ID',
            'Scope',
            'Owner',
            'Name',
            'Remote Path',
            'Remote ID',
            'Local Mount',
            'Mode',
            'Quota',
            'Status',
        ]);

        foreach ($volumes as $vol) {
            $quota = !empty($vol['quota_bytes'])
                ? $this->formatBytes((int)$vol['quota_bytes'])
                : 'Account';

            $table->addRow([
                $vol['id'],
                $vol['scope'],
                $vol['owner_uid'] !== '' ? $vol['owner_uid'] : '*',
                $vol['name'],
                $vol['remote_path'] !== '' ? $vol['remote_path'] : '-',
                $vol['remote_folder_id'] !== '' ? $vol['remote_folder_id'] : '(auto)',
                '/' . $vol['local_mount_path'],
                !empty($vol['read_only']) ? 'RO' : 'RW',
                $quota,
                !empty($vol['enabled']) ? 'Enabled' : 'Disabled',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float)$bytes;
        $idx = 0;
        while ($value >= 1024 && $idx < count($units) - 1) {
            $value /= 1024;
            $idx++;
        }
        return round($value, 2) . ' ' . $units[$idx];
    }
}
