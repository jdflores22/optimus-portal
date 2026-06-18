<?php

namespace App\Service;

use App\Entity\DocumentTemplateConfiguration;

class DocumentTemplatePdfGenerator
{
    public function __construct(
        private DocumentTemplateRenderer $templateRenderer,
        private DocumentTemplateSampleDataProvider $sampleDataProvider,
        private DompdfFactory $dompdfFactory,
    ) {
    }

    /**
     * @return string PDF binary
     */
    public function generatePreviewPdf(DocumentTemplateConfiguration $template): string
    {
        $context = $this->sampleDataProvider->getSampleData($template->getDocumentType());

        return $this->generatePdf($template, $context);
    }

    /**
     * @return string PDF binary
     */
    public function generatePdf(DocumentTemplateConfiguration $template, array $context): string
    {
        $html = $this->templateRenderer->render($template, $context, false);

        return $this->renderHtmlToPdf($html, $template);
    }

    /**
     * @return string PDF binary
     */
    public function renderHtmlToPdf(string $html, DocumentTemplateConfiguration $template): string
    {
        $dompdf = $this->dompdfFactory->create();
        $dompdf->loadHtml($html);
        $dompdf->setPaper($template->getPaperSize(), $template->getOrientation());
        $dompdf->render();

        return $dompdf->output();
    }
}
