<?php

namespace App\Controller\Api;

use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Exception\ValidationException;
use App\Service\ShippingLineService;
use App\Service\ActivityLogService;
use App\Service\ScopeAccessControlService;
use App\Service\ValidationService;
use App\Service\ErrorRecoveryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/shipping-lines', name: 'api_shipping_lines_')]
class ShippingLineApiController extends BaseApiController
{
    public function __construct(
        protected \App\Service\JwtService $jwtService,
        protected \App\Service\UserService $userService,
        private ShippingLineService $shippingLineService,
        private ActivityLogService $activityLogService,
        private ScopeAccessControlService $scopeAccessControlService,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private ValidationService $validationService,
        private ErrorRecoveryService $errorRecoveryService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SYSTEM_ADMIN can list all shipping lines
        $roleError = $this->requireRole($user, [UserRole::SYSTEM_ADMIN->value]);
        if ($roleError) {
            return $roleError;
        }

        try {
            $shippingLines = $this->shippingLineService->getActiveShippingLines();
            
            $result = [];
            foreach ($shippingLines as $shippingLine) {
                $result[] = $this->serializeShippingLine($shippingLine);
            }

            // Log the activity
            $this->activityLogService->logView($user, (object)['type' => 'shipping_lines_list']);

            return $this->jsonResponse([
                'shipping_lines' => $result,
                'total' => count($result)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve shipping lines: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return $this->errorResponse('Shipping line not found', 404);
            }

            // Check access permissions
            if (!$this->canAccessShippingLine($user, $shippingLine)) {
                return $this->errorResponse('Access denied', 403);
            }

            // Log the activity
            $this->activityLogService->logView($user, $shippingLine);

            return $this->jsonResponse([
                'shipping_line' => $this->serializeShippingLine($shippingLine, true)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve shipping line: ' . $e->getMessage(), 500);
        }
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SYSTEM_ADMIN can create shipping lines
        $roleError = $this->requireRole($user, [UserRole::SYSTEM_ADMIN->value]);
        if ($roleError) {
            return $roleError;
        }

        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                return $this->errorResponse('Invalid JSON data', 400);
            }

            // Convert API format to service format
            $serviceData = [
                'brandName' => $data['brand_name'] ?? null,
                'portalConfig' => $data['portal_config'] ?? null,
                'initialAdminId' => $data['initial_admin_id'] ?? null
            ];

            $shippingLine = $this->shippingLineService->createShippingLine($serviceData, $user);

            return $this->jsonResponse([
                'message' => 'Shipping line created successfully',
                'shipping_line' => $this->serializeShippingLine($shippingLine, true)
            ], 201);

        } catch (ValidationException $e) {
            return $this->jsonResponse([
                'error' => 'validation_failed',
                'message' => 'Input validation failed',
                'details' => $e->getErrors()
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            // Check if this is a recoverable error with degraded mode
            if (str_contains($e->getMessage(), 'Recovery actions:')) {
                return $this->jsonResponse([
                    'error' => 'partial_success',
                    'message' => $e->getMessage()
                ], 206); // 206 Partial Content
            }

            return $this->errorResponse('Failed to create shipping line: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return $this->errorResponse('Shipping line not found', 404);
            }

            // Check access permissions
            if (!$this->canModifyShippingLine($user, $shippingLine)) {
                return $this->errorResponse('Access denied', 403);
            }

            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                return $this->errorResponse('Invalid JSON data', 400);
            }

            // Validate update data
            $validationErrors = $this->validateUpdateData($data, $shippingLine);
            if (!empty($validationErrors)) {
                return $this->jsonResponse([
                    'error' => 'validation_failed',
                    'message' => 'Input validation failed',
                    'details' => $validationErrors
                ], 400);
            }

            // Store old values for audit logging
            $oldValues = [
                'brand_name' => $shippingLine->getBrandName(),
                'portal_config' => $shippingLine->getPortalConfig(),
                'is_active' => $shippingLine->isActive()
            ];

            // Update shipping line
            if (isset($data['brand_name'])) {
                $shippingLine->setBrandName($data['brand_name']);
            }
            
            if (isset($data['portal_config'])) {
                $shippingLine->setPortalConfig($data['portal_config']);
            }
            
            if (isset($data['is_active'])) {
                $shippingLine->setIsActive($data['is_active']);
            }

            $this->entityManager->flush();

            // Log the update activity
            $newValues = [
                'brand_name' => $shippingLine->getBrandName(),
                'portal_config' => $shippingLine->getPortalConfig(),
                'is_active' => $shippingLine->isActive()
            ];
            
            $this->activityLogService->logUpdate($user, $shippingLine, [
                'old_values' => $oldValues,
                'new_values' => $newValues
            ]);

            return $this->jsonResponse([
                'message' => 'Shipping line updated successfully',
                'shipping_line' => $this->serializeShippingLine($shippingLine, true)
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update shipping line: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SYSTEM_ADMIN can delete shipping lines
        $roleError = $this->requireRole($user, [UserRole::SYSTEM_ADMIN->value]);
        if ($roleError) {
            return $roleError;
        }

        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return $this->errorResponse('Shipping line not found', 404);
            }

            // Check if shipping line has active users
            if ($shippingLine->hasActiveAdmins()) {
                return $this->errorResponse('Cannot delete shipping line with active administrators', 400);
            }

            // Deactivate instead of hard delete for audit purposes
            $this->shippingLineService->deactivateShippingLine($shippingLine, $user);

            return $this->jsonResponse([
                'message' => 'Shipping line deactivated successfully'
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete shipping line: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}/statistics', name: 'statistics', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function statistics(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return $this->errorResponse('Shipping line not found', 404);
            }

            // Check access permissions
            if (!$this->canAccessShippingLine($user, $shippingLine)) {
                return $this->errorResponse('Access denied', 403);
            }

            $statistics = [
                'total_admins' => $shippingLine->getShippingLineAdmins()->count(),
                'active_admins' => 0,
                'total_users' => count($shippingLine->getScopedUsers()),
                'created_at' => $shippingLine->getCreatedAt()->format('Y-m-d H:i:s'),
                'is_active' => $shippingLine->isActive()
            ];

            // Count active admins
            foreach ($shippingLine->getShippingLineAdmins() as $admin) {
                if ($admin->isActive()) {
                    $statistics['active_admins']++;
                }
            }

            // Log the activity
            $this->activityLogService->logView($user, (object)[
                'type' => 'shipping_line_statistics',
                'shipping_line_id' => $shippingLine->getId()
            ]);

            return $this->jsonResponse([
                'statistics' => $statistics
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve statistics: ' . $e->getMessage(), 500);
        }
    }

    private function serializeShippingLine(ShippingLine $shippingLine, bool $includeDetails = false): array
    {
        $data = [
            'id' => $shippingLine->getId(),
            'brand_name' => $shippingLine->getBrandName(),
            'is_active' => $shippingLine->isActive(),
            'created_at' => $shippingLine->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $shippingLine->getUpdatedAt()->format('Y-m-d H:i:s')
        ];

        if ($includeDetails) {
            $data['portal_config'] = $shippingLine->getPortalConfig();
            $data['admins_count'] = $shippingLine->getShippingLineAdmins()->count();
            $data['has_active_admins'] = $shippingLine->hasActiveAdmins();
        }

        return $data;
    }

    private function canAccessShippingLine(\App\Entity\User $user, ShippingLine $shippingLine): bool
    {
        // SYSTEM_ADMIN can access all shipping lines
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // Users can only access their own shipping line
        $userScope = $this->shippingLineService->getShippingLineScope($user);
        return $userScope && $userScope->getId() === $shippingLine->getId();
    }

    private function canModifyShippingLine(\App\Entity\User $user, ShippingLine $shippingLine): bool
    {
        // Only SYSTEM_ADMIN and SHIPPING_LINES_ADMIN can modify shipping lines
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $userScope = $this->shippingLineService->getShippingLineScope($user);
            return $userScope && $userScope->getId() === $shippingLine->getId();
        }

        return false;
    }

    private function validateCreateData(array $data): array
    {
        $errors = [];

        if (!isset($data['brand_name']) || empty(trim($data['brand_name']))) {
            $errors['brand_name'] = 'Brand name is required';
        } elseif (strlen($data['brand_name']) < 2) {
            $errors['brand_name'] = 'Brand name must be at least 2 characters long';
        } elseif (strlen($data['brand_name']) > 255) {
            $errors['brand_name'] = 'Brand name cannot be longer than 255 characters';
        } else {
            // Check for duplicate brand name
            $existing = $this->shippingLineService->findByBrandName($data['brand_name']);
            if ($existing) {
                $errors['brand_name'] = 'Brand name already exists';
            }
        }

        if (isset($data['portal_config']) && !is_array($data['portal_config'])) {
            $errors['portal_config'] = 'Portal config must be an object';
        }

        return $errors;
    }

    private function validateUpdateData(array $data, ShippingLine $shippingLine): array
    {
        $errors = [];

        if (isset($data['brand_name'])) {
            if (empty(trim($data['brand_name']))) {
                $errors['brand_name'] = 'Brand name cannot be empty';
            } elseif (strlen($data['brand_name']) < 2) {
                $errors['brand_name'] = 'Brand name must be at least 2 characters long';
            } elseif (strlen($data['brand_name']) > 255) {
                $errors['brand_name'] = 'Brand name cannot be longer than 255 characters';
            } else {
                // Check for duplicate brand name (excluding current shipping line)
                $existing = $this->shippingLineService->findByBrandName($data['brand_name']);
                if ($existing && $existing->getId() !== $shippingLine->getId()) {
                    $errors['brand_name'] = 'Brand name already exists';
                }
            }
        }

        if (isset($data['portal_config']) && !is_array($data['portal_config'])) {
            $errors['portal_config'] = 'Portal config must be an object';
        }

        if (isset($data['is_active']) && !is_bool($data['is_active'])) {
            $errors['is_active'] = 'is_active must be a boolean value';
        }

        return $errors;
    }
}