<?php

namespace App\Tests\Service;

use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\UserRole;
use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\StaffUser;
use App\Service\ManifestWorkflowDisplayService;
use PHPUnit\Framework\TestCase;

class ManifestWorkflowDisplayServiceTest extends TestCase
{
    private ManifestWorkflowDisplayService $service;

    protected function setUp(): void
    {
        $this->service = new ManifestWorkflowDisplayService();
    }

    public function testNullManifestIsStepOne(): void
    {
        $this->assertSame(1, $this->service->getCurrentStep(null));
        $this->assertFalse($this->service->isComplete(null));
    }

    public function testPaymentSubmittedAndVerifiedSteps(): void
    {
        $submitted = $this->manifestWithState(WorkflowState::PAYMENT_SUBMITTED);
        $verified = $this->manifestWithState(WorkflowState::PAYMENT_VERIFIED);

        $this->assertSame(5, $this->service->getCurrentStep($submitted));
        $this->assertSame(6, $this->service->getCurrentStep($verified));
    }

    public function testReleasedIsComplete(): void
    {
        $manifest = $this->manifestWithState(WorkflowState::EDO_RELEASED);

        $this->assertSame(8, $this->service->getCurrentStep($manifest));
        $this->assertTrue($this->service->isComplete($manifest));
    }

    public function testShippingLinesHubRolesRedirectToNoaDetail(): void
    {
        $manifest = $this->manifestWithState(WorkflowState::NOA_GENERATED);
        $this->setEntityId($manifest, 176);
        $noa = new NOA();
        $this->setEntityId($noa, 204);
        $manifest->setNoa($noa);

        $slStaff = $this->staffUser(UserRole::SL_STAFF);
        $accounting = $this->staffUser(UserRole::ACCOUNTING);

        $this->assertTrue($this->service->shouldRedirectManifestDetailToNoa($manifest, $slStaff));
        $this->assertTrue($this->service->shouldRedirectManifestDetailToNoa($manifest, $accounting));

        [$slRoute, $slParams] = $this->service->resolveWorkflowDetailRoute($manifest, $slStaff);
        $this->assertSame('manifest_workflow_noa_detail', $slRoute);
        $this->assertSame(204, $slParams['id']);

        [$accountingRoute, $accountingParams] = $this->service->resolveWorkflowDetailRoute($manifest, $accounting);
        $this->assertSame('manifest_workflow_noa_detail', $accountingRoute);
        $this->assertSame(204, $accountingParams['id']);
    }

    public function testManifestWithoutNoaStaysOnManifestDetail(): void
    {
        $manifest = $this->manifestWithState(WorkflowState::MANIFEST_UPLOADED);
        $this->setEntityId($manifest, 176);
        $slStaff = $this->staffUser(UserRole::SL_STAFF);

        $this->assertFalse($this->service->shouldRedirectManifestDetailToNoa($manifest, $slStaff));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }

    private function staffUser(UserRole $role): StaffUser
    {
        $user = new StaffUser();
        $user->setRole($role);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Ops');

        return $user;
    }

    private function manifestWithState(WorkflowState $state): Manifest
    {
        $manifest = new Manifest();
        $manifest->setWorkflowState($state);

        return $manifest;
    }
}
