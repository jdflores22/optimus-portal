<?php

namespace App\Form;

/**
 * Canonical form-builder field types and template groupings.
 */
final class FormFieldTypes
{
    public const ALL = [
        'text',
        'textarea',
        'number',
        'email',
        'phone',
        'date',
        'url',
        'currency',
        'file',
        'image',
        'multi_file',
        'dropdown',
        'multi_select',
        'checkbox',
        'radio',
        'toggle',
        'terms',
        'geolocation',
        'address',
        'signature',
    ];

    /** @return array<string, array<string, array{icon: string, name: string, preset?: array<string, mixed>}>> */
    public static function templateGroups(): array
    {
        return [
            'Basic Inputs' => [
                'text' => ['icon' => 'icon-[tabler--forms]', 'name' => 'Text Input'],
                'textarea' => ['icon' => 'icon-[tabler--align-left]', 'name' => 'Text Area'],
                'number' => ['icon' => 'icon-[tabler--hash]', 'name' => 'Number'],
                'email' => ['icon' => 'icon-[tabler--mail]', 'name' => 'Email'],
                'phone' => ['icon' => 'icon-[tabler--phone]', 'name' => 'Phone Number'],
                'date' => ['icon' => 'icon-[tabler--calendar]', 'name' => 'Date'],
                'url' => ['icon' => 'icon-[tabler--link]', 'name' => 'URL'],
                'currency' => ['icon' => 'icon-[tabler--currency-peso]', 'name' => 'Currency'],
            ],
            'Documents & Compliance' => [
                'file' => ['icon' => 'icon-[tabler--upload]', 'name' => 'File Upload'],
                'image' => ['icon' => 'icon-[tabler--photo]', 'name' => 'Image Upload', 'preset' => [
                    'validation' => ['allowedTypes' => ['jpg', 'jpeg', 'png', 'webp'], 'maxSize' => 5242880, 'preview' => true],
                ]],
                'multi_file' => ['icon' => 'icon-[tabler--files]', 'name' => 'Multiple File Upload', 'preset' => [
                    'validation' => ['allowedTypes' => ['pdf', 'jpg', 'jpeg', 'png'], 'maxSize' => 10485760, 'preview' => true, 'maxFiles' => 5],
                ]],
                'signature' => ['icon' => 'icon-[tabler--signature]', 'name' => 'Digital Signature', 'preset' => [
                    'validation' => ['allowedTypes' => ['jpg', 'jpeg', 'png', 'webp'], 'maxSize' => 2097152, 'preview' => true],
                ]],
            ],
            'Choice Fields' => [
                'dropdown' => ['icon' => 'icon-[tabler--selector]', 'name' => 'Dropdown'],
                'multi_select' => ['icon' => 'icon-[tabler--list-check]', 'name' => 'Multi Select'],
                'checkbox' => ['icon' => 'icon-[tabler--checkbox]', 'name' => 'Checkbox'],
                'radio' => ['icon' => 'icon-[tabler--circle-dot]', 'name' => 'Radio Button'],
                'toggle' => ['icon' => 'icon-[tabler--toggle-right]', 'name' => 'Toggle Switch'],
                'terms' => ['icon' => 'icon-[tabler--file-check]', 'name' => 'Terms & Declaration', 'preset' => [
                    'validation' => [
                        'declaration' => 'I certify that the information provided is true and correct, and I agree to the terms and conditions of this accreditation application.',
                    ],
                ]],
            ],
            'Location' => [
                'geolocation' => ['icon' => 'icon-[tabler--map-pin]', 'name' => 'Geotag Location'],
                'address' => ['icon' => 'icon-[tabler--map-2]', 'name' => 'Address Picker'],
            ],
        ];
    }

    public static function isFileType(string $type): bool
    {
        return in_array($type, ['file', 'image', 'multi_file', 'signature'], true);
    }

    public static function defaultValidationForType(string $type): array
    {
        return match ($type) {
            'phone' => [
                'pattern' => '^\\+?[0-9]{10,15}$',
                'message' => 'Enter a valid phone number',
            ],
            'email' => [],
            'url' => [
                'pattern' => '^https?://.+',
                'message' => 'Enter a valid URL starting with http:// or https://',
            ],
            'currency' => ['min' => 0],
            'image' => ['allowedTypes' => ['jpg', 'jpeg', 'png', 'webp'], 'maxSize' => 5242880, 'preview' => true],
            'multi_file' => ['allowedTypes' => ['pdf', 'jpg', 'jpeg', 'png'], 'maxSize' => 10485760, 'preview' => true, 'maxFiles' => 5],
            'signature' => ['allowedTypes' => ['jpg', 'jpeg', 'png', 'webp'], 'maxSize' => 2097152, 'preview' => true],
            'terms' => [
                'declaration' => 'I certify that the information provided is true and correct, and I agree to the terms and conditions of this accreditation application.',
            ],
            default => [],
        };
    }
}
