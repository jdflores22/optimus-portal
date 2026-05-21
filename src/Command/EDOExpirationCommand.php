<?php

namespace App\Command;

use App\Service\EDOExpirationServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to check and process expired eDOs
 * 
 * This command should be run periodically (e.g., hourly) via cron job
 * Example cron configuration:
 * 0 * * * * cd /path/to/project && php bin/console app:edo:check-expiration
 * 
 * Requirements: 4.1
 */
#[AsCommand(
    name: 'app:edo:check-expiration',
    description: 'Check and process expired Electronic Delivery Orders (eDOs)'
)]
class EDOExpirationCommand extends Command
{
    public function __construct(
        private EDOExpirationServiceInterface $expirationService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Processing eDO Expiration Check');
        $io->text('Checking all active eDOs for expiration...');

        try {
            $startTime = microtime(true);
            
            // Process all active eDOs
            $expiredCount = $this->expirationService->processExpiredEDOs();
            
            $executionTime = round(microtime(true) - $startTime, 2);

            if ($expiredCount > 0) {
                $io->success(sprintf(
                    'Processed %d expired eDO(s) in %s seconds',
                    $expiredCount,
                    $executionTime
                ));
                
                $this->logger->info('eDO expiration check completed', [
                    'expired_count' => $expiredCount,
                    'execution_time' => $executionTime
                ]);
            } else {
                $io->info('No expired eDOs found');
                
                $this->logger->info('eDO expiration check completed - no expired eDOs', [
                    'execution_time' => $executionTime
                ]);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to process eDO expiration check: ' . $e->getMessage());
            
            $this->logger->error('eDO expiration check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }
}
