<?php

namespace App\Controller;

use App\Entity\DocumentTemplateConfiguration;
use App\Entity\Enum\DocumentTemplateType;
use App\Form\DocumentBlockTypes;
use App\Service\DocumentTemplateBuilderService;
use App\Service\DocumentTemplateDeveloperSettingsService;
use App\Service\DocumentTemplatePdfGenerator;
use App\Service\DocumentTemplateRenderer;
use App\Service\DocumentTemplateSampleDataProvider;
use App\Service\FileStorageServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/document-templates')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class DocumentTemplateController extends AbstractController
{
    public function __construct(
        private DocumentTemplateBuilderService $templateBuilderService,
        private DocumentTemplateRenderer $templateRenderer,
        private DocumentTemplatePdfGenerator $pdfGenerator,
        private DocumentTemplateDeveloperSettingsService $developerSettings,
        private DocumentTemplateSampleDataProvider $sampleDataProvider,
        private FileStorageServiceInterface $fileStorageService,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/', name: 'document_template_list', methods: ['GET'])]
    public function list(): Response
    {
        $templates = $this->entityManager->getRepository(DocumentTemplateConfiguration::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return $this->render('document_template/list.html.twig', [
            'templates' => $templates,
        ]);
    }

    #[Route('/create', name: 'document_template_create', methods: ['GET'])]
    public function create(): Response
    {
        return $this->render('document_template/create.html.twig', [
            'documentTypes' => DocumentTemplateType::cases(),
        ]);
    }

    #[Route('/store', name: 'document_template_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $csrfToken = new CsrfToken('document_template_create', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('document_template_create');
        }

        $name = trim((string) $request->request->get('name'));
        $typeValue = $request->request->get('type');

        if ($name === '' || !$typeValue) {
            $this->addFlash('error', 'Template name and document type are required');
            return $this->redirectToRoute('document_template_create');
        }

        try {
            $type = DocumentTemplateType::from($typeValue);
            $template = $this->templateBuilderService->createTemplate($name, $type, $this->getUser());

            $this->addFlash('success', 'Document template created successfully');
            return $this->redirectToRoute('document_template_edit', ['id' => $template->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error creating template: ' . $e->getMessage());
            return $this->redirectToRoute('document_template_create');
        }
    }

    #[Route('/developer-settings/noa-pdf-regenerate', name: 'document_template_toggle_noa_regenerate', methods: ['POST'])]
    public function toggleNoaPdfRegenerate(Request $request): JsonResponse
    {
        $csrfToken = new CsrfToken('document_template_dev_settings', $request->headers->get('X-CSRF-Token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid security token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('enabled', $data)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing enabled flag'], 400);
        }

        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid enabled flag'], 400);
        }

        $this->developerSettings->setNoaPdfRegenerateEnabled($enabled);

        return new JsonResponse([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'NOA PDF regeneration is now enabled on manifest workflow pages.'
                : 'NOA PDF regeneration is now disabled on manifest workflow pages.',
        ]);
    }

    #[Route('/developer-settings/manifest-bl-pdf-regenerate', name: 'document_template_toggle_manifest_bl_regenerate', methods: ['POST'])]
    public function toggleManifestBlPdfRegenerate(Request $request): JsonResponse
    {
        $csrfToken = new CsrfToken('document_template_dev_settings', $request->headers->get('X-CSRF-Token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid security token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('enabled', $data)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing enabled flag'], 400);
        }

        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid enabled flag'], 400);
        }

        $this->developerSettings->setManifestBlPdfRegenerateEnabled($enabled);

        return new JsonResponse([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'Manifest/BL PDF regeneration is now enabled on manifest workflow pages.'
                : 'Manifest/BL PDF regeneration is now disabled on manifest workflow pages.',
        ]);
    }

    #[Route('/developer-settings/billing-pdf-regenerate', name: 'document_template_toggle_billing_regenerate', methods: ['POST'])]
    public function toggleBillingPdfRegenerate(Request $request): JsonResponse
    {
        $csrfToken = new CsrfToken('document_template_dev_settings', $request->headers->get('X-CSRF-Token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid security token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('enabled', $data)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing enabled flag'], 400);
        }

        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid enabled flag'], 400);
        }

        $this->developerSettings->setBillingPdfRegenerateEnabled($enabled);

        return new JsonResponse([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'Billing PDF regeneration is now enabled on manifest workflow and accounting payment pages.'
                : 'Billing PDF regeneration is now disabled on manifest workflow and accounting payment pages.',
        ]);
    }

    #[Route('/developer-settings/official-receipt-pdf-regenerate', name: 'document_template_toggle_official_receipt_regenerate', methods: ['POST'])]
    public function toggleOfficialReceiptPdfRegenerate(Request $request): JsonResponse
    {
        $csrfToken = new CsrfToken('document_template_dev_settings', $request->headers->get('X-CSRF-Token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid security token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('enabled', $data)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing enabled flag'], 400);
        }

        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid enabled flag'], 400);
        }

        $this->developerSettings->setOfficialReceiptPdfRegenerateEnabled($enabled);

        return new JsonResponse([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'Official receipt regeneration is now enabled on accounting payment pages.'
                : 'Official receipt regeneration is now disabled on accounting payment pages.',
        ]);
    }

    #[Route('/developer-settings/edo-pdf-regenerate', name: 'document_template_toggle_edo_regenerate', methods: ['POST'])]
    public function toggleEdoPdfRegenerate(Request $request): JsonResponse
    {
        $csrfToken = new CsrfToken('document_template_dev_settings', $request->headers->get('X-CSRF-Token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid security token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('enabled', $data)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing enabled flag'], 400);
        }

        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid enabled flag'], 400);
        }

        $this->developerSettings->setEdoPdfRegenerateEnabled($enabled);

        return new JsonResponse([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'eDO PDF regeneration is now enabled on manifest workflow pages.'
                : 'eDO PDF regeneration is now disabled on manifest workflow pages.',
        ]);
    }

    #[Route('/{id}/edit', name: 'document_template_edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $template = $this->findTemplate($id);

        return $this->render('document_template/edit.html.twig', [
            'template' => $template,
            'blockGroups' => DocumentBlockTypes::templateGroups(),
            'placeholders' => DocumentBlockTypes::placeholdersForType($template->getDocumentType()),
            'sampleData' => $this->sampleDataProvider->getSampleData($template->getDocumentType()),
            'noaPdfRegenerateEnabled' => $this->developerSettings->isNoaPdfRegenerateEnabled(),
            'manifestBlPdfRegenerateEnabled' => $this->developerSettings->isManifestBlPdfRegenerateEnabled(),
            'billingPdfRegenerateEnabled' => $this->developerSettings->isBillingPdfRegenerateEnabled(),
            'officialReceiptPdfRegenerateEnabled' => $this->developerSettings->isOfficialReceiptPdfRegenerateEnabled(),
            'edoPdfRegenerateEnabled' => $this->developerSettings->isEdoPdfRegenerateEnabled(),
        ]);
    }

    #[Route('/{id}/layout/save', name: 'document_template_save_layout', methods: ['POST'])]
    public function saveLayout(int $id, Request $request): JsonResponse
    {
        $template = $this->findTemplate($id);

        try {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['layout']) || !is_array($data['layout'])) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid layout data'], 400);
            }

            $this->templateBuilderService->updateLayout($id, $data['layout']);

            return new JsonResponse(['success' => true, 'message' => 'Layout saved successfully']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/{id}/upload-image', name: 'document_template_upload_image', methods: ['POST'])]
    public function uploadImage(int $id, Request $request): JsonResponse
    {
        $template = $this->findTemplate($id);
        $file = $request->files->get('image');

        if (!$file) {
            return new JsonResponse(['success' => false, 'message' => 'No image file provided'], 400);
        }

        $mimeType = (string) $file->getMimeType();
        if (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Only PNG and JPG images are allowed'], 400);
        }

        if ($file->getSize() > 2_097_152) {
            return new JsonResponse(['success' => false, 'message' => 'Image must be 2 MB or smaller'], 400);
        }

        try {
            $storedPath = $this->fileStorageService->uploadFile(
                $file,
                'document-templates',
                (string) $template->getId()
            );

            $publicUrl = '/uploads/' . ltrim($storedPath, '/');

            return new JsonResponse([
                'success' => true,
                'url' => $publicUrl,
                'path' => $storedPath,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/{id}/preview-pdf', name: 'document_template_preview_pdf', methods: ['GET', 'POST'])]
    public function previewPdf(int $id, Request $request): Response
    {
        $template = $this->applyPreviewLayoutOverride($id, $request);
        $pdf = $this->pdfGenerator->generatePreviewPdf($template);

        return $this->buildInlinePdfResponse($pdf, sprintf('preview-%s.pdf', $template->getId()));
    }

    #[Route('/{id}/preview-html', name: 'document_template_preview_html', methods: ['GET', 'POST'])]
    public function previewHtml(int $id, Request $request): Response
    {
        $template = $this->applyPreviewLayoutOverride($id, $request);
        $html = $this->templateRenderer->renderPreview($template);

        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }

    #[Route('/{id}/preview', name: 'document_template_preview', methods: ['GET'])]
    public function preview(int $id): Response
    {
        $template = $this->findTemplate($id);

        return $this->render('document_template/preview.html.twig', [
            'template' => $template,
        ]);
    }

    #[Route('/{id}/publish', name: 'document_template_publish', methods: ['POST'])]
    public function publish(int $id, Request $request): Response
    {
        try {
            $this->templateBuilderService->publishTemplate($id);
            $message = 'Template published successfully';
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => $message]);
            }
            $this->addFlash('success', $message);
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('document_template_list');
    }

    #[Route('/{id}/activate', name: 'document_template_activate', methods: ['POST'])]
    public function activate(int $id, Request $request): Response
    {
        try {
            $this->templateBuilderService->activateTemplate($id);
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Template activated successfully']);
            }
            $this->addFlash('success', 'Template activated successfully');
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('document_template_list');
    }

    #[Route('/{id}/deactivate', name: 'document_template_deactivate', methods: ['POST'])]
    public function deactivate(int $id, Request $request): Response
    {
        try {
            $this->templateBuilderService->deactivateTemplate($id);
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Template deactivated successfully']);
            }
            $this->addFlash('success', 'Template deactivated successfully');
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('document_template_list');
    }

    #[Route('/{id}/unpublish', name: 'document_template_unpublish', methods: ['POST'])]
    public function unpublish(int $id, Request $request): Response
    {
        try {
            $this->templateBuilderService->unpublishTemplate($id);
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Template unpublished successfully']);
            }
            $this->addFlash('success', 'Template unpublished successfully');
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('document_template_edit', ['id' => $id]);
    }

    #[Route('/{id}/new-version', name: 'document_template_new_version', methods: ['POST'])]
    public function createNewVersion(int $id): Response
    {
        try {
            $newVersion = $this->templateBuilderService->createNewVersion($id);
            $this->addFlash('success', 'New version created successfully');
            return $this->redirectToRoute('document_template_edit', ['id' => $newVersion->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('document_template_list');
        }
    }

    #[Route('/{id}/delete', name: 'document_template_delete', methods: ['DELETE', 'POST'])]
    public function delete(int $id, Request $request): Response
    {
        $template = $this->findTemplate($id);

        if (!$template->isDeletable()) {
            $message = 'Only draft or inactive templates can be deleted';
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $message], 400);
            }
            $this->addFlash('error', $message);
            return $this->redirectToRoute('document_template_list');
        }

        try {
            $this->entityManager->remove($template);
            $this->entityManager->flush();

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Template deleted successfully']);
            }
            $this->addFlash('success', 'Template deleted successfully');
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('document_template_list');
    }

    private function findTemplate(int $id): DocumentTemplateConfiguration
    {
        $template = $this->entityManager->getRepository(DocumentTemplateConfiguration::class)->find($id);
        if (!$template) {
            throw $this->createNotFoundException('Document template not found');
        }

        return $template;
    }

    private function applyPreviewLayoutOverride(int $id, Request $request): DocumentTemplateConfiguration
    {
        $template = $this->findTemplate($id);

        if ($request->isMethod('POST')) {
            $data = json_decode($request->getContent(), true);
            if (isset($data['layout']) && is_array($data['layout'])) {
                $template->setLayout($data['layout']);
            }
        }

        return $template;
    }

    private function buildInlinePdfResponse(string $pdf, string $filename): Response
    {
        $response = new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }
}
