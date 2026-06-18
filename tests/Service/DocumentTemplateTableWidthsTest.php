<?php

namespace App\Tests\Service;

use App\Form\DocumentBlockTypes;
use App\Service\DocumentTemplateRenderer;
use App\Service\DocumentVerificationService;
use App\Service\DocumentTemplateQrCodeGenerator;
use App\Service\DocumentTemplateSampleDataProvider;
use App\Service\DocumentTemplateVerticalLayout;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class DocumentTemplateTableWidthsTest extends TestCase
{
    public function testComputeTableColumnWidthsNormalizesCustomWidths(): void
    {
        $widths = DocumentBlockTypes::computeTableColumnWidths(
            ['A', 'B', 'C'],
            [20, 30, 50],
        );

        self::assertCount(3, $widths);
        self::assertEqualsWithDelta(100.0, array_sum($widths), 0.1);
        self::assertEqualsWithDelta(20.0, $widths[0], 0.1);
        self::assertEqualsWithDelta(50.0, $widths[2], 0.1);
    }

    public function testRenderedTableIncludesColumnWidthStyles(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader);

        $verificationService = $this->createMock(DocumentVerificationService::class);
        $verificationService->method('buildPreviewSampleUrl')
            ->willReturn('https://example.com/verify/document/preview-sample');

        $renderer = new DocumentTemplateRenderer(
            $twig,
            new DocumentTemplateSampleDataProvider(),
            new DocumentTemplateVerticalLayout(),
            new DocumentTemplateQrCodeGenerator(),
            $verificationService,
            dirname(__DIR__, 2),
        );

        $element = DocumentBlockTypes::normalizeTableElement([
            'type' => 'table',
            'columns' => ['NO.', 'CONTAINER NUMBER', 'OPTIMUS REF NO.'],
            'columnWidths' => [10, 50, 40],
            'placeholder' => 'containers.table',
            'resolvedRows' => [
                ['1', 'WHLU8765432', 'EDO-202606-0002'],
            ],
        ]);

        $html = $renderer->renderElement($element, [
            'containers' => [
                'table' => [
                    ['1', 'WHLU8765432', 'EDO-202606-0002'],
                ],
            ],
        ]);

        self::assertStringContainsString('width: 10%', $html);
        self::assertStringContainsString('width: 50%', $html);
        self::assertStringContainsString('width: 40%', $html);
    }
}
