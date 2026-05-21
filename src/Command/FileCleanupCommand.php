<?php

namespace App\Command;

use App\Service\FileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:file-cleanup',
    description: 'Clean up orphaned files that exist in filesystem but not in database'
)]
class FileCleanupCommand extends Command
{
    public function __construct(
        private FileService $fileService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('OPTIMUS File Cleanup');
        $io->text('Starting cleanup of orphaned files...');

        try {
            $cleanedCount = $this->fileService->cleanupOrphanedFiles();

            if ($cleanedCount > 0) {
                $io->success(sprintf('Successfully cleaned up %d orphaned files.', $cleanedCount));
            } else {
                $io->info('No orphaned files found.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('File cleanup failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}