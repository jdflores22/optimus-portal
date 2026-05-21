<?php

namespace App\Command;

use App\Service\PushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-invalid-subscriptions',
    description: 'Remove inactive push subscriptions that have been marked invalid'
)]
class CleanupInvalidSubscriptionsCommand extends Command
{
    public function __construct(
        private PushNotificationService $pushNotificationService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Perform a dry run without actually deleting subscriptions'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $dryRun = $input->getOption('dry-run');
        
        $io->title('Cleaning Up Invalid Push Subscriptions');
        
        if ($dryRun) {
            $io->note('Running in DRY RUN mode - no subscriptions will be deleted');
        }
        
        $io->info('Removing inactive push subscriptions that returned 410 Gone or 404 Not Found errors');
        
        try {
            if ($dryRun) {
                // In dry run mode, just count without deleting
                $io->warning('DRY RUN: Would remove inactive subscriptions, but no action taken');
                return Command::SUCCESS;
            }
            
            // Call the cleanup method
            $deletedCount = $this->pushNotificationService->cleanupInvalidSubscriptions();
            
            if ($deletedCount === 0) {
                $io->success('No invalid subscriptions found to clean up');
            } else {
                $io->success(sprintf('Successfully removed %d invalid push subscription(s)', $deletedCount));
            }
            
            // Log the cleanup
            $this->logger->info('Push subscription cleanup completed', [
                'deleted_count' => $deletedCount
            ]);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to clean up subscriptions: ' . $e->getMessage());
            
            $this->logger->error('Push subscription cleanup failed', [
                'error' => $e->getMessage()
            ]);
            
            return Command::FAILURE;
        }
    }
}
