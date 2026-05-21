<?php

namespace App\Controller\Admin;

use App\Entity\ShippingLine;
use App\Service\ShippingLineConfigurationService;
use App\Service\ScopeAccessControlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/shipping-line-configuration')]
#[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
class ShippingLineConfigurationAdminController extends AbstractController
{
    private ShippingLineConfigurationService $configurationService;
    private ScopeAccessControlService $scopeAccessControlService;

    public function __construct(
        ShippingLineConfigurationService $configurationService,
        ScopeAccessControlService $scopeAccessControlService
    ) {
        $this->configurationService = $configurationService;
        $this->scopeAccessControlService = $scopeAccessControlService;
    }

    #[Route('/{id}', name: 'admin_shipping_line_configuration_index', methods: ['GET'])]
    public function index(ShippingLine $shippingLine): Response
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);
        
        $configurations = $this->configurationService->getAllConfigurations($shippingLine);
        
        return $this->render('admin/shipping_line_configuration/index.html.twig', [
            'shipping_line' => $shippingLine,
            'configurations' => $configurations,
        ]);
    }

    #[Route('/{id}/create', name: 'admin_shipping_line_configuration_create', methods: ['GET', 'POST'])]
    public function create(ShippingLine $shippingLine, Request $request): Response
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        if ($request->isMethod('POST')) {
            try {
                $configKey = $request->request->get('config_key');
                $configValue = json_decode($request->request->get('config_value'), true);
                $description = $request->request->get('description');

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON format for configuration value');
                }

                $configuration = $this->configurationService->setConfiguration(
                    $shippingLine,
                    $configKey,
                    $configValue,
                    $this->getUser(),
                    $description
                );

                $this->addFlash('success', 'Configuration created successfully.');
                return $this->redirectToRoute('admin_shipping_line_configuration_index', ['id' => $shippingLine->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to create configuration: ' . $e->getMessage());
            }
        }

        return $this->render('admin/shipping_line_configuration/create.html.twig', [
            'shipping_line' => $shippingLine,
        ]);
    }

    #[Route('/{id}/edit/{configKey}', name: 'admin_shipping_line_configuration_edit', methods: ['GET', 'POST'])]
    public function edit(ShippingLine $shippingLine, string $configKey, Request $request): Response
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);
        
        $configuration = $this->configurationService->getConfiguration($shippingLine, $configKey);
        if (!$configuration) {
            throw $this->createNotFoundException('Configuration not found');
        }

        if ($request->isMethod('POST')) {
            try {
                $configValue = json_decode($request->request->get('config_value'), true);
                $description = $request->request->get('description');

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON format for configuration value');
                }

                $this->configurationService->setConfiguration(
                    $shippingLine,
                    $configKey,
                    $configValue,
                    $this->getUser(),
                    $description
                );

                $this->addFlash('success', 'Configuration updated successfully.');
                return $this->redirectToRoute('admin_shipping_line_configuration_index', ['id' => $shippingLine->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to update configuration: ' . $e->getMessage());
            }
        }

        return $this->render('admin/shipping_line_configuration/edit.html.twig', [
            'shipping_line' => $shippingLine,
            'configuration' => $configuration,
        ]);
    }

    #[Route('/{id}/branding', name: 'admin_shipping_line_branding', methods: ['GET', 'POST'])]
    public function branding(ShippingLine $shippingLine, Request $request): Response
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        $currentBranding = $shippingLine->getPortalConfigValue('branding', []);

        if ($request->isMethod('POST')) {
            try {
                $brandingConfig = [
                    'primaryColor' => $request->request->get('primary_color'),
                    'secondaryColor' => $request->request->get('secondary_color'),
                    'logoUrl' => $request->request->get('logo_url'),
                    'faviconUrl' => $request->request->get('favicon_url'),
                    'companyName' => $request->request->get('company_name'),
                    'tagline' => $request->request->get('tagline'),
                ];

                // Remove empty values
                $brandingConfig = array_filter($brandingConfig, function($value) {
                    return $value !== null && $value !== '';
                });

                $this->configurationService->updateBrandingConfiguration(
                    $shippingLine,
                    $brandingConfig,
                    $this->getUser()
                );

                $this->addFlash('success', 'Branding configuration updated successfully.');
                return $this->redirectToRoute('admin_shipping_line_branding', ['id' => $shippingLine->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to update branding: ' . $e->getMessage());
            }
        }

        return $this->render('admin/shipping_line_configuration/branding.html.twig', [
            'shipping_line' => $shippingLine,
            'branding' => $currentBranding,
        ]);
    }

    #[Route('/{id}/history', name: 'admin_shipping_line_configuration_history', methods: ['GET'])]
    public function history(ShippingLine $shippingLine, Request $request): Response
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        $configType = $request->query->get('config_type');
        $configKey = $request->query->get('config_key');
        $limit = (int) $request->query->get('limit', 50);

        $history = $this->configurationService->getConfigurationHistory(
            $shippingLine,
            $configType,
            $configKey,
            $limit
        );

        return $this->render('admin/shipping_line_configuration/history.html.twig', [
            'shipping_line' => $shippingLine,
            'history' => $history,
            'filters' => [
                'config_type' => $configType,
                'config_key' => $configKey,
                'limit' => $limit,
            ],
        ]);
    }

    #[Route('/{id}/delete/{configKey}', name: 'admin_shipping_line_configuration_delete', methods: ['POST'])]
    public function delete(ShippingLine $shippingLine, string $configKey, Request $request): JsonResponse
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        if (!$this->isCsrfTokenValid('delete_configuration', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 400);
        }

        try {
            $this->configurationService->deleteConfiguration($shippingLine, $configKey, $this->getUser());
            return new JsonResponse(['success' => true, 'message' => 'Configuration deleted successfully']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/{id}/get/{configKey}', name: 'api_shipping_line_configuration_get', methods: ['GET'])]
    public function getConfiguration(ShippingLine $shippingLine, string $configKey): JsonResponse
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        try {
            $configValue = $this->configurationService->getConfigurationValue($shippingLine, $configKey);
            return new JsonResponse([
                'success' => true,
                'config_key' => $configKey,
                'config_value' => $configValue
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/{id}/set', name: 'api_shipping_line_configuration_set', methods: ['POST'])]
    public function setConfiguration(ShippingLine $shippingLine, Request $request): JsonResponse
    {
        $this->scopeAccessControlService->validateAccess($this->getUser(), $shippingLine);

        try {
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON format');
            }

            $configKey = $data['config_key'] ?? null;
            $configValue = $data['config_value'] ?? null;
            $description = $data['description'] ?? null;

            if (!$configKey || !$configValue) {
                throw new \InvalidArgumentException('config_key and config_value are required');
            }

            $configuration = $this->configurationService->setConfiguration(
                $shippingLine,
                $configKey,
                $configValue,
                $this->getUser(),
                $description
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Configuration saved successfully',
                'configuration' => [
                    'id' => $configuration->getId(),
                    'config_key' => $configuration->getConfigKey(),
                    'config_value' => $configuration->getConfigValue(),
                    'description' => $configuration->getDescription(),
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}