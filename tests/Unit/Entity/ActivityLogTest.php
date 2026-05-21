<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use PHPUnit\Framework\TestCase;

class ActivityLogTest extends TestCase
{
    private User $user;
    private ShippingLine $shippingLine;

    protected function setUp(): void
    {
        $this->user = $this->createMock(User::class);
        $this->user->method('getEmail')->willReturn('test@example.com');
        
        $this->shippingLine = new ShippingLine();
        $this->shippingLine->setBrandName('Test Shipping Line');
    }

    public function testActivityLogCreation(): void
    {
        $activityLog = new ActivityLog();
        $activityLog->setUser($this->user);
        $activityLog->setShippingLine($this->shippingLine);
        $activityLog->setActivityType(ActivityLog::TYPE_LOGIN);
        $activityLog->setIpAddress('192.168.1.1');
        $activityLog->setUserAgent('Mozilla/5.0');

        $this->assertEquals($this->user, $activityLog->getUser());
        $this->assertEquals($this->shippingLine, $activityLog->getShippingLine());
        $this->assertEquals(ActivityLog::TYPE_LOGIN, $activityLog->getActivityType());
        $this->assertEquals('192.168.1.1', $activityLog->getIpAddress());
        $this->assertEquals('Mozilla/5.0', $activityLog->getUserAgent());
        $this->assertInstanceOf(\DateTimeInterface::class, $activityLog->getCreatedAt());
    }

    public function testActivityLogValidation(): void
    {
        $activityLog = new ActivityLog();
        
        // Test validation with missing required fields
        $errors = $activityLog->validate();
        $this->assertContains('Activity type is required', $errors);
        $this->assertContains('IP address is required', $errors);
        
        // Test with invalid activity type
        $activityLog->setActivityType('invalid_type');
        $activityLog->setIpAddress('192.168.1.1');
        $errors = $activityLog->validate();
        $this->assertContains('Invalid activity type', $errors);
        
        // Test with valid data
        $activityLog->setActivityType(ActivityLog::TYPE_LOGIN);
        $errors = $activityLog->validate();
        $this->assertEmpty($errors);
    }

    public function testActivityDescription(): void
    {
        $activityLog = new ActivityLog();
        
        $activityLog->setActivityType(ActivityLog::TYPE_LOGIN);
        $this->assertEquals('User logged in', $activityLog->getActivityDescription());
        
        $activityLog->setActivityType(ActivityLog::TYPE_CREATE);
        $activityLog->setEntityType('User');
        $this->assertEquals('Created User', $activityLog->getActivityDescription());
        
        $activityLog->setActivityType('unknown_type');
        $this->assertEquals('Unknown activity', $activityLog->getActivityDescription());
    }

    public function testSecurityActivityCheck(): void
    {
        $activityLog = new ActivityLog();
        
        $activityLog->setActivityType(ActivityLog::TYPE_LOGIN);
        $this->assertTrue($activityLog->isSecurityActivity());
        
        $activityLog->setActivityType(ActivityLog::TYPE_FAILED_LOGIN);
        $this->assertTrue($activityLog->isSecurityActivity());
        
        $activityLog->setActivityType(ActivityLog::TYPE_CREATE);
        $this->assertFalse($activityLog->isSecurityActivity());
    }

    public function testBusinessActivityCheck(): void
    {
        $activityLog = new ActivityLog();
        
        $activityLog->setActivityType(ActivityLog::TYPE_CONTAINER_ASSIGNMENT);
        $this->assertTrue($activityLog->isBusinessActivity());
        
        $activityLog->setActivityType(ActivityLog::TYPE_PRE_ADVICE_CREATION);
        $this->assertTrue($activityLog->isBusinessActivity());
        
        $activityLog->setActivityType(ActivityLog::TYPE_LOGIN);
        $this->assertFalse($activityLog->isBusinessActivity());
    }

    public function testShippingLineScopeCheck(): void
    {
        $activityLog = new ActivityLog();
        $activityLog->setShippingLine($this->shippingLine);
        
        // Same shipping line scope
        $this->assertTrue($activityLog->isInShippingLineScope($this->shippingLine));
        
        // Different shipping line scope
        $otherShippingLine = new ShippingLine();
        $otherShippingLine->setBrandName('Other Shipping Line');
        $this->assertFalse($activityLog->isInShippingLineScope($otherShippingLine));
        
        // System admin scope (null)
        $this->assertTrue($activityLog->isInShippingLineScope(null));
    }

    public function testGetAllActivityTypes(): void
    {
        $types = ActivityLog::getAllActivityTypes();
        
        $this->assertIsArray($types);
        $this->assertContains(ActivityLog::TYPE_LOGIN, $types);
        $this->assertContains(ActivityLog::TYPE_CREATE, $types);
        $this->assertContains(ActivityLog::TYPE_CONTAINER_ASSIGNMENT, $types);
        $this->assertContains(ActivityLog::TYPE_TERMINAL_ALLOCATION, $types);
    }

    public function testToString(): void
    {
        $activityLog = new ActivityLog();
        $activityLog->setUser($this->user);
        $activityLog->setActivityType(ActivityLog::TYPE_LOGIN);
        
        $string = (string) $activityLog;
        $this->assertStringContainsString('login', $string);
        $this->assertStringContainsString('test@example.com', $string);
    }
}