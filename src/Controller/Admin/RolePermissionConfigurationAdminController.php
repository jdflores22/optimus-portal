<?php

namespace App\Controller\Admin;

use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Service\RolePermissionConfigurationService;
use App\Service\ScopeAccessControlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/role-permissions')]
#[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
class RolePermissionConfigurationAdminController extends AbstractController
{
    private RolePermissionConfigurationService $permissionService;
    private ScopeAccessControlService $scopeAccessControlService;

    public function __construct(
        RolePermissionConfigurationService $permissionService,
        ScopeAccessControlService $scopeAccessControlService
    ) {
        $this->permissionService = $permissionService;
        $this->scopeAccessControlService = $scopeAccessControlService;
    }

    #[Route('/{id}', name: 'admin_role_permissions_index', methods: ['GET'])]
    public function index(ShippingLine $shippingLine): Response
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);
        
        $rolePermissions = $this->permissionService->getAllRolePermissions($shippingLine);
        $availablePermissions = $this->permissionService->getAvailablePermissions();
        
        // Get hierarchical roles that can be configured
        $configurableRoles = [
            UserRole::SHIPPING_LINES_ADMIN,
            UserRole::SL_STAFF,
            UserRole::EVALUATOR,
            UserRole::ACCOUNTING,
            UserRole::TERMINAL_TEAM,
        ];

        return $this->render('admin/role_permissions/index.html.twig', [
            'shipping_line' => $shippingLine,
            'role_permissions' => $rolePermissions,
            'available_permissions' => $availablePermissions,
            'configurable_roles' => $configurableRoles,
        ]);
    }

    #[Route('/{id}/configure/{role}', name: 'admin_role_permissions_configure', methods: ['GET', 'POST'])]
    public function configure(ShippingLine $shippingLine, string $role, Request $request): Response
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        try {
            $userRole = UserRole::from($role);
        } catch (\ValueError $e) {
            throw $this->createNotFoundException('Invalid role');
        }

        $currentConfig = $this->permissionService->getRolePermissions($shippingLine, $userRole);
        $availablePermissions = $this->permissionService->getAvailablePermissions();
        $defaultPermissions = $this->permissionService->getDefaultPermissions($userRole);

        if ($request->isMethod('POST')) {
            try {
                $permissions = $request->request->all('permissions') ?? [];
                $inheritFromParent = $request->request->getBoolean('inherit_from_parent', false);
                
                // Handle restrictions
                $restrictions = [];
                if ($request->request->get('time_restrictions_enabled')) {
                    $restrictions['time_restrictions'] = [
                        'allowed_hours' => [
                            'start' => $request->request->get('allowed_hours_start'),
                            'end' => $request->request->get('allowed_hours_end'),
                        ],
                        'allowed_days' => $request->request->all('allowed_days') ?? [],
                    ];
                }

                if ($request->request->get('ip_restrictions_enabled')) {
                    $allowedIps = $request->request->get('allowed_ips');
                    if ($allowedIps) {
                        $restrictions['ip_restrictions'] = [
                            'allowed_ips' => array_map('trim', explode(',', $allowedIps)),
                        ];
                    }
                }

                $this->permissionService->setRolePermissions(
                    $shippingLine,
                    $userRole,
                    $permissions,
                    $this->getUser(),
                    $restrictions ?: null,
                    $inheritFromParent
                );

                $this->addFlash('success', "Permissions for {$userRole->value} role updated successfully.");
                return $this->redirectToRoute('admin_role_permissions_index', ['id' => $shippingLine->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to update permissions: ' . $e->getMessage());
            }
        }

        return $this->render('admin/role_permissions/configure.html.twig', [
            'shipping_line' => $shippingLine,
            'role' => $userRole,
            'current_config' => $currentConfig,
            'available_permissions' => $availablePermissions,
            'default_permissions' => $defaultPermissions,
        ]);
    }

    #[Route('/{id}/reset/{role}', name: 'admin_role_permissions_reset', methods: ['POST'])]
    public function reset(ShippingLine $shippingLine, string $role, Request $request): JsonResponse
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        if (!$this->isCsrfTokenValid('reset_permissions', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 400);
        }

        try {
            $userRole = UserRole::from($role);
            $defaultPermissions = $this->permissionService->getDefaultPermissions($userRole);

            $this->permissionService->setRolePermissions(
                $shippingLine,
                $userRole,
                $defaultPermissions,
                $this->getUser()
            );

            return new JsonResponse([
                'success' => true,
                'message' => "Permissions for {$userRole->value} role reset to defaults"
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/{id}/delete/{role}', name: 'admin_role_permissions_delete', methods: ['POST'])]
    public function delete(ShippingLine $shippingLine, string $role, Request $request): JsonResponse
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        if (!$this->isCsrfTokenValid('delete_permissions', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 400);
        }

        try {
            $userRole = UserRole::from($role);
            $this->permissionService->deleteRolePermissions($shippingLine, $userRole, $this->getUser());

            return new JsonResponse([
                'success' => true,
                'message' => "Custom permissions for {$userRole->value} role deleted"
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/{id}/check-permission', name: 'api_check_user_permission', methods: ['POST'])]
    public function checkPermission(ShippingLine $shippingLine, Request $request): JsonResponse
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        try {
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON format');
            }

            $permission = $data['permission'] ?? null;
            if (!$permission) {
                throw new \InvalidArgumentException('Permission is required');
            }

            $hasPermission = $this->permissionService->hasPermission($this->getUser(), $permission);

            return new JsonResponse([
                'success' => true,
                'has_permission' => $hasPermission,
                'permission' => $permission
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/{id}/effective-permissions/{role}', name: 'api_get_effective_permissions', methods: ['GET'])]
    public function getEffectivePermissions(ShippingLine $shippingLine, string $role): JsonResponse
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        try {
            $userRole = UserRole::from($role);
            $roleConfig = $this->permissionService->getRolePermissions($shippingLine, $userRole);
            
            $effectivePermissions = [];
            if ($roleConfig) {
                $effectivePermissions = $roleConfig->getEffectivePermissions();
            } else {
                $effectivePermissions = $this->permissionService->getDefaultPermissions($userRole);
            }

            return new JsonResponse([
                'success' => true,
                'role' => $userRole->value,
                'effective_permissions' => $effectivePermissions,
                'has_custom_config' => $roleConfig !== null
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/available-permissions', name: 'api_get_available_permissions', methods: ['GET'])]
    public function getAvailablePermissions(): JsonResponse
    {
        $availablePermissions = $this->permissionService->getAvailablePermissions();
        
        // Group permissions by category for better UI organization
        $groupedPermissions = [];
        foreach ($availablePermissions as $permission) {
            $category = explode('.', $permission)[0];
            $groupedPermissions[$category][] = $permission;
        }

        return new JsonResponse([
            'success' => true,
            'permissions' => $availablePermissions,
            'grouped_permissions' => $groupedPermissions
        ]);
    }
}