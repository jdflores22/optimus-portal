<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\Enum\AllocationStatus;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\RepositioningRequestStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\RepositioningRequestItem;
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

    /**
     * Aggregated Port vs CY counts for dashboard summary cards.
     *
     * @return array{port: array<string, int|float>, cy: array<string, int|float>}
     */
    public function buildLocationSummary(ShippingLine $shippingLine): array
    {
        $ports = $this->buildPortTerminalLocations($shippingLine);
        $cys = $this->buildCyEmptyReturnLocations($shippingLine);

        $portProjectedTeu = 0.0;
        $portCapacity = 0.0;
        $atPortByTerminal = [];
        $inboundByTerminal = [];
        $outboundByTerminal = [];
        foreach ($ports as $port) {
            $portProjectedTeu += $port['projected_teu'];
            $portCapacity += $port['total_teu_capacity'];
            if ($port['inbound_count'] > 0) {
                $inboundByTerminal[] = [
                    'code' => $port['terminal']->getCode(),
                    'name' => $port['terminal']->getName(),
                    'count' => $port['inbound_count'],
                    'count_20ft' => $port['inbound_20ft'],
                    'count_40ft' => $port['inbound_40ft'],
                ];
            }
            if ($port['transfer_from_cy_count'] > 0) {
                $outboundByTerminal[] = [
                    'code' => $port['terminal']->getCode(),
                    'name' => $port['terminal']->getName(),
                    'count' => $port['transfer_from_cy_count'],
                    'reposition_count' => $port['transfer_reposition_count'],
                    'count_20ft' => $port['transfer_from_cy_20ft'],
                    'count_40ft' => $port['transfer_from_cy_40ft'],
                ];
            }
            if ($port['at_port_count'] > 0) {
                $atPortByTerminal[] = [
                    'code' => $port['terminal']->getCode(),
                    'name' => $port['terminal']->getName(),
                    'count' => $port['at_port_count'],
                    'import_count' => $port['at_port_import_count'],
                    'reposition_count' => $port['at_port_reposition_count'],
                    'count_20ft' => $port['at_port_20ft'],
                    'count_40ft' => $port['at_port_40ft'],
                ];
            }
        }

        $cyUsedTeu = 0.0;
        $cyCapacity = 0.0;
        $cyAllocated = 0;
        $cyPreForecast = 0;
        $cyAllocated20 = 0;
        $cyAllocated40 = 0;
        $cyPreForecast20 = 0;
        $cyPreForecast40 = 0;
        $allocatedByCy = [];
        $preForecastByCy = [];
        $capacityByCy = [];
        foreach ($cys as $cy) {
            $cyUsedTeu += $cy['used_teu'];
            $cyCapacity += $cy['total_teu_capacity'];
            $cyAllocated += $cy['allocated_20ft'] + $cy['allocated_40ft'];
            $cyPreForecast += $cy['pre_forecast_20ft'] + $cy['pre_forecast_40ft'];
            $cyAllocated20 += $cy['allocated_20ft'];
            $cyAllocated40 += $cy['allocated_40ft'];
            $cyPreForecast20 += $cy['pre_forecast_20ft'];
            $cyPreForecast40 += $cy['pre_forecast_40ft'];

            $terminal = $cy['terminal'];
            $allocCount = $cy['allocated_20ft'] + $cy['allocated_40ft'];
            if ($allocCount > 0) {
                $allocatedByCy[] = [
                    'code' => $terminal->getCode(),
                    'name' => $terminal->getName(),
                    'location_type' => 'CY',
                    'count' => $allocCount,
                    'count_20ft' => $cy['allocated_20ft'],
                    'count_40ft' => $cy['allocated_40ft'],
                ];
            }

            $preForecastCount = $cy['pre_forecast_20ft'] + $cy['pre_forecast_40ft'];
            if ($preForecastCount > 0) {
                $preForecastByCy[] = [
                    'code' => $terminal->getCode(),
                    'name' => $terminal->getName(),
                    'location_type' => 'CY',
                    'count' => $preForecastCount,
                    'count_20ft' => $cy['pre_forecast_20ft'],
                    'count_40ft' => $cy['pre_forecast_40ft'],
                ];
            }

            if ($cy['total_teu_capacity'] > 0 || $cy['used_teu'] > 0) {
                $capacityByCy[] = [
                    'code' => $terminal->getCode(),
                    'name' => $terminal->getName(),
                    'location_type' => 'CY',
                    'used_teu' => $cy['used_teu'],
                    'total_capacity' => $cy['total_teu_capacity'],
                    'utilization_percent' => $cy['utilization_percent'],
                    'allocated_20ft' => $cy['allocated_20ft'],
                    'allocated_40ft' => $cy['allocated_40ft'],
                    'pre_forecast_20ft' => $cy['pre_forecast_20ft'],
                    'pre_forecast_40ft' => $cy['pre_forecast_40ft'],
                ];
            }
        }

        return [
            'port' => [
                'location_count' => count($ports),
                'inbound_count' => (int) array_sum(array_column($ports, 'inbound_count')),
                'inbound_20ft' => (int) array_sum(array_column($ports, 'inbound_20ft')),
                'inbound_40ft' => (int) array_sum(array_column($ports, 'inbound_40ft')),
                'inbound_by_terminal' => $inboundByTerminal,
                'at_port_count' => (int) array_sum(array_column($ports, 'at_port_count')),
                'at_port_import_count' => (int) array_sum(array_column($ports, 'at_port_import_count')),
                'at_port_reposition_count' => (int) array_sum(array_column($ports, 'at_port_reposition_count')),
                'at_port_20ft' => (int) array_sum(array_column($ports, 'at_port_20ft')),
                'at_port_40ft' => (int) array_sum(array_column($ports, 'at_port_40ft')),
                'at_port_by_terminal' => $atPortByTerminal,
                'outbound_count' => (int) array_sum(array_column($ports, 'transfer_from_cy_count')),
                'outbound_20ft' => (int) array_sum(array_column($ports, 'transfer_from_cy_20ft')),
                'outbound_40ft' => (int) array_sum(array_column($ports, 'transfer_from_cy_40ft')),
                'outbound_reposition_count' => (int) array_sum(array_column($ports, 'transfer_reposition_count')),
                'outbound_by_terminal' => $outboundByTerminal,
                'container_count' => (int) array_sum(array_column($ports, 'container_count')),
                'projected_teu' => $portProjectedTeu,
                'total_capacity' => $portCapacity,
                'utilization_percent' => $portCapacity > 0
                    ? round(($portProjectedTeu / $portCapacity) * 100, 1)
                    : 0.0,
            ],
            'cy' => [
                'location_type' => 'CY',
                'location_count' => count($cys),
                'allocated_count' => $cyAllocated,
                'allocated_20ft' => $cyAllocated20,
                'allocated_40ft' => $cyAllocated40,
                'allocated_by_yard' => $allocatedByCy,
                'pre_forecast_count' => $cyPreForecast,
                'pre_forecast_20ft' => $cyPreForecast20,
                'pre_forecast_40ft' => $cyPreForecast40,
                'pre_forecast_by_yard' => $preForecastByCy,
                'used_teu' => $cyUsedTeu,
                'total_capacity' => $cyCapacity,
                'utilization_percent' => $cyCapacity > 0
                    ? round(($cyUsedTeu / $cyCapacity) * 100, 1)
                    : 0.0,
                'capacity_by_yard' => $capacityByCy,
            ],
        ];
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
        $dailyCapacity = $port->getDailyCapacity();

        $inbound20 = $this->countPortContainers($port, $shippingLine, 'inbound', 1.0);
        $inbound40 = $this->countPortContainers($port, $shippingLine, 'inbound', 2.0);
        $atPort20 = $this->countPortContainers($port, $shippingLine, 'at_port', 1.0);
        $atPort40 = $this->countPortContainers($port, $shippingLine, 'at_port', 2.0);
        $atPortImport20 = $this->countPortContainers($port, $shippingLine, 'at_port_import', 1.0);
        $atPortImport40 = $this->countPortContainers($port, $shippingLine, 'at_port_import', 2.0);
        $atPortReposition20 = $this->countPortContainers($port, $shippingLine, 'at_port_reposition', 1.0);
        $atPortReposition40 = $this->countPortContainers($port, $shippingLine, 'at_port_reposition', 2.0);
        $transfer20 = $this->countPortContainers($port, $shippingLine, 'transfer_from_cy', 1.0);
        $transfer40 = $this->countPortContainers($port, $shippingLine, 'transfer_from_cy', 2.0);
        $transferReposition20 = $this->countPortContainers($port, $shippingLine, 'transfer_reposition', 1.0);
        $transferReposition40 = $this->countPortContainers($port, $shippingLine, 'transfer_reposition', 2.0);

        $inboundCount = $inbound20 + $inbound40;
        $atPortCount = $atPort20 + $atPort40;
        $atPortImportCount = $atPortImport20 + $atPortImport40;
        $atPortRepositionCount = $atPortReposition20 + $atPortReposition40;
        $transferCount = $transfer20 + $transfer40;
        $transferRepositionCount = $transferReposition20 + $transferReposition40;
        $containerCount = $inboundCount + $atPortCount + $transferCount;

        $projectedTeu = $inbound20 + ($inbound40 * 2) + $atPort20 + ($atPort40 * 2) + $transfer20 + ($transfer40 * 2);
        $utilization = $dailyCapacity > 0 ? round(($projectedTeu / $dailyCapacity) * 100, 1) : 0;

        return [
            'location_type' => 'PORT',
            'terminal' => $port,
            'total_teu_capacity' => $dailyCapacity,
            'inbound_count' => $inboundCount,
            'at_port_count' => $atPortCount,
            'at_port_import_count' => $atPortImportCount,
            'at_port_reposition_count' => $atPortRepositionCount,
            'transfer_from_cy_count' => $transferCount,
            'transfer_reposition_count' => $transferRepositionCount,
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

    /**
     * @return list<string>
     */
    private function getPortLocationIdentifiers(Terminal $port): array
    {
        return array_values(array_filter(array_unique([
            $port->getCode(),
            $port->getName(),
            $port->getLocation(),
        ])));
    }

    private function countPortContainers(
        Terminal $port,
        ShippingLine $shippingLine,
        string $bucket,
        float $teuValue,
    ): int {
        $portIds = $this->getPortLocationIdentifiers($port);

        $qb = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->leftJoin('c.noa', 'noa')
            ->join('c.containerSize', 'cs')
            ->leftJoin(RepositioningRequestItem::class, 'rri', 'WITH', 'rri.container = c')
            ->leftJoin('rri.request', 'rr')
            ->where('c.shippingLine = :shippingLine')
            ->andWhere('cs.teuValue = :teuValue')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('teuValue', $teuValue);

        match ($bucket) {
            'inbound' => $qb
                ->andWhere('c.status = :status')
                ->andWhere('noa.portLocation IN (:portIds)')
                ->andWhere('noa.eta >= :today')
                ->setParameter('status', ContainerStatus::PENDING)
                ->setParameter('portIds', $portIds)
                ->setParameter('today', new \DateTime('today')),
            'at_port' => $qb
                ->andWhere('c.status = :status')
                ->andWhere(
                    'noa.portLocation IN (:portIds) OR (rr.destinationTerminal = :port AND rr.status = :repoCompleted)'
                )
                ->setParameter('status', ContainerStatus::AT_TERMINAL)
                ->setParameter('portIds', $portIds)
                ->setParameter('port', $port)
                ->setParameter('repoCompleted', RepositioningRequestStatus::COMPLETED),
            'at_port_import' => $qb
                ->andWhere('c.status = :status')
                ->andWhere('noa.portLocation IN (:portIds)')
                ->andWhere(
                    'NOT EXISTS (
                        SELECT 1 FROM App\Entity\RepositioningRequestItem ri2
                        JOIN ri2.request r2
                        WHERE ri2.container = c
                        AND r2.destinationTerminal = :port
                        AND r2.status = :repoCompleted
                    )'
                )
                ->setParameter('status', ContainerStatus::AT_TERMINAL)
                ->setParameter('portIds', $portIds)
                ->setParameter('port', $port)
                ->setParameter('repoCompleted', RepositioningRequestStatus::COMPLETED),
            'at_port_reposition' => $qb
                ->andWhere('c.status = :status')
                ->andWhere('rr.destinationTerminal = :port')
                ->andWhere('rr.status = :repoCompleted')
                ->setParameter('status', ContainerStatus::AT_TERMINAL)
                ->setParameter('port', $port)
                ->setParameter('repoCompleted', RepositioningRequestStatus::COMPLETED),
            'transfer_from_cy' => $qb
                ->leftJoin('c.cyAllocation', 'cyAlloc')
                ->leftJoin('cyAlloc.terminal', 'cyTerminal')
                ->andWhere('c.status = :status')
                ->andWhere(
                    '(rr.destinationTerminal = :port AND rr.status = :repoInTransit)
                     OR (noa.portLocation IN (:portIds) AND cyTerminal.type = :cyType)'
                )
                ->setParameter('status', ContainerStatus::IN_TRANSIT)
                ->setParameter('portIds', $portIds)
                ->setParameter('port', $port)
                ->setParameter('repoInTransit', RepositioningRequestStatus::IN_TRANSIT)
                ->setParameter('cyType', TerminalType::CY),
            'transfer_reposition' => $qb
                ->andWhere('c.status = :status')
                ->andWhere('rr.destinationTerminal = :port')
                ->andWhere('rr.status = :repoInTransit')
                ->setParameter('status', ContainerStatus::IN_TRANSIT)
                ->setParameter('port', $port)
                ->setParameter('repoInTransit', RepositioningRequestStatus::IN_TRANSIT),
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
