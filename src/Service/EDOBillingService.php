<?php

namespace App\Service;

use App\Entity\EDOBilling;
use App\Entity\RegenerationRequest;
use App\Entity\User;
use App\Exception\BillingException;
use App\Utility\BillingCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service for eDO billing management
 * 
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8
 */
class EDOBillingService implements EDOBillingServiceInterface
{
    private string $billingDocumentsPath;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BillingCalculator $billingCalculator,
        private EDONotificationServiceInterface $notificationService,
        private EDOAuditServiceInterface $auditService,
        private ConfigurationService $configurationService,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->billingDocumentsPath = $projectDir . '/public/uploads/billing_documents';
        
        // Ensure billing documents directory exists
        if (!is_dir($this->billingDocumentsPath)) {
            mkdir($this->billingDocumentsPath, 0755, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function calculateBilling(RegenerationRequest $regenerationRequest, User $accountingUser): EDOBilling
    {
        $edo = $regenerationRequest->getEdo();
        
        // Get expired days from eDO
        $expiredDays = $edo->getExpiredDays() ?? 0;
        
        if ($expiredDays <= 0) {
            throw new BillingException('Cannot generate billing for non-expired eDO');
        }

        // Get per-day rate from configuration
        $perDayRate = $this->getPerDayRate();
        
        if ($perDayRate <= 0) {
            throw new BillingException('Invalid per-day rate configuration');
        }

        // Calculate total amount
        $totalAmount = $this->billingCalculator->calculateAmount($expiredDays, $perDayRate);
        
        if ($totalAmount < 0) {
            throw new BillingException('Calculated billing amount cannot be negative');
        }

        // Create billing entity
        $billing = new EDOBilling();
        $billing->setRegenerationRequest($regenerationRequest);
        $billing->setExpiredDays($expiredDays);
        $billing->setPerDayRate($perDayRate);
        $billing->setTotalAmount($totalAmount);
        $billing->setGeneratedBy($accountingUser);

        // Persist billing
        $this->entityManager->persist($billing);
        $this->entityManager->flush();

        // Log billing generation
        $this->auditService->logBillingGeneration($billing, $accountingUser);

        $this->logger->info('eDO billing calculated', [
            'billingId' => $billing->getId(),
            'edoId' => $edo->getId(),
            'expiredDays' => $expiredDays,
            'perDayRate' => $perDayRate,
            'totalAmount' => $totalAmount
        ]);

        return $billing;
    }

    /**
     * {@inheritdoc}
     */
    public function generateBillingDocument(EDOBilling $billing): string
    {
        $regenerationRequest = $billing->getRegenerationRequest();
        $edo = $regenerationRequest->getEdo();
        $container = $edo->getContainer();
        $manifest = $edo->getManifest();

        // Prepare data for PDF
        $data = [
            'billing' => $billing,
            'edo' => $edo,
            'container' => $container,
            'manifest' => $manifest,
            'edoNumber' => $edo->getEdoNumber(),
            'containerNumber' => $container->getContainerNumber(),
            'expiredDays' => $billing->getExpiredDays(),
            'perDayRate' => $billing->getPerDayRate(),
            'totalAmount' => $billing->getTotalAmount(),
            'generatedAt' => $billing->getCreatedAt(),
            'generatedBy' => $billing->getGeneratedBy()->getFullName()
        ];

        // Generate HTML content
        $html = $this->generateBillingHtml($data);

        // Generate PDF using Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Save PDF to file
        $filename = sprintf(
            'billing_%s_%s.pdf',
            $edo->getEdoNumber(),
            date('YmdHis')
        );
        $filepath = $this->billingDocumentsPath . '/' . $filename;
        
        file_put_contents($filepath, $dompdf->output());

        // Update billing entity with document path
        $relativePath = '/uploads/billing_documents/' . $filename;
        $billing->setBillingDocumentPath($relativePath);
        $this->entityManager->flush();

        $this->logger->info('Billing document generated', [
            'billingId' => $billing->getId(),
            'filepath' => $relativePath
        ]);

        return $relativePath;
    }

    /**
     * {@inheritdoc}
     */
    public function sendBillingToParties(EDOBilling $billing): void
    {
        try {
            $this->notificationService->notifyBilling($billing);
            
            $this->logger->info('Billing notifications sent', [
                'billingId' => $billing->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send billing notifications', [
                'billingId' => $billing->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get per-day rate from configuration
     * 
     * @return float
     */
    private function getPerDayRate(): float
    {
        return $this->configurationService->getEDOExpiredPerDayRate();
    }

    /**
     * Generate HTML content for billing document
     * 
     * @param array $data
     * @return string
     */
    private function generateBillingHtml(array $data): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>eDO Billing Document</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #0066cc;
        }
        .section {
            margin-bottom: 25px;
        }
        .section h2 {
            color: #0066cc;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
        }
        .info-row {
            display: flex;
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: bold;
            width: 200px;
        }
        .info-value {
            flex: 1;
        }
        .calculation-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .calculation-table th,
        .calculation-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .calculation-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .total-row {
            background-color: #e6f2ff;
            font-weight: bold;
            font-size: 1.1em;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ccc;
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>eDO Expired Days Billing</h1>
        <p>Billing Document</p>
    </div>

    <div class="section">
        <h2>eDO Information</h2>
        <div class="info-row">
            <div class="info-label">eDO Number:</div>
            <div class="info-value">{$data['edoNumber']}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Container Number:</div>
            <div class="info-value">{$data['containerNumber']}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Manifest Number:</div>
            <div class="info-value">{$data['manifest']->getManifestNumber()}</div>
        </div>
    </div>

    <div class="section">
        <h2>Billing Details</h2>
        <div class="info-row">
            <div class="info-label">Generated Date:</div>
            <div class="info-value">{$data['generatedAt']->format('Y-m-d H:i:s')}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Generated By:</div>
            <div class="info-value">{$data['generatedBy']}</div>
        </div>
    </div>

    <div class="section">
        <h2>Calculation Breakdown</h2>
        <table class="calculation-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Quantity</th>
                    <th>Rate (per day)</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Expired Days Charge</td>
                    <td>{$data['expiredDays']} days</td>
                    <td>\${$data['perDayRate']}</td>
                    <td>\${$data['totalAmount']}</td>
                </tr>
                <tr class="total-row">
                    <td colspan="3">Total Amount Due</td>
                    <td>\${$data['totalAmount']}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Payment Instructions</h2>
        <p>Please submit your payment receipt through the system to proceed with eDO regeneration.</p>
        <p>For any questions regarding this billing, please contact the Shipping Lines Accounting department.</p>
    </div>

    <div class="footer">
        <p>This is an automatically generated billing document.</p>
        <p>Document ID: BILL-{$data['billing']->getId()}</p>
    </div>
</body>
</html>
HTML;
    }
}
