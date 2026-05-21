<?php

namespace App\Command;

use App\Service\DwellTimeMonitorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'app:dwell-time-monitoring',
    description: 'Monitor container dwell times and process notifications and automatic returns'
)]
class DwellTimeMonitoringCommand extends Command
{
    public function __construct(
        private DwellTimeMonitorInterface $dwellTimeMonitor,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'report-only',
                'r',
                InputOption::VALUE_NONE,
                'Generate daily report only without processing notifications or returns'
            )
            ->addOption(
                'notifications-only',
                null,
                InputOption::VALUE_NONE,
                'Process notifications only without automatic returns'
            )
            ->addOption(
                'returns-only',
                't',
                InputOption::VALUE_NONE,
                'Process automatic returns only without notifications'
            )
            ->setHelp('
This command monitors container dwell times and processes:
- 60-day notifications to shipping line admins
- 90-day automatic returns to terminals
- Daily monitoring reports

Options:
  --report-only     Generate daily report without processing
  --notifications-only  Process only notifications
  --returns-only    Process only automatic returns

Examples:
  php bin/console app:dwell-time-monitoring
  php bin/console app:dwell-time-monitoring --report-only
  php bin/console app:dwell-time-monitoring --notifications-only
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $reportOnly = $input->getOption('report-only');
        $notificationsOnly = $input->getOption('notifications-only');
        $returnsOnly = $input->getOption('returns-only');

        try {
            $io->title('Container Dwell Time Monitoring');

            if ($reportOnly) {
                return $this->generateReportOnly($io);
            }

            if ($notificationsOnly) {
                return $this->processNotificationsOnly($io);
            }

            if ($returnsOnly) {
                return $this->processReturnsOnly($io);
            }

            // Full processing (default)
            return $this->processAll($io);

        } catch (\Exception $e) {
            $io->error('Dwell time monitoring failed: ' . $e->getMessage());
            $this->logger->error('Dwell time monitoring command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Generate daily report only
     */
    private function generateReportOnly(SymfonyStyle $io): int
    {
        $io->section('Generating Daily Dwell Time Report');

        $report = $this->dwellTimeMonitor->generateDailyReport();

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Containers', $report['total_containers']],
                ['Approaching Notification (60 days)', $report['containers_approaching_notification']],
                ['Approaching Return (90 days)', $report['containers_approaching_return']],
                ['Currently Paused', $report['containers_paused']],
                ['Notifications Due', $report['notifications_due']],
                ['Returns Due', $report['returns_due']]
            ]
        );

        $io->section('Summary');
        foreach ($report['summary'] as $summaryLine) {
            $io->text('• ' . $summaryLine);
        }

        $io->success('Daily report generated successfully.');
        return Command::SUCCESS;
    }

    /**
     * Process notifications only
     */
    private function processNotificationsOnly(SymfonyStyle $io): int
    {
        $io->section('Processing Dwell Time Notifications');

        $notifications = $this->dwellTimeMonitor->checkNotificationThresholds();

        if (empty($notifications)) {
            $io->info('No containers require notifications at this time.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Container ID', 'Container Number', 'Notification Type', 'Dwell Time'],
            array_map(function ($notification) {
                $notificationTypes = array_map(fn($n) => $n['type'], $notification['notifications']);
                $dwellTimes = array_map(fn($n) => $n['dwell_time'] . ' days', $notification['notifications']);
                
                return [
                    $notification['container_id'],
                    $notification['container_number'],
                    implode(', ', $notificationTypes),
                    implode(', ', $dwellTimes)
                ];
            }, $notifications)
        );

        // Process the notifications
        $this->dwellTimeMonitor->processContainers();

        $io->success(sprintf('Processed %d container(s) for notifications.', count($notifications)));
        return Command::SUCCESS;
    }

    /**
     * Process automatic returns only
     */
    private function processReturnsOnly(SymfonyStyle $io): int
    {
        $io->section('Processing Automatic Returns');

        $returns = $this->dwellTimeMonitor->processAutomaticReturns();

        if (empty($returns)) {
            $io->info('No containers require automatic return at this time.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Container ID', 'Container Number', 'Dwell Time', 'Return Date'],
            array_map(function ($return) {
                return [
                    $return['container_id'],
                    $return['container_number'],
                    $return['dwell_time'] . ' days',
                    $return['return_date']->format('Y-m-d H:i:s')
                ];
            }, $returns)
        );

        $io->success(sprintf('Processed %d container(s) for automatic return.', count($returns)));
        return Command::SUCCESS;
    }

    /**
     * Process all monitoring activities
     */
    private function processAll(SymfonyStyle $io): int
    {
        $io->section('Processing All Dwell Time Monitoring');

        // Check what needs to be processed
        $notifications = $this->dwellTimeMonitor->checkNotificationThresholds();
        $returns = $this->dwellTimeMonitor->processAutomaticReturns();

        $io->text(sprintf('Found %d container(s) requiring notifications', count($notifications)));
        $io->text(sprintf('Found %d container(s) requiring automatic return', count($returns)));

        if (!empty($notifications) || !empty($returns)) {
            $io->newLine();
            $io->text('Processing containers...');
            
            // Process all containers
            $this->dwellTimeMonitor->processContainers();
            
            $io->success('All dwell time monitoring activities completed successfully.');
        } else {
            $io->info('No containers require processing at this time.');
        }

        // Generate and display summary report
        $io->section('Daily Summary Report');
        $report = $this->dwellTimeMonitor->generateDailyReport();

        foreach ($report['summary'] as $summaryLine) {
            $io->text('• ' . $summaryLine);
        }

        return Command::SUCCESS;
    }
}