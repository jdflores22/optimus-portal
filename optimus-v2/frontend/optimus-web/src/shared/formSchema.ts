import type { SasFieldValidation, SasFormField, SasFormFieldType } from './types';

export type FieldTemplate = {
  type: SasFormFieldType | string;
  name: string;
  defaultLabel: string;
  requiredDefault?: boolean;
  preset?: { validation?: SasFieldValidation };
};

export type FieldTemplateGroup = {
  title: string;
  templates: FieldTemplate[];
};

export const INPUT_RESTRICTION_PRESETS = {
  numeric: {
    pattern: '^[0-9]+$',
    message: 'Only numbers are allowed',
    inputMode: 'numeric' as const,
  },
  alpha: {
    pattern: '^[A-Za-z]+$',
    message: 'Only letters are allowed',
    inputMode: 'text' as const,
  },
  alphanumeric: {
    pattern: '^[A-Za-z0-9]+$',
    message: 'Only letters and numbers are allowed',
    inputMode: 'text' as const,
  },
};

export const FIELD_TEMPLATE_GROUPS: FieldTemplateGroup[] = [
  {
    title: 'Basic Inputs',
    templates: [
      { type: 'text', name: 'Text Input', defaultLabel: 'Text Field' },
      { type: 'textarea', name: 'Text Area', defaultLabel: 'Comments' },
      { type: 'number', name: 'Number', defaultLabel: 'Number' },
      { type: 'email', name: 'Email', defaultLabel: 'Email Address' },
      { type: 'phone', name: 'Phone Number', defaultLabel: 'Phone Number', preset: { validation: { pattern: '^\\+?[0-9]{10,15}$', message: 'Enter a valid phone number' } } },
      { type: 'date', name: 'Date', defaultLabel: 'Date' },
      { type: 'url', name: 'URL', defaultLabel: 'Website', preset: { validation: { pattern: '^https?://.+', message: 'Enter a valid URL starting with http:// or https://' } } },
      { type: 'currency', name: 'Currency', defaultLabel: 'Amount', preset: { validation: { min: 0 } } },
    ],
  },
  {
    title: 'Documents & Compliance',
    templates: [
      { type: 'file', name: 'File Upload', defaultLabel: 'Upload document' },
      {
        type: 'image',
        name: 'Image Upload',
        defaultLabel: 'Upload image',
        preset: {
          validation: {
            allowedTypes: ['jpg', 'jpeg', 'png', 'webp'],
            maxSize: 5242880,
            preview: true,
          },
        },
      },
      {
        type: 'multi_file',
        name: 'Multiple File Upload',
        defaultLabel: 'Upload documents',
        preset: {
          validation: {
            allowedTypes: ['pdf', 'jpg', 'jpeg', 'png'],
            maxSize: 10485760,
            preview: true,
            maxFiles: 5,
          },
        },
      },
      {
        type: 'signature',
        name: 'Digital Signature',
        defaultLabel: 'Signature',
        preset: {
          validation: {
            allowedTypes: ['jpg', 'jpeg', 'png', 'webp'],
            maxSize: 2097152,
            preview: true,
          },
        },
      },
    ],
  },
  {
    title: 'Choice Fields',
    templates: [
      { type: 'dropdown', name: 'Dropdown', defaultLabel: 'Select an option', requiredDefault: true },
      { type: 'multi_select', name: 'Multi Select', defaultLabel: 'Select all that apply' },
      { type: 'checkbox', name: 'Checkbox', defaultLabel: 'I agree' },
      { type: 'radio', name: 'Radio Button', defaultLabel: 'Choose one' },
      { type: 'toggle', name: 'Toggle Switch', defaultLabel: 'Enable option' },
      {
        type: 'terms',
        name: 'Terms & Declaration',
        defaultLabel: 'Declaration',
        requiredDefault: true,
        preset: {
          validation: {
            declaration:
              'I certify that the information provided is true and correct, and I agree to the terms and conditions of this accreditation application.',
          },
        },
      },
    ],
  },
  {
    title: 'Location',
    templates: [
      {
        type: 'geolocation',
        name: 'Geotag Location',
        defaultLabel: 'Business Location',
        preset: { validation: { defaultLat: 14.5995, defaultLng: 120.9842, defaultZoom: 13 } },
      },
      { type: 'address', name: 'Address Picker', defaultLabel: 'Business Address' },
    ],
  },
  {
    title: 'Layout',
    templates: [
      {
        type: 'section_heading',
        name: 'Section Heading',
        defaultLabel: 'Section',
        requiredDefault: false,
        preset: { validation: { subtitle: '' } },
      },
      { type: 'divider', name: 'Divider', defaultLabel: '', requiredDefault: false },
    ],
  },
];

export const SAS_FIELD_TYPES = FIELD_TEMPLATE_GROUPS.flatMap((g) =>
  g.templates.map((t) => ({ value: t.type, label: t.name })),
);

export function isLayoutField(type: string): boolean {
  return type === 'section_heading' || type === 'divider';
}

export function isFileType(type: string): boolean {
  return ['file', 'image', 'multi_file', 'signature'].includes(type);
}

export function isChoiceType(type: string): boolean {
  return ['dropdown', 'radio', 'multi_select'].includes(type);
}

/** Allowed column spans on the 12-column applicant form grid. */
export const COLUMN_SPAN_OPTIONS = [
  { value: 12, label: 'Full width (1 per row)', short: 'Full' },
  { value: 6, label: 'Half width (2 per row)', short: '1/2' },
  { value: 4, label: 'One third (3 per row)', short: '1/3' },
  { value: 3, label: 'One quarter (4 per row)', short: '1/4' },
] as const;

export function resolveColumnSpan(field: { type: string; columnSpan?: number }): number {
  if (isLayoutField(field.type)) return 12;
  const span = Number(field.columnSpan ?? 12);
  return [3, 4, 6, 12].includes(span) ? span : 12;
}

export function slugifyFieldId(label: string): string {
  return (
    label
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
      .slice(0, 40) || `field_${Date.now().toString(36)}`
  );
}

export function createFieldFromTemplate(template: FieldTemplate, order: number): SasFormField {
  const needsOptions = isChoiceType(template.type);
  const validation: SasFieldValidation = {
    ...(template.preset?.validation ?? {}),
    ...(needsOptions
      ? { options: { option_1: 'Option 1', option_2: 'Option 2', option_3: 'Option 3' } }
      : {}),
  };

  return {
    id: `${slugifyFieldId(template.defaultLabel || template.type)}_${order}`,
    label: template.defaultLabel,
    type: template.type,
    required: template.requiredDefault ?? !isLayoutField(template.type),
    order,
    placeholder: '',
    helpText: '',
    options: validation.options,
    validation: Object.keys(validation).length ? validation : undefined,
  };
}

function asValidation(raw: unknown): SasFieldValidation | undefined {
  if (!raw || typeof raw !== 'object') return undefined;
  return raw as SasFieldValidation;
}

export function parseFormFields(fieldsJson?: string | null): SasFormField[] {
  if (!fieldsJson) return [];
  try {
    const raw = JSON.parse(fieldsJson) as { fields?: unknown };
    if (!Array.isArray(raw.fields)) return [];
    return raw.fields
      .map((f, i) => {
        const row = f as Record<string, unknown>;
        const validation = asValidation(row.validation) ?? {};
        const options =
          (row.options as Record<string, string> | undefined) ?? validation.options ?? undefined;
        const mergedValidation: SasFieldValidation = {
          ...validation,
          ...(options ? { options } : {}),
        };
        return {
          id: String(row.id ?? `field_${i + 1}`),
          label: String(row.label ?? row.id ?? `Field ${i + 1}`),
          type: String(row.type ?? 'text'),
          required: Boolean(row.required),
          order: Number(row.order ?? i + 1),
          placeholder: row.placeholder != null ? String(row.placeholder) : '',
          helpText: row.helpText != null ? String(row.helpText) : '',
          columnSpan: resolveColumnSpan({
            type: String(row.type ?? 'text'),
            columnSpan: row.columnSpan != null ? Number(row.columnSpan) : undefined,
          }),
          options,
          validation: Object.keys(mergedValidation).length ? mergedValidation : undefined,
        } satisfies SasFormField;
      })
      .sort((a, b) => a.order - b.order);
  } catch {
    return [];
  }
}

export function parseSubmittedValues(
  json?: string | null,
): Record<string, string | boolean | string[]> {
  const initial: Record<string, string | boolean | string[]> = {};
  if (!json) return initial;
  try {
    const prev = JSON.parse(json) as Record<string, unknown>;
    Object.entries(prev).forEach(([k, v]) => {
      if (Array.isArray(v)) initial[k] = v.map(String);
      else if (typeof v === 'boolean') initial[k] = v;
      else if (v != null && typeof v === 'object') initial[k] = JSON.stringify(v);
      else if (v != null) initial[k] = String(v);
    });
  } catch {
    /* ignore */
  }
  return initial;
}

export function serializeFormFields(fields: SasFormField[]): string {
  const normalized = fields
    .map((f, i) => {
      const validation: SasFieldValidation = { ...(f.validation ?? {}) };
      if (isChoiceType(f.type) && f.options && Object.keys(f.options).length) {
        validation.options = f.options;
      } else if (!isChoiceType(f.type)) {
        delete validation.options;
      }

      const row: Record<string, unknown> = {
        id: f.id.trim() || slugifyFieldId(f.label),
        label: f.label.trim() || f.id,
        type: f.type || 'text',
        required: isLayoutField(f.type) ? false : Boolean(f.required),
        order: i + 1,
      };
      const span = resolveColumnSpan(f);
      if (span !== 12) row.columnSpan = span;
      if (f.placeholder) row.placeholder = f.placeholder;
      if (f.helpText) row.helpText = f.helpText;
      if (Object.keys(validation).length) row.validation = validation;
      return row;
    })
    .sort((a, b) => Number(a.order) - Number(b.order));
  return JSON.stringify({ fields: normalized });
}

export function optionsToText(options?: Record<string, string>): string {
  if (!options) return '';
  return Object.entries(options)
    .map(([k, v]) => (k === v ? k : `${k}:${v}`))
    .join('\n');
}

export function textToOptions(text: string): Record<string, string> {
  const out: Record<string, string> = {};
  text
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .forEach((line) => {
      const idx = line.indexOf(':');
      if (idx === -1) out[line] = line;
      else {
        const key = line.slice(0, idx).trim();
        const label = line.slice(idx + 1).trim() || key;
        if (key) out[key] = label;
      }
    });
  return out;
}

export function applyInputRestriction(
  validation: SasFieldValidation,
  restriction: string,
): SasFieldValidation {
  const next = { ...validation };
  delete next.inputRestriction;
  delete next.pattern;
  delete next.message;
  if (!restriction || restriction === 'none') return next;
  const preset = INPUT_RESTRICTION_PRESETS[restriction as keyof typeof INPUT_RESTRICTION_PRESETS];
  if (!preset) return next;
  next.inputRestriction = restriction as SasFieldValidation['inputRestriction'];
  next.pattern = preset.pattern;
  next.message = preset.message;
  return next;
}

export function isFieldVisible(
  field: SasFormField,
  values: Record<string, string | boolean | string[]>,
): boolean {
  const rule = field.validation?.showWhen;
  if (!rule?.field) return true;
  const current = values[rule.field];
  const actual = Array.isArray(current) ? current.join(',') : String(current ?? '');
  return actual === String(rule.value ?? '');
}

export function validateSubmission(
  fields: SasFormField[],
  values: Record<string, string | boolean | string[]>,
): string | null {
  const errors = collectValidationErrors(fields, values);
  return errors[0] ?? null;
}

/** Collect all client-side validation messages (V1-style full-form check). */
export function collectValidationErrors(
  fields: SasFormField[],
  values: Record<string, string | boolean | string[]>,
): string[] {
  const errors: string[] = [];

  for (const field of fields) {
    if (isLayoutField(field.type)) continue;
    if (!isFieldVisible(field, values)) continue;

    const v = values[field.id];
    const val = field.validation ?? {};
    const label = field.label || field.id;

    if (field.required) {
      if (field.type === 'checkbox' || field.type === 'toggle' || field.type === 'terms') {
        if (v !== true && v !== 'true') {
          errors.push(`${label} is required.`);
          continue;
        }
      } else if (Array.isArray(v)) {
        if (v.length === 0) {
          errors.push(`${label} is required.`);
          continue;
        }
      } else if (v == null || String(v).trim() === '') {
        errors.push(`${label} is required.`);
        continue;
      }
    }

    const str = Array.isArray(v) ? '' : String(v ?? '');
    if (!str && !Array.isArray(v)) continue;

    if (Array.isArray(v)) {
      // multi_file / multi_select — required already handled
      continue;
    }

    if (val.pattern) {
      try {
        if (!new RegExp(val.pattern).test(str)) {
          errors.push(val.message || `${label} format is invalid.`);
          continue;
        }
      } catch {
        /* ignore bad patterns */
      }
    }

    if (val.minLength != null && str.length < val.minLength) {
      errors.push(`${label} must be at least ${val.minLength} characters.`);
    }
    if (val.maxLength != null && str.length > val.maxLength) {
      errors.push(`${label} must be at most ${val.maxLength} characters.`);
    }

    if (field.type === 'email' && str) {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(str)) {
        errors.push(`${label} must be a valid email address.`);
      }
    }

    if (field.type === 'number' || field.type === 'currency') {
      const n = Number(str);
      if (Number.isNaN(n)) errors.push(`${label} must be a number.`);
      else {
        if (val.min != null && n < val.min) errors.push(`${label} must be ≥ ${val.min}.`);
        if (val.max != null && n > val.max) errors.push(`${label} must be ≤ ${val.max}.`);
      }
    }

    if (field.type === 'geolocation' && str) {
      try {
        const geo = JSON.parse(str) as Record<string, unknown>;
        const lat = Number(geo.lat ?? geo.latitude);
        const lng = Number(geo.lng ?? geo.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
          errors.push(`${label} requires a map location.`);
        } else if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
          errors.push(`${label} has invalid coordinates.`);
        }
      } catch {
        errors.push(`${label} requires a map location.`);
      }
    }

    if (field.type === 'address' && str) {
      try {
        const addr = JSON.parse(str) as Record<string, unknown>;
        const region = String(addr.region_id ?? '').trim();
        const province = String(addr.province_id ?? addr.province ?? '').trim();
        const city = String(addr.city_id ?? '').trim();
        const barangay = String(addr.barangay_id ?? addr.barangay ?? '').trim();
        const anyPart = !!(region || province || city || barangay);
        if ((field.required || anyPart) && (!region || !province || !city || !barangay)) {
          errors.push(`${label} requires region, province, city, and barangay.`);
        }
      } catch {
        if (field.required) {
          errors.push(`${label} requires region, province, city, and barangay.`);
        }
      }
    }
  }

  return errors;
}

export function getSubmissionSummary(
  fields: SasFormField[],
  values: Record<string, string | boolean | string[]>,
): { requiredTotal: number; requiredFilled: number; documentCount: number } {
  let requiredTotal = 0;
  let requiredFilled = 0;
  let documentCount = 0;

  for (const field of fields) {
    if (isLayoutField(field.type)) continue;
    if (!isFieldVisible(field, values)) continue;

    const v = values[field.id];

    if (isFileType(field.type)) {
      const paths = Array.isArray(v)
        ? v.filter(Boolean)
        : String(v ?? '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean);
      documentCount += paths.length;
    }

    if (!field.required) continue;
    requiredTotal += 1;

    let ok = false;
    if (field.type === 'checkbox' || field.type === 'toggle' || field.type === 'terms') {
      ok = v === true || v === 'true';
    } else if (Array.isArray(v)) {
      ok = v.length > 0;
    } else if (field.type === 'address') {
      try {
        const addr = JSON.parse(String(v ?? '')) as Record<string, unknown>;
        ok = !!(addr.region_id && addr.province_id && addr.city_id && addr.barangay_id);
      } catch {
        ok = false;
      }
    } else if (field.type === 'geolocation') {
      try {
        const geo = JSON.parse(String(v ?? '')) as Record<string, unknown>;
        ok = Number.isFinite(Number(geo.lat ?? geo.latitude)) && Number.isFinite(Number(geo.lng ?? geo.longitude));
      } catch {
        ok = false;
      }
    } else {
      ok = v != null && String(v).trim() !== '';
    }
    if (ok) requiredFilled += 1;
  }

  return { requiredTotal, requiredFilled, documentCount };
}

export type SubmissionPreviewRow =
  | { kind: 'section'; id: string; label: string; subtitle?: string }
  | { kind: 'field'; id: string; label: string; value: string; isFile?: boolean };

function displayFileName(path: string): string {
  const base = path.split('/').pop() || path;
  const match = base.match(/^\d{14}_[a-f0-9]{32}_(.+)$/i);
  return match?.[1] || base;
}

export function formatFieldDisplayValue(
  field: SasFormField,
  raw: string | boolean | string[] | undefined,
): string {
  if (raw == null || raw === '') return '—';

  if (field.type === 'checkbox' || field.type === 'toggle' || field.type === 'terms') {
    return raw === true || raw === 'true' ? 'Yes' : 'No';
  }

  if (field.type === 'multi_select') {
    const selected = Array.isArray(raw) ? raw : String(raw).split(',').filter(Boolean);
    const options = field.options ?? field.validation?.options ?? {};
    if (!selected.length) return '—';
    return selected.map((k) => options[k] ?? k).join(', ');
  }

  if (field.type === 'dropdown' || field.type === 'radio') {
    const options = field.options ?? field.validation?.options ?? {};
    const key = String(raw);
    return (options[key] ?? key) || '—';
  }

  if (isFileType(field.type)) {
    const paths = Array.isArray(raw)
      ? raw.filter(Boolean)
      : String(raw)
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean);
    if (!paths.length) return '—';
    return paths.map(displayFileName).join(', ');
  }

  if (field.type === 'address') {
    try {
      const addr = JSON.parse(String(raw)) as Record<string, unknown>;
      const parts = [
        addr.street,
        addr.barangay_name,
        addr.city_name,
        addr.province_name,
        addr.region_name,
      ]
        .map((p) => String(p ?? '').trim())
        .filter(Boolean);
      return parts.length ? parts.join(', ') : '—';
    } catch {
      return String(raw) || '—';
    }
  }

  if (field.type === 'geolocation') {
    try {
      const geo = JSON.parse(String(raw)) as Record<string, unknown>;
      const lat = Number(geo.lat ?? geo.latitude);
      const lng = Number(geo.lng ?? geo.longitude);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return '—';
      return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    } catch {
      return String(raw) || '—';
    }
  }

  if (Array.isArray(raw)) {
    return raw.length ? raw.join(', ') : '—';
  }

  return String(raw).trim() || '—';
}

/** Rows for the submit confirmation preview (sections + visible answers). */
export function buildSubmissionPreview(
  fields: SasFormField[],
  values: Record<string, string | boolean | string[]>,
): SubmissionPreviewRow[] {
  const rows: SubmissionPreviewRow[] = [];

  for (const field of fields) {
    if (field.type === 'divider') continue;

    if (field.type === 'section_heading') {
      rows.push({
        kind: 'section',
        id: field.id,
        label: field.label || 'Section',
        subtitle: field.validation?.subtitle,
      });
      continue;
    }

    if (!isFieldVisible(field, values)) continue;

    rows.push({
      kind: 'field',
      id: field.id,
      label: field.label || field.id,
      value: formatFieldDisplayValue(field, values[field.id]),
      isFile: isFileType(field.type),
    });
  }

  return rows;
}
