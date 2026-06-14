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
                $errors = $this->validationService->validateTextInput($value, 255, false);
                return !empty($errors) ? $errors[0] : null;
                
            case 'number':
                $errors = $this->validationService->validateNumericInput($value, null, null, false);
                return !empty($errors) ? $errors[0] : null;
            
            case 'date':
                $errors = $this->validationService->validateDateInput($value, false);
                return !empty($errors) ? $errors[0] : null;
            
            case 'file':
                // File validation is typically handled separately with uploaded files
                // This would check if a file was uploaded
                break;
        }
        
        return null;
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

        // Min length validation
        if (isset($validation['minLength']) && is_string($value)) {
            if (strlen($value) < $validation['minLength']) {
                return "{$label} must be at least {$validation['minLength']} characters";
            }
        }

        // Max length validation
        if (isset($validation['maxLength']) && is_string($value)) {
            if (strlen($value) > $validation['maxLength']) {
                return "{$label} must not exceed {$validation['maxLength']} characters";
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
     * Check if a string is a valid date
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
