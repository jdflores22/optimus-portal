<?php

namespace App\Service;

use App\Entity\FormConfiguration;
use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use Doctrine\ORM\EntityManagerInterface;

class FormBuilderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CacheService $cacheService
    ) {
    }

    /**
     * Create a new form configuration with initial settings
     * 
     * @param string $name The name of the form
     * @param FormType $type The type of form (CONSIGNEE or BROKER)
     * @return FormConfiguration The newly created form configuration
     */
    public function createForm(string $name, FormType $type): FormConfiguration
    {
        $form = new FormConfiguration();
        $form->setName($name);
        $form->setType($type);
        $form->setStatus(FormStatus::DRAFT);
        $form->setVersion(1);
        $form->setFields(['fields' => []]); // Initialize with empty fields array

        $this->entityManager->persist($form);
        $this->entityManager->flush();

        return $form;
    }

    /**
     * Add a field to an existing form configuration
     * 
     * @param int $formId The ID of the form configuration
     * @param array $fieldDefinition The field definition with validation
     * @throws \InvalidArgumentException If form not found or field definition is invalid
     */
    public function addField(int $formId, array $fieldDefinition): void
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($formId);
        
        if (!$form) {
            throw new \InvalidArgumentException('Form configuration not found');
        }

        // Validate field definition structure
        $this->validateFieldDefinition($fieldDefinition);

        // Get current fields
        $fields = $form->getFields();
        
        // Ensure fields array exists
        if (!isset($fields['fields'])) {
            $fields = ['fields' => []];
        }

        // Add the new field
        $fields['fields'][] = $fieldDefinition;

        // Set the updated fields (this will trigger validation in the entity)
        $form->setFields($fields);

        $this->entityManager->flush();
    }

    /**
     * Publish a form configuration, making it available for users
     * 
     * @param int $formId The ID of the form configuration to publish
     * @throws \InvalidArgumentException If form not found
     */
    public function publishForm(int $formId): void
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($formId);
        
        if (!$form) {
            throw new \InvalidArgumentException('Form configuration not found');
        }

        // If already published, this is a re-publish (version should be incremented via createNewVersion)
        if ($form->isPublished()) {
            $form->incrementVersion();
        }

        $form->publish();
        $this->entityManager->flush();

        // Cache the newly published form and invalidate old cache
        $this->cacheService->invalidateFormConfiguration($form->getType());
        $this->cacheService->cacheActiveFormConfiguration($form->getType(), $form);
    }

    /**
     * Activate a form configuration, deactivating others of the same type
     * 
     * @param int $formId The ID of the form configuration to activate
     * @throws \InvalidArgumentException If form not found or not published
     */
    public function activateForm(int $formId): void
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($formId);
        
        if (!$form) {
            throw new \InvalidArgumentException('Form configuration not found');
        }

        if (!$form->isPublished()) {
            throw new \InvalidArgumentException('Only published forms can be activated');
        }

        // Deactivate all other forms of the same type (set them to INACTIVE)
        $this->entityManager->createQueryBuilder()
            ->update(FormConfiguration::class, 'f')
            ->set('f.status', ':inactive')
            ->where('f.type = :type')
            ->andWhere('f.id != :currentId')
            ->andWhere('f.status = :active')
            ->setParameter('inactive', FormStatus::INACTIVE)
            ->setParameter('type', $form->getType())
            ->setParameter('currentId', $form->getId())
            ->setParameter('active', FormStatus::ACTIVE)
            ->getQuery()
            ->execute();

        // Activate the selected form
        $form->activate();
        $this->entityManager->flush();

        // Update cache
        $this->cacheService->invalidateFormConfiguration($form->getType());
        $this->cacheService->cacheActiveFormConfiguration($form->getType(), $form);
    }

    /**
     * Deactivate a form configuration
     * 
     * @param int $formId The ID of the form configuration to deactivate
     * @throws \InvalidArgumentException If form not found
     */
    public function deactivateForm(int $formId): void
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($formId);
        
        if (!$form) {
            throw new \InvalidArgumentException('Form configuration not found');
        }

        // Deactivate the form
        $form->deactivate();
        $this->entityManager->flush();

        // Update cache
        $this->cacheService->invalidateFormConfiguration($form->getType());
    }

    /**
     * Revert a published form back to draft (unpublish).
     *
     * @throws \InvalidArgumentException If form not found, wrong status, or has submissions
     */
    public function unpublishForm(int $formId): void
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($formId);

        if (!$form) {
            throw new \InvalidArgumentException('Form configuration not found');
        }

        if ($form->getStatus() !== FormStatus::PUBLISHED) {
            throw new \InvalidArgumentException('Only published forms can be unpublished. Deactivate active forms first.');
        }

        if ($this->countSubmissions($form) > 0) {
            throw new \InvalidArgumentException('Cannot unpublish a form that has accreditation submissions');
        }

        $form->unpublish();
        $this->entityManager->flush();

        $this->cacheService->invalidateFormConfiguration($form->getType());
    }

    private function countSubmissions(FormConfiguration $form): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('App\Entity\AccreditationSubmission', 's')
            ->where('s.formConfig = :form')
            ->setParameter('form', $form)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get the currently active form for a specific type
     * Returns the active form of the specified type
     *
     * @param FormType $type The form type to retrieve
     * @return FormConfiguration|null The active form or null if none active
     */
    public function getActiveForm(FormType $type): ?FormConfiguration
    {
        // Skip cache for debugging - always fetch from database
        $repository = $this->entityManager->getRepository(FormConfiguration::class);
        
        // Find the active form of this type
        $forms = $repository->createQueryBuilder('f')
            ->where('f.type = :type')
            ->andWhere('f.status = :status')
            ->setParameter('type', $type)
            ->setParameter('status', FormStatus::ACTIVE)
            ->orderBy('f.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        $activeForm = $forms[0] ?? null;
        
        // Cache the result if found
        if ($activeForm) {
            $this->cacheService->cacheActiveFormConfiguration($type, $activeForm);
        }

        return $activeForm;
    }

    /**
     * Create a new version of an existing form configuration
     * Copies all fields from the original form and increments version
     * 
     * @param int $formId The ID of the form to create a new version from
     * @return FormConfiguration The new version of the form
     * @throws \InvalidArgumentException If form not found
     */
    public function createNewVersion(int $formId): FormConfiguration
    {
        $originalForm = $this->entityManager->getRepository(FormConfiguration::class)->find($formId);
        
        if (!$originalForm) {
            throw new \InvalidArgumentException('Form configuration not found');
        }

        // Create new form with incremented version
        $newForm = new FormConfiguration();
        $newForm->setName($originalForm->getName());
        $newForm->setType($originalForm->getType());
        $newForm->setStatus(FormStatus::DRAFT);
        $newForm->setVersion($originalForm->getVersion() + 1);
        $newForm->setFields($originalForm->getFields()); // Copy all fields

        $this->entityManager->persist($newForm);
        $this->entityManager->flush();

        return $newForm;
    }

    /**
     * Validate field definition structure before adding to form
     * 
     * @param array $fieldDefinition The field definition to validate
     * @throws \InvalidArgumentException If field definition is invalid
     */
    private function validateFieldDefinition(array $fieldDefinition): void
    {
        $allowedTypes = ['text', 'number', 'date', 'file', 'dropdown', 'checkbox', 'radio'];

        if (!isset($fieldDefinition['id']) || !is_string($fieldDefinition['id'])) {
            throw new \InvalidArgumentException('Field must have a string "id"');
        }

        if (!isset($fieldDefinition['label']) || !is_string($fieldDefinition['label'])) {
            throw new \InvalidArgumentException('Field must have a string "label"');
        }

        if (!isset($fieldDefinition['type']) || !in_array($fieldDefinition['type'], $allowedTypes)) {
            throw new \InvalidArgumentException('Field must have a valid "type" (text, number, date, file, dropdown, checkbox, radio)');
        }

        if (!isset($fieldDefinition['required']) || !is_bool($fieldDefinition['required'])) {
            throw new \InvalidArgumentException('Field must have a boolean "required"');
        }

        if (!isset($fieldDefinition['order']) || !is_int($fieldDefinition['order'])) {
            throw new \InvalidArgumentException('Field must have an integer "order"');
        }

        // Validation rules are optional but if present should be an array
        if (isset($fieldDefinition['validation']) && !is_array($fieldDefinition['validation'])) {
            throw new \InvalidArgumentException('Field "validation" must be an array if present');
        }
    }
}
