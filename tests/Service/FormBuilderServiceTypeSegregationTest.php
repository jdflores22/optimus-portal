<?php

namespace App\Tests\Service;

use App\Entity\FormConfiguration;
use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use App\Service\FormBuilderService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 12: Form type segregation
 * 
 * For any form configuration with type CONSIGNEE, it should only be displayed 
 * to consignee users during accreditation, and forms with type BROKER should 
 * only be displayed to broker users.
 * 
 * Validates: Requirements 1.5, 2.2, 3.2
 */
class FormBuilderServiceTypeSegregationTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private FormBuilderService $formBuilderService;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->formBuilderService = new FormBuilderService($this->entityManager);
        
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
        $connection->executeStatement('TRUNCATE TABLE form_configurations');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    /**
     * Property: getActiveForm returns only forms matching the requested type
     */
    public function testGetActiveFormReturnsOnlyMatchingType(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'consigneeName' => 'Consignee_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'brokerName' => 'Broker_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[1]),
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\string()
                )
            )
        )->then(function ($formData) {
            // Create and publish a CONSIGNEE form
            $consigneeForm = $this->formBuilderService->createForm(
                $formData['consigneeName'],
                FormType::CONSIGNEE
            );
            $this->formBuilderService->publishForm($consigneeForm->getId());

            // Create and publish a BROKER form
            $brokerForm = $this->formBuilderService->createForm(
                $formData['brokerName'],
                FormType::BROKER
            );
            $this->formBuilderService->publishForm($brokerForm->getId());

            // Get active form for CONSIGNEE type
            $activeConsigneeForm = $this->formBuilderService->getActiveForm(FormType::CONSIGNEE);
            
            $this->assertNotNull($activeConsigneeForm, 
                'Should return an active form for CONSIGNEE type');
            $this->assertEquals(FormType::CONSIGNEE, $activeConsigneeForm->getType(), 
                'Active form for CONSIGNEE should have CONSIGNEE type');
            $this->assertEquals($consigneeForm->getId(), $activeConsigneeForm->getId(), 
                'Should return the correct CONSIGNEE form');

            // Get active form for BROKER type
            $activeBrokerForm = $this->formBuilderService->getActiveForm(FormType::BROKER);
            
            $this->assertNotNull($activeBrokerForm, 
                'Should return an active form for BROKER type');
            $this->assertEquals(FormType::BROKER, $activeBrokerForm->getType(), 
                'Active form for BROKER should have BROKER type');
            $this->assertEquals($brokerForm->getId(), $activeBrokerForm->getId(), 
                'Should return the correct BROKER form');

            // Verify they are different forms
            $this->assertNotEquals($activeConsigneeForm->getId(), $activeBrokerForm->getId(), 
                'CONSIGNEE and BROKER forms should be different');

            // Clean up
            $this->entityManager->remove($consigneeForm);
            $this->entityManager->remove($brokerForm);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    /**
     * Property: Only published forms of the correct type are returned
     */
    public function testOnlyPublishedFormsOfCorrectTypeAreReturned(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'publishedName' => 'Published_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'draftName' => 'Draft_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[1]),
                        'formType' => $parts[2],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER)
                )
            )
        )->then(function ($formData) {
            // Create and publish one form
            $publishedForm = $this->formBuilderService->createForm(
                $formData['publishedName'],
                $formData['formType']
            );
            $this->formBuilderService->publishForm($publishedForm->getId());

            // Create a draft form of the same type
            $draftForm = $this->formBuilderService->createForm(
                $formData['draftName'],
                $formData['formType']
            );
            // Don't publish this one

            // Get active form
            $activeForm = $this->formBuilderService->getActiveForm($formData['formType']);

            // Should return the published form, not the draft
            $this->assertNotNull($activeForm, 
                'Should return an active form when published form exists');
            $this->assertEquals($publishedForm->getId(), $activeForm->getId(), 
                'Should return the published form, not the draft');
            $this->assertEquals(FormStatus::PUBLISHED, $activeForm->getStatus(), 
                'Returned form should have PUBLISHED status');

            // Clean up
            $this->entityManager->remove($publishedForm);
            $this->entityManager->remove($draftForm);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    /**
     * Property: When no published form exists for a type, getActiveForm returns null
     */
    public function testReturnsNullWhenNoPublishedFormExists(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'draftName' => 'DraftOnly_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER)
                )
            )
        )->then(function ($formData) {
            // Create only a draft form
            $draftForm = $this->formBuilderService->createForm(
                $formData['draftName'],
                $formData['formType']
            );

            // Get active form should return null
            $activeForm = $this->formBuilderService->getActiveForm($formData['formType']);

            $this->assertNull($activeForm, 
                'Should return null when no published form exists for the type');

            // Clean up
            $this->entityManager->remove($draftForm);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    /**
     * Property: Multiple published forms of same type returns highest version
     */
    public function testMultiplePublishedFormsReturnsHighestVersion(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'Versioned_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'versionCount' => $parts[2],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\choose(2, 4)
                )
            )
        )->then(function ($formData) {
            $forms = [];
            
            // Create first version and publish
            $firstForm = $this->formBuilderService->createForm(
                $formData['formName'],
                $formData['formType']
            );
            $this->formBuilderService->publishForm($firstForm->getId());
            $forms[] = $firstForm;

            // Create additional versions and publish them
            $previousForm = $firstForm;
            for ($i = 1; $i < $formData['versionCount']; $i++) {
                $newVersion = $this->formBuilderService->createNewVersion($previousForm->getId());
                $this->formBuilderService->publishForm($newVersion->getId());
                $forms[] = $newVersion;
                $previousForm = $newVersion;
            }

            // Get active form
            $activeForm = $this->formBuilderService->getActiveForm($formData['formType']);

            $this->assertNotNull($activeForm, 
                'Should return an active form');
            
            // Should return the highest version (last created)
            $highestVersion = $formData['versionCount'];
            $this->assertEquals($highestVersion, $activeForm->getVersion(), 
                'Should return the form with highest version number');
            $this->assertEquals($forms[count($forms) - 1]->getId(), $activeForm->getId(), 
                'Should return the most recently created version');

            // Clean up all versions
            foreach ($forms as $form) {
                $this->entityManager->remove($form);
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    /**
     * Property: Form type is immutable after creation
     */
    public function testFormTypeIsImmutableAfterCreation(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'Immutable_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'initialType' => $parts[1],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER)
                )
            )
        )->then(function ($formData) {
            // Create form with initial type
            $form = $this->formBuilderService->createForm(
                $formData['formName'],
                $formData['initialType']
            );

            $originalType = $form->getType();
            $originalId = $form->getId();

            // Refresh from database
            $this->entityManager->refresh($form);

            // Verify type hasn't changed
            $this->assertEquals($originalType, $form->getType(), 
                'Form type should remain unchanged after creation');

            // Publish the form
            $this->formBuilderService->publishForm($form->getId());
            $this->entityManager->refresh($form);

            // Verify type still hasn't changed
            $this->assertEquals($originalType, $form->getType(), 
                'Form type should remain unchanged after publishing');

            // Create new version
            $newVersion = $this->formBuilderService->createNewVersion($form->getId());

            // Verify new version has same type
            $this->assertEquals($originalType, $newVersion->getType(), 
                'New version should have same type as original');

            // Clean up
            $this->entityManager->remove($form);
            $this->entityManager->remove($newVersion);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }
}
