<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\Enum\AllocationStatus;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\NOA;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Repository\TerminalRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds CY vs Port/Terminal capacity cards for SL Staff dashboard.
 */
class SlStaffDashboardLocationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TerminalRepository $terminalRepository,
    ) {
    }

    /**
     * Container Yard allocations — empty return locations only (type = CY).
     *
     * @return list<array<string, mixed>>
     */
    public function buildCyEmptyReturnLocations(ShippingLine $shippingLine): array
    {
        $locations = [];

        $allocations = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->createQueryBuilder('alloc')
            ->leftJoin('alloc.terminal', 'terminal')
            ->leftJoin('terminal.region', 'region')
            ->leftJoin('terminal.city', 'city')
            ->addSelect('terminal', 'region', 'city')
            ->where('alloc.shippingLine = :shippingLine')
            ->andWhere('terminal.type = :cyType')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('cyType', TerminalType::CY)
            ->getQuery()
            ->getResult();

        foreach ($allocations as $allocation) {
            $locations[] = $this->buildCyLocationMetrics($allocation, $shippingLine);
        }

        return $locations;
    }

    /**
     * Port / terminal inbound & transfer metrics (type = ATI, ICTSI, etc.).
     *
     * @return list<array<string, mixed>>
     */
    public function buildPortTerminalLocations(ShippingLine $shippingLine): array
    {
        $locations = [];
        $ports = $this->terminalRepository->findActivePorts();

        foreach ($ports as $port) {
            $metrics = $this->buildPortLocationMetrics($port, $shippingLine);
            if ($metrics['container_count'] > 0 || $metrics['inbound_count'] > 0) {
                $locations[] = $metrics;
            }
        }

        // Always show ports that have contractual allocation even if empty
        if (empty($locations)) {
            foreach ($ports as $port) {
                $locations[] = $this->buildPortLocationMetrics($port, $shippingLine);
            }
        }

        return $locations;
    }

    private function buildCyLocationMetrics(
        ShippingLineTerminalAllocation $allocation,
        ShippingLine $shippingLine,
    ): array {
        $terminal = $allocation->getTerminal();
        $allocatedTeu = $allocation->getAllocatedCapacity();

        $allocatedTeuCount = $this->sumTeuForAllocation($allocation, AllocationStatus::ALLOCATED, $shippingLine);
        $preForecastTeuCount = $this->sumTeuForAllocation($allocation, AllocationStatus::PRE_FORECAST, $shippingLine);
        $totalUsedTeu = $allocatedTeuCount + $preForecastTeuCount;

        $allocated20ft = $this->countContainersBySize($allocation, AllocationStatus::ALLOCATED, 1.0, $shippingLine);
        $preForecast20ft = $this->countContainersBySize($allocation, AllocationStatus::PRE_FORECAST, 1.0, $shippingLine);
        $allocated40ft = $this->countContainersBySize($allocation, AllocationStatus::ALLOCATED, 2.0, $shippingLine);
        $preForecast40ft = $this->countContainersBySize($allocation, AllocationStatus::PRE_FORECAST, 2.0, $shippingLine);

        $capacity20ft = $allocation->getCapacity20ft();
        $capacity40ft = $allocation->getCapacity40ft();
        $used20ft = $allocated20ft + $preForecast20ft;
        $used40ft = $allocated40ft + $preForecast40ft;

        return [
            'location_type' => 'CY',
            'terminal' => $terminal,
            'allocation' => $allocation,
            'total_teu_capacity' => $allocatedTeu,
            'allocated_teu' => $allocatedTeuCount,
            'pre_forecast_teu' => $preForecastTeuCount,
            'used_teu' => $totalUsedTeu,
            'available_teu' => max(0, $allocatedTeu - $totalUsedTeu),
            'utilization_percent' => $allocatedTeu > 0 ? round(($totalUsedTeu / $allocatedTeu) * 100, 1) : 0,
            'capacity_20ft' => $capacity20ft,
            'allocated_20ft' => $allocated20ft,
            'pre_forecast_20ft' => $preForecast20ft,
            'available_20ft' => max(0, $capacity20ft - $used20ft),
            'utilization_20ft' => $capacity20ft > 0 ? round(($used20ft / $capacity20ft) * 100, 1) : 0,
            'capacity_40ft' => $capacity40ft,
            'allocated_40ft' => $allocated40ft,
            'pre_forecast_40ft' => $preForecast40ft,
            'available_40ft' => max(0, $capacity40ft - $used40ft),
            'utilization_40ft' => $capacity40ft > 0 ? round(($used40ft / $capacity40ft) * 100, 1) : 0,
        ];
    }

    private function buildPortLocationMetrics(Terminal $port, ShippingLine $shippingLine): array
    {
        $portCode = $port->getCode();
        $dailyCapacity = $port->getDailyCapacity();

        $inbound20 = $this->countPortContainers($portCode, $shippingLine, 'inbound', 1.0);
        $inbound40 = $this->countPortContainers($portCode, $shippingLine, 'inbound', 2.0);
        $atPort20 = $this->countPortContainers($portCode, $shippingLine, 'at_port', 1.0);
        $atPort40 = $this->countPortContainers($portCode, $shippingLine, 'at_port', 2.0);
        $transfer20 = $this->countPortContainers($portCode, $shippingLine, 'transfer_from_cy', 1.0);
        $transfer40 = $this->countPortContainers($portCode, $shippingLine, 'transfer_from_cy', 2.0);

        $inboundCount = $inbound20 + $inbound40;
        $atPortCount = $atPort20 + $atPort40;
        $transferCount = $transfer20 + $transfer40;
        $containerCount = $inboundCount + $atPortCount + $transferCount;

        $projectedTeu = $inbound20 + ($inbound40 * 2) + $atPort20 + ($atPort40 * 2) + $transfer20 + ($transfer40 * 2);
        $utilization = $dailyCapacity > 0 ? round(($projectedTeu / $dailyCapacity) * 100, 1) : 0;

        return [
            'location_type' => 'PORT',
            'terminal' => $port,
            'total_teu_capacity' => $dailyCapacity,
            'inbound_count' => $inboundCount,
            'at_port_count' => $atPortCount,
            'transfer_from_cy_count' => $transferCount,
            'container_count' => $containerCount,
            'projected_teu' => $projectedTeu,
            'available_teu' => max(0, $dailyCapacity - $projectedTeu),
            'utilization_percent' => $utilization,
            'inbound_20ft' => $inbound20,
            'inbound_40ft' => $inbound40,
            'at_port_20ft' => $atPort20,
            'at_port_40ft' => $atPort40,
            'transfer_from_cy_20ft' => $transfer20,
            'transfer_from_cy_40ft' => $transfer40,
            'capacity_20ft' => $dailyCapacity,
            'capacity_40ft' => $dailyCapacity,
            'allocated_20ft' => $atPort20,
            'allocated_40ft' => $atPort40,
            'pre_forecast_20ft' => $inbound20,
            'pre_forecast_40ft' => $inbound40,
            'transfer_20ft' => $transfer20,
            'transfer_40ft' => $transfer40,
            'available_20ft' => max(0, $dailyCapacity - $atPort20 - $inbound20 - $transfer20),
            'available_40ft' => max(0, $dailyCapacity - $atPort40 - $inbound40 - $transfer40),
            'utilization_20ft' => $dailyCapacity > 0 ? round((($atPort20 + $inbound20 + $transfer20) / $dailyCapacity) * 100, 1) : 0,
            'utilization_40ft' => $dailyCapacity > 0 ? round((($atPort40 + $inbound40 + $transfer40) / $dailyCapacity) * 100, 1) : 0,
        ];
    }

    private function countPortContainers(
        string $portCode,
        ShippingLine $shippingLine,
        string $bucket,
        float $teuValue,
    ): int {
        $qb = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.noa', 'noa')
            ->join('c.containerSize', 'cs')
            ->where('c.shippingLine = :shippingLine')
            ->andWhere('noa.portLocation = :portCode')
            ->andWhere('cs.teuValue = :teuValue')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('portCode', $portCode)
            ->setParameter('teuValue', $teuValue);

        match ($bucket) {
            'inbound' => $qb->andWhere('c.status = :status')
                ->andWhere('noa.eta >= :today')
                ->setParameter('status', ContainerStatus::PENDING)
                ->setParameter('today', new \DateTime('today')),
            'at_port' => $qb->andWhere('c.status = :status')
                ->setParameter('status', ContainerStatus::AT_TERMINAL),
            'transfer_from_cy' => $qb
                ->join('c.cyAllocation', 'cyAlloc')
                ->join('cyAlloc.terminal', 'cyTerminal')
                ->andWhere('cyTerminal.type = :cyType')
                ->andWhere('c.status IN (:transferStatuses)')
                ->setParameter('cyType', TerminalType::CY)
                ->setParameter('transferStatuses', [
                    ContainerStatus::IN_TRANSIT,
                    ContainerStatus::AVAILABLE_FOR_RETURN,
                ]),
            default => null,
        };

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function sumTeuForAllocation(
        ShippingLineTerminalAllocation $allocation,
        AllocationStatus $status,
        ShippingLine $shippingLine,
    ): float {
        $containers = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.containerSize', 'cs')
            ->addSelect('cs')
            ->where('c.cyAllocation = :allocation')
            ->andWhere('c.allocationStatus = :status')
            ->andWhere('c.shippingLine = :shippingLine')
            ->setParameter('allocation', $allocation)
            ->setParameter('status', $status)
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getResult();

        $teu = 0.0;
        foreach ($containers as $container) {
            $teu += $container->getContainerSize()->getTeuValue();
        }

        return $teu;
    }

    private function countContainersBySize(
        ShippingLineTerminalAllocation $allocation,
        AllocationStatus $status,
        float $teuValue,
        ShippingLine $shippingLine,
    ): int {
        return (int) $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->leftJoin('c.containerSize', 'cs')
            ->where('c.cyAllocation = :allocation')
            ->andWhere('c.allocationStatus = :status')
            ->andWhere('c.shippingLine = :shippingLine')
            ->andWhere('cs.teuValue = :teuValue')
            ->setParameter('allocation', $allocation)
            ->setParameter('status', $status)
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('teuValue', $teuValue)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
