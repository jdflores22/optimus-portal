<?php

namespace App\Command;

use App\Repository\NotificationMetricsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsCommand(
    name: 'app:monitor-notification-delivery-rate',
    description: 'Monitor notification delivery rates and send alerts if below threshold'
)]
class MonitorNotificationDeliveryRateCommand extends Command
{
    private const DELIVERY_RATE_THRESHOLD = 95.0;
    private const MONITORING_PERIOD_HOURS = 24;

    public function __construct(
        private NotificationMetricsRepository $metricsRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        private string $adminEmail
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Monitoring Notification Delivery Rates');
        
        // Calculate date range (last 24 hours)
        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify('-' . self::MONITORING_PERIOD_HOURS . ' hours');
        
        $io->info(sprintf(
            'Checking delivery rates from %s to %s',
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s')
        ));
        
        // Get overall delivery rate
        $overallDeliveryRate = $this->metricsRepository->getOverallDeliveryRate($startDate, $endDate);
        
        $io->section('Overall Delivery Rate');
        $io->text(sprintf('Current rate: %.2f%%', $overallDeliveryRate));
        
        // Check if below threshold
        if ($overallDeliveryRate < self::DELIVERY_RATE_THRESHOLD) {
            $io->warning(sprintf(
                'Delivery rate (%.2f%%) is below threshold (%.2f%%)',
                $overallDeliveryRate,
                self::DELIVERY_RATE_THRESHOLD
            ));
            
            // Get detailed metrics by type
            $metricsByType = $this->metricsRepository->getMetricsSummaryByType($startDate, $endDate);
            
            // Send alert email
            $this->sendAlertEmail($overallDeliveryRate, $metricsByType, $startDate, $endDate);
            
            $io->success('Alert email sent to administrators');
            
            // Log the alert
            $this->logger->warning('Notification delivery rate below threshold', [
                'delivery_rate' => $overallDeliveryRate,
                'threshold' => self::DELIVERY_RATE_THRESHOLD,
                'period_hours' => self::MONITORING_PERIOD_HOURS
            ]);
            
            return Command::SUCCESS;
        }
        
        $io->success(sprintf(
            'Delivery rate (%.2f%%) is above threshold (%.2f%%)',
            $overallDeliveryRate,
            self::DELIVERY_RATE_THRESHOLD
        ));
        
        return Command::SUCCESS;
    }

    private function sendAlertEmail(
        float $deliveryRate,
        array $metricsByType,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): void {
        try {
            $htmlBody = $this->twig->render('emails/notification_delivery_alert.html.twig', [
                'deliveryRate' => $deliveryRate,
                'threshold' => self::DELIVERY_RATE_THRESHOLD,
                'metricsByType' => $metricsByType,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'periodHours' => self::MONITORING_PERIOD_HOURS
            ]);
            
            $email = (new Email())
                ->from($this->adminEmail)
                ->to($this->adminEmail)
                ->subject(sprintf(
                    '[ALERT] Notification Delivery Rate Below Threshold: %.2f%%',
                    $deliveryRate
                ))
                ->html($htmlBody);
            
            $this->mailer->send($email);
            
            $this->logger->info('Delivery rate alert email sent', [
                'delivery_rate' => $deliveryRate,
                'recipient' => $this->adminEmail
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send delivery rate alert email', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
