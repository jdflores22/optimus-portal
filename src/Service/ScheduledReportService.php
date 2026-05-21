<?php

namespace App\Service;

use App\Entity\ScheduledReport;
use App\Entity\Enum\ReportType;
use App\Entity\Enum\ReportFrequency;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;

class ScheduledReportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReportingService $reportingService,
        private ReportExportService $reportExportService,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $projectDir
    ) {}

    /**
     * Create a new scheduled report
     */
    public function createScheduledReport(
        string $name,
        ReportType $reportType,
        ReportFrequency $frequency,
        string $format,
        array $recipients,
        array $parameters = []
    ): ScheduledReport {
        $scheduledReport = new ScheduledReport();
        $scheduledReport->setName($name);
        $scheduledReport->setReportType($reportType);
        $scheduledReport->setFrequency($frequency);
        $scheduledReport->setFormat($format);
        $scheduledReport->setRecipients($recipients);
        $scheduledReport->setParameters($parameters);
        $scheduledReport->setIsActive(true);
        $scheduledReport->setNextRunDate($this->calculateNextRunDate($frequency));

        $this->entityManager->persist($scheduledReport);
        $this->entityManager->flush();

        return $scheduledReport;
    }

    /**
     * Process all due scheduled reports
     */
    public function processDueReports(): int
    {
        $now = new \DateTime();
        $dueReports = $this->entityManager->getRepository(ScheduledReport::class)
            ->createQueryBuilder('sr')
            ->where('sr.isActive = :active')
            ->andWhere('sr.nextRunDate <= :now')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $processedCount = 0;
        foreach ($dueReports as $scheduledReport) {
            try {
                $this->processScheduledReport($scheduledReport);
                $processedCount++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to process scheduled report', [
                    'report_id' => $scheduledReport->getId(),
                    'report_name' => $scheduledReport->getName(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $processedCount;
    }

    /**
     * Process a single scheduled report
     */
    public function processScheduledReport(ScheduledReport $scheduledReport): void
    {
        $this->logger->info('Processing scheduled report', [
            'report_id' => $scheduledReport->getId(),
            'report_name' => $scheduledReport->getName(),
            'report_type' => $scheduledReport->getReportType()->value
        ]);

        // Calculate date range based on frequency
        $dateRange = $this->calculateDateRange($scheduledReport->getFrequency());
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        // Generate report based on type
        $filePath = $this->generateReport($scheduledReport, $startDate, $endDate);

        // Send report to recipients
        $this->sendReportToRecipients($scheduledReport, $filePath, $startDate, $endDate);

        // Update next run date
        $scheduledReport->setNextRunDate($this->calculateNextRunDate($scheduledReport->getFrequency()));
        $scheduledReport->setLastRunDate(new \DateTime());

        $this->entityManager->flush();

        $this->logger->info('Scheduled report processed successfully', [
            'report_id' => $scheduledReport->getId(),
            'file_path' => $filePath
        ]);
    }

    /**
     * Generate report file based on scheduled report configuration
     */
    private function generateReport(ScheduledReport $scheduledReport, \DateTime $startDate, \DateTime $endDate): string
    {
        $reportType = $scheduledReport->getReportType();
        $format = $scheduledReport->getFormat();

        switch ($reportType) {
            case ReportType::PRE_ADVICE_STATISTICS:
                return $format === 'pdf' 
                    ? $this->reportExportService->exportPreAdviceStatisticsToPDF($startDate, $endDate)
                    : $this->reportExportService->exportPreAdviceStatisticsToCSV($startDate, $endDate);

            case ReportType::TERMINAL_UTILIZATION:
                return $format === 'pdf'
                    ? $this->reportExportService->exportTerminalUtilizationToPDF($startDate, $endDate)
                    : $this->reportExportService->exportTerminalUtilizationToCSV($startDate, $endDate);

            case ReportType::COMPREHENSIVE:
                return $this->reportExportService->generateComprehensiveReport($startDate, $endDate, $format);

            default:
                throw new \InvalidArgumentException('Unsupported report type: ' . $reportType->value);
        }
    }

    /**
     * Send report to configured recipients
     */
    private function sendReportToRecipients(ScheduledReport $scheduledReport, string $filePath, \DateTime $startDate, \DateTime $endDate): void
    {
        $recipients = $scheduledReport->getRecipients();
        $reportName = $scheduledReport->getName();
        $filename = basename($filePath);

        $email = (new Email())
            ->from('noreply@optimus-portal.com')
            ->subject("Scheduled Report: {$reportName}")
            ->text("Please find attached the scheduled report: {$reportName}\n\nPeriod: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}\nGenerated: " . date('Y-m-d H:i:s'))
            ->attachFromPath($filePath, $filename);

        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }

        $this->mailer->send($email);
    }

    /**
     * Calculate date range based on frequency
     */
    private function calculateDateRange(ReportFrequency $frequency): array
    {
        $endDate = new \DateTime('yesterday'); // End of previous day
        
        switch ($frequency) {
            case ReportFrequency::DAILY:
                $startDate = new \DateTime('yesterday');
                break;
            
            case ReportFrequency::WEEKLY:
                $startDate = new \DateTime('-7 days');
                break;
            
            case ReportFrequency::MONTHLY:
                $startDate = new \DateTime('first day of last month');
                $endDate = new \DateTime('last day of last month');
                break;
            
            default:
                throw new \InvalidArgumentException('Unsupported frequency: ' . $frequency->value);
        }

        return ['start' => $startDate, 'end' => $endDate];
    }

    /**
     * Calculate next run date based on frequency
     */
    private function calculateNextRunDate(ReportFrequency $frequency): \DateTime
    {
        $now = new \DateTime();
        
        switch ($frequency) {
            case ReportFrequency::DAILY:
                return $now->modify('+1 day')->setTime(6, 0); // 6 AM next day
            
            case ReportFrequency::WEEKLY:
                return $now->modify('next monday')->setTime(6, 0); // 6 AM next Monday
            
            case ReportFrequency::MONTHLY:
                return $now->modify('first day of next month')->setTime(6, 0); // 6 AM first day of next month
            
            default:
                throw new \InvalidArgumentException('Unsupported frequency: ' . $frequency->value);
        }
    }

    /**
     * Update scheduled report configuration
     */
    public function updateScheduledReport(
        ScheduledReport $scheduledReport,
        array $updates
    ): ScheduledReport {
        if (isset($updates['name'])) {
            $scheduledReport->setName($updates['name']);
        }
        
        if (isset($updates['frequency'])) {
            $scheduledReport->setFrequency($updates['frequency']);
            $scheduledReport->setNextRunDate($this->calculateNextRunDate($updates['frequency']));
        }
        
        if (isset($updates['format'])) {
            $scheduledReport->setFormat($updates['format']);
        }
        
        if (isset($updates['recipients'])) {
            $scheduledReport->setRecipients($updates['recipients']);
        }
        
        if (isset($updates['parameters'])) {
            $scheduledReport->setParameters($updates['parameters']);
        }
        
        if (isset($updates['is_active'])) {
            $scheduledReport->setIsActive($updates['is_active']);
        }

        $this->entityManager->flush();

        return $scheduledReport;
    }

    /**
     * Delete scheduled report
     */
    public function deleteScheduledReport(ScheduledReport $scheduledReport): void
    {
        $this->entityManager->remove($scheduledReport);
        $this->entityManager->flush();
    }

    /**
     * Get all scheduled reports
     */
    public function getAllScheduledReports(): array
    {
        return $this->entityManager->getRepository(ScheduledReport::class)
            ->findBy([], ['name' => 'ASC']);
    }

    /**
     * Get active scheduled reports
     */
    public function getActiveScheduledReports(): array
    {
        return $this->entityManager->getRepository(ScheduledReport::class)
            ->findBy(['isActive' => true], ['name' => 'ASC']);
    }
}