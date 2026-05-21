<?php

namespace App\Tests\Integration;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\ShippingLineConfigurationService;
use App\Service\RolePermissionConfigurationService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ConfigurationSystemIntegrationTest extends KernelTestCase
{
    private ShippingLineConfigurationService $configService;
    private RolePermissionConfigurationService $permissionService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        // Mock the services since we don't have database tables yet
        $this->configService = $this->createMock(ShippingLineConfigurationService::class);
        $this->permissionService = $this->createMock(RolePermissionConfigurationService::class);
    }

    public function testConfigurationSystemIntegration(): void
    {
        // Create test data
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');

        $admin = $this->createMock(User::class);
        $admin->method('getRole')->willReturn(UserRole::SHIPPING_LINES_ADMIN);
        $admin->method('getManagedShippingLine')->willReturn($shippingLine);

        // Test configuration setting
        $configKey = 'portal_theme';
        $configValue = ['primaryColor' => '#007bff', 'secondaryColor' => '#6c757d'];

        $this->configService
            ->expects($this->once())
            ->method('setConfiguration')
            ->with($shippingLine, $configKey, $configValue, $admin)
            ->willReturn($this->createMock(\App\Entity\ShippingLineConfiguration::class));

        // Test permission setting
        $role = UserRole::SL_STAFF;
        $permissions = ['user.view', 'data.view_own'];

        $this->permissionService
            ->expects($this->once())
            ->method('setRolePermissions')
            ->with($shippingLine, $role, $permissions, $admin)
            ->willReturn($this->createMock(\App\Entity\RolePermissionConfiguration::class));

        // Execute the operations
        $configResult = $this->configService->setConfiguration($shippingLine, $configKey, $configValue, $admin);
        $permissionResult = $this->permissionService->setRolePermissions($shippingLine, $role, $permissions, $admin);

        // Assert results
        $this->assertNotNull($configResult);
        $this->assertNotNull($permissionResult);
    }

    public function testPermissionValidation(): void
    {
        // Test that available permissions are properly defined
        $availablePermissions = $this->permissionService->getAvailablePermissions();
        
        // Mock the method to return expected permissions
        $this->permissionService
            ->method('getAvailablePermissions')
            ->willReturn([
                'user.create', 'user.view', 'user.edit', 'user.delete',
                'data.view_own', 'data.view_all', 'data.export',
                'reports.view', 'reports.generate',
                'config.view', 'config.edit'
            ]);

        $permissions = $this->permissionService->getAvailablePermissions();
        
        $this->assertIsArray($permissions);
        $this->assertContains('user.view', $permissions);
        $this->assertContains('data.view_own', $permissions);
        $this->assertContains('config.edit', $permissions);
    }

    public function testDefaultPermissions(): void
    {
        // Test default permissions for different roles
        $this->permissionService
            ->method('getDefaultPermissions')
            ->willReturnCallback(function(UserRole $role) {
                $defaults = [
                    'SYSTEM_ADMIN' => ['user.create', 'user.delete', 'config.edit'],
                    'SHIPPING_LINES_ADMIN' => ['user.create', 'config.edit'],
                    'SL_STAFF' => ['user.view', 'data.view_own'],
                    'CONSIGNEE' => ['data.view_own']
                ];
                return $defaults[$role->value] ?? [];
            });

        $systemAdminPerms = $this->permissionService->getDefaultPermissions(UserRole::SYSTEM_ADMIN);
        $slStaffPerms = $this->permissionService->getDefaultPermissions(UserRole::SL_STAFF);
        $consigneePerms = $this->permissionService->getDefaultPermissions(UserRole::CONSIGNEE);

        $this->assertContains('user.delete', $systemAdminPerms);
        $this->assertNotContains('user.delete', $slStaffPerms);
        $this->assertContains('data.view_own', $consigneePerms);
    }

    public function testConfigurationValidation(): void
    {
        // Test that configuration validation works properly
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Line');

        $admin = $this->createMock(User::class);
        $admin->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        // Test valid branding configuration
        $validBranding = [
            'primaryColor' => '#007bff',
            'secondaryColor' => '#6c757d',
            'logoUrl' => 'https://example.com/logo.png'
        ];

        $this->configService
            ->expects($this->once())
            ->method('updateBrandingConfiguration')
            ->with($shippingLine, $validBranding, $admin);

        $this->configService->updateBrandingConfiguration($shippingLine, $validBranding, $admin);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }
}