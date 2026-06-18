<?php

namespace App\Service;

use App\Entity\DocumentTemplateConfiguration;
use App\Entity\Enum\DocumentTemplateType;
use App\Entity\Payment;

class OfficialReceiptDocumentGenerator
{
    public function __construct(
        private FileStorageServiceInterface $fileStorageService,
        private DocumentTemplateBuilderService $templateBuilderService,
        private DocumentTemplatePdfGenerator $pdfGenerator,
        private DocumentTemplateContextBuilder $contextBuilder,
        private DocumentVerificationService $documentVerificationService,
        private PaymentReceiptGenerator $legacyReceiptGenerator,
    ) {
    }

    /**
     * @return string Stored file path for the generated official receipt PDF
     */
    public function generateOfficialReceipt(Payment $payment): string
    {
        $officialTemplate = $this->templateBuilderService->getActiveTemplate(DocumentTemplateType::OFFICIAL_RECEIPT);
        $billingTemplate = $officialTemplate === null
            ? $this->templateBuilderService->getActiveTemplate(DocumentTemplateType::BILLING)
            : null;

        $sourceTemplate = $officialTemplate ?? $billingTemplate;

        if ($sourceTemplate === null) {
            return $this->legacyReceiptGenerator->generateOfficialReceipt($payment);
        }

        return $this->generateFromTemplate($payment, $sourceTemplate, $officialTemplate === null);
    }

    private function generateFromTemplate(
        Payment $payment,
        DocumentTemplateConfiguration $sourceTemplate,
        bool $adaptFromBilling,
    ): string {
        $paymentId = $payment->getId();
        if ($paymentId === null) {
            throw new \InvalidArgumentException('Payment must be persisted before generating an official receipt.');
        }

        $documentType = $adaptFromBilling
            ? DocumentTemplateType::BILLING
            : DocumentTemplateType::OFFICIAL_RECEIPT;

        $workingTemplate = new DocumentTemplateConfiguration();
        $workingTemplate->setPaperSize($sourceTemplate->getPaperSize());
        $workingTemplate->setOrientation($sourceTemplate->getOrientation());

        $layout = $sourceTemplate->getLayout();
        if ($adaptFromBilling) {
            $layout = OfficialReceiptLayoutAdapter::adapt($layout);
        }
        $workingTemplate->setLayout($layout);

        $context = $this->contextBuilder->buildOfficialReceiptContext($payment);
        $receiptNumber = $context['receipt']['number'] ?? ('OR-' . str_pad((string) $paymentId, 8, '0', STR_PAD_LEFT));
        $context = $this->documentVerificationService->appendVerificationContext(
            $context,
            $documentType,
            'payment',
            $paymentId,
            $receiptNumber,
            $this->buildVerificationSummary($context, $receiptNumber),
        );

        $pdfContent = $this->pdfGenerator->generatePdf($workingTemplate, $context);

        $filename = sprintf(
            'OfficialReceipt-%s_%s.pdf',
            str_pad((string) $paymentId, 8, '0', STR_PAD_LEFT),
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
            'receipts',
            'official'
        );

        @unlink($tempPath);

        return '/uploads/' . ltrim($filePath, '/');
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildVerificationSummary(array $context, string $receiptNumber): array
    {
        return [
            'document_number' => $receiptNumber,
            'manifest_number' => $context['manifest']['number'] ?? '',
            'bl_number' => $context['manifest']['bl_number'] ?? '',
            'broker_name' => $context['broker']['name'] ?? '',
            'total_amount' => $context['receipt']['amount'] ?? ($context['billing']['total_amount'] ?? ''),
            'company_name' => $context['company']['name'] ?? '',
            'generated_at' => $context['generated']['date'] ?? date('Y-m-d H:i:s'),
        ];
    }
}
