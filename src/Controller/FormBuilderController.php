<?php

namespace App\Controller;

use App\Entity\FormConfiguration;
use App\Form\FormFieldTypes;
use App\Entity\AccreditationSubmission;
use App\Entity\Enum\FormType;
use App\Service\FormBuilderService;
use App\Service\DynamicFormRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

#[Route('/admin/form-builder')]
class FormBuilderController extends AbstractController
{
    public function __construct(
        private FormBuilderService $formBuilderService,
        private DynamicFormRenderer $formRenderer,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }

    /**
     * List all form configurations
     */
    #[Route('/', name: 'form_builder_list', methods: ['GET'])]
    public function list(): Response
    {
        $forms = $this->entityManager->getRepository(FormConfiguration::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return $this->render('form_builder/list.html.twig', [
            'forms' => $forms,
        ]);
    }

    /**
     * Show create form page
     */
    #[Route('/create', name: 'form_builder_create', methods: ['GET'])]
    public function create(): Response
    {
        return $this->render('form_builder/create.html.twig', [
            'formTypes' => FormType::cases(),
        ]);
    }

    /**
     * Store new form configuration
     */
    #[Route('/store', name: 'form_builder_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('form_builder_create', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('form_builder_create');
        }

        $name = $request->request->get('name');
        $typeValue = $request->request->get('type');

        if (!$name || !$typeValue) {
            $this->addFlash('error', 'Form name and type are required');
            return $this->redirectToRoute('form_builder_create');
        }

        try {
            $type = FormType::from($typeValue);
            $form = $this->formBuilderService->createForm($name, $type);

            $this->addFlash('success', 'Form created successfully');
            return $this->redirectToRoute('form_builder_edit', ['id' => $form->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error creating form: ' . $e->getMessage());
            return $this->redirectToRoute('form_builder_create');
        }
    }

    /**
     * Show edit form page with field management
     */
    #[Route('/{id}/edit', name: 'form_builder_edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        $fieldTypes = FormFieldTypes::ALL;
        $fieldTemplateGroups = FormFieldTypes::templateGroups();

        return $this->render('form_builder/edit.html.twig', [
            'form' => $form,
            'fieldTypes' => $fieldTypes,
            'fieldTemplateGroups' => $fieldTemplateGroups,
        ]);
    }

    /**
     * Add a field to the form
     */
    #[Route('/{id}/field/add', name: 'form_builder_add_field', methods: ['POST'])]
    public function addField(int $id, Request $request): JsonResponse
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            return new JsonResponse(['success' => false, 'message' => 'Form not found'], 404);
        }

        try {
            $fieldData = json_decode($request->getContent(), true);
            
            // Validate required field properties
            if (!isset($fieldData['id'], $fieldData['label'], $fieldData['type'], $fieldData['order'])) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Missing required field properties'
                ], 400);
            }

            // Ensure required is boolean
            $fieldData['required'] = isset($fieldData['required']) && $fieldData['required'];

            // Ensure order is integer
            $fieldData['order'] = (int)$fieldData['order'];

            // Add validation array if not present
            if (!isset($fieldData['validation'])) {
                $fieldData['validation'] = [];
            }

            $this->formBuilderService->addField($id, $fieldData);

            return new JsonResponse([
                'success' => true, 
                'message' => 'Field added successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Error adding field: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Update form fields (for reordering, editing, deleting)
     */
    #[Route('/{id}/fields/update', name: 'form_builder_update_fields', methods: ['POST'])]
    public function updateFields(int $id, Request $request): JsonResponse
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            return new JsonResponse(['success' => false, 'message' => 'Form not found'], 404);
        }

        try {
            $fieldsData = json_decode($request->getContent(), true);
            
            if (!isset($fieldsData['fields']) || !is_array($fieldsData['fields'])) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Invalid fields data'
                ], 400);
            }

            // Update the form fields
            $form->setFields(['fields' => $fieldsData['fields']]);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true, 
                'message' => 'Fields updated successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Error updating fields: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Delete a field from the form
     */
    #[Route('/{id}/field/{fieldId}/delete', name: 'form_builder_delete_field', methods: ['DELETE'])]
    public function deleteField(int $id, string $fieldId): JsonResponse
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            return new JsonResponse(['success' => false, 'message' => 'Form not found'], 404);
        }

        try {
            $fields = $form->getFields();
            
            if (!isset($fields['fields'])) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'No fields found'
                ], 400);
            }

            // Filter out the field to delete
            $updatedFields = array_filter($fields['fields'], function($field) use ($fieldId) {
                return $field['id'] !== $fieldId;
            });

            // Re-index array
            $updatedFields = array_values($updatedFields);

            $form->setFields(['fields' => $updatedFields]);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true, 
                'message' => 'Field deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Error deleting field: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Show publish confirmation page
     */
    #[Route('/{id}/publish', name: 'form_builder_publish_confirm', methods: ['GET'])]
    public function publishConfirm(int $id): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        return $this->render('form_builder/publish_confirm.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Publish the form
     */
    #[Route('/{id}/publish', name: 'form_builder_publish', methods: ['POST'])]
    public function publish(int $id): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        try {
            $this->formBuilderService->publishForm($id);
            $this->addFlash('success', 'Form published successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error publishing form: ' . $e->getMessage());
        }

        return $this->redirectToRoute('form_builder_list');
    }

    /**
     * Create a new version of an existing form
     */
    #[Route('/{id}/new-version', name: 'form_builder_new_version', methods: ['POST'])]
    public function createNewVersion(int $id): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        try {
            $newVersion = $this->formBuilderService->createNewVersion($id);
            $this->addFlash('success', 'New version created successfully');
            return $this->redirectToRoute('form_builder_edit', ['id' => $newVersion->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error creating new version: ' . $e->getMessage());
            return $this->redirectToRoute('form_builder_list');
        }
    }

    /**
     * Preview the form
     */
    #[Route('/{id}/preview', name: 'form_builder_preview', methods: ['GET'])]
    public function preview(int $id): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        $renderedForm = $this->formRenderer->renderForm($form);

        return $this->render('form_builder/preview.html.twig', [
            'form' => $form,
            'renderedForm' => $renderedForm,
        ]);
    }

    /**
     * Delete a form configuration
     */
    #[Route('/{id}/delete', name: 'form_builder_delete', methods: ['DELETE', 'POST'])]
    public function delete(int $id, Request $request): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Form not found'], 404);
            }
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        try {
            // Check for dependencies before deletion
            $dependencies = $this->checkFormDependencies($form);
            
            if (!empty($dependencies)) {
                $message = 'Cannot delete this form because it is being used by:';
                foreach ($dependencies as $dependency) {
                    $message .= "\n• " . $dependency;
                }
                $message .= "\n\nPlease remove or reassign these dependencies before deleting the form.";
                
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['success' => false, 'message' => $message], 400);
                }
                
                $this->addFlash('error', $message);
                return $this->redirectToRoute('form_builder_list');
            }
            
            $this->entityManager->remove($form);
            $this->entityManager->flush();
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Form deleted successfully']);
            }
            
            $this->addFlash('success', 'Form deleted successfully');
        } catch (\Exception $e) {
            $errorMessage = 'Error deleting form';
            
            // Check if it's a foreign key constraint error
            if (strpos($e->getMessage(), 'foreign key constraint') !== false || 
                strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $errorMessage = 'Cannot delete this form because it is currently being used by other records in the system. Please remove any dependencies first.';
            } else {
                $errorMessage .= ': ' . $e->getMessage();
            }
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $errorMessage], 500);
            }
            
            $this->addFlash('error', $errorMessage);
        }

        return $this->redirectToRoute('form_builder_list');
    }

    /**
     * Activate a form configuration
     */
    #[Route('/{id}/activate', name: 'form_builder_activate', methods: ['POST'])]
    public function activate(int $id, Request $request): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Form not found'], 404);
            }
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        try {
            $this->formBuilderService->activateForm($id);
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Form activated successfully']);
            }
            
            $this->addFlash('success', 'Form activated successfully');
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Error activating form: ' . $e->getMessage()], 500);
            }
            
            $this->addFlash('error', 'Error activating form: ' . $e->getMessage());
        }

        return $this->redirectToRoute('form_builder_list');
    }

    /**
     * Deactivate a form configuration
     */
    #[Route('/{id}/deactivate', name: 'form_builder_deactivate', methods: ['POST'])]
    public function deactivate(int $id, Request $request): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Form not found'], 404);
            }
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        try {
            $this->formBuilderService->deactivateForm($id);
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Form deactivated successfully']);
            }
            
            $this->addFlash('success', 'Form deactivated successfully');
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Error deactivating form: ' . $e->getMessage()], 500);
            }
            
            $this->addFlash('error', 'Error deactivating form: ' . $e->getMessage());
        }

        return $this->redirectToRoute('form_builder_list');
    }

    /**
     * Unpublish a form configuration (revert to draft)
     */
    #[Route('/{id}/unpublish', name: 'form_builder_unpublish', methods: ['POST'])]
    public function unpublish(int $id, Request $request): Response
    {
        $form = $this->entityManager->getRepository(FormConfiguration::class)->find($id);

        if (!$form) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Form not found'], 404);
            }
            $this->addFlash('error', 'Form not found');
            return $this->redirectToRoute('form_builder_list');
        }

        try {
            $this->formBuilderService->unpublishForm($id);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Form unpublished successfully']);
            }

            $this->addFlash('success', 'Form unpublished successfully');
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
            }

            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('form_builder_edit', ['id' => $id]);
    }

    /**
     * Check for dependencies that would prevent form deletion
     */
    private function checkFormDependencies(FormConfiguration $form): array
    {
        $dependencies = [];
        
        // Prevent deletion of active forms
        if ($form->isActive()) {
            $dependencies[] = "This form is currently active and being used by the system";
        }
        
        // Check for accreditation submissions that reference this form
        $submissionCount = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('App\Entity\AccreditationSubmission', 's')
            ->where('s.formConfig = :form')
            ->setParameter('form', $form)
            ->getQuery()
            ->getSingleScalarResult();
            
        if ($submissionCount > 0) {
            $dependencies[] = sprintf(
                "%d accreditation submission%s using this form", 
                $submissionCount, 
                $submissionCount === 1 ? '' : 's'
            );
        }
        
        return $dependencies;
    }
}
