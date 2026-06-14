<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\RepositioningRequestStatus;
use App\Entity\Enum\RepositioningRequestType;
use App\Entity\Enum\TerminalType;
use App\Entity\RepositioningRequest;
use App\Entity\RepositioningRequestItem;
use App\Entity\ShippingLine;
use App\Entity\Terminal;
use App\Entity\User;
use App\Repository\RepositioningRequestRepository;
use App\Repository\TerminalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CY-to-port export/repositioning request workflow.
 * Containers are prioritized by dwell time (CAO 8-2019) — highest first.
 */
class RepositioningRequestService
{
    /** @var list<RepositioningRequestStatus> */
    private const ACTIVE_STATUSES = [
        RepositioningRequestStatus::PENDING,
        RepositioningRequestStatus::APPROVED,
        RepositioningRequestStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RepositioningRequestRepository $requestRepo,
        private readonly TerminalRepository $terminalRepository,
        private readonly DwellTimeServiceInterface $dwellTimeService,
        private readonly ContainerStatusService $containerStatusService,
        private readonly InAppNotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Eligible CY containers sorted by dwell time descending (highest first).
     *
     * @return list<array{container: Container, dwell_days: int, discharge_date: ?\DateTimeInterface, cy_name: string, size: string}>
     */
    public function getEligibleContainers(
        ShippingLine $shippingLine,
        ?Terminal $sourceCy = null,
        ?string $search = null,
    ): array {
        $qb = $this->em->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->join('c.cyAllocation', 'alloc')
            ->join('alloc.terminal', 'cyTerminal')
            ->leftJoin('c.containerSize', 'cs')->addSelect('cs')
            ->leftJoin('c.containerType', 'ct')->addSelect('ct')
            ->leftJoin('c.noa', 'noa')->addSelect('noa')
            ->addSelect('alloc', 'cyTerminal')
            ->where('c.shippingLine = :shippingLine')
            ->andWhere('cyTerminal.type = :cyType')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('cyType', TerminalType::CY)
            ->setParameter('statuses', [
                ContainerStatus::AVAILABLE_FOR_RETURN,
                ContainerStatus::AT_TERMINAL,
            ]);

        if ($sourceCy !== null) {
            $qb->andWhere('cyTerminal = :sourceCy')
                ->setParameter('sourceCy', $sourceCy);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('c.containerNumber LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $containers = $qb->getQuery()->getResult();
        $blockedIds = $this->getContainerIdsInActiveRequests();

        $result = [];
        foreach ($containers as $container) {
            if (in_array($container->getId(), $blockedIds, true)) {
                continue;
            }

            $dwellDays = $this->dwellTimeService->calculateCurrentDwellTime($container);
            $dischargeDate = $container->getTerminalArrivalDate();

            $result[] = [
                'container' => $container,
                'dwell_days' => $dwellDays,
                'discharge_date' => $dischargeDate,
                'cy_name' => $container->getCyAllocation()?->getTerminal()?->getName() ?? '—',
                'size' => $container->getContainerSize()->getCode(),
            ];
        }

        usort($result, fn (array $a, array $b) => $b['dwell_days'] <=> $a['dwell_days']);

        return $result;
    }

    /**
     * @param int[] $containerIds
     */
    public function createRequest(
        ShippingLine $shippingLine,
        User $requestedBy,
        RepositioningRequestType $requestType,
        Terminal $sourceCy,
        Terminal $destinationPort,
        string $purpose,
        array $containerIds,
        ?string $requestLetterPath = null,
    ): RepositioningRequest {
        if ($sourceCy->getType() !== TerminalType::CY) {
            throw new \InvalidArgumentException('Source must be a Container Yard (CY) terminal.');
        }
        if ($destinationPort->getType() === TerminalType::CY) {
            throw new \InvalidArgumentException('Destination must be a Port/Terminal, not a CY.');
        }
        if ($containerIds === []) {
            throw new \InvalidArgumentException('Select at least one container for the request.');
        }

        $eligible = $this->getEligibleContainers($shippingLine, $sourceCy);
        $eligibleById = [];
        foreach ($eligible as $row) {
            $eligibleById[$row['container']->getId()] = $row;
        }

        $request = new RepositioningRequest();
        $seq = $this->requestRepo->getNextSequenceNumber();
        $request->setRequestNumber(sprintf('RRP-%s-%05d', date('Y'), $seq));
        $request->setShippingLine($shippingLine);
        $request->setRequestType($requestType);
        $request->setSourceTerminal($sourceCy);
        $request->setDestinationTerminal($destinationPort);
        $request->setPurpose($purpose);
        $request->setRequestLetter($requestLetterPath);
        $request->setRequestedBy($requestedBy);
        $request->setStatus(RepositioningRequestStatus::PENDING);

        foreach ($containerIds as $containerId) {
            if (!isset($eligibleById[$containerId])) {
                throw new \InvalidArgumentException('Container #' . $containerId . ' is not eligible for repositioning.');
            }
            $row = $eligibleById[$containerId];
            $item = new RepositioningRequestItem();
            $item->setContainer($row['container']);
            $item->setDwellTimeDays($row['dwell_days']);
            $item->setDischargeDate($row['discharge_date']);
            $request->addItem($item);
        }

        $request->setContainerCount($request->getItems()->count());

        $this->em->persist($request);
        $this->em->flush();

        $this->notifyPendingReview($request);

        $this->logger->info('Repositioning request created', [
            'request_number' => $request->getRequestNumber(),
            'container_count' => $request->getContainerCount(),
        ]);

        return $request;
    }

    public function approveRequest(RepositioningRequest $request, User $reviewer): void
    {
        if (!$request->isPending()) {
            throw new \InvalidArgumentException('Request is not pending review.');
        }

        $request->setStatus(RepositioningRequestStatus::IN_TRANSIT);
        $request->setReviewedAt(new \DateTime());
        $request->setReviewedBy($reviewer);

        $portCode = $request->getDestinationTerminal()->getCode();

        foreach ($request->getItems() as $item) {
            $container = $item->getContainer();
            $noa = $container->getNoa();
            if ($noa !== null) {
                $noa->setPortLocation($portCode);
            }
            $this->containerStatusService->changeStatus(
                $container,
                ContainerStatus::IN_TRANSIT,
                $reviewer,
                'Approved repositioning request ' . $request->getRequestNumber()
            );
        }

        $this->em->flush();

        $this->notifyRequester($request, 'Approved', 'Your repositioning request has been approved. Containers are in transit to ' . $request->getDestinationTerminal()->getName() . '.');
    }

    public function rejectRequest(RepositioningRequest $request, User $reviewer, string $notes): void
    {
        if (!$request->isPending()) {
            throw new \InvalidArgumentException('Request is not pending review.');
        }

        $request->setStatus(RepositioningRequestStatus::REJECTED);
        $request->setReviewedAt(new \DateTime());
        $request->setReviewedBy($reviewer);
        $request->setReviewNotes($notes);

        $this->em->flush();

        $this->notifyRequester($request, 'Rejected', 'Your repositioning request was rejected. Reason: ' . $notes);
    }

    public function completeRequest(RepositioningRequest $request, User $completedBy): void
    {
        if ($request->getStatus() !== RepositioningRequestStatus::IN_TRANSIT) {
            throw new \InvalidArgumentException('Only in-transit requests can be marked completed.');
        }

        foreach ($request->getItems() as $item) {
            $this->containerStatusService->changeStatus(
                $item->getContainer(),
                ContainerStatus::AT_TERMINAL,
                $completedBy,
                'Arrived at port via ' . $request->getRequestNumber()
            );
        }

        $request->setStatus(RepositioningRequestStatus::COMPLETED);
        $request->setCompletedAt(new \DateTime());

        $this->em->flush();

        $this->notifyRequester($request, 'Completed', 'Containers have arrived at ' . $request->getDestinationTerminal()->getName() . '.');
    }

    /**
     * @return Terminal[]
     */
    public function getCyTerminalsForShippingLine(ShippingLine $shippingLine): array
    {
        return $this->em->getRepository(Terminal::class)
            ->createQueryBuilder('t')
            ->join('App\Entity\ShippingLineTerminalAllocation', 'a', 'WITH', 'a.terminal = t')
            ->where('a.shippingLine = :sl')
            ->andWhere('t.type = :cy')
            ->andWhere('t.isActive = true')
            ->setParameter('sl', $shippingLine)
            ->setParameter('cy', TerminalType::CY)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Terminal[]
     */
    public function getPortTerminals(): array
    {
        return $this->terminalRepository->findActivePorts();
    }

    /** @return int[] */
    private function getContainerIdsInActiveRequests(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(i.container) as cid')
            ->from(RepositioningRequestItem::class, 'i')
            ->join('i.request', 'r')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', self::ACTIVE_STATUSES)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'cid'));
    }

    private function notifyPendingReview(RepositioningRequest $request): void
    {
        try {
            $staff = $this->em->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role IN (:roles)')
                ->setParameter('roles', [
                    \App\Entity\Enum\UserRole::SL_STAFF,
                    \App\Entity\Enum\UserRole::TERMINAL_TEAM,
                ])
                ->getQuery()
                ->getResult();

            foreach ($staff as $user) {
                $this->notificationService->createNotification(
                    $user,
                    'New Repositioning Request',
                    sprintf(
                        '%s requested %d container(s) from %s to %s (%s).',
                        $request->getRequestNumber(),
                        $request->getContainerCount(),
                        $request->getSourceTerminal()->getName(),
                        $request->getDestinationTerminal()->getName(),
                        $request->getRequestType()->label()
                    ),
                    'repositioning_request',
                    ['request_id' => $request->getId()]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to notify repositioning request', ['error' => $e->getMessage()]);
        }
    }

    private function notifyRequester(RepositioningRequest $request, string $title, string $message): void
    {
        try {
            $this->notificationService->createNotification(
                $request->getRequestedBy(),
                'Repositioning Request ' . $title,
                $message,
                'repositioning_request',
                ['request_id' => $request->getId()]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to notify requester', ['error' => $e->getMessage()]);
        }
    }
}
