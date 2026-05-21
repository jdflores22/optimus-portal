<?php

namespace App\Service;

use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\Enum\SlotStatus;
use App\Repository\TerminalSlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SlotManagementService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TerminalSlotRepository $terminalSlotRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Create daily slots for a terminal within a date range
     */
    public function createDailySlots(Terminal $terminal, \DateTime $startDate, \DateTime $endDate): array
    {
        $createdSlots = [];
        $currentDate = clone $startDate;

        while ($currentDate <= $endDate) {
            $existingSlot = $this->terminalSlotRepository->findByTerminalAndDate($terminal, $currentDate);
            
            if (!$existingSlot) {
                $slot = new TerminalSlot();
                $slot->setTerminal($terminal);
                $slot->setDate(clone $currentDate);
                $slot->setCapacity($terminal->getDailyCapacity());
                $slot->setAssignedCount(0);
                $slot->setStatus(SlotStatus::AVAILABLE);

                $this->entityManager->persist($slot);
                $createdSlots[] = $slot;

                $this->logger->info('Created terminal slot', [
                    'terminalId' => $terminal->getId(),
                    'date' => $currentDate->format('Y-m-d'),
                    'capacity' => $terminal->getDailyCapacity()
                ]);
            }

            $currentDate->modify('+1 day');
        }

        $this->entityManager->flush();

        $this->logger->info('Bulk slot creation completed', [
            'terminalId' => $terminal->getId(),
            'createdCount' => count($createdSlots),
            'dateRange' => $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d')
        ]);

        return $createdSlots;
    }

    /**
     * Create slots for all active terminals within a date range
     */
    public function createSlotsForAllTerminals(\DateTime $startDate, \DateTime $endDate): array
    {
        $terminals = $this->entityManager->getRepository(Terminal::class)
            ->findBy(['isActive' => true]);

        $allCreatedSlots = [];

        foreach ($terminals as $terminal) {
            $createdSlots = $this->createDailySlots($terminal, $startDate, $endDate);
            $allCreatedSlots = array_merge($allCreatedSlots, $createdSlots);
        }

        return $allCreatedSlots;
    }

    /**
     * Check if a specific slot is available for assignment
     */
    public function isSlotAvailable(TerminalSlot $slot): bool
    {
        $remaining = $slot->getCapacity() - $slot->getAssignedCount();
        return $remaining > 0 && $slot->getStatus() === SlotStatus::AVAILABLE;
    }

    /**
     * Check slot availability for a specific terminal and date
     */
    public function checkSlotAvailability(Terminal $terminal, \DateTime $date): array
    {
        $slot = $this->terminalSlotRepository->findByTerminalAndDate($terminal, $date);

        if (!$slot) {
            // No slot exists, create one with default capacity
            return [
                'available' => true,
                'capacity' => $terminal->getDailyCapacity(),
                'assigned' => 0,
                'remaining' => $terminal->getDailyCapacity(),
                'status' => SlotStatus::AVAILABLE->value
            ];
        }

        $remaining = $slot->getCapacity() - $slot->getAssignedCount();

        return [
            'available' => $remaining > 0 && $slot->getStatus() === SlotStatus::AVAILABLE,
            'capacity' => $slot->getCapacity(),
            'assigned' => $slot->getAssignedCount(),
            'remaining' => $remaining,
            'status' => $slot->getStatus()->value
        ];
    }

    /**
     * Assign a slot to a FREE-ADVICE request
     */
    public function assignSlot(Terminal $terminal, \DateTime $date, PreAdviceRequest $preAdviceRequest): bool
    {
        $slot = $this->terminalSlotRepository->findByTerminalAndDate($terminal, $date);

        if (!$slot) {
            // Create slot if it doesn't exist
            $slot = new TerminalSlot();
            $slot->setTerminal($terminal);
            $slot->setDate($date);
            $slot->setCapacity($terminal->getDailyCapacity());
            $slot->setAssignedCount(0);
            $slot->setStatus(SlotStatus::AVAILABLE);
            $this->entityManager->persist($slot);
        }

        // Check if slot has available capacity
        if ($slot->getAssignedCount() >= $slot->getCapacity() || $slot->getStatus() !== SlotStatus::AVAILABLE) {
            $this->logger->warning('Slot assignment failed - no capacity or slot not available', [
                'terminalId' => $terminal->getId(),
                'date' => $date->format('Y-m-d'),
                'assigned' => $slot->getAssignedCount(),
                'capacity' => $slot->getCapacity(),
                'status' => $slot->getStatus()->value
            ]);
            return false;
        }

        // Assign the slot
        $slot->setAssignedCount($slot->getAssignedCount() + 1);
        $preAdviceRequest->setAssignedSlot($slot);

        // Update slot status if full
        if ($slot->getAssignedCount() >= $slot->getCapacity()) {
            $slot->setStatus(SlotStatus::FULL);
        }

        $this->entityManager->persist($slot);
        $this->entityManager->persist($preAdviceRequest);
        $this->entityManager->flush();

        $this->logger->info('Slot assigned successfully', [
            'terminalId' => $terminal->getId(),
            'date' => $date->format('Y-m-d'),
            'preAdviceId' => $preAdviceRequest->getId(),
            'newAssignedCount' => $slot->getAssignedCount()
        ]);

        return true;
    }

    /**
     * Release a slot assignment
     */
    public function releaseSlot(PreAdviceRequest $preAdviceRequest): bool
    {
        $slot = $preAdviceRequest->getAssignedSlot();

        if (!$slot) {
            $this->logger->warning('No slot to release', [
                'preAdviceId' => $preAdviceRequest->getId()
            ]);
            return false;
        }

        // Decrease assigned count
        $slot->setAssignedCount(max(0, $slot->getAssignedCount() - 1));
        
        // Update slot status if no longer full
        if ($slot->getStatus() === SlotStatus::FULL && $slot->getAssignedCount() < $slot->getCapacity()) {
            $slot->setStatus(SlotStatus::AVAILABLE);
        }

        // Remove slot assignment from FREE-ADVICE request
        $preAdviceRequest->setAssignedSlot(null);

        $this->entityManager->persist($slot);
        $this->entityManager->persist($preAdviceRequest);
        $this->entityManager->flush();

        $this->logger->info('Slot released successfully', [
            'terminalId' => $slot->getTerminal()->getId(),
            'date' => $slot->getDate()->format('Y-m-d'),
            'preAdviceId' => $preAdviceRequest->getId(),
            'newAssignedCount' => $slot->getAssignedCount()
        ]);

        return true;
    }

    /**
     * Block or unblock specific slots
     */
    public function updateSlotStatus(Terminal $terminal, \DateTime $date, SlotStatus $status): bool
    {
        $slot = $this->terminalSlotRepository->findByTerminalAndDate($terminal, $date);

        if (!$slot) {
            $this->logger->warning('Slot not found for status update', [
                'terminalId' => $terminal->getId(),
                'date' => $date->format('Y-m-d')
            ]);
            return false;
        }

        $oldStatus = $slot->getStatus();
        $slot->setStatus($status);

        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $this->logger->info('Slot status updated', [
            'terminalId' => $terminal->getId(),
            'date' => $date->format('Y-m-d'),
            'oldStatus' => $oldStatus->value,
            'newStatus' => $status->value
        ]);

        return true;
    }

    /**
     * Bulk update slot capacity
     */
    public function updateSlotCapacity(Terminal $terminal, \DateTime $startDate, \DateTime $endDate, int $newCapacity): int
    {
        $slots = $this->terminalSlotRepository->findByTerminalAndDateRange($terminal, $startDate, $endDate);
        $updatedCount = 0;

        foreach ($slots as $slot) {
            $slot->setCapacity($newCapacity);
            
            // Update status based on new capacity
            if ($slot->getAssignedCount() >= $newCapacity) {
                $slot->setStatus(SlotStatus::FULL);
            } elseif ($slot->getStatus() === SlotStatus::FULL && $slot->getAssignedCount() < $newCapacity) {
                $slot->setStatus(SlotStatus::AVAILABLE);
            }

            $this->entityManager->persist($slot);
            $updatedCount++;
        }

        $this->entityManager->flush();

        $this->logger->info('Bulk slot capacity update completed', [
            'terminalId' => $terminal->getId(),
            'updatedCount' => $updatedCount,
            'newCapacity' => $newCapacity,
            'dateRange' => $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d')
        ]);

        return $updatedCount;
    }

    /**
     * Get slot utilization statistics
     */
    public function getSlotUtilizationStats(Terminal $terminal, \DateTime $startDate, \DateTime $endDate): array
    {
        $slots = $this->terminalSlotRepository->findByTerminalAndDateRange($terminal, $startDate, $endDate);

        $totalSlots = count($slots);
        $totalCapacity = 0;
        $totalAssigned = 0;
        $availableSlots = 0;
        $fullSlots = 0;
        $blockedSlots = 0;

        foreach ($slots as $slot) {
            $totalCapacity += $slot->getCapacity();
            $totalAssigned += $slot->getAssignedCount();

            switch ($slot->getStatus()) {
                case SlotStatus::AVAILABLE:
                    $availableSlots++;
                    break;
                case SlotStatus::FULL:
                    $fullSlots++;
                    break;
                case SlotStatus::BLOCKED:
                    $blockedSlots++;
                    break;
            }
        }

        $utilizationRate = $totalCapacity > 0 ? ($totalAssigned / $totalCapacity) * 100 : 0;

        return [
            'totalSlots' => $totalSlots,
            'totalCapacity' => $totalCapacity,
            'totalAssigned' => $totalAssigned,
            'totalAvailable' => $totalCapacity - $totalAssigned,
            'utilizationRate' => round($utilizationRate, 2),
            'availableSlots' => $availableSlots,
            'fullSlots' => $fullSlots,
            'blockedSlots' => $blockedSlots,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ]
        ];
    }

    /**
     * Get available slots for a terminal within a date range
     */
    public function getAvailableSlots(Terminal $terminal, \DateTime $startDate, \DateTime $endDate): array
    {
        return $this->terminalSlotRepository->findAvailableSlots($terminal, $startDate, $endDate);
    }

    /**
     * Find next available slot for a terminal
     */
    public function findNextAvailableSlot(Terminal $terminal, \DateTime $fromDate = null): ?TerminalSlot
    {
        $fromDate = $fromDate ?? new \DateTime('today');
        $endDate = (clone $fromDate)->modify('+30 days'); // Look ahead 30 days

        $availableSlots = $this->getAvailableSlots($terminal, $fromDate, $endDate);

        return !empty($availableSlots) ? $availableSlots[0] : null;
    }
}