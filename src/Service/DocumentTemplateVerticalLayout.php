<?php

namespace App\Service;

/**
 * Stacks document template elements vertically by order while preserving horizontal placement.
 */
class DocumentTemplateVerticalLayout
{
    /**
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed> $canvas
     * @return array<int, array<string, mixed>>
     */
    public function applyStack(array $elements, array $canvas): array
    {
        $margins = $canvas['margin'] ?? ['top' => 48, 'right' => 48, 'bottom' => 48, 'left' => 48];
        $pageWidth = (int) ($canvas['width'] ?? 794);
        $contentWidth = $pageWidth - (int) ($margins['left'] ?? 48) - (int) ($margins['right'] ?? 48);
        $y = (int) ($margins['top'] ?? 48);

        foreach ($elements as &$element) {
            if (!isset($element['position']) || !is_array($element['position'])) {
                $element['position'] = [];
            }

            $element['position']['x'] = (int) ($element['position']['x'] ?? ($margins['left'] ?? 48));
            $element['position']['width'] = (int) ($element['position']['width'] ?? $contentWidth);
            $element['position']['y'] = $y;

            $blockHeight = (int) ($element['position']['measuredHeight'] ?? $this->estimateElementHeight($element));
            $y += $blockHeight + 4;
        }
        unset($element);

        return $elements;
    }

    /**
     * Keeps pinned elements at their saved Y while flowing footers (and other unpinned blocks)
     * directly below dynamic-height tables based on actual row data.
     *
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed> $canvas
     * @return array<int, array<string, mixed>>
     */
    public function applyAdaptivePositions(array $elements, array $canvas): array
    {
        if ($elements === []) {
            return $elements;
        }

        $margins = $canvas['margin'] ?? ['top' => 48, 'right' => 48, 'bottom' => 48, 'left' => 48];
        $flowStart = (int) ($margins['top'] ?? 48);

        foreach ($elements as $element) {
            if (!$this->isPinnedElement($element)) {
                continue;
            }

            $y = (int) ($element['position']['y'] ?? ($margins['top'] ?? 48));
            $height = $this->estimateElementHeight($element);
            $flowStart = max($flowStart, $y + $height + 4);
        }

        $flowY = $flowStart;
        foreach ($elements as &$element) {
            if ($this->isPinnedElement($element)) {
                continue;
            }

            if (!isset($element['position']) || !is_array($element['position'])) {
                $element['position'] = [];
            }

            $element['position']['y'] = $flowY;
            $flowY += $this->estimateElementHeight($element) + 4;
        }
        unset($element);

        return $elements;
    }

    /**
     * Elements added after the table but placed above it on the canvas must stay pinned.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    public function resolvePinFlags(array $elements): array
    {
        $tableIndex = $this->findTableIndex($elements);
        if ($tableIndex === null) {
            return $elements;
        }

        $table = $elements[$tableIndex];
        $tableOrder = (int) ($table['order'] ?? 0);
        $tableY = (int) ($table['position']['y'] ?? 0);

        foreach ($elements as &$element) {
            if (!isset($element['position']) || !is_array($element['position'])) {
                $element['position'] = [];
            }

            if (($element['type'] ?? '') === 'footer') {
                $element['position']['pinY'] = false;
                continue;
            }

            if (($element['order'] ?? 0) <= $tableOrder) {
                continue;
            }

            $elementY = (int) ($element['position']['y'] ?? 0);
            // Late-added blocks above the table top stay pinned; designed footer blocks flow.
            $element['position']['pinY'] = $elementY < $tableY;
        }
        unset($element);

        return $elements;
    }

    /**
     * Keeps every element at its saved canvas position. Only unpinned blocks after the
     * table (typically the footer) move down when real row data makes the table taller
     * than the single sample row shown in the editor.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    public function adjustFlowingElementsBelowTable(array $elements): array
    {
        $tableIndex = $this->findTableIndex($elements);
        if ($tableIndex === null) {
            return $elements;
        }

        $table = $elements[$tableIndex];
        $rows = $table['resolvedRows'] ?? [];
        if (count($rows) === 0) {
            return $elements;
        }

        $tableY = (int) ($table['position']['y'] ?? 0);
        $canvasTableHeight = (int) ($table['position']['measuredHeight'] ?? 0);
        if ($canvasTableHeight <= 0) {
            $canvasTableHeight = $this->estimateTableHeight(['resolvedRows' => [[]]]);
        }

        $actualTableHeight = $this->estimateElementHeight($table);
        $designedTableBottom = $tableY + $canvasTableHeight;
        $actualTableBottom = $tableY + $actualTableHeight + 4 + $this->dompdfTableSafetyPadding($table);

        $anchorIndex = null;
        $anchorY = null;
        for ($index = $tableIndex + 1; $index < count($elements); ++$index) {
            if ($this->isPinnedElement($elements[$index])) {
                continue;
            }

            $anchorIndex = $index;
            $anchorY = (int) ($elements[$index]['position']['y'] ?? $designedTableBottom);
            break;
        }

        if ($anchorIndex === null || $anchorY === null) {
            return $elements;
        }

        $designedGap = max(8, $anchorY - $designedTableBottom);
        $targetAnchorY = $actualTableBottom + $designedGap;
        $shift = $targetAnchorY - $anchorY;

        if ($shift === 0) {
            return $elements;
        }

        for ($index = $tableIndex + 1; $index < count($elements); ++$index) {
            if ($this->isPinnedElement($elements[$index])) {
                continue;
            }

            if (!isset($elements[$index]['position']) || !is_array($elements[$index]['position'])) {
                $elements[$index]['position'] = [];
            }

            $elements[$index]['position']['y'] = (int) ($elements[$index]['position']['y'] ?? $anchorY) + $shift;
        }

        return $elements;
    }

    /**
     * Recomputes Y for the table block (heading + table + footer) while keeping pinned
     * elements — such as logos and QR codes — at their saved canvas positions.
     *
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed> $canvas
     * @return array<int, array<string, mixed>>
     */
    public function applyRenderPositions(array $elements, array $canvas): array
    {
        $tableIndex = $this->findTableIndex($elements);
        if ($tableIndex === null) {
            return $this->applyAdaptivePositions($elements, $canvas);
        }

        $flowStartIndex = $this->findFlowStartIndex($elements, $tableIndex);
        $margins = $canvas['margin'] ?? ['top' => 48, 'right' => 48, 'bottom' => 48, 'left' => 48];
        $stackY = (int) ($elements[$flowStartIndex]['position']['y'] ?? ($margins['top'] ?? 48));

        foreach ($elements as $index => &$element) {
            if (!$this->isDynamicStackElement($elements, $index, $tableIndex, $flowStartIndex)) {
                continue;
            }

            if (!isset($element['position']) || !is_array($element['position'])) {
                $element['position'] = [];
            }

            $element['position']['y'] = $stackY;
            $stackY += $this->estimateElementHeight($element) + 4;
        }
        unset($element);

        return $elements;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    private function findTableIndex(array $elements): ?int
    {
        foreach ($elements as $index => $element) {
            if (($element['type'] ?? '') === 'table') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    private function findFlowStartIndex(array $elements, int $tableIndex): int
    {
        $flowStartIndex = $tableIndex;

        while ($flowStartIndex > 0) {
            $previousType = $elements[$flowStartIndex - 1]['type'] ?? '';
            if (!in_array($previousType, ['heading', 'divider', 'spacer'], true)) {
                break;
            }

            $flowStartIndex--;
        }

        return $flowStartIndex;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    private function isDynamicStackElement(array $elements, int $index, int $tableIndex, int $flowStartIndex): bool
    {
        if ($index >= $flowStartIndex && $index <= $tableIndex) {
            return true;
        }

        if ($index > $tableIndex) {
            return !$this->isPinnedElement($elements[$index]);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $element
     */
    public function isPinnedElement(array $element): bool
    {
        if (array_key_exists('pinY', $element['position'] ?? [])) {
            return (bool) $element['position']['pinY'];
        }

        return match ($element['type'] ?? '') {
            'footer' => false,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $element
     */
    public function estimateElementHeight(array $element): int
    {
        $style = $element['style'] ?? [];
        $padding = (int) ($style['padding'] ?? 0);
        $marginTop = (int) ($style['marginTop'] ?? 0);
        $marginBottom = (int) ($style['marginBottom'] ?? 8);

        if (($element['type'] ?? '') === 'table') {
            return $this->estimateTableHeight($element) + ($padding * 2) + $marginTop + $marginBottom + 4;
        }

        if (($element['type'] ?? '') === 'spacer') {
            return (int) ($element['height'] ?? 24) + ($padding * 2) + $marginTop + $marginBottom + 4;
        }

        if (($element['type'] ?? '') === 'divider') {
            $dividerStyle = $element['dividerStyle'] ?? 'solid';
            $contentHeight = match ($dividerStyle) {
                'slash' => (int) ($element['height'] ?? 24),
                'outline' => (int) ($element['height'] ?? 14),
                default => 20,
            };

            return $contentHeight + ($padding * 2) + $marginTop + $marginBottom + 4;
        }

        if (isset($element['position']['measuredHeight']) && (int) $element['position']['measuredHeight'] > 0) {
            return (int) $element['position']['measuredHeight'];
        }

        $contentHeight = match ($element['type'] ?? '') {
            'spacer' => (int) ($element['height'] ?? 24),
            'header_banner' => 72,
            'heading' => 40,
            'logo', 'image' => 56,
            'divider' => 20,
            'signature' => 64,
            'qr_code' => 96,
            'border_box' => 88,
            'two_column' => 64,
            'footer' => 36,
            'text' => 44,
            'placeholder', 'info_row', 'field_label', 'field_value' => 28,
            default => 40,
        };

        return $contentHeight + ($padding * 2) + $marginTop + $marginBottom + 4;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function estimateTableHeight(array $element): int
    {
        $rows = $element['resolvedRows'] ?? [];
        if ($rows === []) {
            return 58;
        }

        $fontSize = (float) ($element['style']['fontSize'] ?? 10);
        $headerHeight = (int) max(34, round($fontSize * 5.2));
        $rowBaseHeight = (int) max(20, round($fontSize * 3.1));
        $lineHeight = (int) max(12, round($fontSize * 1.9));
        $bodyHeight = 0;

        foreach ($rows as $row) {
            $rowHeight = $rowBaseHeight;
            if (!is_array($row)) {
                $bodyHeight += $rowHeight;
                continue;
            }

            foreach ($row as $cell) {
                if (!is_string($cell)) {
                    continue;
                }

                $lineCount = substr_count($cell, "\n") + 1;
                $rowHeight = max($rowHeight, $rowBaseHeight + (($lineCount - 1) * $lineHeight));
            }

            $bodyHeight += $rowHeight + 2;
        }

        return $headerHeight + $bodyHeight + 12;
    }

    /**
     * Extra clearance below tables in Dompdf, where row heights often exceed PHP estimates.
     *
     * @param array<string, mixed> $table
     */
    public function dompdfTableSafetyPadding(array $table): int
    {
        $rowCount = count($table['resolvedRows'] ?? []);

        return max(8, 8 + ($rowCount * 4));
    }
}
