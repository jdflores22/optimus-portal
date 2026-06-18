<?php

namespace App\Tests\Service;

use App\Service\DocumentTemplateVerticalLayout;
use PHPUnit\Framework\TestCase;

class DocumentTemplateVerticalLayoutTest extends TestCase
{
    public function testStacksFooterDirectlyBelowTable(): void
    {
        $layout = new DocumentTemplateVerticalLayout();
        $canvas = ['width' => 794, 'height' => 1123, 'margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48]];

        $elements = $layout->applyStack([
            [
                'type' => 'table',
                'order' => 1,
                'position' => ['x' => 48, 'y' => 9999, 'width' => 698, 'measuredHeight' => 82],
                'resolvedRows' => [
                    ['MSCU1234567', 'Dry', '40ft', '2.0'],
                    ['TCLU7654321', 'Reefer', '20ft', '1.0'],
                ],
                'style' => ['marginBottom' => 8],
            ],
            [
                'type' => 'footer',
                'order' => 2,
                'position' => ['x' => 48, 'y' => 9999, 'width' => 698],
                'content' => 'Generated on {{ generated.date }}',
                'style' => ['marginBottom' => 0],
            ],
        ], $canvas);

        $tableBottom = $elements[0]['position']['y'] + 82 + 4;
        $this->assertSame($tableBottom, $elements[1]['position']['y']);
        $this->assertLessThan(1123, $elements[1]['position']['y']);
    }

    public function testAdaptivePositionsFlowFooterBelowTallTable(): void
    {
        $layout = new DocumentTemplateVerticalLayout();
        $canvas = ['margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48]];

        $elements = $layout->applyAdaptivePositions([
            [
                'type' => 'table',
                'order' => 1,
                'position' => ['x' => 48, 'y' => 600, 'width' => 698, 'pinY' => true],
                'resolvedRows' => array_fill(0, 8, ['A', 'B', 'C', 'D']),
            ],
            [
                'type' => 'footer',
                'order' => 2,
                'position' => ['x' => 48, 'y' => 650, 'width' => 698],
                'content' => 'Footer',
            ],
        ], $canvas);

        $tableBottom = 600 + $layout->estimateElementHeight($elements[0]) + 4;
        $this->assertSame($tableBottom, $elements[1]['position']['y']);
        $this->assertGreaterThan(650, $elements[1]['position']['y']);
    }

    public function testPinnedQrKeepsSavedY(): void
    {
        $layout = new DocumentTemplateVerticalLayout();
        $canvas = ['margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48]];

        $elements = $layout->applyAdaptivePositions([
            [
                'type' => 'qr_code',
                'order' => 1,
                'position' => ['x' => 520, 'y' => 72, 'width' => 120, 'pinY' => true],
                'placeholder' => 'noa.number',
            ],
            [
                'type' => 'table',
                'order' => 2,
                'position' => ['x' => 48, 'y' => 600, 'width' => 698, 'pinY' => true],
                'resolvedRows' => [['A', 'B', 'C', 'D']],
            ],
            [
                'type' => 'footer',
                'order' => 3,
                'position' => ['x' => 48, 'y' => 900, 'width' => 698],
            ],
        ], $canvas);

        $this->assertSame(72, $elements[0]['position']['y']);
    }

    public function testRenderPositionsStacksHeadingTableAndFooterTogether(): void
    {
        $layout = new DocumentTemplateVerticalLayout();
        $canvas = ['margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48]];

        $elements = $layout->applyRenderPositions([
            [
                'type' => 'heading',
                'order' => 1,
                'position' => ['x' => 48, 'y' => 560, 'width' => 698, 'pinY' => true],
                'content' => 'Container Details',
            ],
            [
                'type' => 'table',
                'order' => 2,
                'position' => ['x' => 48, 'y' => 620, 'width' => 698, 'pinY' => true],
                'resolvedRows' => [
                    ['MSCU1234567', 'Dry', '40ft', '2.0'],
                    ['TCLU7654321', 'Reefer', '20ft', '1.0'],
                ],
            ],
            [
                'type' => 'footer',
                'order' => 3,
                'position' => ['x' => 48, 'y' => 980, 'width' => 698],
                'content' => 'Footer',
            ],
        ], $canvas);

        $this->assertSame(560, $elements[0]['position']['y']);
        $this->assertSame(560 + $layout->estimateElementHeight($elements[0]) + 4, $elements[1]['position']['y']);
        $this->assertSame(
            $elements[1]['position']['y'] + $layout->estimateElementHeight($elements[1]) + 4,
            $elements[2]['position']['y']
        );
    }

    public function testRenderPositionsKeepsPinnedQrAboveTableWhenAddedLast(): void
    {
        $layout = new DocumentTemplateVerticalLayout();
        $canvas = ['margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48]];

        $elements = $layout->applyRenderPositions([
            [
                'type' => 'logo',
                'order' => 1,
                'position' => ['x' => 300, 'y' => 48, 'width' => 200, 'pinY' => true],
            ],
            [
                'type' => 'heading',
                'order' => 10,
                'position' => ['x' => 48, 'y' => 560, 'width' => 698, 'pinY' => true],
                'content' => 'Container Details',
            ],
            [
                'type' => 'table',
                'order' => 11,
                'position' => ['x' => 48, 'y' => 620, 'width' => 698, 'pinY' => true],
                'resolvedRows' => [['A', 'B', 'C', 'D']],
            ],
            [
                'type' => 'footer',
                'order' => 12,
                'position' => ['x' => 48, 'y' => 980, 'width' => 698],
                'content' => 'Footer',
            ],
            [
                'type' => 'qr_code',
                'order' => 20,
                'position' => ['x' => 560, 'y' => 72, 'width' => 120, 'pinY' => true],
                'placeholder' => 'noa.number',
            ],
        ], $canvas);

        $this->assertSame(72, $elements[4]['position']['y']);
        $this->assertGreaterThan(700, $elements[3]['position']['y']);
    }

    public function testResolvePinFlagsKeepsLateAddedQrPinnedAboveTable(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $elements = $layout->resolvePinFlags([
            [
                'type' => 'table',
                'order' => 10,
                'position' => ['x' => 48, 'y' => 600, 'width' => 698],
                'resolvedRows' => [['A', 'B', 'C', 'D']],
            ],
            [
                'type' => 'qr_code',
                'order' => 20,
                'position' => ['x' => 560, 'y' => 72, 'width' => 120],
            ],
        ]);

        $this->assertTrue($elements[1]['position']['pinY']);
    }

    public function testAdjustFlowingElementsBelowTableKeepsCanvasPositionsWhenTableUnchanged(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $elements = $layout->adjustFlowingElementsBelowTable([
            [
                'type' => 'table',
                'order' => 1,
                'position' => ['x' => 48, 'y' => 600, 'width' => 698, 'measuredHeight' => 82],
                'resolvedRows' => [],
                'style' => [],
            ],
            [
                'type' => 'footer',
                'order' => 2,
                'position' => ['x' => 48, 'y' => 686, 'width' => 698],
                'content' => 'Footer',
            ],
        ]);

        $this->assertSame(600, $elements[0]['position']['y']);
        $this->assertSame(686, $elements[1]['position']['y']);
    }

    public function testAdjustFlowingElementsBelowTablePushesFooterWhenRowsGrow(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $elements = $layout->adjustFlowingElementsBelowTable([
            [
                'type' => 'table',
                'order' => 1,
                'position' => ['x' => 48, 'y' => 600, 'width' => 698, 'measuredHeight' => 82],
                'resolvedRows' => [
                    ['MSCU1234567', 'Dry', '40ft', '2.0'],
                    ['TCLU7654321', 'Reefer', '20ft', '1.0'],
                    ['GESU9876543', 'Dry', '20ft', '1.0'],
                ],
                'style' => [],
            ],
            [
                'type' => 'footer',
                'order' => 2,
                'position' => ['x' => 48, 'y' => 686, 'width' => 698, 'pinY' => false],
                'content' => 'Footer',
            ],
        ]);

        $tableBottom = 600 + $layout->estimateElementHeight($elements[0]) + 4;
        $this->assertGreaterThan(686, $elements[1]['position']['y']);
        $this->assertGreaterThanOrEqual($tableBottom, $elements[1]['position']['y']);
    }

    public function testAdjustFlowingElementsShiftsAllPostTableBlocksTogether(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $elements = $layout->resolvePinFlags([
            [
                'type' => 'table',
                'order' => 18,
                'position' => ['x' => 48, 'y' => 372, 'width' => 698, 'measuredHeight' => 82],
                'resolvedRows' => [
                    ['1', 'Freight Charges', "$1,082.00\nP66,815.66", '0%'],
                    ['2', 'Terminal Handling Charges (THC)', "$1,500.00\nP92,628.00", '0%'],
                    ['3', 'Other Fee', 'P617.52', '0%'],
                    ['4', 'System Fee', 'P617.52', '0%'],
                    ['5', 'Fuel Surcharge', 'P617.52', '0%'],
                    ['6', 'Cleaning Container', 'P617.52', '0%'],
                ],
            ],
            [
                'type' => 'field_label',
                'order' => 19,
                'position' => ['x' => 48, 'y' => 580, 'width' => 80],
                'label' => 'NOTE:',
            ],
            [
                'type' => 'field_value',
                'order' => 22,
                'position' => ['x' => 500, 'y' => 600, 'width' => 246],
                'resolvedValue' => 'P161,913.74',
            ],
            [
                'type' => 'footer',
                'order' => 24,
                'position' => ['x' => 48, 'y' => 680, 'width' => 400],
                'content' => 'Generated by: Sample',
            ],
        ]);

        $elements = $layout->adjustFlowingElementsBelowTable($elements);

        $this->assertFalse($elements[1]['position']['pinY']);
        $this->assertFalse($elements[2]['position']['pinY']);
        $this->assertGreaterThan(580, $elements[1]['position']['y']);
        $this->assertGreaterThan(600, $elements[2]['position']['y']);
        $this->assertGreaterThan(680, $elements[3]['position']['y']);
        $this->assertSame(
            $elements[1]['position']['y'] - 580,
            $elements[2]['position']['y'] - 600,
            'Post-table blocks should keep their relative spacing'
        );
    }

    public function testAdjustFlowingElementsKeepsPinnedQrAtSavedY(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $elements = $layout->resolvePinFlags([
            [
                'type' => 'table',
                'order' => 10,
                'position' => ['x' => 48, 'y' => 600, 'width' => 698, 'measuredHeight' => 82],
                'resolvedRows' => [['A'], ['B'], ['C']],
            ],
            [
                'type' => 'footer',
                'order' => 12,
                'position' => ['x' => 48, 'y' => 686, 'width' => 698],
                'content' => 'Footer',
            ],
            [
                'type' => 'qr_code',
                'order' => 20,
                'position' => ['x' => 560, 'y' => 72, 'width' => 120],
            ],
        ]);

        $elements = $layout->adjustFlowingElementsBelowTable($elements);

        $this->assertSame(72, $elements[2]['position']['y']);
        $this->assertGreaterThan(686, $elements[1]['position']['y']);
    }

    public function testAdjustFlowingElementsShiftsWhenCanvasMeasuredHeightIsTooLarge(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $elements = $layout->resolvePinFlags([
            [
                'type' => 'table',
                'order' => 18,
                'position' => ['x' => 48, 'y' => 423, 'width' => 698, 'measuredHeight' => 400],
                'resolvedRows' => [
                    ['1', 'Freight Charges', "$500.00\nP30,155.50", '0%'],
                    ['2', 'Terminal Handling Charges (THC)', "$450.00\nP27,139.95", '0%'],
                    ['3', 'Other Fee', 'P603.11', '0%'],
                    ['4', 'System Fee', 'P603.11', '0%'],
                    ['5', 'Fuel Surcharge', 'P603.11', '0%'],
                    ['6', 'Cleaning Container', 'P603.11', '0%'],
                ],
                'style' => ['marginBottom' => 8],
            ],
            [
                'type' => 'field_label',
                'order' => 19,
                'position' => ['x' => 48, 'y' => 580, 'width' => 80],
                'label' => 'NOTE:',
            ],
        ]);

        $elements = $layout->adjustFlowingElementsBelowTable($elements);

        $tableBottom = 423 + $layout->estimateElementHeight($elements[0]) + 4;
        $this->assertGreaterThan(580, $elements[1]['position']['y']);
        $this->assertGreaterThanOrEqual($tableBottom + 8, $elements[1]['position']['y']);
    }

    public function testBillingRowsWithAllMultilineAmountsShiftFurtherThanSample(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $sampleRows = [
            ['1', 'Freight', "$1,082.00\nP66,815.66", '0%'],
            ['2', 'THC', "$1,500.00\nP92,628.00", '0%'],
            ['3', 'Other Fee', 'P617.52', '0%'],
            ['4', 'System Fee', 'P617.52', '0%'],
        ];

        $billingRows = [
            ['1', 'Freight', "$500.00\nP30,155.50", '0%'],
            ['2', 'THC', "$450.00\nP27,139.95", '0%'],
            ['3', 'Other Fee', "$10.00\nP603.11", '0%'],
            ['4', 'System Fee', "$10.00\nP603.11", '0%'],
        ];

        $shiftFor = static function (array $rows) use ($layout): int {
            $elements = $layout->resolvePinFlags([
                [
                    'type' => 'table',
                    'order' => 18,
                    'position' => ['x' => 48, 'y' => 423, 'width' => 698, 'measuredHeight' => 154],
                    'resolvedRows' => $rows,
                    'style' => ['marginBottom' => 8],
                ],
                [
                    'type' => 'field_label',
                    'order' => 19,
                    'position' => ['x' => 48, 'y' => 580, 'width' => 80],
                    'label' => 'NOTE:',
                ],
            ]);

            $elements = $layout->adjustFlowingElementsBelowTable($elements);

            return (int) $elements[1]['position']['y'] - 580;
        };

        $this->assertGreaterThan($shiftFor($sampleRows), $shiftFor($billingRows));
    }

    public function testAdjustFlowingElementsPullsPostTableBlocksUpWhenCanvasMeasuredHeightIsTooLarge(): void
    {
        $layout = new DocumentTemplateVerticalLayout();

        $elements = $layout->resolvePinFlags([
            [
                'type' => 'table',
                'order' => 21,
                'position' => ['x' => 24, 'y' => 310, 'width' => 1075, 'measuredHeight' => 154],
                'resolvedRows' => [
                    ['1', 'WHLU8765432', '40 Feet', 'DRY', '—', '—', '—', 'EDO-202606-0002', '11-JUN-2026', 'ATI Terminal Facility'],
                ],
                'style' => ['fontSize' => 7, 'marginBottom' => 8],
            ],
            [
                'type' => 'field_label',
                'order' => 22,
                'position' => ['x' => 48, 'y' => 464, 'width' => 1075],
                'label' => 'PORT OPERATIONS DIRECTIVE:',
            ],
            [
                'type' => 'text',
                'order' => 23,
                'position' => ['x' => 48, 'y' => 494, 'width' => 1000],
                'content' => 'Directive text',
            ],
        ]);

        $elements = $layout->adjustFlowingElementsBelowTable($elements);

        $tableBottom = 310 + $layout->estimateElementHeight($elements[0]) + 4 + $layout->dompdfTableSafetyPadding($elements[0]);
        $this->assertLessThan(464, $elements[1]['position']['y']);
        $this->assertGreaterThanOrEqual($tableBottom + 8, $elements[1]['position']['y']);
        $this->assertSame(
            $elements[1]['position']['y'] - 464,
            $elements[2]['position']['y'] - 494,
            'Post-table blocks should keep their relative spacing'
        );
    }

    public function testTableHeightIgnoresCanvasMeasuredHeight(): void
    {
        $layout = new DocumentTemplateVerticalLayout();
        $height = $layout->estimateElementHeight([
            'type' => 'table',
            'position' => ['measuredHeight' => 82],
            'resolvedRows' => [['A'], ['B'], ['C']],
            'style' => [],
        ]);

        $this->assertGreaterThan(82, $height);
    }
}
