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
 * This command can be run manually or scheduled via Symfony Scheduler or cron job
 * 
 * Manual execution:
 * php bin/console app:edo:check-expiration
 * 
 * Symfony Scheduler (recommended):
 * Configure in config/packages/scheduler.yaml and run:
 * php bin/console messenger:consume scheduler_default
 * 
 * Cron configuration (alternative):
 * 0 1 * * * cd /path/to/project && php bin/console app:edo:check-expiration
 * 
 * Requirements: 2.1, 2.2
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
            
            // Detect expired eDOs
            $expiredEDOs = $this->expirationService->detectExpiredEDOs();
            
            if (count($expiredEDOs) > 0) {
                $io->text(sprintf('Found %d expired eDO(s)', count($expiredEDOs)));
                
                // Process each expired eDO
                $processedCount = 0;
                foreach ($expiredEDOs as $edo) {
                    try {
                        // Mark as expired and send notifications
                        $this->expirationService->markAsExpired($edo);
                        $processedCount++;
                    } catch (\Exception $e) {
                        $io->warning(sprintf(
                            'Failed to process eDO %s: %s',
                            $edo->getEdoNumber(),
                            $e->getMessage()
                        ));
                        
                        $this->logger->error('Failed to process expired eDO', [
                            'edoId' => $edo->getId(),
                            'edoNumber' => $edo->getEdoNumber(),
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                $executionTime = round(microtime(true) - $startTime, 2);
                
                $io->success(sprintf(
                    'Processed %d of %d expired eDO(s) in %s seconds',
                    $processedCount,
                    count($expiredEDOs),
                    $executionTime
                ));
                
                $this->logger->info('eDO expiration check completed', [
                    'expired_count' => count($expiredEDOs),
                    'processed_count' => $processedCount,
                    'execution_time' => $executionTime
                ]);
            } else {
                $executionTime = round(microtime(true) - $startTime, 2);
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
