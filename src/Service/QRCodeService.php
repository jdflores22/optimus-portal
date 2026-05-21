<?php

namespace App\Service;

use App\Entity\PreAdviceRequest;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class QRCodeService
{
    private const QR_CODE_PREFIX = 'TTFA'; // Terminal Team FREE-ADVICE
    private const QR_CODE_VERSION = 'v1';
    
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    /**
     * Generate QR code with terminal and slot details
     * Integrates with existing EDO generation system
     */
    public function generateQRCode(PreAdviceRequest $preAdvice): string
    {
        if (!$preAdvice->getEdoNumber()) {
            throw new \InvalidArgumentException('EDO number is required for QR code generation');
        }

        if (!$preAdvice->getAssignedSlot()) {
            throw new \InvalidArgumentException('Assigned slot is required for QR code generation');
        }

        // Generate QR code data with terminal and slot details
        $qrData = $this->buildQRCodeData($preAdvice);
        
        // Generate unique QR code identifier
        $qrCodeId = $this->generateUniqueQRCodeId($preAdvice);
        
        // Create QR code content (JSON format for easy parsing)
        $qrContent = json_encode($qrData);
        
        // Generate secure hash for validation
        $securityHash = $this->generateSecurityHash($qrContent);
        
        // Final QR code format: PREFIX_VERSION_ID_HASH
        $qrCode = sprintf('%s_%s_%s_%s', 
            self::QR_CODE_PREFIX,
            self::QR_CODE_VERSION,
            $qrCodeId,
            substr($securityHash, 0, 8) // First 8 characters of hash
        );

        $this->logger->info('QR code generated successfully', [
            'preAdviceId' => $preAdvice->getId(),
            'edoNumber' => $preAdvice->getEdoNumber(),
            'qrCodeId' => $qrCodeId,
            'terminalId' => $preAdvice->getSelectedTerminal()->getId(),
            'slotDate' => $preAdvice->getAssignedSlot()->getDate()->format('Y-m-d')
        ]);

        return $qrCode;
    }

    /**
     * Validate QR code and return associated data
     */
    public function validateQRCode(string $qrCode): array
    {
        $parts = explode('_', $qrCode);
        
        if (count($parts) !== 4) {
            throw new \InvalidArgumentException('Invalid QR code format');
        }

        [$prefix, $version, $qrCodeId, $hashPart] = $parts;

        // Validate prefix and version
        if ($prefix !== self::QR_CODE_PREFIX || $version !== self::QR_CODE_VERSION) {
            throw new \InvalidArgumentException('Invalid QR code prefix or version');
        }

        // Find FREE-ADVICE request by QR code
        $preAdvice = $this->findPreAdviceByQRCode($qrCode);
        
        if (!$preAdvice) {
            throw new \InvalidArgumentException('QR code not found or invalid');
        }

        // Validate security hash
        $qrData = $this->buildQRCodeData($preAdvice);
        $qrContent = json_encode($qrData);
        $expectedHash = $this->generateSecurityHash($qrContent);
        
        if (substr($expectedHash, 0, 8) !== $hashPart) {
            throw new \InvalidArgumentException('QR code security validation failed');
        }

        return [
            'valid' => true,
            'preAdviceId' => $preAdvice->getId(),
            'edoNumber' => $preAdvice->getEdoNumber(),
            'data' => $qrData
        ];
    }

    /**
     * Get QR code details for display
     */
    public function getQRCodeDetails(PreAdviceRequest $preAdvice): array
    {
        if (!$preAdvice->getQrCode()) {
            throw new \InvalidArgumentException('QR code not generated for this FREE-ADVICE');
        }

        $qrData = $this->buildQRCodeData($preAdvice);

        return [
            'qrCode' => $preAdvice->getQrCode(),
            'edoNumber' => $preAdvice->getEdoNumber(),
            'data' => $qrData,
            'displayInfo' => [
                'containerNumber' => $preAdvice->getContainer()->getContainerNumber(),
                'terminalName' => $preAdvice->getSelectedTerminal()->getName(),
                'terminalType' => $preAdvice->getSelectedTerminal()->getType()->value,
                'slotDate' => $preAdvice->getAssignedSlot()->getDate()->format('Y-m-d'),
                'truckerName' => $preAdvice->getTrucker()->getEmail(), // Assuming email as identifier
                'generatedAt' => $preAdvice->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate QR code for terminal access
     */
    public function generateTerminalAccessQR(PreAdviceRequest $preAdvice): string
    {
        $accessData = [
            'type' => 'terminal_access',
            'edo_number' => $preAdvice->getEdoNumber(),
            'container_number' => $preAdvice->getContainer()->getContainerNumber(),
            'terminal_id' => $preAdvice->getSelectedTerminal()->getId(),
            'terminal_type' => $preAdvice->getSelectedTerminal()->getType()->value,
            'slot_date' => $preAdvice->getAssignedSlot()->getDate()->format('Y-m-d'),
            'access_expires' => (new \DateTime('+7 days'))->format('Y-m-d H:i:s'),
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s')
        ];

        return base64_encode(json_encode($accessData));
    }

    /**
     * Check if QR code is expired or invalid
     */
    public function isQRCodeValid(string $qrCode): bool
    {
        try {
            $validation = $this->validateQRCode($qrCode);
            return $validation['valid'];
        } catch (\Exception $e) {
            $this->logger->warning('QR code validation failed', [
                'qrCode' => $qrCode,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Revoke QR code (mark as invalid)
     */
    public function revokeQRCode(PreAdviceRequest $preAdvice, string $reason = null): bool
    {
        if (!$preAdvice->getQrCode()) {
            return false;
        }

        // In a real implementation, this would mark the QR code as revoked in a separate table
        // For now, we'll clear the QR code from the FREE-ADVICE request
        $oldQrCode = $preAdvice->getQrCode();
        $preAdvice->setQrCode(null);

        $this->entityManager->persist($preAdvice);
        $this->entityManager->flush();

        $this->logger->info('QR code revoked', [
            'preAdviceId' => $preAdvice->getId(),
            'oldQrCode' => $oldQrCode,
            'reason' => $reason
        ]);

        return true;
    }

    /**
     * Build QR code data structure
     */
    private function buildQRCodeData(PreAdviceRequest $preAdvice): array
    {
        return [
            'edo_number' => $preAdvice->getEdoNumber(),
            'pre_advice_id' => $preAdvice->getId(),
            'container_number' => $preAdvice->getContainer()->getContainerNumber(),
            'container_size' => $preAdvice->getContainer()->getContainerSize()->getCode(),
            'container_type' => $preAdvice->getContainer()->getContainerType()->getCode(),
            'terminal' => [
                'id' => $preAdvice->getSelectedTerminal()->getId(),
                'name' => $preAdvice->getSelectedTerminal()->getName(),
                'type' => $preAdvice->getSelectedTerminal()->getType()->value,
                'location' => $preAdvice->getSelectedTerminal()->getLocation()
            ],
            'slot' => [
                'date' => $preAdvice->getAssignedSlot()->getDate()->format('Y-m-d'),
                'capacity' => $preAdvice->getAssignedSlot()->getCapacity(),
                'assigned_count' => $preAdvice->getAssignedSlot()->getAssignedCount()
            ],
            'trucker' => [
                'id' => $preAdvice->getTrucker()->getId(),
                'email' => $preAdvice->getTrucker()->getEmail()
            ],
            'verification' => [
                'verified_by' => $preAdvice->getVerifiedBy()?->getId(),
                'verified_at' => $preAdvice->getVerifiedAt()?->format('Y-m-d H:i:s'),
                'status' => $preAdvice->getStatus()->value
            ],
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'expires_at' => (new \DateTime('+30 days'))->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate unique QR code identifier
     */
    private function generateUniqueQRCodeId(PreAdviceRequest $preAdvice): string
    {
        $timestamp = date('ymdHis');
        $preAdviceId = str_pad($preAdvice->getId(), 6, '0', STR_PAD_LEFT);
        $terminalCode = substr($preAdvice->getSelectedTerminal()->getType()->value, 0, 2);
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        
        return "{$timestamp}{$preAdviceId}{$terminalCode}{$random}";
    }

    /**
     * Generate security hash for QR code validation
     */
    private function generateSecurityHash(string $content): string
    {
        // Use a combination of content and secret key for security
        $secretKey = 'terminal_team_pre_advice_secret_2024'; // In production, use environment variable
        return hash('sha256', $content . $secretKey);
    }

    /**
     * Find FREE-ADVICE request by QR code
     */
    private function findPreAdviceByQRCode(string $qrCode): ?PreAdviceRequest
    {
        return $this->entityManager->getRepository(PreAdviceRequest::class)
            ->findOneBy(['qrCode' => $qrCode]);
    }

    /**
     * Generate QR code image (placeholder for actual QR code library integration)
     */
    public function generateQRCodeImage(string $qrCode, int $size = 200): string
    {
        // In a real implementation, this would use a QR code library like endroid/qr-code
        // For now, return a placeholder path
        $filename = "qr_code_{$qrCode}_{$size}.png";
        
        $this->logger->info('QR code image generation requested', [
            'qrCode' => $qrCode,
            'size' => $size,
            'filename' => $filename
        ]);

        return $filename;
    }

    /**
     * Get QR code statistics for reporting
     */
    public function getQRCodeStatistics(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $result = $qb->select('COUNT(p.id) as total_generated')
            ->from(PreAdviceRequest::class, 'p')
            ->where('p.qrCode IS NOT NULL')
            ->andWhere('p.updatedAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleResult();

        return [
            'total_generated' => (int) $result['total_generated'],
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ]
        ];
    }
}