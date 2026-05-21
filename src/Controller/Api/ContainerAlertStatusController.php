<?php

namespace App\Controller\Api;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Service\DwellTimeServiceInterface;
use App\Service\JwtService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/containers', name: 'api_container_alert_')]
class ContainerAlertStatusController extends BaseApiController
{
    public function __construct(
        JwtService $jwtService,
        UserService $userService,
        private EntityManagerInterface $entityManager,
        private DwellTimeServiceInterface $dwellTimeService,
        private ValidatorInterface $validator
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/{containerNumber}/alert/pause', name: 'pause', methods: ['POST'])]
    public function pauseAlertStatus(string $containerNumber, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Check authorization - only shipping line admins and terminal team members
        $roleCheck = $this->requireRole($user, [
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::TERMINAL_TEAM->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        // Find container
        $container = $this->entityManager->getRepository(Container::class)
            ->findOneBy(['containerNumber' => $containerNumber]);

        if (!$container) {
            return $this->errorResponse('Container not found', 404);
        }

        // Validate request data
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['reason'])) {
            return $this->errorResponse('Reason is required', 400);
        }

        $reason = trim($data['reason']);
        if (empty($reason)) {
            return $this->errorResponse('Reason cannot be empty', 400);
        }

        try {
            // Change status to ALERT and pause dwell time
            $oldStatus = $container->getStatus();
            $container->setStatus(ContainerStatus::ALERT);
            
            // Handle status change through DwellTimeService
            $this->dwellTimeService->handleStatusChange($container, $oldStatus, ContainerStatus::ALERT, $user);
            
            $this->entityManager->flush();

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Container alert status activated and dwell time paused',
                'data' => [
                    'container_number' => $container->getContainerNumber(),
                    'old_status' => $oldStatus->value,
                    'new_status' => $container->getStatus()->value,
                    'dwell_time_paused_at' => $container->getDwellTimePausedAt()?->format('Y-m-d H:i:s'),
                    'current_dwell_time' => $container->getCurrentDwellTime(),
                    'reason' => $reason,
                    'triggered_by' => $user->getId()
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to pause alert status: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{containerNumber}/alert/resume', name: 'resume', methods: ['POST'])]
    public function resumeAlertStatus(string $containerNumber, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Check authorization - only shipping line admins and terminal team members
        $roleCheck = $this->requireRole($user, [
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::TERMINAL_TEAM->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        // Find container
        $container = $this->entityManager->getRepository(Container::class)
            ->findOneBy(['containerNumber' => $containerNumber]);

        if (!$container) {
            return $this->errorResponse('Container not found', 404);
        }

        // Validate that container is currently in ALERT status
        if ($container->getStatus() !== ContainerStatus::ALERT) {
            return $this->errorResponse('Container is not in alert status', 400);
        }

        // Get target status from request (default to AT_TERMINAL)
        $data = json_decode($request->getContent(), true);
        $targetStatusValue = $data['target_status'] ?? ContainerStatus::AT_TERMINAL->value;
        
        try {
            $targetStatus = ContainerStatus::from($targetStatusValue);
        } catch (\ValueError $e) {
            return $this->errorResponse('Invalid target status', 400);
        }

        // Validate target status is not ALERT
        if ($targetStatus === ContainerStatus::ALERT) {
            return $this->errorResponse('Target status cannot be ALERT', 400);
        }

        try {
            // Change status from ALERT and resume dwell time
            $oldStatus = $container->getStatus();
            $container->setStatus($targetStatus);
            
            // Handle status change through DwellTimeService
            $this->dwellTimeService->handleStatusChange($container, $oldStatus, $targetStatus, $user);
            
            $this->entityManager->flush();

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Container alert status deactivated and dwell time resumed',
                'data' => [
                    'container_number' => $container->getContainerNumber(),
                    'old_status' => $oldStatus->value,
                    'new_status' => $container->getStatus()->value,
                    'dwell_time_resumed_at' => $container->getLastDwellTimeCalculation()?->format('Y-m-d H:i:s'),
                    'current_dwell_time' => $container->getCurrentDwellTime(),
                    'total_paused_days' => $container->getTotalPausedDays(),
                    'triggered_by' => $user->getId()
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to resume alert status: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{containerNumber}/alert/status', name: 'status', methods: ['GET'])]
    public function getAlertStatus(string $containerNumber, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Check authorization - shipping line admins and terminal team members
        $roleCheck = $this->requireRole($user, [
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::TERMINAL_TEAM->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        // Find container
        $container = $this->entityManager->getRepository(Container::class)
            ->findOneBy(['containerNumber' => $containerNumber]);

        if (!$container) {
            return $this->errorResponse('Container not found', 404);
        }

        // Get dwell time history for audit trail
        $dwellTimeHistory = $this->dwellTimeService->getDwellTimeHistory($container);

        return $this->jsonResponse([
            'success' => true,
            'data' => [
                'container_number' => $container->getContainerNumber(),
                'current_status' => $container->getStatus()->value,
                'is_alert_active' => $container->getStatus() === ContainerStatus::ALERT,
                'dwell_time_paused_at' => $container->getDwellTimePausedAt()?->format('Y-m-d H:i:s'),
                'current_dwell_time' => $container->getCurrentDwellTime(),
                'total_paused_days' => $container->getTotalPausedDays(),
                'terminal_arrival_date' => $container->getTerminalArrivalDate()?->format('Y-m-d H:i:s'),
                'next_notification_date' => $container->getNextNotificationDate()?->format('Y-m-d H:i:s'),
                'automatic_return_date' => $container->getAutomaticReturnDate()?->format('Y-m-d H:i:s'),
                'last_calculation' => $container->getLastDwellTimeCalculation()?->format('Y-m-d H:i:s'),
                'dwell_time_history' => $dwellTimeHistory
            ]
        ]);
    }

    #[Route('/alerts', name: 'list', methods: ['GET'])]
    public function listContainersInAlert(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Check authorization - shipping line admins and terminal team members
        $roleCheck = $this->requireRole($user, [
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::TERMINAL_TEAM->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        // Get pagination parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        // Find containers in ALERT status
        $queryBuilder = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->where('c.status = :alertStatus')
            ->setParameter('alertStatus', ContainerStatus::ALERT)
            ->orderBy('c.dwellTimePausedAt', 'DESC');

        // Get total count
        $totalQuery = clone $queryBuilder;
        $total = $totalQuery->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        // Get paginated results
        $containers = $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $containerData = [];
        foreach ($containers as $container) {
            $containerData[] = [
                'container_number' => $container->getContainerNumber(),
                'status' => $container->getStatus()->value,
                'dwell_time_paused_at' => $container->getDwellTimePausedAt()?->format('Y-m-d H:i:s'),
                'current_dwell_time' => $container->getCurrentDwellTime(),
                'total_paused_days' => $container->getTotalPausedDays(),
                'terminal_arrival_date' => $container->getTerminalArrivalDate()?->format('Y-m-d H:i:s'),
                'current_location' => $container->getCurrentLocation()
            ];
        }

        return $this->jsonResponse([
            'success' => true,
            'data' => [
                'containers' => $containerData,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]
        ]);
    }
}