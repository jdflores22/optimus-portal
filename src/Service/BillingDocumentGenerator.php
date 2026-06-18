<?php

namespace App\Service;

use App\Entity\Billing;
use App\Entity\Enum\DocumentTemplateType;

class BillingDocumentGenerator
{
    public function __construct(
        private FileStorageServiceInterface $fileStorageService,
        private DocumentTemplateBuilderService $templateBuilderService,
        private DocumentTemplatePdfGenerator $pdfGenerator,
        private DocumentTemplateContextBuilder $contextBuilder,
        private DocumentVerificationService $documentVerificationService,
    ) {
    }

    /**
     * @return string Stored file path for the generated billing PDF
     */
    public function generatePDF(Billing $billing, ?bool $isPaid = null): string
    {
        $activeTemplate = $this->getActiveBillingTemplate();

        if ($activeTemplate) {
            return $this->generateFromTemplate($billing, $activeTemplate, $isPaid);
        }

        throw new \RuntimeException(
            'No active Billing document template found. Please publish and activate a BILLING template in Document Templates.'
        );
    }

    public function getActiveBillingTemplate(): ?\App\Entity\DocumentTemplateConfiguration
    {
        return $this->templateBuilderService->getActiveTemplate(DocumentTemplateType::BILLING);
    }

    private function generateFromTemplate(Billing $billing, \App\Entity\DocumentTemplateConfiguration $activeTemplate, ?bool $isPaid = null): string
    {
        $billingId = $billing->getId();
        if ($billingId === null) {
            throw new \InvalidArgumentException('Billing must be persisted before generating a PDF.');
        }

        $manifest = $billing->getManifest();
        if ($manifest === null) {
            throw new \InvalidArgumentException('Billing must be linked to a manifest.');
        }

        $context = $this->contextBuilder->buildBillingContext($billing, $isPaid);
        $invoiceNumber = $context['billing']['invoice_number'] ?? (string) $billingId;
        $context = $this->documentVerificationService->appendVerificationContext(
            $context,
            DocumentTemplateType::BILLING,
            'billing',
            $billingId,
            $invoiceNumber,
            $this->buildVerificationSummary($context),
        );

        $pdfContent = $this->pdfGenerator->generatePdf($activeTemplate, $context);

        $filename = sprintf(
            'Billing-%s_%s.pdf',
            str_pad((string) $billingId, 5, '0', STR_PAD_LEFT),
            date('YmdHis')
        );
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $pdfContent);

        $filePath = $this->fileStorageService->uploadFile(
            new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $tempPath,
                $filename,
                'application/pdf',
                null,
                true
            ),
            'documents',
            'billing'
        );

        @unlink($tempPath);

        $billing->setPdfPath($filePath);
        $billing->setPdfTemplateHash(self::computeTemplateHash($activeTemplate));

        return $filePath;
    }

    public static function computeTemplateHash(\App\Entity\DocumentTemplateConfiguration $template): string
    {
        // Bump when PDF layout logic changes so stored billing PDFs refresh on download.
        $rendererVersion = 3;

        return hash('sha256', $rendererVersion . json_encode($template->getLayout(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildVerificationSummary(array $context): array
    {
        return [
            'document_number' => $context['billing']['invoice_number'] ?? '',
            'manifest_number' => $context['manifest']['number'] ?? '',
            'bl_number' => $context['manifest']['bl_number'] ?? '',
            'broker_name' => $context['broker']['name'] ?? '',
            'total_amount' => $context['billing']['total_amount'] ?? '',
            'company_name' => $context['company']['name'] ?? '',
            'generated_at' => $context['generated']['date'] ?? date('Y-m-d H:i:s'),
        ];
    }
}
