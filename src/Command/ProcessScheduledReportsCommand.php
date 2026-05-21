<?php

namespace App\Command;

use App\Service\ScheduledReportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-scheduled-reports',
    description: 'Process all due scheduled reports'
)]
class ProcessScheduledReportsCommand extends Command
{
    public function __construct(
        private ScheduledReportService $scheduledReportService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Processing Scheduled Reports');

        try {
            $processedCount = $this->scheduledReportService->processDueReports();
            
            if ($processedCount > 0) {
                $io->success("Successfully processed {$processedCount} scheduled report(s).");
            } else {
                $io->info('No scheduled reports were due for processing.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to process scheduled reports: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}