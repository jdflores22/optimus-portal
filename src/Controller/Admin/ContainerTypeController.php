<?php

namespace App\Controller\Admin;

use App\Entity\ContainerType;
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

#[Route('/admin/container-types')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class ContainerTypeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogService $activityLogService,
        private CacheInterface $cache
    ) {}

    #[Route('', name: 'admin_container_types_index', methods: ['GET'])]
    public function index(): Response
    {
        $containerTypes = $this->entityManager
            ->getRepository(ContainerType::class)
            ->findAllOrdered();

        return $this->render('admin/container_types/index.html.twig', [
            'containerTypes' => $containerTypes
        ]);
    }

    #[Route('/new', name: 'admin_container_types_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('admin/container_types/new.html.twig');
    }

    #[Route('/store', name: 'admin_container_types_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        try {
            $name = $request->request->get('name');
            $code = $request->request->get('code');
            $description = $request->request->get('description');

            // Validation
            if (empty($name) || empty($code)) {
                $this->addFlash('error', 'Name and code are required');
                return $this->redirectToRoute('admin_container_types_new');
            }

            // Check for duplicate code
            $existingContainerType = $this->entityManager
                ->getRepository(ContainerType::class)
                ->findByCode($code);

            if ($existingContainerType) {
                if (!$existingContainerType->isActive()) {
                    $this->addFlash('error', 'A container type with this code already exists but is inactive. Please reactivate it instead.');
                } else {
                    $this->addFlash('error', 'This code already exists');
                }
                return $this->redirectToRoute('admin_container_types_new');
            }

            // Create container type
            $containerType = new ContainerType();
            $containerType->setName($name);
            $containerType->setCode($code);
            $containerType->setDescription($description);
            $containerType->setIsActive(true);

            $this->entityManager->persist($containerType);
            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                ActivityLog::TYPE_CONTAINER_TYPE_CREATED,
                'ContainerType',
                $containerType->getId(),
                null,
                [
                    'name' => $containerType->getName(),
                    'code' => $containerType->getCode(),
                    'description' => $containerType->getDescription()
                ]
            );

            // Invalidate cache
            $this->invalidateCache();

            $this->addFlash('success', 'Container type created successfully');
            return $this->redirectToRoute('admin_container_types_index');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create container type: ' . $e->getMessage());
            return $this->redirectToRoute('admin_container_types_new');
        }
    }

    #[Route('/{id}/edit', name: 'admin_container_types_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $containerType = $this->entityManager
            ->getRepository(ContainerType::class)
            ->find($id);

        if (!$containerType) {
            $this->addFlash('error', 'Container type not found');
            return $this->redirectToRoute('admin_container_types_index');
        }

        return $this->render('admin/container_types/edit.html.twig', [
            'containerType' => $containerType
        ]);
    }

    #[Route('/{id}/update', name: 'admin_container_types_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        try {
            $containerType = $this->entityManager
                ->getRepository(ContainerType::class)
                ->find($id);

            if (!$containerType) {
                $this->addFlash('error', 'Container type not found');
                return $this->redirectToRoute('admin_container_types_index');
            }

            $name = $request->request->get('name');
            $code = $request->request->get('code');
            $description = $request->request->get('description');

            // Validation
            if (empty($name) || empty($code)) {
                $this->addFlash('error', 'Name and code are required');
                return $this->redirectToRoute('admin_container_types_edit', ['id' => $id]);
            }

            // Check for duplicate code (excluding current entity)
            $existingContainerType = $this->entityManager
                ->getRepository(ContainerType::class)
                ->findByCode($code);

            if ($existingContainerType && $existingContainerType->getId() !== $containerType->getId()) {
                $this->addFlash('error', 'This code already exists');
                return $this->redirectToRoute('admin_container_types_edit', ['id' => $id]);
            }

            // Store old values for logging
            $oldValues = [
                'name' => $containerType->getName(),
                'code' => $containerType->getCode(),
                'description' => $containerType->getDescription()
            ];

            // Update container type
            $containerType->setName($name);
            $containerType->setCode($code);
            $containerType->setDescription($description);
            $containerType->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                ActivityLog::TYPE_CONTAINER_TYPE_UPDATED,
                'ContainerType',
                $containerType->getId(),
                $oldValues,
                [
                    'name' => $containerType->getName(),
                    'code' => $containerType->getCode(),
                    'description' => $containerType->getDescription()
                ]
            );

            // Invalidate cache
            $this->invalidateCache();

            $this->addFlash('success', 'Container type updated successfully');
            return $this->redirectToRoute('admin_container_types_index');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update container type: ' . $e->getMessage());
            return $this->redirectToRoute('admin_container_types_edit', ['id' => $id]);
        }
    }

    #[Route('/{id}/toggle-status', name: 'admin_container_types_toggle_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $containerType = $this->entityManager
                ->getRepository(ContainerType::class)
                ->find($id);

            if (!$containerType) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Container type not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $oldStatus = $containerType->isActive();
            $containerType->setIsActive(!$containerType->isActive());
            $containerType->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            // Log activity
            $activityType = $containerType->isActive() 
                ? ActivityLog::TYPE_CONTAINER_TYPE_CREATED 
                : ActivityLog::TYPE_CONTAINER_TYPE_DELETED;

            $this->activityLogService->logActivity(
                $this->getUser(),
                $activityType,
                'ContainerType',
                $containerType->getId(),
                ['is_active' => $oldStatus],
                ['is_active' => $containerType->isActive()],
                ['container_type_name' => $containerType->getName()]
            );

            // Invalidate cache
            $this->invalidateCache();

            return new JsonResponse([
                'success' => true,
                'message' => 'Container type status updated successfully',
                'isActive' => $containerType->isActive()
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to update container type status: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function invalidateCache(): void
    {
        $this->cache->delete('container_types.active');
    }
}
