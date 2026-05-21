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
 * Feature: optimus-shipping-portal, Property 1: Form configuration versioning preserves history
 * 
 * For any published form configuration, when a modification is made, 
 * the system should create a new version while preserving the previous version 
 * with all its field definitions intact.
 * 
 * Validates: Requirements 1.4
 */
class FormBuilderServiceVersioningTest extends KernelTestCase
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
     * Property: Creating a new version preserves the original form with all field definitions
     */
    public function testCreateNewVersionPreservesOriginalForm(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'Form_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'fields' => $this->generateFieldsArray($parts[2]),
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\choose(1, 5) // Number of fields
                )
            )
        )->then(function ($formData) {
            // Create original form
            $originalForm = $this->formBuilderService->createForm(
                $formData['formName'],
                $formData['formType']
            );

            // Add fields to original form
            foreach ($formData['fields'] as $field) {
                $this->formBuilderService->addField($originalForm->getId(), $field);
            }

            // Publish the original form
            $this->formBuilderService->publishForm($originalForm->getId());
            $this->entityManager->refresh($originalForm);

            // Store original form data for comparison
            $originalId = $originalForm->getId();
            $originalVersion = $originalForm->getVersion();
            $originalFields = $originalForm->getFields();
            $originalStatus = $originalForm->getStatus();
            $originalPublishedAt = $originalForm->getPublishedAt();

            // Create new version
            $newVersion = $this->formBuilderService->createNewVersion($originalForm->getId());

            // Refresh original form from database
            $this->entityManager->refresh($originalForm);

            // Verify original form is preserved
            $this->assertEquals($originalId, $originalForm->getId(), 
                'Original form ID should remain unchanged');
            $this->assertEquals($originalVersion, $originalForm->getVersion(), 
                'Original form version should remain unchanged');
            $this->assertEquals($originalFields, $originalForm->getFields(), 
                'Original form fields should be preserved exactly');
            $this->assertEquals($originalStatus, $originalForm->getStatus(), 
                'Original form status should remain unchanged');
            $this->assertEquals($originalPublishedAt, $originalForm->getPublishedAt(), 
                'Original form publishedAt should remain unchanged');

            // Verify new version has correct properties
            $this->assertNotEquals($originalId, $newVersion->getId(), 
                'New version should have different ID');
            $this->assertEquals($originalVersion + 1, $newVersion->getVersion(), 
                'New version should have incremented version number');
            $this->assertEquals($originalFields, $newVersion->getFields(), 
                'New version should have copied all fields from original');
            $this->assertEquals(FormStatus::DRAFT, $newVersion->getStatus(), 
                'New version should start as DRAFT');
            $this->assertNull($newVersion->getPublishedAt(), 
                'New version should not have publishedAt initially');
            $this->assertEquals($originalForm->getName(), $newVersion->getName(), 
                'New version should have same name');
            $this->assertEquals($originalForm->getType(), $newVersion->getType(), 
                'New version should have same type');

            // Clean up
            $this->entityManager->remove($originalForm);
            $this->entityManager->remove($newVersion);
            $this->entityManager->flush();
        });
    }

    /**
     * Property: Multiple versions can coexist with different version numbers
     */
    public function testMultipleVersionsCanCoexist(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'MultiVersion_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'versionCount' => $parts[2],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\choose(2, 4) // Create 2-4 versions
                )
            )
        )->then(function ($formData) {
            // Create original form
            $originalForm = $this->formBuilderService->createForm(
                $formData['formName'],
                $formData['formType']
            );

            // Publish original
            $this->formBuilderService->publishForm($originalForm->getId());

            $versions = [$originalForm];

            // Create multiple versions, each from the previous version
            $previousVersion = $originalForm;
            for ($i = 1; $i < $formData['versionCount']; $i++) {
                $newVersion = $this->formBuilderService->createNewVersion($previousVersion->getId());
                $versions[] = $newVersion;
                $previousVersion = $newVersion;
            }

            // Verify all versions exist and have correct version numbers
            for ($i = 0; $i < count($versions); $i++) {
                $this->entityManager->refresh($versions[$i]);
                $this->assertEquals($i + 1, $versions[$i]->getVersion(), 
                    "Version {$i} should have version number " . ($i + 1));
                
                // Verify form still exists in database
                $formFromDb = $this->entityManager->getRepository(FormConfiguration::class)
                    ->find($versions[$i]->getId());
                $this->assertNotNull($formFromDb, "Version {$i} should exist in database");
            }

            // Clean up all versions
            foreach ($versions as $version) {
                $this->entityManager->remove($version);
            }
            $this->entityManager->flush();
        });
    }

    /**
     * Property: Published forms remain accessible after new version is created
     */
    public function testPublishedFormRemainsAccessibleAfterNewVersion(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'Published_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER)
                )
            )
        )->then(function ($formData) {
            // Create and publish original form
            $originalForm = $this->formBuilderService->createForm(
                $formData['formName'],
                $formData['formType']
            );
            $this->formBuilderService->publishForm($originalForm->getId());
            $this->entityManager->refresh($originalForm);

            // Verify it's the active form
            $activeForm = $this->formBuilderService->getActiveForm($formData['formType']);
            $this->assertNotNull($activeForm, 'Active form should exist');
            $this->assertEquals($originalForm->getId(), $activeForm->getId(), 
                'Original form should be the active form');

            // Create new version (but don't publish it)
            $newVersion = $this->formBuilderService->createNewVersion($originalForm->getId());

            // Verify original is still the active form
            $activeFormAfter = $this->formBuilderService->getActiveForm($formData['formType']);
            $this->assertNotNull($activeFormAfter, 'Active form should still exist');
            $this->assertEquals($originalForm->getId(), $activeFormAfter->getId(), 
                'Original form should still be the active form after new version created');

            // Verify original form is still published
            $this->entityManager->refresh($originalForm);
            $this->assertEquals(FormStatus::PUBLISHED, $originalForm->getStatus(), 
                'Original form should remain published');

            // Clean up
            $this->entityManager->remove($originalForm);
            $this->entityManager->remove($newVersion);
            $this->entityManager->flush();
        });
    }

    /**
     * Helper method to generate an array of field definitions
     */
    private function generateFieldsArray(int $count): array
    {
        $fields = [];
        $types = ['text', 'number', 'date', 'file', 'dropdown', 'checkbox', 'radio'];
        
        for ($i = 0; $i < $count; $i++) {
            $fields[] = [
                'id' => 'field_' . uniqid(),
                'label' => 'Field ' . ($i + 1),
                'type' => $types[array_rand($types)],
                'required' => (bool)random_int(0, 1),
                'order' => $i + 1,
                'validation' => []
            ];
        }
        
        return $fields;
    }
}
