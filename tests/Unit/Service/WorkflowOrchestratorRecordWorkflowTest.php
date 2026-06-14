<?php

namespace App\Tests\Unit\Service;

use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\WorkflowState;
use App\Service\ActivityLogService;
use App\Service\AuditService;
use App\Service\ManifestNotificationService;
use App\Service\WorkflowOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkflowOrchestratorRecordWorkflowTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private WorkflowOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist');

        $this->orchestrator = new WorkflowOrchestrator(
            $this->entityManager,
            $this->createMock(ManifestNotificationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(ActivityLogService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function createActor(): User
    {
        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn(1);
        $actor->method('getRole')->willReturn(UserRole::SL_STAFF);

        return $actor;
    }

    private function createManifest(WorkflowState $state = WorkflowState::MANIFEST_UPLOADED): Manifest
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('TEST-MNF-' . uniqid());
        $manifest->setVesselName('Test Vessel');
        $manifest->setVoyageNumber('V001');

        $idProperty = new \ReflectionProperty(Manifest::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($manifest, random_int(1000, 9999));

        if ($state !== WorkflowState::MANIFEST_UPLOADED) {
            foreach ($this->pathToState($state) as $step) {
                $manifest->transitionTo($step);
            }
        }

        return $manifest;
    }

    /** @return list<WorkflowState> */
    private function pathToState(WorkflowState $target): array
    {
        $path = [];
        $current = WorkflowState::MANIFEST_UPLOADED;

        while ($current !== $target) {
            $next = match ($current) {
                WorkflowState::MANIFEST_UPLOADED => WorkflowState::NOA_GENERATED,
                WorkflowState::NOA_GENERATED => WorkflowState::BL_GENERATED,
                default => throw new \InvalidArgumentException('Unsupported test state path'),
            };

            $path[] = $next;
            $current = $next;
        }

        return $path;
    }

    public function testBootstrapManifestNoaGeneratedTransitionsFromManifestUploaded(): void
    {
        $manifest = $this->createManifest();
        $actor = $this->createActor();

        $this->orchestrator->recordNoaGeneratedWorkflow($manifest, $actor, 'NOA linked');

        $this->assertSame(WorkflowState::NOA_GENERATED, $manifest->getWorkflowState());
    }

    public function testBootstrapManifestBlGeneratedChainsFromManifestUploaded(): void
    {
        $manifest = $this->createManifest();
        $actor = $this->createActor();

        $this->orchestrator->recordBlGeneratedWorkflow($manifest, $actor, 'BL generated');

        $this->assertSame(WorkflowState::BL_GENERATED, $manifest->getWorkflowState());
    }

    public function testBootstrapManifestBlGeneratedIsNoOpWhenAlreadyBlGenerated(): void
    {
        $manifest = $this->createManifest(WorkflowState::BL_GENERATED);
        $actor = $this->createActor();

        $this->orchestrator->recordBlGeneratedWorkflow($manifest, $actor, 'Repeat call');

        $this->assertSame(WorkflowState::BL_GENERATED, $manifest->getWorkflowState());
    }

    public function testBootstrapManifestNoaGeneratedIsNoOpWhenNotAtManifestUploaded(): void
    {
        $manifest = $this->createManifest(WorkflowState::NOA_GENERATED);
        $actor = $this->createActor();

        $this->orchestrator->recordNoaGeneratedWorkflow($manifest, $actor, 'Should not apply');

        $this->assertSame(WorkflowState::NOA_GENERATED, $manifest->getWorkflowState());
    }
}
