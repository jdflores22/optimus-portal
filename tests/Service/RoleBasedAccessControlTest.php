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
use App\Service\AuditService;
use App\Service\FormBuilderService;
use App\Service\DynamicFormRenderer;
use App\Service\UserService;
use App\Service\ValidationService;
use App\Service\NotificationService;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Feature: optimus-shipping-portal, Property 11: Role-based access control
 * 
 * For any user attempting to access a resource, access should be granted only if 
 * the user's role has the required permissions for that resource.
 * 
 * Validates: Requirements 8.2, 8.5
 */
class RoleBasedAccessControlTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private AccreditationWorkflowService $accreditationService;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->userService = new UserService($this->entityManager, $this->passwordHasher);
        
        $formBuilderService = new FormBuilderService($this->entityManager);
        $validator = $container->get(ValidatorInterface::class);
        $validationService = new ValidationService($validator);
        $formRenderer = new DynamicFormRenderer($validationService);
        $auditService = $container->get(AuditService::class);
        $notificationService = $container->get(NotificationService::class);
        $this->accreditationService = new AccreditationWorkflowService(
            $this->entityManager,
            $formBuilderService,
            $formRenderer,
            $auditService,
            $notificationService
        );
        
        // Configure Eris
        $this->minimumEvaluationRatio = 0.5;
        $this->iterations = 100;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE audit_logs');
        $connection->executeStatement('TRUNCATE TABLE accreditation_submissions');
        $connection->executeStatement('TRUNCATE TABLE form_configurations');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('TRUNCATE TABLE consignees');
        $connection->executeStatement('TRUNCATE TABLE brokers');
        $connection->executeStatement('TRUNCATE TABLE staff_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    /**
     * Property: Only evaluators can evaluate accreditation applications
     */
    public function testOnlyEvaluatorsCanEvaluateApplications(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'evaluatorEmail' => 'eval' . strtolower(preg_replace('/[^a-z0-9]/', '', $parts[0])) . '@test.com',
                        'nonEvaluatorEmail' => 'user' . strtolower(preg_replace('/[^a-z0-9]/', '', $parts[1])) . '@test.com',
                        'password' => $parts[2] . 'Pass123!',
                        'nonEvaluatorRole' => $parts[3],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\string(),
                    Generator\string(),
                    Generator\elements(
                        UserRole::CONSIGNEE,
                        UserRole::BROKER,
                        UserRole::SL_STAFF,
                        UserRole::ACCOUNTING,
                        UserRole::SHIPPING_LINES_ADMIN
                    )
                )
            )
        )->then(function ($userData) {
            // Create an evaluator
            $evaluator = $this->createStaffUser($userData['evaluatorEmail'], $userData['password'], UserRole::EVALUATOR);
            
            // Create a non-evaluator user
            $nonEvaluator = $this->createUserByRole($userData['nonEvaluatorEmail'], $userData['password'], $userData['nonEvaluatorRole']);
            
            // Create a test submission
            $submission = $this->createTestSubmission();
            
            // Evaluator should be able to evaluate
            try {
                $this->accreditationService->evaluateApplication(
                    $submission->getId(),
                    $evaluator,
                    AccreditationStatus::APPROVED,
                    null
                );
                $evaluatorCanEvaluate = true;
            } catch (\InvalidArgumentException $e) {
                $evaluatorCanEvaluate = false;
            }
            
            $this->assertTrue($evaluatorCanEvaluate, 'Evaluator should be able to evaluate applications');
            
            // Reset submission status for next test
            $submission->setStatus(AccreditationStatus::PENDING);
            $submission->setEvaluator(null);
            $submission->setEvaluatedAt(null);
            $this->entityManager->flush();
            
            // Non-evaluator should NOT be able to evaluate
            try {
                $this->accreditationService->evaluateApplication(
                    $submission->getId(),
                    $nonEvaluator,
                    AccreditationStatus::APPROVED,
                    null
                );
                $nonEvaluatorCanEvaluate = true;
            } catch (\InvalidArgumentException $e) {
                $nonEvaluatorCanEvaluate = false;
                $this->assertStringContainsString('evaluator', strtolower($e->getMessage()), 
                    'Error message should mention evaluator role requirement');
            }
            
            $this->assertFalse($nonEvaluatorCanEvaluate, 
                'Non-evaluator with role ' . $userData['nonEvaluatorRole']->value . ' should NOT be able to evaluate applications');
            
            // Clean up - delete audit logs first
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM audit_logs WHERE user_id IN (?, ?)', 
                [$evaluator->getId(), $nonEvaluator->getId()]);
            
            $this->entityManager->remove($submission->getApplicant());
            $this->entityManager->remove($submission->getFormConfig());
            $this->entityManager->remove($submission);
            $this->entityManager->remove($evaluator);
            $this->entityManager->remove($nonEvaluator);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    /**
     * Property: Only Shipping Lines Admin can perform final approval
     */
    public function testOnlyShippingLinesAdminCanPerformFinalApproval(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'adminEmail' => 'admin' . strtolower(preg_replace('/[^a-z0-9]/', '', $parts[0])) . '@test.com',
                        'nonAdminEmail' => 'user' . strtolower(preg_replace('/[^a-z0-9]/', '', $parts[1])) . '@test.com',
                        'password' => $parts[2] . 'Pass123!',
                        'nonAdminRole' => $parts[3],
                        'approvalDecision' => $parts[4],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\string(),
                    Generator\string(),
                    Generator\elements(
                        UserRole::CONSIGNEE,
                        UserRole::BROKER,
                        UserRole::EVALUATOR,
                        UserRole::SL_STAFF,
                        UserRole::ACCOUNTING
                    ),
                    Generator\bool()
                )
            )
        )->then(function ($userData) {
            // Create a Shipping Lines Admin
            $admin = $this->createStaffUser($userData['adminEmail'], $userData['password'], UserRole::SHIPPING_LINES_ADMIN);
            
            // Create a non-admin user
            $nonAdmin = $this->createUserByRole($userData['nonAdminEmail'], $userData['password'], $userData['nonAdminRole']);
            
            // Create a test submission that has been evaluator-approved
            $submission = $this->createEvaluatorApprovedSubmission();
            
            // Admin should be able to perform final approval
            try {
                $this->accreditationService->finalApproval(
                    $submission->getId(),
                    $admin,
                    $userData['approvalDecision'],
                    null
                );
                $adminCanApprove = true;
            } catch (\InvalidArgumentException $e) {
                $adminCanApprove = false;
            }
            
            $this->assertTrue($adminCanApprove, 'Shipping Lines Admin should be able to perform final approval');
            
            // Create another submission for non-admin test
            $submission2 = $this->createEvaluatorApprovedSubmission();
            
            // Non-admin should NOT be able to perform final approval
            try {
                $this->accreditationService->finalApproval(
                    $submission2->getId(),
                    $nonAdmin,
                    $userData['approvalDecision'],
                    null
                );
                $nonAdminCanApprove = true;
            } catch (\InvalidArgumentException $e) {
                $nonAdminCanApprove = false;
                $this->assertStringContainsString('admin', strtolower($e->getMessage()), 
                    'Error message should mention admin role requirement');
            }
            
            $this->assertFalse($nonAdminCanApprove, 
                'Non-admin with role ' . $userData['nonAdminRole']->value . ' should NOT be able to perform final approval');
            
            // Clean up - delete audit logs first
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM audit_logs WHERE user_id IN (?, ?)', 
                [$admin->getId(), $nonAdmin->getId()]);
            
            $this->entityManager->remove($submission->getApplicant());
            $this->entityManager->remove($submission->getFormConfig());
            $this->entityManager->remove($submission);
            $this->entityManager->remove($submission2->getApplicant());
            $this->entityManager->remove($submission2->getFormConfig());
            $this->entityManager->remove($submission2);
            $this->entityManager->remove($admin);
            $this->entityManager->remove($nonAdmin);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    /**
     * Property: Users can only access their own accreditation submissions
     */
    public function testUsersCanOnlyAccessTheirOwnSubmissions(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'user1Email' => 'user1' . strtolower(preg_replace('/[^a-z0-9]/', '', $parts[0])) . '@test.com',
                        'user2Email' => 'user2' . strtolower(preg_replace('/[^a-z0-9]/', '', $parts[1])) . '@test.com',
                        'password' => $parts[2] . 'Pass123!',
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\string(),
                    Generator\string()
                )
            )
        )->then(function ($userData) {
            // Create two consignees with linked brokers
            $broker = $this->createBroker('broker' . uniqid() . '@test.com', $userData['password']);
            $broker->setStatus(AccountStatus::APPROVED);
            $this->entityManager->flush();
            
            $user1 = $this->createConsignee($userData['user1Email'], $userData['password']);
            $user1->setLinkedBroker($broker);
            $this->entityManager->flush();
            
            $user2 = $this->createConsignee($userData['user2Email'], $userData['password']);
            $user2->setLinkedBroker($broker);
            $this->entityManager->flush();
            
            // Create submissions for both users
            $formConfig = $this->createTestFormConfig(FormType::CONSIGNEE);
            
            $submission1 = new AccreditationSubmission();
            $submission1->setApplicant($user1);
            $submission1->setFormConfig($formConfig);
            $submission1->setSubmittedData(['field1' => 'value1']);
            $this->entityManager->persist($submission1);
            
            $submission2 = new AccreditationSubmission();
            $submission2->setApplicant($user2);
            $submission2->setFormConfig($formConfig);
            $submission2->setSubmittedData(['field1' => 'value2']);
            $this->entityManager->persist($submission2);
            
            $this->entityManager->flush();
            
            // User1 should get their own submission
            $user1Submission = $this->accreditationService->getSubmissionForUser($user1);
            $this->assertNotNull($user1Submission, 'User1 should have a submission');
            $this->assertEquals($user1->getId(), $user1Submission->getApplicant()->getId(), 
                'User1 should get their own submission');
            
            // User2 should get their own submission
            $user2Submission = $this->accreditationService->getSubmissionForUser($user2);
            $this->assertNotNull($user2Submission, 'User2 should have a submission');
            $this->assertEquals($user2->getId(), $user2Submission->getApplicant()->getId(), 
                'User2 should get their own submission');
            
            // Submissions should be different
            $this->assertNotEquals($user1Submission->getId(), $user2Submission->getId(), 
                'Users should have different submissions');
            
            // Clean up
            $this->entityManager->remove($submission1);
            $this->entityManager->remove($submission2);
            $this->entityManager->remove($formConfig);
            $this->entityManager->remove($user1);
            $this->entityManager->remove($user2);
            $this->entityManager->remove($broker);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    // Helper methods

    private function createStaffUser(string $email, string $password, UserRole $role): StaffUser
    {
        $data = [
            'email' => $email,
            'password' => $password,
            'firstName' => 'Test',
            'lastName' => 'User',
            'department' => 'Testing',
        ];
        
        return $this->userService->createUser($data, $role);
    }

    private function createConsignee(string $email, string $password): Consignee
    {
        $data = [
            'email' => $email,
            'password' => $password,
            'businessName' => 'Test Business ' . uniqid(),
        ];
        
        return $this->userService->createUser($data, UserRole::CONSIGNEE);
    }

    private function createBroker(string $email, string $password): Broker
    {
        $data = [
            'email' => $email,
            'password' => $password,
            'businessName' => 'Test Broker ' . uniqid(),
        ];
        
        return $this->userService->createUser($data, UserRole::BROKER);
    }

    private function createUserByRole(string $email, string $password, UserRole $role): \App\Entity\User
    {
        if ($role === UserRole::CONSIGNEE || $role === UserRole::BROKER) {
            $data = [
                'email' => $email,
                'password' => $password,
                'businessName' => 'Test Business ' . uniqid(),
            ];
        } else {
            $data = [
                'email' => $email,
                'password' => $password,
                'firstName' => 'Test',
                'lastName' => 'User',
                'department' => 'Testing',
            ];
        }
        
        return $this->userService->createUser($data, $role);
    }

    private function createTestFormConfig(FormType $type): FormConfiguration
    {
        $formConfig = new FormConfiguration();
        $formConfig->setName('Test Form ' . uniqid());
        $formConfig->setType($type);
        $formConfig->setVersion(1);
        $formConfig->setStatus(FormStatus::PUBLISHED);
        $formConfig->setFields([
            'fields' => [
                [
                    'id' => 'field1',
                    'label' => 'Test Field',
                    'type' => 'text',
                    'required' => true,
                    'order' => 1,
                ]
            ]
        ]);
        
        $this->entityManager->persist($formConfig);
        $this->entityManager->flush();
        
        return $formConfig;
    }

    private function createTestSubmission(): AccreditationSubmission
    {
        $broker = $this->createBroker('broker' . uniqid() . '@test.com', 'TestPass123!');
        $broker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();
        
        $applicant = $this->createConsignee('applicant' . uniqid() . '@test.com', 'TestPass123!');
        $applicant->setLinkedBroker($broker);
        $this->entityManager->flush();
        
        $formConfig = $this->createTestFormConfig(FormType::CONSIGNEE);
        
        $submission = new AccreditationSubmission();
        $submission->setApplicant($applicant);
        $submission->setFormConfig($formConfig);
        $submission->setSubmittedData(['field1' => 'test value']);
        $submission->setStatus(AccreditationStatus::PENDING);
        
        $this->entityManager->persist($submission);
        $this->entityManager->flush();
        
        return $submission;
    }

    private function createEvaluatorApprovedSubmission(): AccreditationSubmission
    {
        $submission = $this->createTestSubmission();
        
        $evaluator = $this->createStaffUser('evaluator' . uniqid() . '@test.com', 'TestPass123!', UserRole::EVALUATOR);
        
        $submission->setStatus(AccreditationStatus::AWAITING_FINAL_APPROVAL);
        $submission->setEvaluator($evaluator);
        $submission->setEvaluatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        return $submission;
    }
}
