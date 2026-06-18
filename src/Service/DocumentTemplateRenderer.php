<?php

namespace App\Service;

use App\Entity\DocumentTemplateConfiguration;
use App\Form\DocumentBlockTypes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

class DocumentTemplateRenderer
{
    public function __construct(
        private Environment $twig,
        private DocumentTemplateSampleDataProvider $sampleDataProvider,
        private DocumentTemplateVerticalLayout $verticalLayout,
        private DocumentTemplateQrCodeGenerator $qrCodeGenerator,
        private DocumentVerificationService $documentVerificationService,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function renderPreview(DocumentTemplateConfiguration $template, ?array $context = null): string
    {
        $context ??= $this->sampleDataProvider->getSampleData($template->getDocumentType());
        $context = $this->ensurePreviewVerificationUrl($context);

        return $this->render($template, $context, true);
    }

    public function render(DocumentTemplateConfiguration $template, array $context, bool $preview = false): string
    {
        $layout = $this->normalizeLayout($template->getLayout());
        $elements = $layout['elements'] ?? [];
        usort($elements, fn (array $a, array $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        $elements = $this->normalizeTableElements($elements);
        $elements = $this->resolvePlaceholdersInElements($elements, $context);
        $elements = $this->resolveQrCodeImagesInElements($elements, $context);
        $elements = $this->resolveImageSourcesInElements($elements);

        if (($layout['canvas']['layoutMode'] ?? '') === 'free') {
            if ($this->hasSavedPositions($elements)) {
                $elements = $this->normalizePositions($elements, $layout['canvas']);
                $elements = $this->verticalLayout->resolvePinFlags($elements);
                $elements = $this->verticalLayout->adjustFlowingElementsBelowTable($elements);
            } else {
                $elements = $this->verticalLayout->applyStack($elements, $layout['canvas']);
            }

            $layout['canvas'] = $this->applyPageDimensions($layout['canvas'], $elements);
        }

        return $this->twig->render('document_template/pdf/document.html.twig', [
            'canvas' => $layout['canvas'] ?? [],
            'elements' => $elements,
            'context' => $context,
            'paperSize' => $layout['canvas']['paperSize'] ?? $template->getPaperSize(),
            'orientation' => $layout['canvas']['orientation'] ?? $template->getOrientation(),
            'preview' => $preview,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTableElements(array $elements): array
    {
        foreach ($elements as $index => $element) {
            $elements[$index] = DocumentBlockTypes::normalizeTableElement($element);
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function normalizeLayout(array $layout): array
    {
        $elements = $layout['elements'] ?? [];
        $hasPositions = false;

        foreach ($elements as $element) {
            if (isset($element['position']['x'], $element['position']['y'])) {
                $hasPositions = true;
                break;
            }
        }

        if (!isset($layout['canvas']) || !is_array($layout['canvas'])) {
            $layout['canvas'] = [];
        }

        if ($hasPositions) {
            $layout['canvas']['layoutMode'] = 'free';
        }

        $layout['canvas']['width'] ??= 794;
        $layout['canvas']['height'] ??= 1123;

        $layout = $this->syncCanvasDimensionsFromPaper($layout);

        return $layout;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function syncCanvasDimensionsFromPaper(array $layout): array
    {
        $paperSize = (string) ($layout['canvas']['paperSize'] ?? 'A4');
        $orientation = (string) ($layout['canvas']['orientation'] ?? 'portrait');

        $dimensions = match (strtolower($paperSize)) {
            'letter' => ['width' => 816, 'height' => 1056],
            'legal' => ['width' => 816, 'height' => 1344],
            default => ['width' => 794, 'height' => 1123],
        };

        if ($orientation === 'landscape') {
            $dimensions = ['width' => $dimensions['height'], 'height' => $dimensions['width']];
        }

        $layout['canvas']['width'] = $dimensions['width'];
        $layout['canvas']['height'] = $dimensions['height'];

        return $layout;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    private function hasSavedPositions(array $elements): bool
    {
        if ($elements === []) {
            return false;
        }

        foreach ($elements as $element) {
            if (!isset($element['position']['x'], $element['position']['y'], $element['position']['width'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed> $canvas
     * @return array<int, array<string, mixed>>
     */
    private function normalizePositions(array $elements, array $canvas): array
    {
        $margins = $canvas['margin'] ?? ['top' => 48, 'right' => 48, 'bottom' => 48, 'left' => 48];
        $pageWidth = (int) ($canvas['width'] ?? 794);
        $contentWidth = $pageWidth - (int) ($margins['left'] ?? 48) - (int) ($margins['right'] ?? 48);

        foreach ($elements as &$element) {
            if (!isset($element['position']) || !is_array($element['position'])) {
                $element['position'] = [];
            }

            $element['position']['x'] = (int) ($element['position']['x'] ?? ($margins['left'] ?? 48));
            $element['position']['y'] = (int) ($element['position']['y'] ?? ($margins['top'] ?? 48));
            $element['position']['width'] = (int) ($element['position']['width'] ?? $contentWidth);
        }
        unset($element);

        return $elements;
    }

    /**
     * @param array<string, mixed> $canvas
     * @param array<int, array<string, mixed>> $elements
     * @return array<string, mixed>
     */
    private function applyPageDimensions(array $canvas, array $elements): array
    {
        $baseHeight = (int) ($canvas['height'] ?? 1123);
        $canvas['renderHeight'] = $baseHeight;

        return $canvas;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed> $canvas
     */
    private function computeRenderHeight(array $elements, array $canvas): int
    {
        $baseHeight = (int) ($canvas['height'] ?? 1123);
        $bottomMargin = (int) (($canvas['margin'] ?? [])['bottom'] ?? 48);
        $maxBottom = 0;

        foreach ($elements as $element) {
            $position = $element['position'] ?? [];
            $y = (int) ($position['y'] ?? 0);
            $height = $this->verticalLayout->estimateElementHeight($element);
            $maxBottom = max($maxBottom, $y + $height);
        }

        return max($baseHeight, $maxBottom + $bottomMargin);
    }

    public function renderElement(array $element, array $context): string
    {
        $element = DocumentBlockTypes::normalizeTableElement($element);
        $resolved = $this->resolvePlaceholdersInElements([$element], $context);
        $resolved = $this->resolveQrCodeImagesInElements($resolved, $context);

        return $this->twig->render('document_template/pdf/_element.html.twig', [
            'element' => $resolved[0],
            'context' => $context,
            'preview' => true,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function resolvePlaceholdersInElements(array $elements, array $context): array
    {
        foreach ($elements as &$element) {
            foreach (['content', 'subtitle', 'leftContent', 'rightContent', 'label'] as $field) {
                if (!empty($element[$field]) && is_string($element[$field])) {
                    $element[$field] = $this->resolvePlaceholdersInText($element[$field], $context);
                    $element[$field] = $this->resolveBarePlaceholderInString($element[$field], $context);
                }
            }

            if (empty($element['placeholder']) && !empty($element['content']) && is_string($element['content'])
                && ($element['type'] ?? '') === 'field_value') {
                $element['placeholder'] = $this->normalizePlaceholderPath($element['content']);
            }

            if (!empty($element['placeholder']) && is_string($element['placeholder'])) {
                $placeholder = $this->normalizePlaceholderPath($element['placeholder']);
                $resolved = $this->resolveDotPath($placeholder, $context);

                if (is_array($resolved)) {
                    $element['resolvedRows'] = $resolved;
                } else {
                    $element['resolvedValue'] = $resolved !== null ? (string) $resolved : '—';
                }
            }
        }
        unset($element);

        return $elements;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function resolveQrCodeImagesInElements(array $elements, array $context): array
    {
        $verificationUrl = trim((string) ($context['document']['verification_url'] ?? ''));

        foreach ($elements as &$element) {
            if (($element['type'] ?? '') !== 'qr_code') {
                continue;
            }

            $value = $verificationUrl !== ''
                ? $verificationUrl
                : (string) ($element['resolvedValue'] ?? '');

            if ($value === '' || $value === '—') {
                continue;
            }

            $size = (int) ($element['size'] ?? 80);
            $element['resolvedValue'] = $value;
            $element['qrImageSrc'] = $this->qrCodeGenerator->generateDataUri($value, $size);
        }
        unset($element);

        return $elements;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function ensurePreviewVerificationUrl(array $context): array
    {
        if (!empty($context['document']['verification_url'])) {
            return $context;
        }

        $context['document'] ??= [];
        $context['document']['verification_url'] = $this->documentVerificationService->buildPreviewSampleUrl();

        return $context;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function resolveImageSourcesInElements(array $elements): array
    {
        foreach ($elements as &$element) {
            if (!in_array($element['type'] ?? '', ['logo', 'image'], true)) {
                continue;
            }

            $src = $element['config']['src'] ?? '';
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $fullPath = $this->resolvePublicImagePath($src);
            if ($fullPath !== null) {
                $element['config']['src'] = $fullPath;
            }
        }
        unset($element);

        return $elements;
    }

    private function resolvePublicImagePath(string $src): ?string
    {
        if (str_starts_with($src, '/uploads/')) {
            $fullPath = $this->projectDir . '/public' . $src;

            return file_exists($fullPath) ? $fullPath : null;
        }

        if (str_starts_with($src, 'uploads/')) {
            $fullPath = $this->projectDir . '/public/' . $src;

            return file_exists($fullPath) ? $fullPath : null;
        }

        if (str_starts_with($src, 'document-templates/')) {
            $fullPath = $this->projectDir . '/public/uploads/' . $src;

            return file_exists($fullPath) ? $fullPath : null;
        }

        return null;
    }

    public function resolvePlaceholderValue(string $path, array $context): string
    {
        $resolved = $this->resolveDotPath($path, $context);

        if (is_array($resolved)) {
            return '';
        }

        return $resolved !== null ? (string) $resolved : '—';
    }

    public function resolvePlaceholdersInText(string $text, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            function (array $matches) use ($context): string {
                $value = $this->resolveDotPath($matches[1], $context);
                return $value !== null ? (string) $value : $matches[0];
            },
            $text
        ) ?? $text;
    }

    private function resolveDotPath(string $path, array $context): mixed
    {
        $current = $context;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function normalizePlaceholderPath(string $placeholder): string
    {
        $placeholder = trim($placeholder);

        if (preg_match('/^\{\{\s*([\w.]+)\s*\}\}$/', $placeholder, $matches) === 1) {
            return $matches[1];
        }

        return $placeholder;
    }

    private function resolveBarePlaceholderInString(string $text, array $context): string
    {
        $resolved = $this->tryResolveBarePlaceholder($text, $context);

        return $resolved ?? $text;
    }

    private function tryResolveBarePlaceholder(string $text, array $context): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '' || preg_match('/^[\w.]+$/', $trimmed) !== 1) {
            return null;
        }

        $value = $this->resolveDotPath($trimmed, $context);
        if ($value === null || is_array($value)) {
            return null;
        }

        return (string) $value;
    }
}
