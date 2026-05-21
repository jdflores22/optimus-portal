<?php

namespace App\Command;

use App\Service\FileManagementIntegrationService;
use App\Service\FileService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-files',
    description: 'Clean up orphaned files and perform file system maintenance'
)]
class CleanupFilesCommand extends Command
{
    public function __construct(
        private FileService $fileService,
        private FileManagementIntegrationService $fileIntegrationService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be cleaned up without actually deleting files')
            ->addOption('geotag-photos-only', null, InputOption::VALUE_NONE, 'Only clean up geotag photos')
            ->addOption('all-files', null, InputOption::VALUE_NONE, 'Clean up all file types')
            ->setHelp('This command cleans up orphaned files and performs file system maintenance tasks.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');
        $geotagPhotosOnly = $input->getOption('geotag-photos-only');
        $allFiles = $input->getOption('all-files');

        $io->title('File System Cleanup');

        if ($isDryRun) {
            $io->note('Running in dry-run mode - no files will be deleted');
        }

        $totalCleaned = 0;

        try {
            // Clean up geotag photos
            if ($geotagPhotosOnly || $allFiles || (!$geotagPhotosOnly && !$allFiles)) {
                $io->section('Cleaning up orphaned geotag photos');
                
                if (!$isDryRun) {
                    $geotagCleaned = $this->fileIntegrationService->cleanupOrphanedGeotagPhotos();
                    $totalCleaned += $geotagCleaned;
                    $io->success("Cleaned up {$geotagCleaned} orphaned geotag photos");
                } else {
                    $io->info('Would clean up orphaned geotag photos (dry-run mode)');
                }
            }

            // Clean up all other files
            if ($allFiles || (!$geotagPhotosOnly && !$allFiles)) {
                $io->section('Cleaning up orphaned files');
                
                if (!$isDryRun) {
                    $filesCleaned = $this->fileService->cleanupOrphanedFiles();
                    $totalCleaned += $filesCleaned;
                    $io->success("Cleaned up {$filesCleaned} orphaned files");
                } else {
                    $io->info('Would clean up orphaned files (dry-run mode)');
                }
            }

            // Display statistics
            $this->displayFileStatistics($io);

            if (!$isDryRun) {
                $io->success("File cleanup completed. Total files cleaned: {$totalCleaned}");
                
                $this->logger->info('File cleanup command completed', [
                    'total_cleaned' => $totalCleaned,
                    'geotag_photos_only' => $geotagPhotosOnly,
                    'all_files' => $allFiles
                ]);
            } else {
                $io->info('Dry-run completed - no files were actually deleted');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('File cleanup failed: ' . $e->getMessage());
            
            $this->logger->error('File cleanup command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    private function displayFileStatistics(SymfonyStyle $io): void
    {
        $io->section('File Statistics');

        try {
            // Get geotag photo statistics
            $photoStats = $this->fileIntegrationService->getPhotoStatistics();
            
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Total Geotag Photos', $photoStats['total_photos']],
                    ['Verified Photos', $photoStats['verified_photos']],
                    ['Unverified Photos', $photoStats['unverified_photos']],
                    ['Photos Uploaded Today', $photoStats['photos_today']],
                    ['Verification Rate', $photoStats['verification_rate'] . '%']
                ]
            );

        } catch (\Exception $e) {
            $io->warning('Could not retrieve file statistics: ' . $e->getMessage());
        }
    }
}