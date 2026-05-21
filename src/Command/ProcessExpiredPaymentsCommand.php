<?php

namespace App\Command;

use App\Service\PaymentIntegrationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-expired-payments',
    description: 'Process and cancel expired pre-advice payments'
)]
class ProcessExpiredPaymentsCommand extends Command
{
    public function __construct(
        private PaymentIntegrationService $paymentIntegrationService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Processing Expired Pre-Advice Payments');

        try {
            $cancelledCount = $this->paymentIntegrationService->cancelExpiredPayments();

            if ($cancelledCount > 0) {
                $io->success("Cancelled {$cancelledCount} expired payment(s)");
                
                $this->logger->info('Expired payments processed', [
                    'cancelled_count' => $cancelledCount
                ]);
            } else {
                $io->info('No expired payments found');
            }

            // Display payment statistics
            $this->displayPaymentStatistics($io);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to process expired payments: ' . $e->getMessage());
            
            $this->logger->error('Expired payments processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    private function displayPaymentStatistics(SymfonyStyle $io): void
    {
        try {
            $stats = $this->paymentIntegrationService->getPaymentStatistics();
            
            $io->section('Payment Statistics');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Payments', $stats['total_payments']],
                    ['Verified Payments', $stats['verified_payments']],
                    ['Failed Payments', $stats['failed_payments']],
                    ['Pending Payments', $stats['pending_payments']],
                    ['Payments Today', $stats['payments_today']],
                    ['Success Rate', $stats['success_rate'] . '%'],
                    ['Total Revenue', '$' . number_format($stats['total_revenue'], 2)]
                ]
            );

        } catch (\Exception $e) {
            $io->warning('Could not retrieve payment statistics: ' . $e->getMessage());
        }
    }
}