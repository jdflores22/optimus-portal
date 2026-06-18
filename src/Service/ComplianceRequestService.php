<?php

namespace App\Service;

use App\Entity\AccreditationSubmission;
use App\Entity\FormConfiguration;
use App\Form\FormFieldTypes;

/**
 * Structured compliance requests: evaluator-selected fields that must be corrected.
 */
final class ComplianceRequestService
{
    public const STORAGE_KEY = '_compliance_request';

    /**
     * @param list<string> $fieldIds
     * @param array<string, string> $fieldNotes
     * @return array{field_ids: list<string>, field_notes: array<string, string>, general_note: ?string, requested_at: string}
     */
    public static function build(array $fieldIds, array $fieldNotes, ?string $generalNote): array
    {
        $fieldIds = array_values(array_unique(array_filter(array_map('strval', $fieldIds))));
        $notes = [];
        foreach ($fieldNotes as $fieldId => $note) {
            $note = trim((string) $note);
            if ($note !== '' && in_array((string) $fieldId, $fieldIds, true)) {
                $notes[(string) $fieldId] = $note;
            }
        }

        return [
            'field_ids' => $fieldIds,
            'field_notes' => $notes,
            'general_note' => $generalNote !== null && trim($generalNote) !== '' ? trim($generalNote) : null,
            'requested_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{field_ids: list<string>, field_notes: array<string, string>, general_note: ?string, requested_at?: string}|null
     */
    public static function fromSubmission(AccreditationSubmission $submission): ?array
    {
        $data = $submission->getSubmittedData()[self::STORAGE_KEY] ?? null;

        if (!is_array($data) || empty($data['field_ids']) || !is_array($data['field_ids'])) {
            return null;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public static function fieldIds(AccreditationSubmission $submission): array
    {
        $request = self::fromSubmission($submission);

        return $request['field_ids'] ?? [];
    }

    /**
     * @return list<array{id: string, label: string, type: string, note: ?string}>
     */
    public static function resolveFields(AccreditationSubmission $submission, ?FormConfiguration $formConfig): array
    {
        $request = self::fromSubmission($submission);
        if ($request === null) {
            return [];
        }

        $fieldsById = [];
        if ($formConfig !== null) {
            foreach ($formConfig->getFields()['fields'] ?? [] as $field) {
                if (isset($field['id'])) {
                    $fieldsById[$field['id']] = $field;
                }
            }
        }

        $resolved = [];
        foreach ($request['field_ids'] as $fieldId) {
            $field = $fieldsById[$fieldId] ?? null;
            $resolved[] = [
                'id' => $fieldId,
                'label' => $field['label'] ?? self::humanizeFieldId($fieldId),
                'type' => $field['type'] ?? 'text',
                'note' => $request['field_notes'][$fieldId] ?? null,
            ];
        }

        return $resolved;
    }

    public static function applyToSubmissionData(array $submittedData, array $complianceRequest): array
    {
        $submittedData[self::STORAGE_KEY] = $complianceRequest;

        return $submittedData;
    }

    public static function stripInternalKeys(array $submittedData): array
    {
        unset(
            $submittedData['_files'],
            $submittedData['_csrf_token'],
            $submittedData['_token'],
            $submittedData['_resubmitted_after_compliance'],
            $submittedData[self::STORAGE_KEY]
        );

        return $submittedData;
    }

    /**
     * @param list<string> $newlyUploadedFieldIds Field IDs that received a new upload in this request
     * @return array<string, string>
     */
    public static function validateCorrections(
        AccreditationSubmission $submission,
        FormConfiguration $formConfig,
        array $formData,
        array $mergedFiles,
        array $newlyUploadedFieldIds
    ): array {
        $request = self::fromSubmission($submission);
        if ($request === null) {
            return [];
        }

        $previousData = self::stripInternalKeys($submission->getSubmittedData());
        $previousFiles = $submission->getSubmittedData()['_files'] ?? [];
        $fieldsById = [];
        foreach ($formConfig->getFields()['fields'] ?? [] as $field) {
            if (isset($field['id'])) {
                $fieldsById[$field['id']] = $field;
            }
        }

        $errors = [];
        foreach ($request['field_ids'] as $fieldId) {
            $field = $fieldsById[$fieldId] ?? null;
            $label = $field['label'] ?? self::humanizeFieldId($fieldId);
            $type = $field['type'] ?? 'text';

            if (FormFieldTypes::isFileType($type)) {
                if (!in_array($fieldId, $newlyUploadedFieldIds, true)) {
                    $errors[$fieldId] = $label . ' must be updated with a new upload';
                }
                continue;
            }

            $newValue = $formData[$fieldId] ?? null;
            $oldValue = $previousData[$fieldId] ?? null;
            if (self::valuesEqual($newValue, $oldValue, $type)) {
                $errors[$fieldId] = $label . ' must be corrected before resubmitting';
            }
        }

        return $errors;
    }

    public static function valuesEqual(mixed $a, mixed $b, ?string $fieldType = null): bool
    {
        $a = self::normalizeComparableValue($a, $fieldType);
        $b = self::normalizeComparableValue($b, $fieldType);

        if (is_array($a) || is_array($b)) {
            return json_encode($a ?? []) === json_encode($b ?? []);
        }

        return trim((string) ($a ?? '')) === trim((string) ($b ?? ''));
    }

    private static function normalizeComparableValue(mixed $value, ?string $fieldType): mixed
    {
        if ($fieldType === 'address') {
            if (is_string($value)) {
                return ['legacy_text' => trim($value)];
            }
            if (!is_array($value)) {
                return $value;
            }

            return array_filter([
                'region_id' => trim((string) ($value['region_id'] ?? '')),
                'province_id' => trim((string) ($value['province_id'] ?? '')),
                'city_id' => trim((string) ($value['city_id'] ?? '')),
                'barangay_id' => trim((string) ($value['barangay_id'] ?? '')),
                'street' => trim((string) ($value['street'] ?? '')),
            ], static fn (string $part): bool => $part !== '');
        }

        if ($fieldType === 'geolocation' && is_array($value)) {
            return [
                'latitude' => trim((string) ($value['latitude'] ?? '')),
                'longitude' => trim((string) ($value['longitude'] ?? '')),
            ];
        }

        return $value;
    }

    private static function humanizeFieldId(string $fieldId): string
    {
        return ucwords(str_replace('_', ' ', $fieldId));
    }
}
