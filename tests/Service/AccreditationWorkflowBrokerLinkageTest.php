<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\FormConfiguration;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use App\Service\AccreditationWorkflowService;
use App\Service\DynamicFormRenderer;
use App\Service\FormBuilderService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 3: Broker linkage requirement enforcement
 * 
 * For any consignee attempting to submit accreditation, the submission should be rejected
 * if no broker is linked to the consignee account.
 * 
 * Validates: Requirements 2.3
 */
class AccreditationWorkflowBrokerLinkageTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private AccreditationWorkflowService $accreditationService;
    private FormBuilderService $formBuilderService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->formBuilderService = $container->get(FormBuilderService::class);
        $this->accreditationService = $container->get(AccreditationWorkflowService::class);

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
     * Property: Consignees without linked brokers cannot submit accreditation
     * 
     * For any consignee without a linked broker, attempting to submit accreditation
     * should fail with an appropriate error message.
     */
    public function testConsigneeWithoutBrokerCannotSubmitAccreditation(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'businessName' => Generator\map(
                    fn($s) => 'Business ' . $s,
                    Generator\string()
                ),
                'formData' => Generator\associative([
                    'field1' => Generator\string(),
                    'field2' => Generator\string()
                ])
            ])
        )->then(function ($data) {
            // Create unique email for this iteration
            $email = "consignee{$data['emailSuffix']}" . uniqid() . "@test.com";
            // Create a consignee without a linked broker
            $consignee = $this->createConsigneeWithoutBroker($email, $data['businessName']);

            // Ensure there's a published form configuration
            $this->ensurePublishedFormExists(FormType::CONSIGNEE);

            // Attempt to check if consignee can submit accreditation
            $result = $this->accreditationService->canSubmitAccreditation($consignee);

            // Assert that submission is not allowed
            $this->assertFalse($result['valid'], 'Consignee without broker should not be able to submit accreditation');
            $this->assertStringContainsString(
                'broker',
                strtolower($result['message']),
                'Error message should mention broker requirement'
            );

            // Attempt to submit accreditation should throw exception
            $exceptionThrown = false;
            try {
                $this->accreditationService->submitAccreditation($consignee, $data['formData']);
            } catch (\InvalidArgumentException $e) {
                $exceptionThrown = true;
                $this->assertStringContainsString('broker', strtolower($e->getMessage()));
            }

            $this->assertTrue($exceptionThrown, 'Submitting accreditation without broker should throw exception');
        });
    }

    /**
     * Property: Consignees with linked brokers can submit accreditation
     * 
     * For any consignee with a linked approved broker, the canSubmitAccreditation
     * check should pass (though actual submission may fail for other reasons like validation).
     */
    public function testConsigneeWithBrokerCanCheckSubmission(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'consigneeBusinessName' => Generator\map(
                    fn($s) => 'Consignee Business ' . $s,
                    Generator\string()
                ),
                'brokerBusinessName' => Generator\map(
                    fn($s) => 'Broker Business ' . $s,
                    Generator\string()
                )
            ])
        )->then(function ($data) {
            // Create unique emails for this iteration
            $consigneeEmail = "consignee{$data['emailSuffix']}" . uniqid() . "@test.com";
            $brokerEmail = "broker{$data['emailSuffix']}" . uniqid() . "@test.com";
            // Create a broker
            $broker = $this->createBroker($brokerEmail, $data['brokerBusinessName']);
            $broker->setStatus(AccountStatus::APPROVED);
            $this->entityManager->flush();

            // Create a consignee and link to broker
            $consignee = $this->createConsigneeWithoutBroker($consigneeEmail, $data['consigneeBusinessName']);
            $consignee->setLinkedBroker($broker);
            $this->entityManager->flush();

            // Check if consignee can submit accreditation
            $result = $this->accreditationService->canSubmitAccreditation($consignee);

            // Assert that the broker linkage check passes
            // (Note: submission might still fail for other reasons like missing form config or validation)
            $this->assertTrue(
                $result['valid'] || !str_contains(strtolower($result['message']), 'broker'),
                'Consignee with linked broker should pass broker linkage check'
            );
        });
    }

    /**
     * Helper: Create a consignee without a linked broker
     */
    private function createConsigneeWithoutBroker(string $email, string $businessName): Consignee
    {
        $consignee = new Consignee();
        $consignee->setEmail($email);
        $consignee->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName($businessName);
        $consignee->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

        return $consignee;
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
        $broker->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

        return $broker;
    }

    /**
     * Helper: Ensure a published form configuration exists for the given type
     */
    private function ensurePublishedFormExists(FormType $type): void
    {
        $existingForm = $this->formBuilderService->getActiveForm($type);

        if (!$existingForm) {
            $form = new FormConfiguration();
            $form->setName('Test ' . $type->value . ' Form');
            $form->setType($type);
            $form->setStatus(FormStatus::PUBLISHED);
            $form->setVersion(1);
            $form->setFields([
                'fields' => [
                    [
                        'id' => 'field1',
                        'label' => 'Field 1',
                        'type' => 'text',
                        'required' => false,
                        'order' => 1
                    ],
                    [
                        'id' => 'field2',
                        'label' => 'Field 2',
                        'type' => 'text',
                        'required' => false,
                        'order' => 2
                    ]
                ]
            ]);
            $form->setPublishedAt(new \DateTime());

            $this->entityManager->persist($form);
            $this->entityManager->flush();
        }
    }
}
