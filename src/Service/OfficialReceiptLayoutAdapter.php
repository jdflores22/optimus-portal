<?php

namespace App\Service;

/**
 * Adapts a Billing Statement template layout for Official Receipt generation.
 */
class OfficialReceiptLayoutAdapter
{
    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public static function adapt(array $layout): array
    {
        $elements = $layout['elements'] ?? [];

        foreach ($elements as &$element) {
            if (($element['type'] ?? '') === 'heading') {
                $element['content'] = 'Official Receipt';
            }

            $label = (string) ($element['label'] ?? '');
            if ($label === 'INVOICE No:') {
                $element['label'] = 'OR No:';
            } elseif ($label === 'INVOICE DUE DATE:') {
                $element['label'] = 'RECEIPT DATE:';
            } elseif ($label === 'DATE:') {
                $element['label'] = 'BILLING DATE:';
            }

            if (($element['type'] ?? '') === 'border_box' && str_contains((string) ($element['content'] ?? ''), 'billing.status')) {
                $element['style']['backgroundColor'] = '#28a745';
                $element['style']['borderColor'] = '#28a745';
                $element['style']['color'] = '#ffffff';
            }
        }
        unset($element);

        $layout['elements'] = $elements;

        return $layout;
    }
}
