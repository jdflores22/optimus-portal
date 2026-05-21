<?php

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\Container;
use DateTime;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    public function testContainerCreationWithValidData(): void
    {
        $gateInDate = new DateTime('2024-01-15');
        
        $container = new Container(
            'MSKU7834567',
            '40ft HC',
            $gateInDate,
            15,
            'Good',
            'Available',
            'Block A-12'
        );
        
        $this->assertEquals('MSKU7834567', $container->getContainerNumber());
        $this->assertEquals('40ft HC', $container->getSizeType());
        $this->assertEquals($gateInDate, $container->getGateInDate());
        $this->assertEquals(15, $container->getDwellTime());
        $this->assertEquals('Good', $container->getCondition());
        $this->assertEquals('Available', $container->getStatus());
        $this->assertEquals('Block A-12', $container->getLocation());
    }

    public function testTeuCountCalculation(): void
    {
        $gateInDate = new DateTime('2024-01-15');
        
        // Test 20ft container (1 TEU)
        $container20ft = new Container(
            'TCLU9876543',
            '20ft DV',
            $gateInDate,
            8,
            'Good',
            'Available',
            'Block B-05'
        );
        
        $this->assertEquals(1, $container20ft->getTeuCount());
        
        // Test 40ft container (2 TEU)
        $container40ft = new Container(
            'MSKU7834567',
            '40ft HC',
            $gateInDate,
            15,
            'Good',
            'Available',
            'Block A-12'
        );
        
        $this->assertEquals(2, $container40ft->getTeuCount());
    }

    public function testIsAvailable(): void
    {
        $gateInDate = new DateTime('2024-01-15');
        
        $availableContainer = new Container(
            'MSKU7834567',
            '40ft HC',
            $gateInDate,
            15,
            'Good',
            'Available',
            'Block A-12'
        );
        
        $this->assertTrue($availableContainer->isAvailable());
        
        $reservedContainer = new Container(
            'TCLU9876543',
            '20ft DV',
            $gateInDate,
            8,
            'Good',
            'Reserved',
            'Block B-05'
        );
        
        $this->assertFalse($reservedContainer->isAvailable());
    }

    public function testJsonSerialization(): void
    {
        $gateInDate = new DateTime('2024-01-15');
        
        $container = new Container(
            'MSKU7834567',
            '40ft HC',
            $gateInDate,
            15,
            'Good',
            'Available',
            'Block A-12'
        );
        
        $expected = [
            'containerNumber' => 'MSKU7834567',
            'sizeType' => '40ft HC',
            'gateInDate' => '2024-01-15',
            'dwellTime' => 15,
            'condition' => 'Good',
            'status' => 'Available',
            'location' => 'Block A-12',
            'teuCount' => 2,
            'isAvailable' => true
        ];
        
        $this->assertEquals($expected, $container->jsonSerialize());
    }

    public function testFromArrayCreation(): void
    {
        $data = [
            'containerNumber' => 'MSKU7834567',
            'sizeType' => '40ft HC',
            'gateInDate' => new DateTime('2024-01-15'),
            'dwellTime' => 15,
            'condition' => 'Good',
            'status' => 'Available',
            'location' => 'Block A-12'
        ];
        
        $container = Container::fromArray($data);
        
        $this->assertEquals('MSKU7834567', $container->getContainerNumber());
        $this->assertEquals('40ft HC', $container->getSizeType());
        $this->assertEquals(15, $container->getDwellTime());
    }

    public function testInvalidContainerNumberThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid container number format');
        
        new Container(
            'INVALID',
            '40ft HC',
            new DateTime('2024-01-15'),
            15,
            'Good',
            'Available',
            'Block A-12'
        );
    }

    public function testInvalidSizeTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid size type');
        
        new Container(
            'MSKU7834567',
            'Invalid Size',
            new DateTime('2024-01-15'),
            15,
            'Good',
            'Available',
            'Block A-12'
        );
    }

    public function testInvalidConditionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid condition');
        
        new Container(
            'MSKU7834567',
            '40ft HC',
            new DateTime('2024-01-15'),
            15,
            'Invalid Condition',
            'Available',
            'Block A-12'
        );
    }

    public function testInvalidStatusThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status');
        
        new Container(
            'MSKU7834567',
            '40ft HC',
            new DateTime('2024-01-15'),
            15,
            'Good',
            'Invalid Status',
            'Block A-12'
        );
    }

    public function testNegativeDwellTimeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dwell time cannot be negative');
        
        new Container(
            'MSKU7834567',
            '40ft HC',
            new DateTime('2024-01-15'),
            -5,
            'Good',
            'Available',
            'Block A-12'
        );
    }
}