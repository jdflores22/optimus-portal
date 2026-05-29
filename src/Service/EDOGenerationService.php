<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Manifest;
use App\Exception\EDOWorkflowException;
use App\Utility\EDONumberGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for generating container-level eDOs
 */
class EDOGenerationService implements EDOGenerationServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EDONumberGenerator $edoNumberGenerator,
        private ConfigurationService $configurationService,
        private PaymentFeeConfigurationServiceInterface $paymentFeeConfigurationService,
        private EDODocumentGenerator $edoDocumentGenerator,
        private CYAllocationService $cyAllocationService
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function generateEDOsForManifest(Manifest $manifest): array
    {
        $edos = [];
        
        // Get NOA from manifest to access containers
        $noa = $manifest->getNoa();
        if (!$noa) {
            throw new EDOWorkflowException('Manifest must have an associated NOA', 400);
        }

        $containers = $noa->getContainers();
        if ($containers->isEmpty()) {
            throw new EDOWorkflowException('NOA must have at least one container', 400);
        }

        // Begin transaction to ensure all eDOs are created atomically
        $this->entityManager->beginTransaction();
        
        try {
            foreach ($containers as $container) {
                $edo = $this->generateEDOForContainer($container, $manifest);
                $edos[] = $edo;
                
                // TODO: Re-implement audit logging with general AuditService
                // Log eDO creation
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        return $edos;
    }

    /**
     * {@inheritdoc}
     */
    public function generateEDOForContainer(Container $container, Manifest $manifest): ElectronicDeliveryOrder
    {
        $edo = new ElectronicDeliveryOrder();
        
        // Assign unique eDO number
        $edoNumber = $this->assignEDONumber($container->getContainerNumber());
        $edo->setEdoNumber($edoNumber);
        
        // Set status to PENDING_RELEASE (awaiting payment)
        $edo->setStatus(EDOStatus::PENDING_RELEASE);
        
        // Link to container and manifest
        $edo->setContainer($container);
        $edo->setManifest($manifest);
        
        // Set shipping line from manifest
        $edo->setShippingLine($manifest->getShippingLine());
        
        // Set expiration date from configuration
        $validityDays = $this->configurationService->getEDOValidityPeriod();
        $expiresAt = new \DateTime('+' . $validityDays . ' days');
        $edo->setExpiresAt($expiresAt);
        
        // Initialize expired days to 0
        $edo->setExpiredDays(0);
        
        // Set version to 1 (first version)
        $edo->setVersion(1);
        
        // Capture current eDO fee from payment fee configuration at generation time
        $edoFee = $this->paymentFeeConfigurationService->getCurrentEDOFee();
        $edo->setFeeAmount($edoFee);
        
        // Set temporary PDF path (will be updated after generation)
        $edo->setPdfPath('pending');
        
        // Generate digital signature (hash of eDO data)
        $signatureData = sprintf(
            '%s|%s|%s|%s',
            $edoNumber,
            $container->getContainerNumber(),
            (new \DateTime())->format('Y-m-d H:i:s'),
            $manifest->getManifestNumber()
        );
        $digitalSignature = hash('sha256', $signatureData);
        $edo->setDigitalSignature($digitalSignature);
        
        // Persist the eDO first to get an ID
        $this->entityManager->persist($edo);
        $this->entityManager->flush();
        
        // Note: PDF generation is handled by BatchEDOGenerationService for bulk operations
        // For single eDO generation, the PDF path remains 'pending' until explicitly generated
        
        // Lock CY allocation when eDO is generated (Task 14.1)
        // Change allocation status from PRE_FORECAST to ALLOCATED
        if ($container->getCyAllocation() !== null) {
            $this->cyAllocationService->lockAllocation($container);
        }
        
        return $edo;
    }

    /**
     * {@inheritdoc}
     */
    public function assignEDONumber(string $containerNumber): string
    {
        return $this->edoNumberGenerator->generate();
    }
}
