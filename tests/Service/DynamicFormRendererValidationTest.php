<?php

namespace App\Tests\Service;

use App\Entity\FormConfiguration;
use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use App\Service\DynamicFormRenderer;
use App\Service\FormBuilderService;
use App\Service\ValidationService;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 2: Form field validation consistency
 * 
 * For any form submission, all required fields marked in the form configuration 
 * should be validated, and the submission should be rejected if any required 
 * field is missing or invalid.
 * 
 * Validates: Requirements 1.2, 2.5
 */
class DynamicFormRendererValidationTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private FormBuilderService $formBuilderService;
    private DynamicFormRenderer $formRenderer;
    private ValidationService $validationService;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $validator = $container->get(ValidatorInterface::class);
        $this->validationService = new ValidationService($validator);
        $this->formBuilderService = new FormBuilderService($this->entityManager);
        $this->formRenderer = new DynamicFormRenderer($this->validationService);
        
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
     * Property: Required fields must be present in submission
     */
    public function testRequiredFieldsMustBePresent(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'RequiredTest_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'requiredFieldCount' => $parts[2],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\choose(1, 5)
                )
            )
        )->then(function ($testData) {
            // Create form with required fields
            $form = $this->formBuilderService->createForm(
                $testData['formName'],
                $testData['formType']
            );

            $requiredFields = [];
            for ($i = 0; $i < $testData['requiredFieldCount']; $i++) {
                $fieldId = 'required_field_' . $i;
                $field = [
                    'id' => $fieldId,
                    'label' => 'Required Field ' . $i,
                    'type' => 'text',
                    'required' => true,
                    'order' => $i + 1,
                ];
                $this->formBuilderService->addField($form->getId(), $field);
                $requiredFields[] = $fieldId;
            }

            $this->entityManager->refresh($form);

            // Test 1: Empty submission should fail validation
            $emptySubmission = [];
            $result = $this->formRenderer->validateSubmission($form, $emptySubmission);
            
            $this->assertFalse($result['valid'], 
                'Validation should fail when required fields are missing');
            $this->assertCount($testData['requiredFieldCount'], $result['errors'], 
                'Should have error for each required field');
            
            foreach ($requiredFields as $fieldId) {
                $this->assertArrayHasKey($fieldId, $result['errors'], 
                    "Should have error for required field {$fieldId}");
            }

            // Test 2: Partial submission should fail validation
            $partialSubmission = [];
            $providedCount = max(1, intval($testData['requiredFieldCount'] / 2));
            for ($i = 0; $i < $providedCount; $i++) {
                $partialSubmission[$requiredFields[$i]] = 'value_' . $i;
            }
            
            $partialResult = $this->formRenderer->validateSubmission($form, $partialSubmission);
            
            if ($providedCount < $testData['requiredFieldCount']) {
                $this->assertFalse($partialResult['valid'], 
                    'Validation should fail when some required fields are missing');
                $this->assertGreaterThan(0, count($partialResult['errors']), 
                    'Should have errors for missing required fields');
            }

            // Test 3: Complete submission should pass validation
            $completeSubmission = [];
            foreach ($requiredFields as $fieldId) {
                $completeSubmission[$fieldId] = 'valid_value_' . uniqid();
            }
            
            $completeResult = $this->formRenderer->validateSubmission($form, $completeSubmission);
            
            $this->assertTrue($completeResult['valid'], 
                'Validation should pass when all required fields are provided');
            $this->assertEmpty($completeResult['errors'], 
                'Should have no errors when all required fields are provided');

            // Clean up
            $this->entityManager->remove($form);
            $this->entityManager->flush();
        });
    }

    /**
     * Property: Empty strings should be treated as missing for required fields
     */
    public function testEmptyStringsAreInvalidForRequiredFields(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'EmptyTest_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'emptyValue' => $parts[2],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\elements('', '   ', "\t", "\n", "  \t  ")
                )
            )
        )->then(function ($testData) {
            // Create form with one required text field
            $form = $this->formBuilderService->createForm(
                $testData['formName'],
                $testData['formType']
            );

            $fieldId = 'required_text_field';
            $field = [
                'id' => $fieldId,
                'label' => 'Required Text Field',
                'type' => 'text',
                'required' => true,
                'order' => 1,
            ];
            $this->formBuilderService->addField($form->getId(), $field);
            $this->entityManager->refresh($form);

            // Submit with empty/whitespace value
            $submission = [$fieldId => $testData['emptyValue']];
            $result = $this->formRenderer->validateSubmission($form, $submission);

            $this->assertFalse($result['valid'], 
                'Validation should fail for empty/whitespace values in required fields');
            $this->assertArrayHasKey($fieldId, $result['errors'], 
                'Should have error for the required field with empty value');

            // Clean up
            $this->entityManager->remove($form);
            $this->entityManager->flush();
        });
    }

    /**
     * Property: Optional fields should not cause validation failure when missing
     */
    public function testOptionalFieldsCanBeMissing(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'OptionalTest_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'optionalFieldCount' => $parts[2],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\choose(1, 5)
                )
            )
        )->then(function ($testData) {
            // Create form with optional fields only
            $form = $this->formBuilderService->createForm(
                $testData['formName'],
                $testData['formType']
            );

            for ($i = 0; $i < $testData['optionalFieldCount']; $i++) {
                $field = [
                    'id' => 'optional_field_' . $i,
                    'label' => 'Optional Field ' . $i,
                    'type' => 'text',
                    'required' => false,
                    'order' => $i + 1,
                ];
                $this->formBuilderService->addField($form->getId(), $field);
            }

            $this->entityManager->refresh($form);

            // Submit empty form (no optional fields provided)
            $emptySubmission = [];
            $result = $this->formRenderer->validateSubmission($form, $emptySubmission);

            $this->assertTrue($result['valid'], 
                'Validation should pass when optional fields are missing');
            $this->assertEmpty($result['errors'], 
                'Should have no errors when optional fields are missing');

            // Clean up
            $this->entityManager->remove($form);
            $this->entityManager->flush();
        });
    }

    /**
     * Property: Number fields should reject non-numeric values
     */
    public function testNumberFieldsValidateNumericValues(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'NumberTest_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'invalidValue' => $parts[2],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\elements('abc', 'not-a-number', '12.34.56', 'NaN')
                )
            )
        )->then(function ($testData) {
            // Create form with number field
            $form = $this->formBuilderService->createForm(
                $testData['formName'],
                $testData['formType']
            );

            $fieldId = 'number_field';
            $field = [
                'id' => $fieldId,
                'label' => 'Number Field',
                'type' => 'number',
                'required' => false,
                'order' => 1,
            ];
            $this->formBuilderService->addField($form->getId(), $field);
            $this->entityManager->refresh($form);

            // Submit with invalid non-numeric value
            $submission = [$fieldId => $testData['invalidValue']];
            $result = $this->formRenderer->validateSubmission($form, $submission);

            $this->assertFalse($result['valid'], 
                'Validation should fail for non-numeric values in number fields');
            $this->assertArrayHasKey($fieldId, $result['errors'], 
                'Should have error for number field with non-numeric value');

            // Clean up
            $this->entityManager->remove($form);
            $this->entityManager->flush();
        });
    }

    /**
     * Property: Pattern validation should be enforced when specified
     */
    public function testPatternValidationIsEnforced(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'formName' => 'PatternTest_' . preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]),
                        'formType' => $parts[1],
                        'validValue' => 'ABC' . str_pad((string)$parts[2], 7, '0', STR_PAD_LEFT),
                        'invalidValue' => strtolower('xyz' . $parts[3]),
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\elements(FormType::CONSIGNEE, FormType::BROKER),
                    Generator\choose(1, 9999999),
                    Generator\choose(1, 999)
                )
            )
        )->then(function ($testData) {
            // Create form with pattern validation (uppercase letters followed by digits)
            $form = $this->formBuilderService->createForm(
                $testData['formName'],
                $testData['formType']
            );

            $fieldId = 'pattern_field';
            $field = [
                'id' => $fieldId,
                'label' => 'Pattern Field',
                'type' => 'text',
                'required' => false,
                'order' => 1,
                'validation' => [
                    'pattern' => '^[A-Z]{3}[0-9]{7}$',
                    'message' => 'Must be 3 uppercase letters followed by 7 digits'
                ]
            ];
            $this->formBuilderService->addField($form->getId(), $field);
            $this->entityManager->refresh($form);

            // Test valid value
            $validSubmission = [$fieldId => $testData['validValue']];
            $validResult = $this->formRenderer->validateSubmission($form, $validSubmission);
            
            $this->assertTrue($validResult['valid'], 
                'Validation should pass for values matching the pattern');

            // Test invalid value
            $invalidSubmission = [$fieldId => $testData['invalidValue']];
            $invalidResult = $this->formRenderer->validateSubmission($form, $invalidSubmission);
            
            $this->assertFalse($invalidResult['valid'], 
                'Validation should fail for values not matching the pattern');
            $this->assertArrayHasKey($fieldId, $invalidResult['errors'], 
                'Should have error for field with invalid pattern');

            // Clean up
            $this->entityManager->remove($form);
            $this->entityManager->flush();
        });
    }
}
