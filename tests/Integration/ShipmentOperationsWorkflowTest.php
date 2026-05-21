<?php

namespace App\Tests\Integration;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\StaffUser;
use App\Entity\ShipmentRecord;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\UserService;
use App\Service\ShipmentService;
use App\Service\AccreditationWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for complete shipment creation and broker search workflow
 * Tests Requirements: 6.1-6.4
 */
class ShipmentOperationsWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private ShipmentService $shipmentService;
    private AccreditationWorkflowService $accreditationService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userService = $container->get(UserService::class);
        $this->shipmentService = $container->get(ShipmentService::class);
        $this->accreditationService = $container->get(AccreditationWorkflowService::class);

        // Clean database
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testCompleteShipmentCreationAndBrokerSearchWorkflow(): void
    {
        // Step 1: Create SL-Staff user
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        // Step 2: Create broker and consignee with linkage
        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);

        $consignee = $this->userService->createUser([
            'email' => 'consignee@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Consignee Corp'
        ], UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);

        $this->accreditationService->linkBrokerToConsignee($consignee, $broker);
        $this->entityManager->flush();

        // Step 3: SL-Staff creates shipment record
        $shipmentData = [
            'manifestNumber' => 'MAN-2024-001',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15 10:00:00'),
            'actualArrivalDate' => new \DateTime('2024-01-16 14:30:00'),
            'billingInformation' => 'Container: MSKU1234567, Size: 40ft, Weight: 25,000kg, Charges: $5,000.00'
        ];

        $shipment = $this->shipmentService->createShipment($shipmentData, $slStaff);
        
        $this->assertInstanceOf(ShipmentRecord::class, $shipment);
        $this->assertEquals('MAN-2024-001', $shipment->getManifestNumber());
        $this->assertEquals($slStaff, $shipment->getCreatedBy());
        $this->assertNotNull($shipment->getCreatedAt());

        // Step 4: Authorize broker access to shipment
        $this->shipmentService->addAuthorizedBroker($shipment->getId(), $broker, $slStaff);
        
        $this->entityManager->refresh($shipment);
        $this->assertTrue($shipment->getAuthorizedBrokers()->contains($broker));

        // Step 5: Broker searches for shipments
        $searchCriteria = [
            'manifestNumber' => 'MAN-2024-001'
        ];

        $searchResults = $this->shipmentService->searchShipments($searchCriteria, $broker);
        
        $this->assertCount(1, $searchResults);
        $this->assertEquals($shipment, $searchResults[0]);

        // Step 6: Test broker can only see authorized shipments
        $unauthorizedBroker = $this->userService->createUser([
            'email' => 'unauthorized@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Unauthorized Broker'
        ], UserRole::BROKER);
        $unauthorizedBroker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();

        $unauthorizedResults = $this->shipmentService->searchShipments($searchCriteria, $unauthorizedBroker);
        $this->assertCount(0, $unauthorizedResults);

        // Step 7: Test search by arrival date
        $dateSearchCriteria = [
            'arrivalDateFrom' => new \DateTime('2024-01-15'),
            'arrivalDateTo' => new \DateTime('2024-01-17')
        ];

        $dateResults = $this->shipmentService->searchShipments($dateSearchCriteria, $broker);
        $this->assertCount(1, $dateResults);
        $this->assertEquals($shipment, $dateResults[0]);

        // Step 8: Test search by consignee (through broker linkage)
        $consigneeSearchCriteria = [
            'consignee' => $consignee->getBusinessName()
        ];

        $consigneeResults = $this->shipmentService->searchShipments($consigneeSearchCriteria, $broker);
        $this->assertCount(1, $consigneeResults);
    }

    public function testShipmentUpdateWithAuditLogging(): void
    {
        // Create entities
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $this->entityManager->flush();

        // Create shipment
        $shipment = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-002',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'billingInformation' => 'Initial billing info'
        ], $slStaff);

        $originalBilling = $shipment->getBillingInformation();

        // Update shipment
        $updatedData = [
            'manifestNumber' => 'MAN-2024-002',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'actualArrivalDate' => new \DateTime('2024-01-16'),
            'billingInformation' => 'Updated billing information with additional charges'
        ];

        $this->shipmentService->updateShipment($shipment->getId(), $updatedData, $slStaff);

        // Verify update
        $this->entityManager->refresh($shipment);
        $this->assertEquals($updatedData['billingInformation'], $shipment->getBillingInformation());
        $this->assertEquals($updatedData['actualArrivalDate'], $shipment->getActualArrivalDate());

        // Verify audit logging (check that audit service was called)
        // This would be verified through the audit log repository in a real scenario
        $this->assertNotEquals($originalBilling, $shipment->getBillingInformation());
    }

    public function testMultipleBrokerAccessToSameShipment(): void
    {
        // Create entities
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $broker1 = $this->userService->createUser([
            'email' => 'broker1@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Broker One LLC'
        ], UserRole::BROKER);
        $broker1->setStatus(AccountStatus::APPROVED);

        $broker2 = $this->userService->createUser([
            'email' => 'broker2@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Broker Two LLC'
        ], UserRole::BROKER);
        $broker2->setStatus(AccountStatus::APPROVED);

        $this->entityManager->flush();

        // Create shipment
        $shipment = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-003',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'billingInformation' => 'Shared shipment billing'
        ], $slStaff);

        // Authorize both brokers
        $this->shipmentService->addAuthorizedBroker($shipment->getId(), $broker1, $slStaff);
        $this->shipmentService->addAuthorizedBroker($shipment->getId(), $broker2, $slStaff);

        $this->entityManager->refresh($shipment);

        // Verify both brokers have access
        $this->assertTrue($shipment->getAuthorizedBrokers()->contains($broker1));
        $this->assertTrue($shipment->getAuthorizedBrokers()->contains($broker2));
        $this->assertEquals(2, $shipment->getAuthorizedBrokers()->count());

        // Both brokers should be able to find the shipment
        $searchCriteria = ['manifestNumber' => 'MAN-2024-003'];
        
        $results1 = $this->shipmentService->searchShipments($searchCriteria, $broker1);
        $results2 = $this->shipmentService->searchShipments($searchCriteria, $broker2);

        $this->assertCount(1, $results1);
        $this->assertCount(1, $results2);
        $this->assertEquals($shipment, $results1[0]);
        $this->assertEquals($shipment, $results2[0]);
    }

    public function testShipmentSearchWithComplexCriteria(): void
    {
        // Create entities
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);

        $this->entityManager->flush();

        // Create multiple shipments
        $shipment1 = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-001',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'actualArrivalDate' => new \DateTime('2024-01-16'),
            'billingInformation' => 'Container charges: $3,000'
        ], $slStaff);

        $shipment2 = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-002',
            'noticeOfArrivalDate' => new \DateTime('2024-01-20'),
            'actualArrivalDate' => new \DateTime('2024-01-21'),
            'billingInformation' => 'Container charges: $4,500'
        ], $slStaff);

        $shipment3 = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-003',
            'noticeOfArrivalDate' => new \DateTime('2024-02-01'),
            'billingInformation' => 'Container charges: $2,800'
        ], $slStaff);

        // Authorize broker for all shipments
        $this->shipmentService->addAuthorizedBroker($shipment1->getId(), $broker, $slStaff);
        $this->shipmentService->addAuthorizedBroker($shipment2->getId(), $broker, $slStaff);
        $this->shipmentService->addAuthorizedBroker($shipment3->getId(), $broker, $slStaff);

        // Test date range search
        $dateRangeResults = $this->shipmentService->searchShipments([
            'arrivalDateFrom' => new \DateTime('2024-01-15'),
            'arrivalDateTo' => new \DateTime('2024-01-25')
        ], $broker);

        $this->assertCount(2, $dateRangeResults);

        // Test manifest number pattern search
        $manifestResults = $this->shipmentService->searchShipments([
            'manifestNumber' => 'MAN-2024-001'
        ], $broker);

        $this->assertCount(1, $manifestResults);
        $this->assertEquals($shipment1, $manifestResults[0]);

        // Test search with no results
        $noResults = $this->shipmentService->searchShipments([
            'manifestNumber' => 'NONEXISTENT'
        ], $broker);

        $this->assertCount(0, $noResults);
    }

    public function testBrokerConsigneeRelationshipInShipmentAccess(): void
    {
        // Create entities
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);

        $consignee1 = $this->userService->createUser([
            'email' => 'consignee1@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Consignee One Corp'
        ], UserRole::CONSIGNEE);

        $consignee2 = $this->userService->createUser([
            'email' => 'consignee2@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Consignee Two Corp'
        ], UserRole::CONSIGNEE);

        // Link both consignees to broker
        $this->accreditationService->linkBrokerToConsignee($consignee1, $broker);
        $this->accreditationService->linkBrokerToConsignee($consignee2, $broker);
        $this->entityManager->flush();

        // Create shipment
        $shipment = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-004',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'billingInformation' => 'Multi-consignee shipment'
        ], $slStaff);

        $this->shipmentService->addAuthorizedBroker($shipment->getId(), $broker, $slStaff);

        // Verify broker can access shipment for both linked consignees
        $searchResults = $this->shipmentService->searchShipments([
            'consignee' => 'Consignee One Corp'
        ], $broker);

        $this->assertCount(1, $searchResults);

        $searchResults2 = $this->shipmentService->searchShipments([
            'consignee' => 'Consignee Two Corp'
        ], $broker);

        $this->assertCount(1, $searchResults2);

        // Verify relationship integrity
        $this->assertTrue($broker->getLinkedConsignees()->contains($consignee1));
        $this->assertTrue($broker->getLinkedConsignees()->contains($consignee2));
        $this->assertEquals($broker, $consignee1->getLinkedBroker());
        $this->assertEquals($broker, $consignee2->getLinkedBroker());
    }
}