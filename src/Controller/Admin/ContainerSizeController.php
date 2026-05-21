<?php

namespace App\Controller\Admin;

use App\Entity\ContainerSize;
use App\Entity\ActivityLog;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/admin/container-sizes')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class ContainerSizeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogService $activityLogService,
        private CacheInterface $cache
    ) {}

    #[Route('', name: 'admin_container_sizes_index', methods: ['GET'])]
    public function index(): Response
    {
        $containerSizes = $this->entityManager
            ->getRepository(ContainerSize::class)
            ->findAllOrdered();

        return $this->render('admin/container_sizes/index.html.twig', [
            'containerSizes' => $containerSizes
        ]);
    }

    #[Route('/new', name: 'admin_container_sizes_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('admin/container_sizes/new.html.twig');
    }

    #[Route('/store', name: 'admin_container_sizes_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        try {
            $name = $request->request->get('name');
            $code = $request->request->get('code');
            $description = $request->request->get('description');

            // Validation
            if (empty($name) || empty($code)) {
                $this->addFlash('error', 'Name and code are required');
                return $this->redirectToRoute('admin_container_sizes_new');
            }

            // Check for duplicate code
            $existingContainerSize = $this->entityManager
                ->getRepository(ContainerSize::class)
                ->findByCode($code);

            if ($existingContainerSize) {
                if (!$existingContainerSize->isActive()) {
                    $this->addFlash('error', 'A container size with this code already exists but is inactive. Please reactivate it instead.');
                } else {
                    $this->addFlash('error', 'This code already exists');
                }
                return $this->redirectToRoute('admin_container_sizes_new');
            }

            // Create container size
            $containerSize = new ContainerSize();
            $containerSize->setName($name);
            $containerSize->setCode($code);
            $containerSize->setDescription($description);
            $containerSize->setIsActive(true);

            $this->entityManager->persist($containerSize);
            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                ActivityLog::TYPE_CONTAINER_SIZE_CREATED,
                'ContainerSize',
                $containerSize->getId(),
                null,
                [
                    'name' => $containerSize->getName(),
                    'code' => $containerSize->getCode(),
                    'description' => $containerSize->getDescription()
                ]
            );

            // Invalidate cache
            $this->invalidateCache();

            $this->addFlash('success', 'Container size created successfully');
            return $this->redirectToRoute('admin_container_sizes_index');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create container size: ' . $e->getMessage());
            return $this->redirectToRoute('admin_container_sizes_new');
        }
    }

    #[Route('/{id}/edit', name: 'admin_container_sizes_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $containerSize = $this->entityManager
            ->getRepository(ContainerSize::class)
            ->find($id);

        if (!$containerSize) {
            $this->addFlash('error', 'Container size not found');
            return $this->redirectToRoute('admin_container_sizes_index');
        }

        return $this->render('admin/container_sizes/edit.html.twig', [
            'containerSize' => $containerSize
        ]);
    }

    #[Route('/{id}/update', name: 'admin_container_sizes_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        try {
            $containerSize = $this->entityManager
                ->getRepository(ContainerSize::class)
                ->find($id);

            if (!$containerSize) {
                $this->addFlash('error', 'Container size not found');
                return $this->redirectToRoute('admin_container_sizes_index');
            }

            $name = $request->request->get('name');
            $code = $request->request->get('code');
            $description = $request->request->get('description');

            // Validation
            if (empty($name) || empty($code)) {
                $this->addFlash('error', 'Name and code are required');
                return $this->redirectToRoute('admin_container_sizes_edit', ['id' => $id]);
            }

            // Check for duplicate code (excluding current entity)
            $existingContainerSize = $this->entityManager
                ->getRepository(ContainerSize::class)
                ->findByCode($code);

            if ($existingContainerSize && $existingContainerSize->getId() !== $containerSize->getId()) {
                $this->addFlash('error', 'This code already exists');
                return $this->redirectToRoute('admin_container_sizes_edit', ['id' => $id]);
            }

            // Store old values for logging
            $oldValues = [
                'name' => $containerSize->getName(),
                'code' => $containerSize->getCode(),
                'description' => $containerSize->getDescription()
            ];

            // Update container size
            $containerSize->setName($name);
            $containerSize->setCode($code);
            $containerSize->setDescription($description);
            $containerSize->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                ActivityLog::TYPE_CONTAINER_SIZE_UPDATED,
                'ContainerSize',
                $containerSize->getId(),
                $oldValues,
                [
                    'name' => $containerSize->getName(),
                    'code' => $containerSize->getCode(),
                    'description' => $containerSize->getDescription()
                ]
            );

            // Invalidate cache
            $this->invalidateCache();

            $this->addFlash('success', 'Container size updated successfully');
            return $this->redirectToRoute('admin_container_sizes_index');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update container size: ' . $e->getMessage());
            return $this->redirectToRoute('admin_container_sizes_edit', ['id' => $id]);
        }
    }

    #[Route('/{id}/toggle-status', name: 'admin_container_sizes_toggle_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $containerSize = $this->entityManager
                ->getRepository(ContainerSize::class)
                ->find($id);

            if (!$containerSize) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Container size not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $oldStatus = $containerSize->isActive();
            $containerSize->setIsActive(!$containerSize->isActive());
            $containerSize->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            // Log activity
            $activityType = $containerSize->isActive() 
                ? ActivityLog::TYPE_CONTAINER_SIZE_CREATED 
                : ActivityLog::TYPE_CONTAINER_SIZE_DELETED;

            $this->activityLogService->logActivity(
                $this->getUser(),
                $activityType,
                'ContainerSize',
                $containerSize->getId(),
                ['is_active' => $oldStatus],
                ['is_active' => $containerSize->isActive()],
                ['container_size_name' => $containerSize->getName()]
            );

            // Invalidate cache
            $this->invalidateCache();

            return new JsonResponse([
                'success' => true,
                'message' => 'Container size status updated successfully',
                'isActive' => $containerSize->isActive()
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to update container size status: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function invalidateCache(): void
    {
        $this->cache->delete('container_sizes.active');
    }
}
