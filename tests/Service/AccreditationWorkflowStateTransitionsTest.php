<?php

namespace App\Tests\Service;

use App\Entity\AccreditationSubmission;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\FormConfiguration;
use App\Entity\StaffUser;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use App\Service\AccreditationWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 4: Accreditation workflow state transitions
 * 
 * For any accreditation submission, status transitions should follow the valid workflow:
 * Pending → (Evaluator Review) → Approved/Denied/Rejected/Compliance Required → 
 * (Admin Review if Approved) → Final Approved/Denied.
 * 
 * Validates: Requirements 4.3, 5.1
 */
class AccreditationWorkflowStateTransitionsTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private AccreditationWorkflowService $accreditationService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->accreditationService = $container->get(AccreditationWorkflowService::class);

        // Configure Eris
        $this->minimumEvaluationRatio = 0.5;
        $this->iterations = 10;

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
     * Property: Evaluator can only evaluate pending submissions
     * 
     * For any submission that is not in PENDING status, attempting to evaluate
     * should fail.
     */
    public function testEvaluatorCanOnlyEvaluatePendingSubmissions(): void
    {
        $this->forAll(
            Generator\elements(
                AccreditationStatus::APPROVED,
                AccreditationStatus::DENIED,
                AccreditationStatus::REJECTED,
                AccreditationStatus::COMPLIANCE_REQUIRED
            )
        )->then(function ($nonPendingStatus) {
            // Create a submission with non-pending status
            $submission = $this->createSubmission();
            $submission->setStatus($nonPendingStatus);
            $this->entityManager->flush();

            // Create an evaluator
            $evaluator = $this->createEvaluator();

            // Attempt to evaluate should throw exception
            $exceptionThrown = false;
            try {
                $this->accreditationService->evaluateApplication(
                    $submission->getId(),
                    $evaluator,
                    AccreditationStatus::APPROVED
                );
            } catch (\InvalidArgumentException $e) {
                $exceptionThrown = true;
                $this->assertStringContainsString('pending', strtolower($e->getMessage()));
            }

            $this->assertTrue($exceptionThrown, 'Evaluating non-pending submission should throw exception');
        });
    }

    /**
     * Property: Evaluator can transition pending to valid states
     * 
     * For any pending submission, an evaluator should be able to transition it to
     * APPROVED, DENIED, REJECTED, or COMPLIANCE_REQUIRED.
     */
    public function testEvaluatorCanTransitionPendingToValidStates(): void
    {
        $this->forAll(
            Generator\elements(
                AccreditationStatus::APPROVED,
                AccreditationStatus::DENIED,
                AccreditationStatus::REJECTED,
                AccreditationStatus::COMPLIANCE_REQUIRED
            )
        )->then(function ($targetStatus) {
            // Create a pending submission
            $submission = $this->createSubmission();
            $this->entityManager->flush();

            // Create an evaluator
            $evaluator = $this->createEvaluator();

            // Evaluate the submission
            $this->accreditationService->evaluateApplication(
                $submission->getId(),
                $evaluator,
                $targetStatus,
                'Test reason'
            );

            // Refresh submission from database
            $this->entityManager->refresh($submission);

            // Assert status was changed
            $this->assertEquals($targetStatus, $submission->getStatus());
            $this->assertEquals($evaluator->getId(), $submission->getEvaluator()->getId());
            $this->assertNotNull($submission->getEvaluatedAt());
        });
    }

    /**
     * Property: Only Shipping Lines Admin can perform final approval
     * 
     * For any evaluator-approved submission, only a user with SHIPPING_LINES_ADMIN
     * role should be able to perform final approval.
     */
    public function testOnlyAdminCanPerformFinalApproval(): void
    {
        $this->forAll(
            Generator\elements(
                UserRole::EVALUATOR,
                UserRole::SL_STAFF,
                UserRole::ACCOUNTING,
                UserRole::SYSTEM_ADMIN
            )
        )->then(function ($nonAdminRole) {
            // Create an evaluator-approved submission
            $submission = $this->createSubmission();
            $evaluator = $this->createEvaluator();
            $submission->setStatus(AccreditationStatus::APPROVED);
            $submission->setEvaluator($evaluator);
            $submission->setEvaluatedAt(new \DateTime());
            $this->entityManager->flush();

            // Create a non-admin user
            $nonAdmin = $this->createStaffUser($nonAdminRole);

            // Attempt final approval should throw exception
            $exceptionThrown = false;
            try {
                $this->accreditationService->finalApproval(
                    $submission->getId(),
                    $nonAdmin,
                    true
                );
            } catch (\InvalidArgumentException $e) {
                $exceptionThrown = true;
                $this->assertStringContainsString('admin', strtolower($e->getMessage()));
            }

            $this->assertTrue($exceptionThrown, 'Non-admin performing final approval should throw exception');
        });
    }

    /**
     * Property: Admin can only perform final approval on evaluator-approved submissions
     * 
     * For any submission not in evaluator-approved state, admin final approval should fail.
     */
    public function testAdminCanOnlyApprovEvaluatorApprovedSubmissions(): void
    {
        $this->forAll(
            Generator\elements(
                AccreditationStatus::PENDING,
                AccreditationStatus::DENIED,
                AccreditationStatus::REJECTED,
                AccreditationStatus::COMPLIANCE_REQUIRED
            )
        )->then(function ($nonApprovedStatus) {
            // Create a submission with non-approved status
            $submission = $this->createSubmission();
            $submission->setStatus($nonApprovedStatus);
            $this->entityManager->flush();

            // Create an admin
            $admin = $this->createAdmin();

            // Attempt final approval should throw exception
            $exceptionThrown = false;
            try {
                $this->accreditationService->finalApproval(
                    $submission->getId(),
                    $admin,
                    true
                );
            } catch (\InvalidArgumentException $e) {
                $exceptionThrown = true;
            }

            $this->assertTrue($exceptionThrown, 'Final approval on non-evaluator-approved submission should throw exception');
        });
    }

    /**
     * Property: Final approval updates user account status
     * 
     * For any evaluator-approved submission, when admin performs final approval,
     * the applicant's account status should be updated accordingly.
     */
    public function testFinalApprovalUpdatesUserAccountStatus(): void
    {
        $this->forAll(
            Generator\bool()
        )->then(function ($approved) {
            // Create an evaluator-approved submission
            $submission = $this->createSubmission();
            $evaluator = $this->createEvaluator();
            $submission->setStatus(AccreditationStatus::APPROVED);
            $submission->setEvaluator($evaluator);
            $submission->setEvaluatedAt(new \DateTime());
            $this->entityManager->flush();

            // Create an admin
            $admin = $this->createAdmin();

            // Perform final approval
            $this->accreditationService->finalApproval(
                $submission->getId(),
                $admin,
                $approved,
                $approved ? null : 'Test denial reason'
            );

            // Refresh entities
            $this->entityManager->refresh($submission);
            $applicant = $submission->getApplicant();
            $this->entityManager->refresh($applicant);

            // Assert account status was updated
            if ($approved) {
                $this->assertEquals(AccountStatus::APPROVED, $applicant->getStatus());
                $this->assertEquals(AccreditationStatus::APPROVED, $submission->getStatus());
                $this->assertNotNull($submission->getApprovedAt());
            } else {
                $this->assertEquals(AccountStatus::DENIED, $applicant->getStatus());
                $this->assertEquals(AccreditationStatus::DENIED, $submission->getStatus());
                $this->assertNotNull($submission->getDenialReason());
            }

            $this->assertEquals($admin->getId(), $submission->getFinalApprover()->getId());
        });
    }

    /**
     * Helper: Create a submission
     */
    private function createSubmission(): AccreditationSubmission
    {
        // Create a broker
        $broker = new Broker();
        $broker->setEmail('broker' . uniqid() . '@test.com');
        $broker->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $broker->setRole(UserRole::BROKER);
        $broker->setBusinessName('Test Broker');
        $broker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->persist($broker);

        // Create a consignee
        $consignee = new Consignee();
        $consignee->setEmail('consignee' . uniqid() . '@test.com');
        $consignee->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('Test Consignee');
        $consignee->setStatus(AccountStatus::PENDING);
        $consignee->setLinkedBroker($broker);
        $this->entityManager->persist($consignee);

        // Create a form configuration
        $form = new FormConfiguration();
        $form->setName('Test Form');
        $form->setType(FormType::CONSIGNEE);
        $form->setStatus(FormStatus::PUBLISHED);
        $form->setVersion(1);
        $form->setFields(['fields' => []]);
        $form->setPublishedAt(new \DateTime());
        $this->entityManager->persist($form);

        // Create a submission
        $submission = new AccreditationSubmission();
        $submission->setApplicant($consignee);
        $submission->setFormConfig($form);
        $submission->setSubmittedData([]);
        $submission->setStatus(AccreditationStatus::PENDING);
        $this->entityManager->persist($submission);

        $this->entityManager->flush();

        return $submission;
    }

    /**
     * Helper: Create an evaluator
     */
    private function createEvaluator(): StaffUser
    {
        return $this->createStaffUser(UserRole::EVALUATOR);
    }

    /**
     * Helper: Create an admin
     */
    private function createAdmin(): StaffUser
    {
        return $this->createStaffUser(UserRole::SHIPPING_LINES_ADMIN);
    }

    /**
     * Helper: Create a staff user with specified role
     */
    private function createStaffUser(UserRole $role): StaffUser
    {
        $staff = new StaffUser();
        $staff->setEmail('staff' . uniqid() . '@test.com');
        $staff->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $staff->setRole($role);
        $staff->setFirstName('Test');
        $staff->setLastName('User');
        $staff->setDepartment('Test Department');
        $staff->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($staff);
        $this->entityManager->flush();

        return $staff;
    }
}
