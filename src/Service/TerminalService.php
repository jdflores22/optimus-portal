<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\Enum\SlotStatus;
use App\Entity\Enum\TerminalType;
use App\Repository\TerminalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TerminalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TerminalRepository $terminalRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Get available slots for a terminal within a date range
     */
    public function getAvailableSlots(Terminal $terminal, \DateTime $startDate, \DateTime $endDate): array
    {
        return $this->entityManager->getRepository(TerminalSlot::class)
            ->createQueryBuilder('ts')
            ->where('ts.terminal = :terminal')
            ->andWhere('ts.date >= :startDate')
            ->andWhere('ts.date <= :endDate')
            ->andWhere('ts.status = :status')
            ->andWhere('ts.assignedCount < ts.capacity')
            ->setParameter('terminal', $terminal)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('status', SlotStatus::AVAILABLE)
            ->orderBy('ts.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get terminal capacity information for a specific date
     */
    public function getTerminalCapacity(Terminal $terminal, \DateTime $date): array
    {
        $slot = $this->entityManager->getRepository(TerminalSlot::class)
            ->findOneBy(['terminal' => $terminal, 'date' => $date]);

        if (!$slot) {
            return [
                'capacity' => $terminal->getDailyCapacity(),
                'assigned' => 0,
                'available' => $terminal->getDailyCapacity()
            ];
        }

        return [
            'capacity' => $slot->getCapacity(),
            'assigned' => $slot->getAssignedCount(),
            'available' => $slot->getCapacity() - $slot->getAssignedCount()
        ];
    }

    /**
     * Check if terminal can accept a specific container
     */
    public function canAcceptContainer(Terminal $terminal, Container $container): bool
    {
        if (!$terminal->isActive()) {
            $this->logger->info('Terminal is not active', ['terminalId' => $terminal->getId()]);
            return false;
        }

        // Check container-terminal compatibility based on terminal type and container specifications
        return $this->validateContainerTerminalCompatibility($terminal, $container);
    }

    /**
     * Validate container-terminal compatibility
     */
    public function validateContainerTerminalCompatibility(Terminal $terminal, Container $container): bool
    {
        // Basic compatibility rules based on terminal type
        $terminalType = $terminal->getType();
        $containerType = $container->getContainerType()->getCode();
        $containerSize = $container->getContainerSize()->getCode();

        $this->logger->info('Validating container-terminal compatibility', [
            'terminalType' => $terminalType->value,
            'containerType' => $containerType,
            'containerSize' => $containerSize
        ]);

        // All terminals can accept standard dry containers
        if ($containerType === 'Dry') {
            return true;
        }

        // Reefer containers require special handling - only certain terminals
        if ($containerType === 'Reefer') {
            return in_array($terminalType, [TerminalType::CY, TerminalType::ICTSI]);
        }

        // Hazardous containers have special requirements
        if ($containerType === 'Hazardous') {
            return $terminalType === TerminalType::CY;
        }

        // Default: allow if no specific restrictions
        return true;
    }

    /**
     * Get all active terminals
     */
    public function getActiveTerminals(): array
    {
        return $this->terminalRepository->findActive();
    }

    /**
     * Get terminals by type
     */
    public function getTerminalsByType(TerminalType $type): array
    {
        return $this->terminalRepository->findByType($type);
    }

    /**
     * Find terminals that can accept a specific container
     */
    public function findCompatibleTerminals(Container $container): array
    {
        $activeTerminals = $this->getActiveTerminals();
        $compatibleTerminals = [];

        foreach ($activeTerminals as $terminal) {
            if ($this->canAcceptContainer($terminal, $container)) {
                $compatibleTerminals[] = $terminal;
            }
        }

        $this->logger->info('Found compatible terminals', [
            'containerNumber' => $container->getContainerNumber(),
            'compatibleCount' => count($compatibleTerminals)
        ]);

        return $compatibleTerminals;
    }

    /**
     * Find terminals with available capacity for a specific date
     */
    public function findTerminalsWithAvailableCapacity(\DateTime $date): array
    {
        return $this->terminalRepository->findWithAvailableCapacity($date);
    }

    /**
     * Check if terminal has available capacity for a specific date
     */
    public function hasAvailableCapacity(Terminal $terminal, \DateTime $date): bool
    {
        $capacityInfo = $this->getTerminalCapacity($terminal, $date);
        return $capacityInfo['available'] > 0;
    }

    /**
     * Get terminal utilization statistics
     */
    public function getTerminalUtilization(Terminal $terminal, \DateTime $startDate, \DateTime $endDate): array
    {
        $slots = $this->entityManager->getRepository(TerminalSlot::class)
            ->createQueryBuilder('ts')
            ->where('ts.terminal = :terminal')
            ->andWhere('ts.date >= :startDate')
            ->andWhere('ts.date <= :endDate')
            ->setParameter('terminal', $terminal)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getResult();

        $totalCapacity = 0;
        $totalAssigned = 0;
        $totalSlots = count($slots);

        foreach ($slots as $slot) {
            $totalCapacity += $slot->getCapacity();
            $totalAssigned += $slot->getAssignedCount();
        }

        $utilizationRate = $totalCapacity > 0 ? ($totalAssigned / $totalCapacity) * 100 : 0;

        return [
            'totalSlots' => $totalSlots,
            'totalCapacity' => $totalCapacity,
            'totalAssigned' => $totalAssigned,
            'totalAvailable' => $totalCapacity - $totalAssigned,
            'utilizationRate' => round($utilizationRate, 2)
        ];
    }

    /**
     * Configure terminal settings
     */
    public function configureTerminal(Terminal $terminal, array $settings): Terminal
    {
        if (isset($settings['dailyCapacity'])) {
            $terminal->setDailyCapacity($settings['dailyCapacity']);
        }

        if (isset($settings['isActive'])) {
            $terminal->setIsActive($settings['isActive']);
        }

        if (isset($settings['location'])) {
            $terminal->setLocation($settings['location']);
        }

        $terminal->setUpdatedAt(new \DateTime());
        
        $this->entityManager->persist($terminal);
        $this->entityManager->flush();

        $this->logger->info('Terminal configuration updated', [
            'terminalId' => $terminal->getId(),
            'settings' => $settings
        ]);

        return $terminal;
    }

    /**
     * Get shipping line utilization for a terminal with TEU calculation and container breakdown
     */
    public function getShippingLineUtilization(Terminal $terminal, ?\App\Entity\ShippingLine $shippingLine = null): array
    {
        if (!$shippingLine) {
            return [
                'currentTEUs' => 0,
                'allocatedTEUs' => $terminal->getDailyCapacity(),
                'percentage' => 0,
                'container20ft' => 0,
                'container40ft' => 0
            ];
        }

        // Find the allocated capacity for this shipping line at this terminal
        // Get the shipping line admin(s) for this shipping line
        $shippingLineAdmins = $shippingLine->getShippingLineAdmins();
        
        $allocatedCapacity = $terminal->getDailyCapacity(); // Default to terminal capacity
        
        // Find the allocation for this terminal from any of the shipping line admins
        foreach ($shippingLineAdmins as $admin) {
            $allocation = $this->entityManager->getRepository(\App\Entity\ShippingLineTerminalAllocation::class)
                ->findOneBy([
                    'staffUser' => $admin,
                    'terminal' => $terminal
                ]);
            
            if ($allocation) {
                $allocatedCapacity = $allocation->getAllocatedCapacity();
                break; // Use the first allocation found
            }
        }

        // Get containers at this terminal for this shipping line with approved/edo_ready status
        $containers = $this->entityManager->getRepository(\App\Entity\Container::class)
            ->createQueryBuilder('c')
            ->join('c.bookingRequests', 'pr')
            ->where('pr.selectedTerminal = :terminal')
            ->andWhere('c.shippingLine = :shippingLine')
            ->andWhere('pr.status IN (:statuses)')
            ->setParameter('terminal', $terminal)
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('statuses', ['approved', 'edo_ready'])
            ->getQuery()
            ->getResult();

        $totalTEUs = 0;
        $count20ft = 0;
        $count40ft = 0;

        foreach ($containers as $container) {
            $size = $container->getContainerSize()->getCode();
            
            // Convert container size to TEUs
            // 20ft = 1 TEU, 40ft = 2 TEUs, 45ft = 2.25 TEUs
            if (strpos($size, '20') !== false) {
                $totalTEUs += 1;
                $count20ft++;
            } elseif (strpos($size, '40') !== false) {
                $totalTEUs += 2;
                $count40ft++;
            } elseif (strpos($size, '45') !== false) {
                $totalTEUs += 2.25;
                $count40ft++; // Count 45ft as 40ft for display purposes
            } else {
                // Default to 1 TEU if size is unknown
                $totalTEUs += 1;
                $count20ft++;
            }
        }

        $percentage = $allocatedCapacity > 0 ? round(($totalTEUs / $allocatedCapacity) * 100, 0) : 0;

        return [
            'currentTEUs' => (int)$totalTEUs,
            'allocatedTEUs' => $allocatedCapacity,
            'percentage' => (int)$percentage,
            'container20ft' => $count20ft,
            'container40ft' => $count40ft
        ];
    }

    /**
     * Get terminal details for display
     */
    public function getTerminalDetails(Terminal $terminal, ?\App\Entity\ShippingLine $shippingLine = null): array
    {
        $today = new \DateTime('today');
        $capacityInfo = $this->getTerminalCapacity($terminal, $today);
        $shippingLineUtilization = $this->getShippingLineUtilization($terminal, $shippingLine);

        return [
            'id' => $terminal->getId(),
            'name' => $terminal->getName(),
            'type' => $terminal->getType()->value,
            'location' => $terminal->getLocation(),
            'dailyCapacity' => $terminal->getDailyCapacity(),
            'isActive' => $terminal->isActive(),
            'todayCapacity' => $capacityInfo,
            'shippingLineUtilization' => $shippingLineUtilization,
            'createdAt' => $terminal->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $terminal->getUpdatedAt()->format('Y-m-d H:i:s')
        ];
    }
}