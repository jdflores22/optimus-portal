<?php

namespace App\Tests\Service;

use App\Entity\DocumentTemplateConfiguration;
use App\Entity\Enum\DocumentTemplateType;
use App\Service\DocumentTemplateRenderer;
use App\Service\DocumentTemplateSampleDataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class BillingTemplateLayoutRenderTest extends TestCase
{
    public function testBillingTemplateShiftsNoteBelowSixRowTable(): void
    {
        $layoutJson = file_get_contents(dirname(__DIR__, 2) . '/var/billing_template_5_layout.json');
        if ($layoutJson === false) {
            $this->markTestSkipped('Run bin/console app:export-template-layout first');
        }

        $layout = json_decode($layoutJson, true, 512, JSON_THROW_ON_ERROR);

        $verificationService = $this->createMock(\App\Service\DocumentVerificationService::class);
        $renderer = new DocumentTemplateRenderer(
            new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates')),
            new DocumentTemplateSampleDataProvider(),
            new \App\Service\DocumentTemplateVerticalLayout(),
            new \App\Service\DocumentTemplateQrCodeGenerator(),
            $verificationService,
            dirname(__DIR__, 2),
        );

        $template = new DocumentTemplateConfiguration();
        $template->setName('Billing');
        $template->setDocumentType(DocumentTemplateType::BILLING);
        $template->setPaperSize('A4');
        $template->setOrientation('portrait');
        $template->setLayout($layout);

        $sample = (new DocumentTemplateSampleDataProvider())->getSampleData(DocumentTemplateType::BILLING);
        $html = $renderer->render($template, $sample, false);

        preg_match('/NOTE:.*?top: (\d+)px/s', $html, $noteMatch);
        preg_match_all('/top: (\d+)px; width: 698px/s', $html, $tableMatches);

        $this->assertNotEmpty($noteMatch, 'NOTE block should be present in HTML');
        $noteY = (int) $noteMatch[1];

        // Table is 698px wide at y=423 in saved layout
        $tableY = 423;
        $minNoteY = $tableY + 300; // 6-row table should push NOTE well below 723
        $this->assertGreaterThan($minNoteY, $noteY, "NOTE at {$noteY}px should be below tall table (min {$minNoteY}px)");
    }
}
