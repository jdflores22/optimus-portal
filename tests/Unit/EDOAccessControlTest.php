<?php

namespace App\Tests\Unit;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use PHPUnit\Framework\TestCase;

class EDOAccessControlTest extends TestCase
{
    public function testEDOStatusEnum(): void
    {
        // Test that all required statuses exist
        $this->assertEquals('pending_release', EDOStatus::PENDING_RELEASE->value);
        $this->assertEquals('released', EDOStatus::RELEASED->value);
        $this->assertEquals('rejected', EDOStatus::REJECTED->value);
    }

    public function testEDOStatusDisplayNames(): void
    {
        $this->assertEquals('Pending Release', EDOStatus::PENDING_RELEASE->getDisplayName());
        $this->assertEquals('Released', EDOStatus::RELEASED->getDisplayName());
        $this->assertEquals('Rejected', EDOStatus::REJECTED->getDisplayName());
    }

    public function testEDOEntityHasStatusField(): void
    {
        $reflection = new \ReflectionClass(ElectronicDeliveryOrder::class);
        $this->assertTrue($reflection->hasProperty('status'));
        
        $statusProperty = $reflection->getProperty('status');
        $type = $statusProperty->getType();
        $this->assertNotNull($type);
        $this->assertEquals('App\Entity\Enum\EDOStatus', $type->getName());
    }

    public function testEDOEntityHasGetStatusMethod(): void
    {
        $reflection = new \ReflectionClass(ElectronicDeliveryOrder::class);
        $this->assertTrue($reflection->hasMethod('getStatus'));
        
        $method = $reflection->getMethod('getStatus');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('App\Entity\Enum\EDOStatus', $returnType->getName());
    }
}
