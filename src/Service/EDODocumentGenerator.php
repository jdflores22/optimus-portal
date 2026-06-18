<?php

namespace App\Service;

use App\Entity\DocumentTemplateConfiguration;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\DocumentTemplateType;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Generates eDO PDF documents from the active EDO document template.
 */
class EDODocumentGenerator
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
     * @param array<int, ElectronicDeliveryOrder> $edos
     */
    public function generateBulkPDF(array $edos, ?\App\Entity\User $generatedBy = null): string
    {
        if ($edos === []) {
            throw new \InvalidArgumentException('Cannot generate PDF for empty eDO array');
        }

        $activeTemplate = $this->getActiveEdoTemplate();
        if ($activeTemplate === null) {
            throw new \RuntimeException(
                'No active EDO document template found. Please publish and activate an EDO template in Document Templates.'
            );
        }

        return $this->generateFromTemplate($edos, $activeTemplate, $generatedBy);
    }

    public function generatePDF(ElectronicDeliveryOrder $edo, ?\App\Entity\User $generatedBy = null): string
    {
        return $this->generateBulkPDF([$edo], $generatedBy);
    }

    public function getActiveEdoTemplate(): ?DocumentTemplateConfiguration
    {
        return $this->templateBuilderService->getActiveTemplate(DocumentTemplateType::EDO);
    }

    /**
     * @param array<int, ElectronicDeliveryOrder> $edos
     */
    private function generateFromTemplate(array $edos, DocumentTemplateConfiguration $activeTemplate, ?\App\Entity\User $generatedBy = null): string
    {
        $firstEdo = $edos[0];
        $manifest = $firstEdo->getManifest();

        $context = $this->contextBuilder->buildEdoBulkContext($edos, $generatedBy);
        $documentNumber = count($edos) > 1
            ? (string) $manifest->getManifestNumber()
            : $firstEdo->getEdoNumber();

        $subjectId = $firstEdo->getId() ?? $manifest->getId() ?? 0;

        $context = $this->documentVerificationService->appendVerificationContext(
            $context,
            DocumentTemplateType::EDO,
            'edo',
            (int) $subjectId,
            $documentNumber,
            $this->buildVerificationSummary($context),
        );

        $pdfContent = $this->pdfGenerator->generatePdf($activeTemplate, $context);

        $filename = count($edos) > 1
            ? sprintf('EDO_BULK_%s_%s.pdf', $manifest->getManifestNumber(), date('YmdHis'))
            : sprintf('EDO_%s_%s.pdf', $firstEdo->getEdoNumber(), date('YmdHis'));

        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $pdfContent);

        $filePath = $this->fileStorageService->uploadFile(
            new UploadedFile(
                $tempPath,
                $filename,
                'application/pdf',
                null,
                true
            ),
            'documents',
            'edo'
        );

        @unlink($tempPath);

        return $filePath;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildVerificationSummary(array $context): array
    {
        return [
            'document_number' => $context['manifest']['number'] ?? '',
            'manifest_number' => $context['manifest']['number'] ?? '',
            'bl_number' => $context['manifest']['bl_number'] ?? '',
            'broker_name' => $context['broker']['name'] ?? '',
            'consignee_name' => $context['consignee']['name'] ?? '',
            'company_name' => $context['company']['name'] ?? '',
            'generated_by' => $context['generated']['by'] ?? '',
            'generated_at' => $context['generated']['datetime'] ?? date('Y-m-d H:i:s'),
        ];
    }
}
