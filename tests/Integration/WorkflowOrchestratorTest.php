<?php

namespace App\Tests\Integration;

use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\WorkflowStateHistory;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\UserRole;
use App\Service\WorkflowOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class WorkflowOrchestratorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WorkflowOrchestrator $orchestrator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->orchestrator = $container->get(WorkflowOrchestrator::class);
    }

    public function testTransitionStateCreatesHistory(): void
    {
        // Create test data
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $manifest = $this->createTestManifest($user);
        $manifest->setWorkflowState(WorkflowState::PENDING_PAYMENT);
        $this->entityManager->flush();
        
        // Transition state
        $this->orchestrator->transitionState(
            $manifest,
            WorkflowState::PAYMENT_VERIFIED,
            $user,
            'Test transition'
        );
        $this->entityManager->flush();
        
        // Verify state changed
        $this->assertEquals(WorkflowState::PAYMENT_VERIFIED, $manifest->getWorkflowState());
        
        // Verify history was created
        $history = $this->orchestrator->getWorkflowHistory($manifest);
        $this->assertNotEmpty($history);
        $this->assertInstanceOf(WorkflowStateHistory::class, $history[0]);
        $this->assertEquals(WorkflowState::PENDING_PAYMENT->value, $history[0]->getFromState());
        $this->assertEquals(WorkflowState::PAYMENT_VERIFIED->value, $history[0]->getToState());
        $this->assertEquals($user->getId(), $history[0]->getActor()->getId());
        $this->assertEquals('Test transition', $history[0]->getTransitionReason());
        
        // Cleanup
        $this->cleanup($manifest, $user);
    }

    public function testInvalidTransitionThrowsException(): void
    {
        // Create test data
        $user = $this->createTestUser(UserRole::SL_STAFF);
        $manifest = $this->createTestManifest($user);
        $manifest->setWorkflowState(WorkflowState::PENDING_PAYMENT);
        $this->entityManager->flush();
        
        // Attempt invalid transition (skip payment_verified)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid state transition');
        
        $this->orchestrator->transitionState(
            $manifest,
            WorkflowState::NOA_GENERATED,
            $user
        );
        
        // Cleanup
        $this->cleanup($manifest, $user);
    }

    public function testGetWorkflowHistoryReturnsOrderedHistory(): void
    {
        // Create test data
        $user = $this->createTestUser(UserRole::SL_STAFF);
        $manifest = $this->createTestManifest($user);
        $manifest->setWorkflowState(WorkflowState::PENDING_PAYMENT);
        $this->entityManager->flush();
        
        // Perform multiple transitions
        $this->orchestrator->transitionState(
            $manifest,
            WorkflowState::PAYMENT_VERIFIED,
            $user,
            'First transition'
        );
        $this->entityManager->flush();
        
        $this->orchestrator->transitionState(
            $manifest,
            WorkflowState::NOA_GENERATED,
            $user,
            'Second transition'
        );
        $this->entityManager->flush();
        
        // Get history
        $history = $this->orchestrator->getWorkflowHistory($manifest);
        
        // Verify history is ordered (most recent first)
        $this->assertCount(2, $history);
        $this->assertEquals(WorkflowState::NOA_GENERATED->value, $history[0]->getToState());
        $this->assertEquals(WorkflowState::PAYMENT_VERIFIED->value, $history[1]->getToState());
        
        // Cleanup
        $this->cleanup($manifest, $user);
    }

    private function createTestUser(UserRole $role): User
    {
        $user = new User();
        $user->setEmail('test_' . uniqid() . '@example.com');
        $user->setPassword('test_password');
        $user->setRole($role);
        $user->setFirstName('Test');
        $user->setLastName('User');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createTestManifest(User $creator): Manifest
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('TEST-' . uniqid());
        $manifest->setCreatedBy($creator);
        $manifest->setVesselName('Test Vessel');
        $manifest->setVoyageNumber('V123');
        
        $this->entityManager->persist($manifest);
        $this->entityManager->flush();
        
        return $manifest;
    }

    private function cleanup(Manifest $manifest, User $user): void
    {
        // Clean up test data
        $this->entityManager->remove($manifest);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
