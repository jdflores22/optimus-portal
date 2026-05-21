<?php

namespace App\Tests\Entity;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\SlotStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\GeotagPhoto;
use App\Entity\PreAdviceRequest;
use App\Entity\StaffUser;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Feature: terminal-team-pre-advice, Property 1: Entity relationship integrity
 * 
 * Property-based test for validating entity relationships in the Terminal Team Pre-Advice system.
 * This test validates Requirements 3.1, 7.1, 10.1 by ensuring that all entity relationships
 * maintain referential integrity and proper bidirectional associations.
 */
class EntityRelationshipPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 1: Entity relationship integrity
     * 
     * For any valid Terminal, Container, and PreAdviceRequest entities,
     * the relationships should maintain bidirectional consistency and referential integrity.
     * 
     * Validates: Requirements 3.1, 7.1, 10.1
     */
    public function testEntityRelationshipIntegrity(): void
    {
        $this->forAll(
            Generator\elements(TerminalType::CY, TerminalType::ATI, TerminalType::ICTSI),
            Generator\elements(
                ContainerStatus::AVAILABLE_FOR_RETURN,
                ContainerStatus::IN_TRANSIT,
                ContainerStatus::AT_TERMINAL,
                ContainerStatus::RETURNED,
                ContainerStatus::MAINTENANCE
            ),
            Generator\choose(1, 100),
            Generator\string(),
            Generator\string(),
            Generator\string()
        )->then(function (
            TerminalType $terminalType,
            ContainerStatus $containerStatus,
            int $capacity,
            string $containerNumber,
            string $containerSize,
            string $containerType
        ) {
            // Skip empty strings to avoid validation issues
            if (empty($containerNumber) || empty($containerSize) || empty($containerType)) {
                return;
            }
            // Create Terminal entity
            $terminal = new Terminal();
            $terminal->setName('Test Terminal')
                ->setType($terminalType)
                ->setLocation('Test Location')
                ->setDailyCapacity($capacity)
                ->setIsActive(true);

            // Create Container entity
            $container = new Container();
            $container->setContainerNumber($containerNumber)
                ->setSize($containerSize)
                ->setType($containerType)
                ->setStatus($containerStatus)
                ->setCurrentLocation('Test Location')
                ->setExpectedReturnDate(new \DateTime('+1 day'));

            // Create TerminalSlot entity
            $terminalSlot = new TerminalSlot();
            $terminalSlot->setTerminal($terminal)
                ->setDate(new \DateTime('tomorrow'))
                ->setCapacity($capacity)
                ->setAssignedCount(0)
                ->setStatus(SlotStatus::AVAILABLE);

            // Create User (StaffUser) entity for trucker
            $trucker = new StaffUser();
            $trucker->setEmail('trucker@test.com')
                ->setPasswordHash('hashed_password');

            // Create PreAdviceRequest entity
            $preAdviceRequest = new PreAdviceRequest();
            $preAdviceRequest->setTrucker($trucker)
                ->setContainer($container)
                ->setSelectedTerminal($terminal)
                ->setAssignedSlot($terminalSlot)
                ->setPaymentReference('PAY123456')
                ->setStatus(PreAdviceStatus::PENDING);

            // Create GeotagPhoto entity
            $geotagPhoto = new GeotagPhoto();
            $geotagPhoto->setPreAdviceRequest($preAdviceRequest)
                ->setFilename('test_photo.jpg')
                ->setOriginalName('original_photo.jpg')
                ->setLatitude(40.7128)
                ->setLongitude(-74.0060)
                ->setCapturedAt(new \DateTime())
                ->setIsVerified(false);

            // Test Terminal -> TerminalSlot relationship
            $terminal->addSlot($terminalSlot);
            $this->assertTrue($terminal->getSlots()->contains($terminalSlot));
            $this->assertSame($terminal, $terminalSlot->getTerminal());

            // Test Terminal -> PreAdviceRequest relationship
            $terminal->addPreAdviceRequest($preAdviceRequest);
            $this->assertTrue($terminal->getPreAdviceRequests()->contains($preAdviceRequest));
            $this->assertSame($terminal, $preAdviceRequest->getSelectedTerminal());

            // Test Container -> PreAdviceRequest relationship
            $container->addBookingRequest($preAdviceRequest);
            $this->assertTrue($container->getBookingRequests()->contains($preAdviceRequest));
            $this->assertSame($container, $preAdviceRequest->getContainer());

            // Test TerminalSlot -> PreAdviceRequest relationship
            $terminalSlot->addPreAdviceRequest($preAdviceRequest);
            $this->assertTrue($terminalSlot->getPreAdviceRequests()->contains($preAdviceRequest));
            $this->assertSame($terminalSlot, $preAdviceRequest->getAssignedSlot());

            // Test PreAdviceRequest -> GeotagPhoto relationship
            $preAdviceRequest->addGeotagPhoto($geotagPhoto);
            $this->assertTrue($preAdviceRequest->getGeotagPhotos()->contains($geotagPhoto));
            $this->assertSame($preAdviceRequest, $geotagPhoto->getPreAdviceRequest());

            // Test that entities maintain their core properties
            $this->assertEquals($terminalType, $terminal->getType());
            $this->assertEquals($containerStatus, $container->getStatus());
            $this->assertEquals($capacity, $terminal->getDailyCapacity());
            $this->assertEquals($containerNumber, $container->getContainerNumber());
            $this->assertEquals(PreAdviceStatus::PENDING, $preAdviceRequest->getStatus());
            $this->assertEquals('PAY123456', $preAdviceRequest->getPaymentReference());
            $this->assertEquals(40.7128, $geotagPhoto->getLatitude());
            $this->assertEquals(-74.0060, $geotagPhoto->getLongitude());

            // Test collection operations without causing null references
            $this->assertGreaterThan(0, $terminal->getSlots()->count());
            $this->assertGreaterThan(0, $terminal->getPreAdviceRequests()->count());
            $this->assertGreaterThan(0, $container->getBookingRequests()->count());
            $this->assertGreaterThan(0, $terminalSlot->getPreAdviceRequests()->count());
            $this->assertGreaterThan(0, $preAdviceRequest->getGeotagPhotos()->count());
        });
    }

    /**
     * Property test for TerminalSlot availability logic
     * 
     * For any TerminalSlot with capacity and assigned count,
     * the availability should be correctly calculated based on capacity and status.
     */
    public function testTerminalSlotAvailabilityLogic(): void
    {
        $this->forAll(
            Generator\choose(1, 100),
            Generator\choose(0, 100),
            Generator\elements(SlotStatus::AVAILABLE, SlotStatus::FULL, SlotStatus::BLOCKED)
        )->then(function (int $capacity, int $assignedCount, SlotStatus $status) {
            $terminalSlot = new TerminalSlot();
            $terminalSlot->setCapacity($capacity)
                ->setAssignedCount($assignedCount)
                ->setStatus($status);

            // Test availability logic
            $expectedAvailable = $status === SlotStatus::AVAILABLE && $assignedCount < $capacity;
            $this->assertEquals($expectedAvailable, $terminalSlot->isAvailable());

            // Test increment/decrement operations
            if ($assignedCount < $capacity) {
                $terminalSlot->incrementAssignedCount();
                $this->assertEquals($assignedCount + 1, $terminalSlot->getAssignedCount());
                
                if ($assignedCount + 1 >= $capacity) {
                    $this->assertEquals(SlotStatus::FULL, $terminalSlot->getStatus());
                }
            }

            if ($assignedCount > 0) {
                $terminalSlot->setAssignedCount($assignedCount); // Reset
                $terminalSlot->setStatus($status); // Reset
                $terminalSlot->decrementAssignedCount();
                $this->assertEquals($assignedCount - 1, $terminalSlot->getAssignedCount());
            }
        });
    }

    /**
     * Property test for enum value consistency
     * 
     * For any enum values used in entities, they should maintain their string values
     * and be properly serializable.
     */
    public function testEnumValueConsistency(): void
    {
        $this->forAll(
            Generator\elements(TerminalType::CY, TerminalType::ATI, TerminalType::ICTSI),
            Generator\elements(
                ContainerStatus::AVAILABLE_FOR_RETURN,
                ContainerStatus::IN_TRANSIT,
                ContainerStatus::AT_TERMINAL,
                ContainerStatus::RETURNED,
                ContainerStatus::MAINTENANCE
            ),
            Generator\elements(
                PreAdviceStatus::PENDING,
                PreAdviceStatus::VERIFIED,
                PreAdviceStatus::REJECTED,
                PreAdviceStatus::COMPLETED,
                PreAdviceStatus::CANCELLED
            ),
            Generator\elements(SlotStatus::AVAILABLE, SlotStatus::FULL, SlotStatus::BLOCKED)
        )->then(function (
            TerminalType $terminalType,
            ContainerStatus $containerStatus,
            PreAdviceStatus $preAdviceStatus,
            SlotStatus $slotStatus
        ) {
            // Test that enum values are consistent with their string representations
            $this->assertContains($terminalType->value, ['CY', 'ATI', 'ICTSI']);
            $this->assertContains($containerStatus->value, [
                'available_for_return', 'in_transit', 'at_terminal', 'returned', 'maintenance'
            ]);
            $this->assertContains($preAdviceStatus->value, [
                'pending', 'verified', 'rejected', 'completed', 'cancelled'
            ]);
            $this->assertContains($slotStatus->value, ['available', 'full', 'blocked']);

            // Test that enums can be serialized and deserialized
            $this->assertEquals($terminalType, TerminalType::from($terminalType->value));
            $this->assertEquals($containerStatus, ContainerStatus::from($containerStatus->value));
            $this->assertEquals($preAdviceStatus, PreAdviceStatus::from($preAdviceStatus->value));
            $this->assertEquals($slotStatus, SlotStatus::from($slotStatus->value));
        });
    }
}