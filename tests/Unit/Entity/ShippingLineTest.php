<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use PHPUnit\Framework\TestCase;

class ShippingLineTest extends TestCase
{
    public function testShippingLineCreation(): void
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');
        $shippingLine->setPortalConfig(['theme' => 'blue', 'logo' => 'test.png']);

        $this->assertEquals('Test Shipping Line', $shippingLine->getBrandName());
        $this->assertEquals(['theme' => 'blue', 'logo' => 'test.png'], $shippingLine->getPortalConfig());
        $this->assertTrue($shippingLine->isActive());
        $this->assertInstanceOf(\DateTimeInterface::class, $shippingLine->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $shippingLine->getUpdatedAt());
    }

    public function testShippingLineValidation(): void
    {
        $shippingLine = new ShippingLine();
        
        // Test empty brand name validation
        $errors = $shippingLine->validate();
        $this->assertContains('Brand name is required', $errors);
        
        // Test short brand name validation
        $shippingLine->setBrandName('A');
        $errors = $shippingLine->validate();
        $this->assertContains('Brand name must be at least 2 characters long', $errors);
        
        // Test valid brand name
        $shippingLine->setBrandName('Valid Shipping Line');
        $errors = $shippingLine->validate();
        $this->assertEmpty($errors);
    }

    public function testPortalConfigManagement(): void
    {
        $shippingLine = new ShippingLine();
        
        // Test setting individual config values
        $shippingLine->setPortalConfigValue('theme', 'dark');
        $shippingLine->setPortalConfigValue('logo', 'logo.png');
        
        $this->assertEquals('dark', $shippingLine->getPortalConfigValue('theme'));
        $this->assertEquals('logo.png', $shippingLine->getPortalConfigValue('logo'));
        $this->assertNull($shippingLine->getPortalConfigValue('nonexistent'));
        $this->assertEquals('default', $shippingLine->getPortalConfigValue('nonexistent', 'default'));
    }

    public function testDeactivateActivate(): void
    {
        $shippingLine = new ShippingLine();
        $this->assertTrue($shippingLine->isActive());
        
        $shippingLine->deactivate();
        $this->assertFalse($shippingLine->isActive());
        
        $shippingLine->activate();
        $this->assertTrue($shippingLine->isActive());
    }

    public function testToString(): void
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Line');
        
        $this->assertEquals('Test Line', (string) $shippingLine);
    }
}