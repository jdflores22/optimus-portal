<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\ShipmentRecord;
use App\Entity\StaffUser;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Service\ShipmentService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 6: Shipment access authorization
 * 
 * For any broker searching for shipment records, only shipments linked to the broker's
 * authorized consignees should be returned in search results.
 * 
 * Validates: Requirements 6.3, 12.2
 */
class ShipmentAccessAuthorizationTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private ShipmentService $shipmentService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->shipmentService = $container->get(ShipmentService::class);

        // Configure Eris
        $this->minimumEvaluationRatio = 0.5;
        $this->iterations = 10; // Reduced for faster execution

        // Begin transaction for test isolation
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * Property: Brokers can only search shipments they are authorized for
     * 
     * For any broker, searching for shipments should only return shipments
     * where the broker is in the authorized brokers list.
     */
    public function testBrokerCanOnlyAccessAuthorizedShipments(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'manifestSuffix' => Generator\nat(),
                'billingInfo' => Generator\map(
                    fn($s) => 'Billing: ' . $s,
                    Generator\string()
                )
            ])
        )->then(function ($data) {
            // Create unique identifiers for this iteration
            $uniqueId = uniqid();
            $brokerEmail = "broker{$data['emailSuffix']}{$uniqueId}@test.com";
            $staffEmail = "staff{$data['emailSuffix']}{$uniqueId}@test.com";
            
            // Create a broker
            $broker = $this->createBroker($brokerEmail, 'Test Broker ' . $data['emailSuffix']);
            
            // Create SL-Staff user
            $staff = $this->createStaffUser($staffEmail);
            
            // Create a shipment and authorize the broker
            $authorizedShipment = $this->createShipment(
                'MAN-' . $uniqueId . '-' . $data['manifestSuffix'] . '-AUTH',
                $data['billingInfo'],
                $staff
            );
            $authorizedShipment->addAuthorizedBroker($broker);
            $this->entityManager->flush();
            
            // Create another shipment without authorizing the broker
            $unauthorizedShipment = $this->createShipment(
                'MAN-' . $uniqueId . '-' . $data['manifestSuffix'] . '-UNAUTH',
                $data['billingInfo'] . ' Unauthorized',
                $staff
            );
            $this->entityManager->flush();
            
            // Search for all shipments as this broker
            $results = $this->shipmentService->searchShipments([], $broker);
            
            // Extract shipment IDs from results
            $resultIds = array_map(fn($s) => $s->getId(), $results);
            
            // Assert that authorized shipment is in results
            $this->assertContains(
                $authorizedShipment->getId(),
                $resultIds,
                'Broker should be able to access authorized shipment'
            );
            
            // Assert that unauthorized shipment is NOT in results
            $this->assertNotContains(
                $unauthorizedShipment->getId(),
                $resultIds,
                'Broker should NOT be able to access unauthorized shipment'
            );
        });
    }

    /**
     * Property: authorizeAccess correctly validates broker authorization
     * 
     * For any shipment and broker, authorizeAccess should return true only if
     * the broker is in the shipment's authorized brokers list.
     */
    public function testAuthorizeAccessValidatesBrokerAuthorization(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'manifestSuffix' => Generator\nat(),
                'billingInfo' => Generator\string()
            ])
        )->then(function ($data) {
            // Create unique identifiers
            $uniqueId = uniqid();
            $authorizedBrokerEmail = "broker_auth{$data['emailSuffix']}{$uniqueId}@test.com";
            $unauthorizedBrokerEmail = "broker_unauth{$data['emailSuffix']}{$uniqueId}@test.com";
            $staffEmail = "staff{$data['emailSuffix']}{$uniqueId}@test.com";
            
            // Create brokers
            $authorizedBroker = $this->createBroker($authorizedBrokerEmail, 'Authorized Broker');
            $unauthorizedBroker = $this->createBroker($unauthorizedBrokerEmail, 'Unauthorized Broker');
            
            // Create staff and shipment
            $staff = $this->createStaffUser($staffEmail);
            $shipment = $this->createShipment(
                'MAN-' . $uniqueId . '-' . $data['manifestSuffix'],
                $data['billingInfo'],
                $staff
            );
            
            // Authorize only the first broker
            $shipment->addAuthorizedBroker($authorizedBroker);
            $this->entityManager->flush();
            
            // Test authorization
            $authorizedAccess = $this->shipmentService->authorizeAccess(
                $shipment->getId(),
                $authorizedBroker
            );
            $unauthorizedAccess = $this->shipmentService->authorizeAccess(
                $shipment->getId(),
                $unauthorizedBroker
            );
            
            // Assert correct authorization results
            $this->assertTrue(
                $authorizedAccess,
                'Authorized broker should have access'
            );
            $this->assertFalse(
                $unauthorizedAccess,
                'Unauthorized broker should NOT have access'
            );
        });
    }

    /**
     * Property: Search with manifest number filter respects authorization
     * 
     * For any broker searching with a manifest number filter, only authorized
     * shipments matching the filter should be returned.
     */
    public function testSearchWithManifestNumberRespectsAuthorization(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'manifestSuffix' => Generator\nat(),
                'billingInfo' => Generator\string()
            ])
        )->then(function ($data) {
            // Create unique identifiers
            $uniqueId = uniqid();
            $manifestPrefix = 'MAN-' . $uniqueId . '-' . $data['manifestSuffix'];
            $brokerEmail = "broker{$data['emailSuffix']}{$uniqueId}@test.com";
            $staffEmail = "staff{$data['emailSuffix']}{$uniqueId}@test.com";
            
            // Create broker and staff
            $broker = $this->createBroker($brokerEmail, 'Test Broker');
            $staff = $this->createStaffUser($staffEmail);
            
            // Create authorized shipment with matching manifest
            $authorizedShipment = $this->createShipment(
                $manifestPrefix . '-001',
                $data['billingInfo'],
                $staff
            );
            $authorizedShipment->addAuthorizedBroker($broker);
            
            // Create unauthorized shipment with matching manifest
            $unauthorizedShipment = $this->createShipment(
                $manifestPrefix . '-002',
                $data['billingInfo'],
                $staff
            );
            // Don't authorize this one
            
            $this->entityManager->flush();
            
            // Search with manifest number filter
            $results = $this->shipmentService->searchShipments(
                ['manifestNumber' => $manifestPrefix],
                $broker
            );
            
            $resultIds = array_map(fn($s) => $s->getId(), $results);
            
            // Should find authorized shipment
            $this->assertContains(
                $authorizedShipment->getId(),
                $resultIds,
                'Should find authorized shipment matching manifest filter'
            );
            
            // Should NOT find unauthorized shipment even though it matches filter
            $this->assertNotContains(
                $unauthorizedShipment->getId(),
                $resultIds,
                'Should NOT find unauthorized shipment even if it matches manifest filter'
            );
        });
    }

    /**
     * Helper: Create a broker
     */
    private function createBroker(string $email, string $businessName): Broker
    {
        $broker = new Broker();
        $broker->setEmail($email);
        $broker->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $broker->setRole(UserRole::BROKER);
        $broker->setBusinessName($businessName);
        $broker->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

        return $broker;
    }

    /**
     * Helper: Create a staff user
     */
    private function createStaffUser(string $email): StaffUser
    {
        $staff = new StaffUser();
        $staff->setEmail($email);
        $staff->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $staff->setRole(UserRole::SL_STAFF);
        $staff->setFirstName('Test');
        $staff->setLastName('Staff');
        $staff->setDepartment('Operations');
        $staff->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($staff);
        $this->entityManager->flush();

        return $staff;
    }

    /**
     * Helper: Create a shipment record
     */
    private function createShipment(
        string $manifestNumber,
        string $billingInfo,
        StaffUser $staff
    ): ShipmentRecord {
        $shipment = new ShipmentRecord();
        $shipment->setManifestNumber($manifestNumber);
        $shipment->setNoticeOfArrivalDate(new \DateTime());
        $shipment->setBillingInformation($billingInfo);
        $shipment->setCreatedBy($staff);

        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $shipment;
    }
}
