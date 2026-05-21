<?php

namespace App\Tests\Integration;

use App\Entity\Consignee;
use App\Entity\Broker;
use App\Entity\FormConfiguration;
use App\Entity\AccreditationSubmission;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\FormStatus;
use App\Entity\Enum\AccreditationStatus;
use App\Service\UserService;
use App\Service\FormBuilderService;
use App\Service\AccreditationWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Integration test for complete consignee registration and accreditation workflow
 * Tests Requirements: 2.1-2.5
 */
class ConsigneeRegistrationWorkflowTest extends KernelTestCase
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

    public function testCompleteConsigneeRegistrationAndAccreditationWorkflow(): void
    {
        // Step 1: Create a broker first (consignee needs to link to broker)
        $brokerData = [
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ];
        
        $broker = $this->userService->createUser($brokerData, UserRole::BROKER);
        $this->assertInstanceOf(Broker::class, $broker);
        $this->assertEquals(AccountStatus::PENDING, $broker->getStatus());
        
        // Approve the broker for linking
        $broker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();

        // Step 2: Create consignee form configuration
        $consigneeForm = $this->formBuilderService->createForm('Consignee Registration', FormType::CONSIGNEE);
        
        $this->formBuilderService->addField($consigneeForm->getId(), [
            'id' => 'business_name',
            'label' => 'Business Name',
            'type' => 'text',
            'required' => true,
            'order' => 1
        ]);
        
        $this->formBuilderService->addField($consigneeForm->getId(), [
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
        
        $this->formBuilderService->publishForm($consigneeForm->getId());

        // Step 3: Create consignee account
        $consigneeData = [
            'email' => 'consignee@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Consignee Corp'
        ];
        
        $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);
        $this->assertInstanceOf(Consignee::class, $consignee);
        $this->assertEquals(AccountStatus::PENDING, $consignee->getStatus());

        // Step 4: Link broker to consignee
        $this->accreditationService->linkBrokerToConsignee($consignee, $broker);
        $this->assertEquals($broker, $consignee->getLinkedBroker());

        // Step 5: Submit accreditation with form data
        $formData = [
            'business_name' => 'Test Consignee Corp',
            'business_license' => 'test_license.pdf'
        ];
        
        // Create a mock uploaded file with correct MIME type
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, '%PDF-1.4 test file content'); // PDF header
        $uploadedFile = new UploadedFile($tempFile, 'test_license.pdf', 'application/pdf', null, true);
        
        $files = ['business_license' => $uploadedFile];
        
        $submission = $this->accreditationService->submitAccreditation($consignee, $formData, $files);
        
        $this->assertInstanceOf(AccreditationSubmission::class, $submission);
        $this->assertEquals(AccreditationStatus::PENDING, $submission->getStatus());
        $this->assertEquals($consignee, $submission->getApplicant());
        $this->assertEquals($formData['business_name'], $submission->getSubmittedData()['business_name']);

        // Step 6: Verify workflow state
        $this->assertEquals(AccountStatus::PENDING, $consignee->getStatus());
        
        // Get accreditation through repository
        $accreditationRepo = $this->entityManager->getRepository(AccreditationSubmission::class);
        $consigneeAccreditation = $accreditationRepo->findOneBy(['applicant' => $consignee]);
        
        $this->assertNotNull($consigneeAccreditation);
        $this->assertEquals($submission, $consigneeAccreditation);

        // Clean up temp file
        unlink($tempFile);
    }

    public function testConsigneeCannotSubmitWithoutBrokerLinkage(): void
    {
        // Create consignee form configuration
        $consigneeForm = $this->formBuilderService->createForm('Consignee Registration', FormType::CONSIGNEE);
        $this->formBuilderService->publishForm($consigneeForm->getId());

        // Create consignee account without broker linkage
        $consigneeData = [
            'email' => 'consignee@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Consignee Corp'
        ];
        
        $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);

        // Attempt to submit accreditation without broker linkage
        $result = $this->accreditationService->canSubmitAccreditation($consignee);
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('broker', strtolower($result['message']));
    }

    public function testFormDataValidationInWorkflow(): void
    {
        // Create broker and link to consignee
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

        $this->accreditationService->linkBrokerToConsignee($consignee, $broker);

        // Create form with required fields
        $consigneeForm = $this->formBuilderService->createForm('Consignee Registration', FormType::CONSIGNEE);
        $this->formBuilderService->addField($consigneeForm->getId(), [
            'id' => 'business_name',
            'label' => 'Business Name',
            'type' => 'text',
            'required' => true,
            'order' => 1
        ]);
        $this->formBuilderService->publishForm($consigneeForm->getId());

        // Test with missing required field
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/required/i');
        
        $this->accreditationService->submitAccreditation($consignee, [], []);
    }
}