<?php

namespace App\Tests\Integration;

use App\Entity\Broker;
use App\Entity\StaffUser;
use App\Entity\FormConfiguration;
use App\Entity\AccreditationSubmission;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\AccreditationStatus;
use App\Service\UserService;
use App\Service\FormBuilderService;
use App\Service\AccreditationWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Integration test for complete broker registration and approval workflow
 * Tests Requirements: 3.1-3.5
 */
class BrokerRegistrationWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private FormBuilderService $formBuilderService;
    private AccreditationWorkflowService $accreditationService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userService = $container->get(UserService::class);
        $this->formBuilderService = $container->get(FormBuilderService::class);
        $this->accreditationService = $container->get(AccreditationWorkflowService::class);

        // Clean database
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testCompleteBrokerRegistrationAndApprovalWorkflow(): void
    {
        // Step 1: Create broker form configuration
        $brokerForm = $this->formBuilderService->createForm('Broker Registration', FormType::BROKER);
        
        $this->formBuilderService->addField($brokerForm->getId(), [
            'id' => 'business_name',
            'label' => 'Business Name',
            'type' => 'text',
            'required' => true,
            'order' => 1
        ]);
        
        $this->formBuilderService->addField($brokerForm->getId(), [
            'id' => 'business_license',
            'label' => 'Business License',
            'type' => 'file',
            'required' => true,
            'validation' => [
                'allowedTypes' => ['pdf', 'jpg', 'png'],
                'maxSize' => 10485760
            ],
            'order' => 2
        ]);
        
        $this->formBuilderService->addField($brokerForm->getId(), [
            'id' => 'legitimacy_documents',
            'label' => 'Legitimacy Documents',
            'type' => 'file',
            'required' => true,
            'validation' => [
                'allowedTypes' => ['pdf'],
                'maxSize' => 10485760
            ],
            'order' => 3
        ]);
        
        $this->formBuilderService->publishForm($brokerForm->getId());

        // Step 2: Create broker account
        $brokerData = [
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ];
        
        $broker = $this->userService->createUser($brokerData, UserRole::BROKER);
        $this->assertInstanceOf(Broker::class, $broker);
        $this->assertEquals(AccountStatus::PENDING, $broker->getStatus());

        // Step 3: Submit broker registration with legitimacy documents
        $formData = [
            'business_name' => 'Test Broker LLC',
            'business_license' => 'broker_license.pdf',
            'legitimacy_documents' => 'legitimacy_docs.pdf'
        ];
        
        // Create mock uploaded files with correct MIME types
        $tempFile1 = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile1, '%PDF-1.4 business license content');
        $uploadedFile1 = new UploadedFile($tempFile1, 'broker_license.pdf', 'application/pdf', null, true);
        
        $tempFile2 = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile2, '%PDF-1.4 legitimacy documents content');
        $uploadedFile2 = new UploadedFile($tempFile2, 'legitimacy_docs.pdf', 'application/pdf', null, true);
        
        $files = [
            'business_license' => $uploadedFile1,
            'legitimacy_documents' => $uploadedFile2
        ];
        
        $submission = $this->accreditationService->submitAccreditation($broker, $formData, $files);
        
        $this->assertInstanceOf(AccreditationSubmission::class, $submission);
        $this->assertEquals(AccreditationStatus::PENDING, $submission->getStatus());
        $this->assertEquals($broker, $submission->getApplicant());

        // Step 4: Create evaluator and evaluate application
        $evaluator = $this->userService->createUser([
            'email' => 'evaluator@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Evaluator',
            'department' => 'Compliance'
        ], UserRole::EVALUATOR);

        $this->accreditationService->evaluateApplication(
            $submission->getId(),
            $evaluator,
            AccreditationStatus::APPROVED,
            'Application meets all requirements'
        );

        // Refresh submission
        $this->entityManager->refresh($submission);
        $this->assertEquals(AccreditationStatus::APPROVED, $submission->getStatus());
        $this->assertEquals($evaluator, $submission->getEvaluator());

        // Step 5: Create shipping lines admin and perform final approval
        $admin = $this->userService->createUser([
            'email' => 'admin@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'Jane',
            'lastName' => 'Admin',
            'department' => 'Administration'
        ], UserRole::SHIPPING_LINES_ADMIN);

        $this->accreditationService->finalApproval(
            $submission->getId(),
            $admin,
            true,
            'Final approval granted'
        );

        // Step 6: Verify final state
        $this->entityManager->refresh($submission);
        $this->entityManager->refresh($broker);
        
        $this->assertEquals(AccreditationStatus::APPROVED, $submission->getStatus());
        $this->assertEquals($admin, $submission->getFinalApprover());
        $this->assertEquals(AccountStatus::APPROVED, $broker->getStatus());
        $this->assertNotNull($submission->getApprovedAt());

        // Clean up temp files
        unlink($tempFile1);
        unlink($tempFile2);
    }

    public function testBrokerApprovalMakesAvailableForConsigneeLinkage(): void
    {
        // Create and approve a broker
        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);

        // Initially broker should not be available for linkage
        $this->assertEquals(AccountStatus::PENDING, $broker->getStatus());

        // Approve the broker
        $broker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();

        // Now broker should be available for linkage
        $this->assertEquals(AccountStatus::APPROVED, $broker->getStatus());

        // Create a consignee and verify linkage works
        $consignee = $this->userService->createUser([
            'email' => 'consignee@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Consignee Corp'
        ], UserRole::CONSIGNEE);

        $this->accreditationService->linkBrokerToConsignee($consignee, $broker);
        
        $this->assertEquals($broker, $consignee->getLinkedBroker());
        $this->assertTrue($broker->getLinkedConsignees()->contains($consignee));
    }

    public function testBrokerCanLinkToMultipleConsignees(): void
    {
        // Create and approve a broker
        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();

        // Create multiple consignees
        $consignee1 = $this->userService->createUser([
            'email' => 'consignee1@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Consignee 1'
        ], UserRole::CONSIGNEE);

        $consignee2 = $this->userService->createUser([
            'email' => 'consignee2@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Consignee 2'
        ], UserRole::CONSIGNEE);

        // Link both consignees to the broker
        $this->accreditationService->linkBrokerToConsignee($consignee1, $broker);
        $this->accreditationService->linkBrokerToConsignee($consignee2, $broker);

        // Verify relationships
        $this->assertEquals($broker, $consignee1->getLinkedBroker());
        $this->assertEquals($broker, $consignee2->getLinkedBroker());
        $this->assertTrue($broker->getLinkedConsignees()->contains($consignee1));
        $this->assertTrue($broker->getLinkedConsignees()->contains($consignee2));
        $this->assertEquals(2, $broker->getLinkedConsignees()->count());
    }

    public function testBrokerDenialWorkflow(): void
    {
        // Create broker and submit application
        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);

        // Create form and submit
        $brokerForm = $this->formBuilderService->createForm('Broker Registration', FormType::BROKER);
        $this->formBuilderService->publishForm($brokerForm->getId());

        $submission = $this->accreditationService->submitAccreditation($broker, ['business_name' => 'Test Broker LLC'], []);

        // Create evaluator and deny application
        $evaluator = $this->userService->createUser([
            'email' => 'evaluator@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Evaluator',
            'department' => 'Compliance'
        ], UserRole::EVALUATOR);

        $this->accreditationService->evaluateApplication(
            $submission->getId(),
            $evaluator,
            AccreditationStatus::DENIED,
            'Insufficient documentation provided'
        );

        // Verify denial state
        $this->entityManager->refresh($submission);
        $this->entityManager->refresh($broker);
        
        $this->assertEquals(AccreditationStatus::DENIED, $submission->getStatus());
        $this->assertEquals('Insufficient documentation provided', $submission->getDenialReason());
        $this->assertEquals(AccountStatus::PENDING, $broker->getStatus()); // Should remain pending, not approved
    }
}