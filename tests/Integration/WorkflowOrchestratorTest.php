<?php

namespace App\Tests\Integration;

use App\Entity\Manifest;
use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Entity\User;
use App\Entity\WorkflowStateHistory;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
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

        $this->skipIfIntegrationSchemaIncomplete();
    }

    private function skipIfIntegrationSchemaIncomplete(): void
    {
        $connection = $this->entityManager->getConnection();
        $requiredColumns = [
            ['users', 'is_active'],
            ['users', 'suspension_attachments'],
            ['shipping_lines', 'logo_path'],
            ['shipping_lines', 'brand_color'],
        ];

        foreach ($requiredColumns as [$table, $column]) {
            $present = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            if ($present === 0) {
                self::markTestSkipped(
                    sprintf(
                        'Integration test database schema is incomplete (missing %s.%s). Sync with: php bin/console doctrine:schema:update --force --env=test',
                        $table,
                        $column
                    )
                );
            }
        }
    }

    public function testTransitionStateCreatesHistory(): void
    {
        // Create test data
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $manifest = $this->createTestManifest($user);
        $manifest->setWorkflowState(WorkflowState::PAYMENT_SUBMITTED);
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
        $this->assertEquals(WorkflowState::PAYMENT_SUBMITTED->value, $history[0]->getFromState());
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
        $manifest->setWorkflowState(WorkflowState::PAYMENT_SUBMITTED);
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
        $manifest->setWorkflowState(WorkflowState::MANIFEST_UPLOADED);
        $this->entityManager->flush();
        
        // Perform multiple transitions
        $this->orchestrator->transitionState(
            $manifest,
            WorkflowState::NOA_GENERATED,
            $user,
            'First transition'
        );
        $this->entityManager->flush();
        
        $this->orchestrator->transitionState(
            $manifest,
            WorkflowState::BL_GENERATED,
            $user,
            'Second transition'
        );
        $this->entityManager->flush();
        
        // Get history
        $history = $this->orchestrator->getWorkflowHistory($manifest);
        
        // Verify history is ordered (most recent first)
        $this->assertCount(2, $history);
        $this->assertEquals(WorkflowState::BL_GENERATED->value, $history[0]->getToState());
        $this->assertEquals(WorkflowState::NOA_GENERATED->value, $history[1]->getToState());
        
        // Cleanup
        $this->cleanup($manifest, $user);
    }

    public function testBootstrapManifestNoaGeneratedCreatesHistory(): void
    {
        $user = $this->createTestUser(UserRole::SL_STAFF);
        $manifest = $this->createTestManifest($user);
        $this->assertEquals(WorkflowState::MANIFEST_UPLOADED, $manifest->getWorkflowState());

        $this->orchestrator->recordNoaGeneratedWorkflow($manifest, $user, 'NOA linked');
        $this->entityManager->flush();

        $this->assertEquals(WorkflowState::NOA_GENERATED, $manifest->getWorkflowState());

        $history = $this->orchestrator->getWorkflowHistory($manifest);
        $this->assertCount(1, $history);
        $this->assertEquals(WorkflowState::MANIFEST_UPLOADED->value, $history[0]->getFromState());
        $this->assertEquals(WorkflowState::NOA_GENERATED->value, $history[0]->getToState());

        $this->cleanup($manifest, $user);
    }

    public function testBootstrapManifestBlGeneratedChainsTransitions(): void
    {
        $user = $this->createTestUser(UserRole::SL_STAFF);
        $manifest = $this->createTestManifest($user);
        $this->assertEquals(WorkflowState::MANIFEST_UPLOADED, $manifest->getWorkflowState());

        $this->orchestrator->recordBlGeneratedWorkflow($manifest, $user, 'BL PDF generated');
        $this->entityManager->flush();

        $this->assertEquals(WorkflowState::BL_GENERATED, $manifest->getWorkflowState());

        $history = $this->orchestrator->getWorkflowHistory($manifest);
        $this->assertCount(2, $history);
        $this->assertEquals(WorkflowState::BL_GENERATED->value, $history[0]->getToState());
        $this->assertEquals(WorkflowState::NOA_GENERATED->value, $history[1]->getToState());

        $this->cleanup($manifest, $user);
    }

    public function testBootstrapManifestBlGeneratedIsIdempotentWhenAlreadyBlGenerated(): void
    {
        $user = $this->createTestUser(UserRole::SL_STAFF);
        $manifest = $this->createTestManifest($user);

        $this->orchestrator->recordBlGeneratedWorkflow($manifest, $user, 'First call');
        $this->entityManager->flush();

        $this->orchestrator->recordBlGeneratedWorkflow($manifest, $user, 'Second call');
        $this->entityManager->flush();

        $this->assertEquals(WorkflowState::BL_GENERATED, $manifest->getWorkflowState());
        $this->assertCount(2, $this->orchestrator->getWorkflowHistory($manifest));

        $this->cleanup($manifest, $user);
    }

    private function createTestUser(UserRole $role): User
    {
        $user = new StaffUser();
        $user->setEmail('test_' . uniqid() . '@example.com');
        $user->setPasswordHash('test_password_hash');
        $user->setRole($role);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Testing');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createTestManifest(User $creator): Manifest
    {
        $shippingLine = $this->createTestShippingLine();

        $manifest = new Manifest();
        $manifest->setManifestNumber('TEST-' . uniqid());
        $manifest->setCreatedBy($creator);
        $manifest->setShippingLine($shippingLine);
        $manifest->setVesselName('Test Vessel');
        $manifest->setVoyageNumber('V123');
        
        $this->entityManager->persist($manifest);
        $this->entityManager->flush();
        
        return $manifest;
    }

    private function createTestShippingLine(): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line ' . uniqid());

        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();

        return $shippingLine;
    }

    private function cleanup(Manifest $manifest, User $user): void
    {
        foreach ($this->orchestrator->getWorkflowHistory($manifest) as $history) {
            $this->entityManager->remove($history);
        }

        $shippingLine = $manifest->getShippingLine();

        $this->entityManager->remove($manifest);
        if ($shippingLine !== null) {
            $this->entityManager->remove($shippingLine);
        }

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM audit_logs WHERE user_id = ?',
            [$user->getId()]
        );

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
