<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Payment;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\WorkflowState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class EDOService implements EDOServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentService $documentService,
        private AuditService $auditService,
        private ActivityLogService $activityLogService,
        private ManifestNotificationService $notificationService,
        private CacheInterface $edoPdfCache
    ) {
    }

    /**
     * @deprecated Use BatchEDOGenerationService after final payment approval instead.
     */
    public function autoGenerateEDO(Payment $verifiedPayment): ElectronicDeliveryOrder
    {
        // Validate payment type and status
        if ($verifiedPayment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw new \InvalidArgumentException('Payment must be a final payment');
        }

        if ($verifiedPayment->getStatus() !== PaymentStatus::VERIFIED) {
            throw new \InvalidArgumentException('Payment must be verified');
        }

        $manifest = $verifiedPayment->getManifest();

        // Check if EDO already exists
        if ($manifest->getEdo()) {
            throw new \InvalidArgumentException('EDO already exists for this manifest');
        }

        // Generate EDO number (format: EDO-YYYYMM-NNNN)
        $edoNumber = $this->generateEDONumber();

        // Create new EDO with PENDING_RELEASE status
        // The entity constructor sets status to PENDING_RELEASE by default
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber($edoNumber);
        $edo->setManifest($manifest);
        $edo->setShippingLine($manifest->getShippingLine()); // Set shipping line from manifest
        
        // Explicitly set status to PENDING_RELEASE (requirement 2.1)
        // This ensures the eDO requires SYSTEM_ADMIN approval before access
        $edo->setStatus(EDOStatus::PENDING_RELEASE);

        // Generate PDF
        $pdfPath = $this->documentService->generateEDOPDF($edo);
        $edo->setPdfPath($pdfPath);

        // Add digital signature
        $this->documentService->addDigitalSignature($pdfPath);
        $signature = hash_file('sha256', $pdfPath);
        $edo->setDigitalSignature($signature);

        $this->entityManager->persist($edo);
        
        // Note: Workflow state transition to EDO_GENERATED is handled by WorkflowOrchestrator
        // Note: Flush is handled by the transaction service
        // Note: Audit logs, activity logs, and notifications are handled AFTER flush by the transaction service
        
        return $edo;
    }

    public function getEDOByManifest(int $manifestId): ?ElectronicDeliveryOrder
    {
        return $this->entityManager->getRepository(ElectronicDeliveryOrder::class)
            ->findOneBy(['manifest' => $manifestId]);
    }

    public function getEDOByNumber(string $edoNumber): ?ElectronicDeliveryOrder
    {
        return $this->entityManager->getRepository(ElectronicDeliveryOrder::class)
            ->findOneBy(['edoNumber' => $edoNumber]);
    }

    public function generateEDONumber(): string
    {
        $year = date('Y');
        $month = date('m');
        
        // Get the last EDO number for this month
        $lastEDO = $this->entityManager->getRepository(ElectronicDeliveryOrder::class)
            ->createQueryBuilder('e')
            ->where('e.edoNumber LIKE :prefix')
            ->setParameter('prefix', "EDO-{$year}{$month}-%")
            ->orderBy('e.edoNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($lastEDO) {
            // Extract sequence number and increment
            $parts = explode('-', $lastEDO->getEdoNumber());
            $sequence = intval($parts[2] ?? 0) + 1;
        } else {
            $sequence = 1;
        }

        return sprintf('EDO-%s%s-%04d', $year, $month, $sequence);
    }

    /**
     * Get cached eDO PDF content
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return string PDF file content
     */
    public function getCachedEDOPDF(ElectronicDeliveryOrder $edo): string
    {
        $cacheKey = 'edo_pdf_' . $edo->getId();
        
        return $this->edoPdfCache->get($cacheKey, function (ItemInterface $item) use ($edo) {
            // Cache for 24 hours (86400 seconds)
            $item->expiresAfter(86400);
            
            // Read PDF file from disk
            $pdfPath = $edo->getPdfPath();
            if (!file_exists($pdfPath)) {
                throw new \RuntimeException("eDO PDF file not found: {$pdfPath}");
            }
            
            return file_get_contents($pdfPath);
        });
    }

    /**
     * Invalidate cached eDO PDF
     * 
     * @param ElectronicDeliveryOrder $edo
     */
    public function invalidateEDOPDFCache(ElectronicDeliveryOrder $edo): void
    {
        $cacheKey = 'edo_pdf_' . $edo->getId();
        $this->edoPdfCache->delete($cacheKey);
    }
}
