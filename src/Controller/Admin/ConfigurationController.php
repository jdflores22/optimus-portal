<?php

namespace App\Controller\Admin;

use App\Service\ConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Controller for System Configuration Management
 * Task 20.1: Create admin interface for managing configuration
 * 
 * Role-based access:
 * - SYSTEM_ADMIN: Full access to all configurations
 * - SL_STAFF: Can update eDO validity period
 * - ACCOUNTING: Can update per-day rate for expired eDOs
 */
#[Route('/admin/configuration')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ConfigurationController extends AbstractController
{
    public function __construct(
        private ConfigurationService $configService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'admin_configuration_index', methods: ['GET'])]
    public function index(): Response
    {
        // Check if user has any configuration management role
        if (!$this->isGranted('ROLE_SYSTEM_ADMIN') && 
            !$this->isGranted('ROLE_SL_STAFF') && 
            !$this->isGranted('ROLE_ACCOUNTING')) {
            throw $this->createAccessDeniedException('You do not have permission to access configuration management.');
        }
        
        $configurations = $this->configService->getAllActive();
        
        // Group configurations by category
        $grouped = [
            'edo' => [],
            'cy' => [],
            'other' => []
        ];
        
        foreach ($configurations as $config) {
            $key = $config->getConfigKey();
            if (str_starts_with($key, 'edo.')) {
                $grouped['edo'][] = $config;
            } elseif (str_starts_with($key, 'cy.')) {
                $grouped['cy'][] = $config;
            } else {
                $grouped['other'][] = $config;
            }
        }
        
        // Pass role permissions to template
        $permissions = [
            'can_edit_validity_period' => $this->isGranted('ROLE_SL_STAFF') || $this->isGranted('ROLE_SYSTEM_ADMIN'),
            'can_edit_per_day_rate' => $this->isGranted('ROLE_ACCOUNTING') || $this->isGranted('ROLE_SYSTEM_ADMIN'),
            'can_edit_cy_locations' => $this->isGranted('ROLE_SYSTEM_ADMIN'),
            'is_system_admin' => $this->isGranted('ROLE_SYSTEM_ADMIN'),
        ];
        
        return $this->render('admin/configuration/index.html.twig', [
            'configurations' => $grouped,
            'cyLocations' => $this->configService->getCYLocations(),
            'permissions' => $permissions,
        ]);
    }

    #[Route('/edo/update', name: 'admin_configuration_edo_update', methods: ['POST'])]
    public function updateEDOConfig(Request $request): Response
    {
        try {
            $validityDays = $request->request->get('validity_days');
            $perDayRate = $request->request->get('per_day_rate');
            $reason = $request->request->get('reason');
            
            $user = $this->getUser();
            
            // Role-based access control for eDO configuration
            // SL_STAFF can update validity period
            // ACCOUNTING can update per-day rate
            
            if ($validityDays !== null) {
                if (!$this->isGranted('ROLE_SL_STAFF')) {
                    throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException(
                        'Only SL_STAFF can update eDO validity period'
                    );
                }
                
                $this->configService->set(
                    'edo.validity_period_days',
                    (int) $validityDays,
                    'integer',
                    $user,
                    $reason ?? 'Updated eDO validity period'
                );
            }
            
            if ($perDayRate !== null) {
                if (!$this->isGranted('ROLE_ACCOUNTING')) {
                    throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException(
                        'Only ACCOUNTING can update per-day rate for expired eDOs'
                    );
                }
                
                $this->configService->set(
                    'edo.expired_per_day_rate',
                    (float) $perDayRate,
                    'float',
                    $user,
                    $reason ?? 'Updated per-day rate for expired eDOs'
                );
            }
            
            $this->addFlash('success', 'eDO configuration updated successfully.');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update eDO configuration: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_configuration_index');
    }

    #[Route('/cy/update', name: 'admin_configuration_cy_update', methods: ['POST'])]
    public function updateCYConfig(Request $request): Response
    {
        try {
            $locations = $request->request->all('cy_locations');
            $reason = $request->request->get('reason');
            
            // Convert to proper format
            $cyData = [];
            foreach ($locations as $location => $capacity) {
                if (!empty($location) && is_numeric($capacity)) {
                    $cyData[$location] = (float) $capacity;
                }
            }
            
            $user = $this->getUser();
            
            $this->configService->set(
                'cy.locations',
                $cyData,
                'json',
                $user,
                $reason
            );
            
            $this->addFlash('success', 'CY locations updated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update CY locations: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_configuration_index');
    }

    #[Route('/cy/add', name: 'admin_configuration_cy_add', methods: ['POST'])]
    public function addCYLocation(Request $request): Response
    {
        try {
            $newLocation = $request->request->get('new_location');
            $newCapacity = (float) $request->request->get('new_capacity');
            $reason = $request->request->get('reason');
            
            if (empty($newLocation)) {
                throw new \InvalidArgumentException('Location name cannot be empty');
            }
            
            $currentLocations = $this->configService->getCYLocations();
            
            if (isset($currentLocations[$newLocation])) {
                throw new \InvalidArgumentException('Location already exists');
            }
            
            $currentLocations[$newLocation] = $newCapacity;
            
            $user = $this->getUser();
            
            $this->configService->set(
                'cy.locations',
                $currentLocations,
                'json',
                $user,
                $reason ?? "Added new CY location: $newLocation"
            );
            
            $this->addFlash('success', "CY location '$newLocation' added successfully.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to add CY location: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_configuration_index');
    }

    #[Route('/cy/remove/{location}', name: 'admin_configuration_cy_remove', methods: ['POST'])]
    public function removeCYLocation(string $location, Request $request): Response
    {
        try {
            $reason = $request->request->get('reason');
            
            $currentLocations = $this->configService->getCYLocations();
            
            if (!isset($currentLocations[$location])) {
                throw new \InvalidArgumentException('Location does not exist');
            }
            
            unset($currentLocations[$location]);
            
            $user = $this->getUser();
            
            $this->configService->set(
                'cy.locations',
                $currentLocations,
                'json',
                $user,
                $reason ?? "Removed CY location: $location"
            );
            
            $this->addFlash('success', "CY location '$location' removed successfully.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to remove CY location: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_configuration_index');
    }

    #[Route('/history/{key}', name: 'admin_configuration_history', methods: ['GET'])]
    public function history(string $key): Response
    {
        $history = $this->configService->getHistory($key);
        
        return $this->render('admin/configuration/history.html.twig', [
            'configKey' => $key,
            'history' => $history,
        ]);
    }

    #[Route('/cache/clear', name: 'admin_configuration_cache_clear', methods: ['POST'])]
    public function clearCache(): Response
    {
        try {
            $this->configService->clearCache();
            $this->addFlash('success', 'Configuration cache cleared successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to clear cache: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_configuration_index');
    }
}
