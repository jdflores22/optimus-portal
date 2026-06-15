<?php

namespace App\Service;

use App\Entity\FormConfiguration;

class DynamicFormRenderer
{
    public function __construct(
        private ValidationService $validationService
    ) {
    }

    /**
     * Render a form configuration as HTML
     * 
     * @param FormConfiguration $config The form configuration to render
     * @return string The rendered HTML
     */
    public function renderForm(FormConfiguration $config): string
    {
        $fields = $config->getFields();
        
        if (!isset($fields['fields']) || !is_array($fields['fields'])) {
            return '<p>No fields configured for this form.</p>';
        }

        // Sort fields by order
        $sortedFields = $fields['fields'];
        usort($sortedFields, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $html = '<div class="dynamic-form">';
        
        foreach ($sortedFields as $field) {
            $html .= $this->renderField($field);
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Validate a form submission against the form configuration
     * 
     * @param FormConfiguration $config The form configuration
     * @param array $data The submitted data
     * @return array Array with 'valid' boolean and 'errors' array
     */
    public function validateSubmission(FormConfiguration $config, array $data): array
    {
        $fields = $config->getFields();
        $errors = [];
        
        if (!isset($fields['fields']) || !is_array($fields['fields'])) {
            return ['valid' => false, 'errors' => ['form' => 'Invalid form configuration']];
        }

        foreach ($fields['fields'] as $field) {
            $fieldId = $field['id'];
            $fieldLabel = $field['label'];
            $fieldType = $field['type'];
            $isRequired = $field['required'] ?? false;
            $validation = $field['validation'] ?? [];

            if (!$this->shouldShowField($field, $data)) {
                continue;
            }

            // Check if required field is present
            if ($isRequired && (!isset($data[$fieldId]) || $this->isEmpty($data[$fieldId]))) {
                $errors[$fieldId] = "{$fieldLabel} is required";
                continue;
            }

            // Skip validation if field is not required and not provided
            if (!isset($data[$fieldId]) || $this->isEmpty($data[$fieldId])) {
                continue;
            }

            $value = $data[$fieldId];

            // Type-specific validation
            $typeError = $this->validateFieldType($fieldType, $value, $fieldLabel);
            if ($typeError) {
                $errors[$fieldId] = $typeError;
                continue;
            }

            // Custom validation rules
            $validationError = $this->validateFieldRules($value, $validation, $fieldLabel);
            if ($validationError) {
                $errors[$fieldId] = $validationError;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Render a single field as HTML
     * 
     * @param array $field The field definition
     * @return string The rendered HTML for the field
     */
    private function renderField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $label = htmlspecialchars($field['label']);
        $type = $field['type'];
        $required = $field['required'] ?? false;
        $requiredMark = $required ? '<span class="text-error">*</span>' : '';

        $html = '<div class="form-control mb-4">';
        $html .= "<label for=\"{$fieldId}\" class=\"label py-1\">";
        $html .= "<span class=\"label-text font-medium\">{$label} {$requiredMark}</span>";
        $html .= '</label>';

        switch ($type) {
            case 'text':
                $html .= $this->renderTextField($field);
                break;
            case 'number':
                $html .= $this->renderNumberField($field);
                break;
            case 'date':
                $html .= $this->renderDateField($field);
                break;
            case 'file':
                $html .= $this->renderFileField($field);
                break;
            case 'dropdown':
                $html .= $this->renderDropdownField($field);
                break;
            case 'checkbox':
                $html .= $this->renderCheckboxField($field);
                break;
            case 'radio':
                $html .= $this->renderRadioField($field);
                break;
            case 'geolocation':
                $html .= $this->renderGeolocationField($field);
                break;
            default:
                $html .= '<p class="text-error text-sm">Unknown field type</p>';
        }

        $html .= '<label class="label py-0"><span class="label-text-alt text-error hidden field-error"></span></label>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a text input field
     */
    private function renderTextField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        
        return "<input type=\"text\" id=\"{$fieldId}\" name=\"{$fieldId}\" {$required} " .
               "class=\"input input-bordered w-full\" />";
    }

    /**
     * Render a number input field
     */
    private function renderNumberField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        
        return "<input type=\"number\" id=\"{$fieldId}\" name=\"{$fieldId}\" {$required} " .
               "class=\"input input-bordered w-full\" />";
    }

    /**
     * Render a date input field
     */
    private function renderDateField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        
        return "<input type=\"date\" id=\"{$fieldId}\" name=\"{$fieldId}\" {$required} " .
               "class=\"input input-bordered w-full\" />";
    }

    /**
     * Render a file input field
     */
    private function renderFileField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        $validation = $field['validation'] ?? [];
        
        $accept = '';
        if (isset($validation['allowedTypes']) && is_array($validation['allowedTypes'])) {
            $extensions = array_map(fn($type) => '.' . $type, $validation['allowedTypes']);
            $accept = 'accept="' . implode(',', $extensions) . '"';
        }
        
        return "<input type=\"file\" id=\"{$fieldId}\" name=\"{$fieldId}\" {$required} {$accept} " .
               "class=\"file-input file-input-bordered w-full\" />";
    }

    /**
     * Render a dropdown field
     */
    private function renderDropdownField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        $validation = $field['validation'] ?? [];
        $options = $validation['options'] ?? [];
        
        $html = "<select id=\"{$fieldId}\" name=\"{$fieldId}\" {$required} " .
                "class=\"select select-bordered w-full\">";
        $html .= '<option value="">-- Select --</option>';
        
        foreach ($options as $option) {
            $value = htmlspecialchars($option['value'] ?? $option);
            $label = htmlspecialchars($option['label'] ?? $option);
            $html .= "<option value=\"{$value}\">{$label}</option>";
        }
        
        $html .= '</select>';
        
        return $html;
    }

    /**
     * Render a checkbox field
     */
    private function renderCheckboxField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        
        return "<input type=\"checkbox\" id=\"{$fieldId}\" name=\"{$fieldId}\" value=\"1\" {$required} " .
               "class=\"checkbox checkbox-primary\" />";
    }

    /**
     * Render a geolocation map picker field
     */
    private function renderGeolocationField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        $validation = $field['validation'] ?? [];
        $defaultLat = $validation['defaultLat'] ?? 14.5995;
        $defaultLng = $validation['defaultLng'] ?? 120.9842;
        $defaultZoom = $validation['defaultZoom'] ?? 13;

        return <<<HTML
<div class="form-geolocation-picker space-y-3"
     data-field-id="{$fieldId}"
     data-default-lat="{$defaultLat}"
     data-default-lng="{$defaultLng}"
     data-default-zoom="{$defaultZoom}">
    <p class="text-xs text-base-content/60">Click the map or drag the pin to set a location.</p>
    <div id="map_{$fieldId}" class="geolocation-map w-full h-72 rounded-xl border border-base-content/15 overflow-hidden z-0"></div>
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn btn-sm btn-outline gap-1 geolocation-use-gps">
            <span class="icon-[tabler--current-location] size-4"></span>
            Use my location
        </button>
        <span class="text-xs text-base-content/50 geolocation-coords-display">No location selected yet</span>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <input type="text" name="{$fieldId}[latitude]" id="field_{$fieldId}_lat"
               class="input input-bordered input-sm w-full geolocation-lat" readonly {$required}>
        <input type="text" name="{$fieldId}[longitude]" id="field_{$fieldId}_lng"
               class="input input-bordered input-sm w-full geolocation-lng" readonly {$required}>
    </div>
</div>
HTML;
    }

    /**
     * Render a radio button field
     */
    private function renderRadioField(array $field): string
    {
        $fieldId = htmlspecialchars($field['id']);
        $required = $field['required'] ? 'required' : '';
        $validation = $field['validation'] ?? [];
        $options = $validation['options'] ?? [];
        
        $html = '<div class="space-y-2">';
        
        foreach ($options as $index => $option) {
            $value = htmlspecialchars($option['value'] ?? $option);
            $label = htmlspecialchars($option['label'] ?? $option);
            $optionId = "{$fieldId}_{$index}";
            
            $html .= '<label class="flex items-center gap-3 cursor-pointer">';
            $html .= "<input type=\"radio\" id=\"{$optionId}\" name=\"{$fieldId}\" value=\"{$value}\" {$required} " .
                     "class=\"radio radio-primary\" />";
            $html .= "<span class=\"text-sm\">{$label}</span>";
            $html .= '</label>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Check if a value is empty (considering various types)
     */
    private function isEmpty($value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            if (isset($value['latitude']) || isset($value['longitude'])) {
                $lat = trim((string) ($value['latitude'] ?? ''));
                $lng = trim((string) ($value['longitude'] ?? ''));

                return $lat === '' || $lng === '';
            }

            if (isset($value['region_id']) || isset($value['city_id']) || isset($value['province_id']) || isset($value['barangay_id']) || isset($value['barangay'])) {
                $hasProvince = !empty($value['province_id'])
                    || trim((string) ($value['province_name'] ?? $value['province'] ?? '')) !== '';
                $hasBarangay = !empty($value['barangay_id'])
                    || trim((string) ($value['barangay_name'] ?? $value['barangay'] ?? '')) !== '';

                return empty($value['region_id'])
                    || !$hasProvince
                    || empty($value['city_id'])
                    || !$hasBarangay;
            }

            return empty($value);
        }

        return $value === null || $value === '';
    }

    /**
     * Validate field type-specific constraints
     */
    private function validateFieldType(string $type, $value, string $label): ?string
    {
        switch ($type) {
            case 'text':
                if (!is_string($value)) {
                    return "{$label} must be text";
                }
                if (strlen($value) > 255) {
                    return "{$label} must not exceed 255 characters";
                }
                break;

            case 'textarea':
                if (!is_string($value)) {
                    return "{$label} must be text";
                }
                break;

            case 'email':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "{$label} must be a valid email address";
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return "{$label} must be a valid number";
                }
                break;

            case 'date':
                if (!is_string($value) || !$this->isValidDate($value)) {
                    return "{$label} must be a valid date";
                }
                break;

            case 'file':
            case 'image':
            case 'multi_file':
            case 'signature':
                // File validation is handled separately with uploaded files
                break;

            case 'phone':
                if (!is_string($value) || !preg_match('/^\+?[0-9]{10,15}$/', $value)) {
                    return $validation['message'] ?? "{$label} must be a valid phone number";
                }
                break;

            case 'url':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
                    return $validation['message'] ?? "{$label} must be a valid URL";
                }
                break;

            case 'currency':
                if (!is_numeric($value)) {
                    return "{$label} must be a valid amount";
                }
                break;

            case 'address':
                if (!is_array($value)) {
                    return "{$label} must include a complete address";
                }
                $hasProvince = !empty($value['province_id'])
                    || trim((string) ($value['province_name'] ?? $value['province'] ?? '')) !== '';
                $hasBarangay = !empty($value['barangay_id'])
                    || trim((string) ($value['barangay_name'] ?? $value['barangay'] ?? '')) !== '';
                if (empty($value['region_id']) || !$hasProvince || empty($value['city_id']) || !$hasBarangay) {
                    return "{$label} requires region, province, city, and barangay";
                }
                break;

            case 'terms':
            case 'toggle':
                break;

            case 'geolocation':
                if (!is_array($value)) {
                    return "{$label} must include latitude and longitude";
                }
                $lat = $value['latitude'] ?? null;
                $lng = $value['longitude'] ?? null;
                if ($lat === null || $lng === null || trim((string) $lat) === '' || trim((string) $lng) === '') {
                    return "{$label} requires a map location";
                }
                if (!$this->isValidGeolocation((float) $lat, (float) $lng)) {
                    return "{$label} has invalid coordinates";
                }
                break;
        }
        
        return null;
    }

    /**
     * Evaluate showWhen conditional visibility (mirrors client-side FormConditional).
     */
    public function isFieldVisible(array $field, array $data): bool
    {
        return $this->shouldShowField($field, $data);
    }

    /**
     * Evaluate showWhen conditional visibility (mirrors client-side FormConditional).
     */
    private function shouldShowField(array $field, array $data): bool
    {
        $rule = $field['validation']['showWhen'] ?? null;
        if (!is_array($rule) || empty($rule['field'])) {
            return true;
        }

        $current = $this->resolveConditionalFieldValue($data[$rule['field']] ?? '');
        $expected = (string) ($rule['value'] ?? '');
        $operator = $rule['operator'] ?? 'equals';

        return match ($operator) {
            'equals' => $current === $expected,
            'not_equals' => $current !== $expected,
            'contains' => str_contains($current, $expected),
            default => true,
        };
    }

    private function resolveConditionalFieldValue(mixed $value): string
    {
        if (is_array($value)) {
            if (isset($value['latitude']) || isset($value['longitude'])) {
                return trim((string) ($value['latitude'] ?? '')) . '|' . trim((string) ($value['longitude'] ?? ''));
            }
            if (isset($value['region_id']) || isset($value['city_id'])) {
                return trim((string) ($value['region_id'] ?? '')) . '|'
                    . trim((string) ($value['province_id'] ?? '')) . '|'
                    . trim((string) ($value['city_id'] ?? '')) . '|'
                    . trim((string) ($value['barangay_id'] ?? ''));
            }

            return implode(',', array_map('strval', $value));
        }

        return trim((string) $value);
    }

    /**
     * Validate custom field rules
     */
    private function validateFieldRules($value, array $validation, string $label): ?string
    {
        // Pattern validation
        if (isset($validation['pattern']) && is_string($value)) {
            $pattern = $validation['pattern'];
            if (!preg_match('/' . $pattern . '/', $value)) {
                return $validation['message'] ?? "{$label} format is invalid";
            }
        }

        // Max length validation (supports legacy lowercase key from older form builder saves)
        [$minLength, $maxLength] = $this->resolveStringLengthConstraints($validation);
        if ($maxLength !== null && is_string($value)) {
            if (strlen($value) > $maxLength) {
                return "{$label} must not exceed {$maxLength} characters";
            }
        }

        // Min length validation
        if ($minLength !== null && is_string($value)) {
            if (strlen($value) < $minLength) {
                if ($minLength === $maxLength) {
                    return "{$label} must be exactly {$minLength} characters";
                }
                return "{$label} must be at least {$minLength} characters";
            }
        }

        // Min value validation
        if (isset($validation['min']) && is_numeric($value)) {
            if ($value < $validation['min']) {
                return "{$label} must be at least {$validation['min']}";
            }
        }

        // Max value validation
        if (isset($validation['max']) && is_numeric($value)) {
            if ($value > $validation['max']) {
                return "{$label} must not exceed {$validation['max']}";
            }
        }

        // File size validation (for file uploads)
        if (isset($validation['maxSize']) && is_array($value) && isset($value['size'])) {
            if ($value['size'] > $validation['maxSize']) {
                $maxSizeMB = round($validation['maxSize'] / 1048576, 2);
                return "{$label} must not exceed {$maxSizeMB} MB";
            }
        }

        return null;
    }

    /**
     * Resolve min/max string length from validation rules (including legacy max-only as exact).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveStringLengthConstraints(array $validation): array
    {
        $min = isset($validation['minLength']) ? (int) $validation['minLength']
            : (isset($validation['minlength']) ? (int) $validation['minlength'] : null);
        $max = isset($validation['maxLength']) ? (int) $validation['maxLength']
            : (isset($validation['maxlength']) ? (int) $validation['maxlength'] : null);
        $mode = $validation['lengthMode'] ?? null;

        if ($mode === 'exact') {
            $len = $max ?? $min ?? 0;

            return [$len > 0 ? $len : null, $len > 0 ? $len : null];
        }

        if ($mode === 'max') {
            return [$min, $max];
        }

        if ($mode === 'range') {
            return [$min, $max];
        }

        if ($mode === 'min') {
            return [$min, null];
        }

        if ($min !== null && $max !== null) {
            return [$min, $max];
        }

        // Legacy: maxLength-only from form builder meant exact character count
        if ($max !== null && $min === null) {
            return [$max, $max];
        }

        if ($min !== null) {
            return [$min, null];
        }

        return [null, null];
    }

    /**
     * Check if latitude/longitude are within valid ranges
     */
    private function isValidGeolocation(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /**
     * Check if a string is a valid date
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
