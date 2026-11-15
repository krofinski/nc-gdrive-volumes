<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Command;

use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveVolumeCommand extends Command {
    public function __construct(
        private readonly ConfigService $configService,
        private readonly MetadataCacheService $cacheService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('gdrive-volumes:remove')
            ->setDescription('Remove a configured Google Drive volume by ID')
            ->addArgument('id', InputArgument::REQUIRED, 'Volume ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $id = (string)$input->getArgument('id');
        $volume = $this->configService->getVolumeById($id);
        if ($volume === null) {
            $output->writeln('<error>Volume "' . $id . '" not found.</error>');
            return Command::FAILURE;
        }

        $ownerUid = $volume['owner_uid'] !== '' ? (string)$volume['owner_uid'] : null;
        $deleted = $this->configService->deleteVolume((string)$volume['scope'], $ownerUid, $id);
        if (!$deleted) {
            $output->writeln('<error>Failed to remove volume "' . $id . '".</error>');
            return Command::FAILURE;
        }

        $this->cacheService->invalidateNamespace(MetadataCacheService::volumeNamespace($id));
        $output->writeln('<info>Removed volume "' . $volume['name'] . '" (' . $id . ').</info>');
        return Command::SUCCESS;
    }
}
