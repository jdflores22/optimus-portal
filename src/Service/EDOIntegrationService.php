<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\PreAdviceRequest;
use App\Entity\TerminalTeamUser;
use App\Entity\Trucker;
use App\Entity\User;
use App\Entity\Enum\PreAdviceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use TCPDF;

/**
 * Service for integrating pre-advice EDO generation with existing EDO system
 */
class EDOIntegrationService
{
    private string $uploadDirectory;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentService $paymentService,
        private AuditService $auditService,
        private LoggerInterface $logger,
        private ParameterBagInterface $parameterBag
    ) {
        $this->uploadDirectory = $parameterBag->get('kernel.project_dir') . '/public/uploads';
    }

    /**
     * Generate EDO and QR code for verified pre-advice request
     */
    public function generatePreAdviceEDO(
        PreAdviceRequest $preAdviceRequest,
        TerminalTeamUser $terminalTeamUser
    ): array {
        // Validate pre-advice request status
        if ($preAdviceRequest->getStatus() !== PreAdviceStatus::VERIFIED) {
            throw new \InvalidArgumentException('Pre-advice request must be verified before generating EDO');
        }

        if (!$preAdviceRequest->isPaymentVerified()) {
            throw new \InvalidArgumentException('Payment must be verified before generating EDO');
        }

        // Generate unique EDO number
        $edoNumber = $this->generatePreAdviceEdoNumber($preAdviceRequest);

        // Create QR code data
        $qrData = $this->createQrCodeData($preAdviceRequest, $edoNumber);

        // Generate QR code
        $qrCodePath = $this->generateQrCode($qrData, $edoNumber);

        // Create EDO PDF
        $pdfPath = $this->createPreAdviceEdoPdf($preAdviceRequest, $edoNumber, $qrCodePath);

        // Update pre-advice request
        $preAdviceRequest->setEdoNumber($edoNumber);
        $preAdviceRequest->setQrCode($qrCodePath);
        $preAdviceRequest->setStatus(PreAdviceStatus::COMPLETED);
        $preAdviceRequest->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        // Log EDO generation
        $this->auditService->logAction(
            $terminalTeamUser,
            'generate_pre_advice_edo',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'edo_number' => $edoNumber,
                'qr_code_path' => $qrCodePath,
                'pdf_path' => $pdfPath,
                'container_number' => $preAdviceRequest->getContainer()->getContainerNumber(),
                'terminal' => $preAdviceRequest->getSelectedTerminal()->getName()
            ]
        );

        $this->logger->info('Pre-advice EDO generated successfully', [
            'pre_advice_id' => $preAdviceRequest->getId(),
            'edo_number' => $edoNumber,
            'trucker_id' => $preAdviceRequest->getTrucker()->getId(),
            'terminal_team_id' => $terminalTeamUser->getId()
        ]);

        return [
            'edo_number' => $edoNumber,
            'qr_code_path' => $qrCodePath,
            'pdf_path' => $pdfPath,
            'generated_at' => new \DateTime()
        ];
    }

    /**
     * Get EDO content for download
     */
    public function getPreAdviceEdoContent(PreAdviceRequest $preAdviceRequest, User $user): ?array
    {
        // Check access permissions
        if (!$this->canUserAccessPreAdviceEdo($preAdviceRequest, $user)) {
            $this->logger->warning('Unauthorized access attempt to pre-advice EDO', [
                'pre_advice_id' => $preAdviceRequest->getId(),
                'user_id' => $user->getId(),
                'user_role' => $user->getRole()->value
            ]);
            return null;
        }

        if (!$preAdviceRequest->getEdoNumber()) {
            return null;
        }

        // Get PDF path (assuming it's stored in the pre-advice request or can be derived)
        $pdfPath = $this->getPreAdviceEdoPdfPath($preAdviceRequest);

        if (!file_exists($pdfPath)) {
            $this->logger->error('Pre-advice EDO PDF file not found', [
                'pre_advice_id' => $preAdviceRequest->getId(),
                'edo_number' => $preAdviceRequest->getEdoNumber(),
                'expected_path' => $pdfPath
            ]);
            return null;
        }

        // Read PDF content
        $content = file_get_contents($pdfPath);

        if ($content === false) {
            $this->logger->error('Failed to read pre-advice EDO PDF content', [
                'pre_advice_id' => $preAdviceRequest->getId(),
                'pdf_path' => $pdfPath
            ]);
            return null;
        }

        // Log EDO access
        $this->auditService->logAction(
            $user,
            'access_pre_advice_edo',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'edo_number' => $preAdviceRequest->getEdoNumber(),
                'access_type' => 'download'
            ]
        );

        return [
            'content' => $content,
            'filename' => 'pre_advice_edo_' . $preAdviceRequest->getEdoNumber() . '.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen($content),
            'edo_number' => $preAdviceRequest->getEdoNumber(),
            'generated_at' => $preAdviceRequest->getUpdatedAt()
        ];
    }

    /**
     * Get QR code content for display or download
     */
    public function getPreAdviceQrCodeContent(PreAdviceRequest $preAdviceRequest, User $user): ?array
    {
        // Check access permissions
        if (!$this->canUserAccessPreAdviceEdo($preAdviceRequest, $user)) {
            return null;
        }

        if (!$preAdviceRequest->getQrCode()) {
            return null;
        }

        $qrCodePath = $preAdviceRequest->getQrCode();

        if (!file_exists($qrCodePath)) {
            $this->logger->error('Pre-advice QR code file not found', [
                'pre_advice_id' => $preAdviceRequest->getId(),
                'qr_code_path' => $qrCodePath
            ]);
            return null;
        }

        $content = file_get_contents($qrCodePath);

        if ($content === false) {
            return null;
        }

        return [
            'content' => $content,
            'filename' => 'pre_advice_qr_' . $preAdviceRequest->getEdoNumber() . '.png',
            'mime_type' => 'image/png',
            'size' => strlen($content)
        ];
    }

    /**
     * Validate EDO and QR code for terminal access
     */
    public function validatePreAdviceEdo(string $edoNumber, ?string $qrCodeData = null): ?PreAdviceRequest
    {
        $preAdviceRequest = $this->entityManager->getRepository(PreAdviceRequest::class)
            ->findOneBy(['edoNumber' => $edoNumber]);

        if (!$preAdviceRequest) {
            $this->logger->warning('Pre-advice EDO validation failed - EDO not found', [
                'edo_number' => $edoNumber
            ]);
            return null;
        }

        // Validate QR code data if provided
        if ($qrCodeData) {
            $expectedQrData = $this->createQrCodeData($preAdviceRequest, $edoNumber);
            $expectedQrDataJson = json_encode($expectedQrData);

            if ($qrCodeData !== $expectedQrDataJson) {
                $this->logger->warning('Pre-advice EDO validation failed - QR code mismatch', [
                    'edo_number' => $edoNumber,
                    'expected_qr_data' => $expectedQrDataJson,
                    'provided_qr_data' => $qrCodeData
                ]);
                return null;
            }
        }

        // Check if EDO is still valid (not expired)
        if ($this->isPreAdviceEdoExpired($preAdviceRequest)) {
            $this->logger->warning('Pre-advice EDO validation failed - EDO expired', [
                'edo_number' => $edoNumber,
                'pre_advice_id' => $preAdviceRequest->getId()
            ]);
            return null;
        }

        // Log successful validation
        $this->auditService->logAction(
            null, // System validation
            'validate_pre_advice_edo',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'edo_number' => $edoNumber,
                'validation_result' => 'success',
                'qr_code_validated' => !empty($qrCodeData)
            ]
        );

        return $preAdviceRequest;
    }

    /**
     * Get EDO statistics for dashboard
     */
    public function getEdoStatistics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        // Total EDOs generated
        $totalEdos = $qb->select('COUNT(pa.id)')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.edoNumber IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        // EDOs generated today
        $today = new \DateTime('today');
        $edosToday = $qb->select('COUNT(pa.id)')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.edoNumber IS NOT NULL')
            ->andWhere('pa.updatedAt >= :today')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        // EDOs by terminal type
        $edosByTerminal = $qb->select('t.type, COUNT(pa.id) as count')
            ->from(PreAdviceRequest::class, 'pa')
            ->join('pa.selectedTerminal', 't')
            ->where('pa.edoNumber IS NOT NULL')
            ->groupBy('t.type')
            ->getQuery()
            ->getResult();

        return [
            'total_edos' => (int)$totalEdos,
            'edos_today' => (int)$edosToday,
            'edos_by_terminal' => $edosByTerminal
        ];
    }

    /**
     * Generate unique EDO number for pre-advice
     */
    private function generatePreAdviceEdoNumber(PreAdviceRequest $preAdviceRequest): string
    {
        $maxAttempts = 10;
        $attempt = 0;

        do {
            $prefix = 'PA'; // Pre-Advice
            $timestamp = date('YmdHis');
            $microseconds = substr(microtime(), 2, 6);
            $preAdviceId = str_pad($preAdviceRequest->getId(), 6, '0', STR_PAD_LEFT);
            $random = str_pad(random_int(0, 999), 3, '0', STR_PAD_LEFT);

            $edoNumber = $prefix . $timestamp . $microseconds . $preAdviceId . $random;

            // Check if this EDO number already exists
            $existing = $this->entityManager->getRepository(PreAdviceRequest::class)
                ->findOneBy(['edoNumber' => $edoNumber]);

            if (!$existing) {
                return $edoNumber;
            }

            $attempt++;
            usleep(1000); // Wait 1ms before retry

        } while ($attempt < $maxAttempts);

        throw new \RuntimeException('Unable to generate unique pre-advice EDO number after ' . $maxAttempts . ' attempts');
    }

    /**
     * Create QR code data for pre-advice
     */
    private function createQrCodeData(PreAdviceRequest $preAdviceRequest, string $edoNumber): array
    {
        return [
            'type' => 'pre_advice_edo',
            'edo_number' => $edoNumber,
            'container_number' => $preAdviceRequest->getContainer()->getContainerNumber(),
            'terminal' => $preAdviceRequest->getSelectedTerminal()->getName(),
            'terminal_type' => $preAdviceRequest->getSelectedTerminal()->getType()->value,
            'trucker_id' => $preAdviceRequest->getTrucker()->getId(),
            'generated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'verification_url' => 'https://optimus-shipping.com/verify-pre-advice/' . $edoNumber
        ];
    }

    /**
     * Generate QR code image
     */
    private function generateQrCode(array $qrData, string $edoNumber): string
    {
        $qrDataJson = json_encode($qrData);

        // Create QR code
        $qrCode = new QrCode($qrDataJson);
        $qrCode->setSize(300);
        $qrCode->setMargin(10);

        // Create QR code writer
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        // Create QR code directory
        $qrCodeDir = $this->uploadDirectory . '/pre_advice_qr_codes';
        if (!is_dir($qrCodeDir)) {
            mkdir($qrCodeDir, 0755, true);
        }

        // Save QR code
        $filename = 'pre_advice_qr_' . $edoNumber . '.png';
        $qrCodePath = $qrCodeDir . '/' . $filename;
        file_put_contents($qrCodePath, $result->getString());

        return $qrCodePath;
    }

    /**
     * Create pre-advice EDO PDF
     */
    private function createPreAdviceEdoPdf(
        PreAdviceRequest $preAdviceRequest,
        string $edoNumber,
        string $qrCodePath
    ): string {
        $container = $preAdviceRequest->getContainer();
        $terminal = $preAdviceRequest->getSelectedTerminal();
        $trucker = $preAdviceRequest->getTrucker();
        $assignedSlot = $preAdviceRequest->getAssignedSlot();

        // Create new PDF document
        $pdf = new TCPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('OPTIMUS Shipping Portal - Pre-Advice System');
        $pdf->SetAuthor('OPTIMUS Terminal Team');
        $pdf->SetTitle('Pre-Advice Electronic Delivery Order - ' . $edoNumber);
        $pdf->SetSubject('Pre-Advice EDO for Container Return');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Create EDO content
        $html = $this->createPreAdviceEdoPdfTemplate($preAdviceRequest, $edoNumber);

        // Write HTML content
        $pdf->writeHTML($html, true, false, true, false, '');

        // Add QR code
        if (file_exists($qrCodePath)) {
            $pdf->Image($qrCodePath, 150, 30, 40, 40, 'PNG');
        }

        // Create EDO directory
        $edoDir = $this->uploadDirectory . '/pre_advice_edos';
        if (!is_dir($edoDir)) {
            mkdir($edoDir, 0755, true);
        }

        // Save PDF
        $filename = 'pre_advice_edo_' . $edoNumber . '.pdf';
        $pdfPath = $edoDir . '/' . $filename;
        $pdf->Output($pdfPath, 'F');

        return $pdfPath;
    }

    /**
     * Create pre-advice EDO PDF template
     */
    private function createPreAdviceEdoPdfTemplate(PreAdviceRequest $preAdviceRequest, string $edoNumber): string
    {
        $container = $preAdviceRequest->getContainer();
        $terminal = $preAdviceRequest->getSelectedTerminal();
        $trucker = $preAdviceRequest->getTrucker();
        $assignedSlot = $preAdviceRequest->getAssignedSlot();

        return '
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
            .header h1 { font-size: 18px; font-weight: bold; margin: 0; }
            .header h2 { font-size: 14px; color: #666; margin: 5px 0; }
            .info-section { margin-bottom: 15px; }
            .info-title { font-weight: bold; font-size: 14px; margin-bottom: 5px; color: #333; }
            .info-table { width: 100%; border-collapse: collapse; }
            .info-table td { padding: 5px; border: 1px solid #ddd; }
            .label { font-weight: bold; background-color: #f5f5f5; width: 30%; }
            .value { width: 70%; }
            .important { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; }
            .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
        </style>
        
        <div class="header">
            <h1>PRE-ADVICE ELECTRONIC DELIVERY ORDER</h1>
            <h2>Container Return Authorization</h2>
            <p><strong>EDO Number:</strong> ' . htmlspecialchars($edoNumber) . '</p>
            <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
        </div>
        
        <div class="info-section">
            <div class="info-title">CONTAINER INFORMATION</div>
            <table class="info-table">
                <tr>
                    <td class="label">Container Number</td>
                    <td class="value">' . htmlspecialchars($container->getContainerNumber()) . '</td>
                </tr>
                <tr>
                    <td class="label">Container Size</td>
                    <td class="value">' . htmlspecialchars($container->getContainerSize()->getName()) . '</td>
                </tr>
                <tr>
                    <td class="label">Container Type</td>
                    <td class="value">' . htmlspecialchars($container->getContainerType()->getName()) . '</td>
                </tr>
                <tr>
                    <td class="label">Current Location</td>
                    <td class="value">' . htmlspecialchars($container->getCurrentLocation() ?: 'N/A') . '</td>
                </tr>
            </table>
        </div>
        
        <div class="info-section">
            <div class="info-title">TERMINAL INFORMATION</div>
            <table class="info-table">
                <tr>
                    <td class="label">Terminal Name</td>
                    <td class="value">' . htmlspecialchars($terminal->getName()) . '</td>
                </tr>
                <tr>
                    <td class="label">Terminal Type</td>
                    <td class="value">' . htmlspecialchars($terminal->getType()->value) . '</td>
                </tr>
                <tr>
                    <td class="label">Terminal Location</td>
                    <td class="value">' . htmlspecialchars($terminal->getLocation()) . '</td>
                </tr>
                ' . ($assignedSlot ? '
                <tr>
                    <td class="label">Assigned Slot Date</td>
                    <td class="value">' . $assignedSlot->getDate()->format('Y-m-d') . '</td>
                </tr>
                ' : '') . '
            </table>
        </div>
        
        <div class="info-section">
            <div class="info-title">TRUCKER INFORMATION</div>
            <table class="info-table">
                <tr>
                    <td class="label">Trucker Name</td>
                    <td class="value">' . htmlspecialchars($trucker->getFullName()) . '</td>
                </tr>
                <tr>
                    <td class="label">Company</td>
                    <td class="value">' . htmlspecialchars($trucker->getCompanyName() ?: 'N/A') . '</td>
                </tr>
                <tr>
                    <td class="label">License Number</td>
                    <td class="value">' . htmlspecialchars($trucker->getLicenseNumber() ?: 'N/A') . '</td>
                </tr>
                <tr>
                    <td class="label">Truck Plate Number</td>
                    <td class="value">' . htmlspecialchars($trucker->getTruckPlateNumber() ?: 'N/A') . '</td>
                </tr>
            </table>
        </div>
        
        <div class="important">
            <strong>IMPORTANT INSTRUCTIONS:</strong><br>
            1. This EDO is valid for 30 days from the generation date.<br>
            2. Present this document and the QR code at the terminal gate.<br>
            3. Ensure the container is in good condition before return.<br>
            4. Follow all terminal safety and security procedures.<br>
            5. Contact the terminal team if you encounter any issues.
        </div>
        
        <div class="info-section">
            <div class="info-title">VERIFICATION</div>
            <table class="info-table">
                <tr>
                    <td class="label">Verified By</td>
                    <td class="value">' . htmlspecialchars($preAdviceRequest->getVerifiedBy()->getFullName()) . '</td>
                </tr>
                <tr>
                    <td class="label">Verification Date</td>
                    <td class="value">' . $preAdviceRequest->getVerifiedAt()->format('Y-m-d H:i:s') . '</td>
                </tr>
                <tr>
                    <td class="label">Payment Reference</td>
                    <td class="value">' . htmlspecialchars($preAdviceRequest->getPaymentReference()) . '</td>
                </tr>
            </table>
        </div>
        
        <div class="footer">
            <p>OPTIMUS Shipping Portal - Terminal Team Pre-Advice System</p>
            <p>For assistance, contact: terminal-team@optimus-shipping.com</p>
            <p>This document is electronically generated and does not require a signature.</p>
        </div>
        ';
    }

    /**
     * Check if user can access pre-advice EDO
     */
    private function canUserAccessPreAdviceEdo(PreAdviceRequest $preAdviceRequest, User $user): bool
    {
        // Trucker can access their own EDO
        if ($user instanceof Trucker && $preAdviceRequest->getTrucker() === $user) {
            return true;
        }

        // Terminal Team can access all EDOs
        if ($user instanceof TerminalTeamUser) {
            return true;
        }

        // System admin can access all EDOs
        if ($user->getRole()->value === 'SYSTEM_ADMIN') {
            return true;
        }

        return false;
    }

    /**
     * Check if pre-advice EDO is expired
     */
    private function isPreAdviceEdoExpired(PreAdviceRequest $preAdviceRequest): bool
    {
        if (!$preAdviceRequest->getEdoNumber()) {
            return true;
        }

        // EDOs are valid for 30 days from generation
        $generatedAt = $preAdviceRequest->getUpdatedAt();
        $expirationDate = clone $generatedAt;
        $expirationDate->add(new \DateInterval('P30D'));

        return new \DateTime() > $expirationDate;
    }

    /**
     * Get pre-advice EDO PDF path
     */
    private function getPreAdviceEdoPdfPath(PreAdviceRequest $preAdviceRequest): string
    {
        $edoNumber = $preAdviceRequest->getEdoNumber();
        return $this->uploadDirectory . '/pre_advice_edos/pre_advice_edo_' . $edoNumber . '.pdf';
    }
}