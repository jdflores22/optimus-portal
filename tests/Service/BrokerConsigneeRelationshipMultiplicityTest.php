<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Service\AccreditationWorkflowService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 14: Broker-consignee relationship multiplicity
 * For any broker, the system should maintain linkages to multiple consignees, 
 * and each linkage should be independently queryable.
 */
class BrokerConsigneeRelationshipMultiplicityTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private AccreditationWorkflowService $accreditationService;
    private UserService $userService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->accreditationService = $container->get(AccreditationWorkflowService::class);
        $this->userService = $container->get(UserService::class);
        
        // Configure Eris
        $this->minimumEvaluationRatio = 0.5;
        $this->iterations = 100;
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->entityManager->createQuery('DELETE FROM App\Entity\Consignee')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Broker')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        
        parent::tearDown();
    }

    /**
     * Property 14: Broker-consignee relationship multiplicity
     * 
     * Tests that:
     * 1. A broker can be linked to multiple consignees
     * 2. Each linkage is maintained independently
     * 3. Each linkage is queryable independently
     * 4. Removing one linkage doesn't affect others
     * 
     * **Validates: Requirements 3.5**
     */
    public function testBrokerConsigneeRelationshipMultiplicity(): void
    {
        $this->forAll(
            Generator\choose(2, 4) // Number of consignees to create (2-4)
        )->then(function (int $consigneeCount) {
            // Create a broker
            $brokerData = [
                'email' => 'broker_' . uniqid() . '@example.com',
                'password' => 'TestPassword123!',
                'businessName' => 'Test Broker ' . uniqid()
            ];
            
            $broker = $this->userService->createUser($brokerData, UserRole::BROKER);
            $broker->setStatus(AccountStatus::APPROVED);
            $this->entityManager->flush();
            
            // Create multiple consignees
            $consignees = [];
            for ($i = 0; $i < $consigneeCount; $i++) {
                $consigneeData = [
                    'email' => 'consignee_' . $i . '_' . uniqid() . '@example.com',
                    'password' => 'TestPassword123!',
                    'businessName' => 'Test Consignee ' . $i . ' ' . uniqid()
                ];
                
                $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);
                $consignee->setStatus(AccountStatus::APPROVED);
                $consignees[] = $consignee;
            }
            $this->entityManager->flush();
            
            // Link all consignees to the broker
            foreach ($consignees as $consignee) {
                $this->accreditationService->linkBrokerToConsignee($consignee, $broker);
            }
            
            // Refresh entities to get updated relationships
            $this->entityManager->refresh($broker);
            foreach ($consignees as $consignee) {
                $this->entityManager->refresh($consignee);
            }
            
            // Property 1: Broker should have all consignees linked
            $linkedConsignees = $broker->getLinkedConsignees();
            $this->assertEquals(
                $consigneeCount, 
                $linkedConsignees->count(),
                'Broker should have all consignees linked'
            );
            
            // Property 2: Each consignee should be independently queryable
            foreach ($consignees as $expectedConsignee) {
                $found = false;
                foreach ($linkedConsignees as $linkedConsignee) {
                    if ($linkedConsignee->getId() === $expectedConsignee->getId()) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $found,
                    'Each consignee should be independently queryable in broker\'s linked consignees'
                );
            }
            
            // Property 3: Each consignee should have the broker linked
            foreach ($consignees as $consignee) {
                $this->assertNotNull(
                    $consignee->getLinkedBroker(),
                    'Each consignee should have the broker linked'
                );
                $this->assertEquals(
                    $broker->getId(),
                    $consignee->getLinkedBroker()->getId(),
                    'Each consignee should be linked to the correct broker'
                );
            }
            
            // Property 4: Removing one linkage doesn't affect others
            if ($consigneeCount > 1) {
                $consigneeToRemove = $consignees[0];
                $remainingConsignees = array_slice($consignees, 1);
                
                // Remove the linkage
                $consigneeToRemove->setLinkedBroker(null);
                $this->entityManager->flush();
                
                // Refresh entities
                $this->entityManager->refresh($broker);
                foreach ($remainingConsignees as $consignee) {
                    $this->entityManager->refresh($consignee);
                }
                
                // Check that other linkages are still intact
                $linkedConsigneesAfterRemoval = $broker->getLinkedConsignees();
                $this->assertEquals(
                    $consigneeCount - 1,
                    $linkedConsigneesAfterRemoval->count(),
                    'Removing one linkage should not affect others - count should decrease by 1'
                );
                
                // Check that remaining consignees are still linked
                foreach ($remainingConsignees as $remainingConsignee) {
                    $this->assertNotNull(
                        $remainingConsignee->getLinkedBroker(),
                        'Remaining consignees should still be linked to broker'
                    );
                    $this->assertEquals(
                        $broker->getId(),
                        $remainingConsignee->getLinkedBroker()->getId(),
                        'Remaining consignees should still be linked to the correct broker'
                    );
                }
                
                // Check that removed consignee is no longer linked
                $this->entityManager->refresh($consigneeToRemove);
                $this->assertNull(
                    $consigneeToRemove->getLinkedBroker(),
                    'Removed consignee should no longer be linked to broker'
                );
            }
        });
    }

    /**
     * Test that multiple brokers can each have their own set of consignees
     */
    public function testMultipleBrokersIndependentConsigneeManagement(): void
    {
        $this->forAll(
            Generator\choose(2, 3), // Number of brokers
            Generator\choose(1, 3)  // Number of consignees per broker
        )->then(function (int $brokerCount, int $consigneesPerBroker) {
            $brokers = [];
            $allConsignees = [];
            
            // Create multiple brokers, each with their own consignees
            for ($b = 0; $b < $brokerCount; $b++) {
                // Create broker
                $brokerData = [
                    'email' => 'broker_' . $b . '_' . uniqid() . '@example.com',
                    'password' => 'TestPassword123!',
                    'businessName' => 'Test Broker ' . $b . ' ' . uniqid()
                ];
                
                $broker = $this->userService->createUser($brokerData, UserRole::BROKER);
                $broker->setStatus(AccountStatus::APPROVED);
                $brokers[] = $broker;
                
                // Create consignees for this broker
                $brokerConsignees = [];
                for ($c = 0; $c < $consigneesPerBroker; $c++) {
                    $consigneeData = [
                        'email' => 'consignee_' . $b . '_' . $c . '_' . uniqid() . '@example.com',
                        'password' => 'TestPassword123!',
                        'businessName' => 'Test Consignee ' . $b . '_' . $c . ' ' . uniqid()
                    ];
                    
                    $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);
                    $consignee->setStatus(AccountStatus::APPROVED);
                    $brokerConsignees[] = $consignee;
                    $allConsignees[] = $consignee;
                }
                
                $this->entityManager->flush();
                
                // Link consignees to this broker
                foreach ($brokerConsignees as $consignee) {
                    $this->accreditationService->linkBrokerToConsignee($consignee, $broker);
                }
            }
            
            // Refresh all entities
            foreach ($brokers as $broker) {
                $this->entityManager->refresh($broker);
            }
            foreach ($allConsignees as $consignee) {
                $this->entityManager->refresh($consignee);
            }
            
            // Property: Each broker should have exactly their own consignees
            foreach ($brokers as $brokerIndex => $broker) {
                $linkedConsignees = $broker->getLinkedConsignees();
                $this->assertEquals(
                    $consigneesPerBroker,
                    $linkedConsignees->count(),
                    "Broker {$brokerIndex} should have exactly {$consigneesPerBroker} consignees"
                );
                
                // Verify that this broker's consignees are correctly linked
                foreach ($linkedConsignees as $linkedConsignee) {
                    $this->assertEquals(
                        $broker->getId(),
                        $linkedConsignee->getLinkedBroker()->getId(),
                        'Each linked consignee should reference back to the correct broker'
                    );
                }
            }
            
            // Property: No consignee should be linked to multiple brokers
            foreach ($allConsignees as $consignee) {
                $linkedBroker = $consignee->getLinkedBroker();
                $this->assertNotNull($linkedBroker, 'Each consignee should be linked to exactly one broker');
                
                // Count how many brokers claim this consignee
                $claimingBrokers = 0;
                foreach ($brokers as $broker) {
                    foreach ($broker->getLinkedConsignees() as $linkedConsignee) {
                        if ($linkedConsignee->getId() === $consignee->getId()) {
                            $claimingBrokers++;
                        }
                    }
                }
                
                $this->assertEquals(
                    1,
                    $claimingBrokers,
                    'Each consignee should be claimed by exactly one broker'
                );
            }
        });
    }
}