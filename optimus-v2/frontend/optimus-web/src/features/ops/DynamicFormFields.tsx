import {
  Alert,
  Box,
  Checkbox,
  Divider,
  FormControl,
  FormControlLabel,
  FormGroup,
  FormHelperText,
  FormLabel,
  MenuItem,
  Radio,
  RadioGroup,
  Switch,
  TextField,
  Typography,
} from '@mui/material';
import type { SasFormField } from '../../shared/types';
import {
  INPUT_RESTRICTION_PRESETS,
  isFieldVisible,
  isFileType,
  isLayoutField,
  resolveColumnSpan,
} from '../../shared/formSchema';
import { AddressPicker } from './AddressPicker';
import { FileUploadField } from './FileUploadField';
import { GeolocationMapPicker } from './GeolocationMapPicker';

export type DynamicFormValues = Record<string, string | boolean | string[]>;

type Props = {
  fields: SasFormField[];
  values: DynamicFormValues;
  onChange: (id: string, value: string | boolean | string[]) => void;
  disabled?: boolean;
};

function renderFieldContent(
  field: SasFormField,
  values: DynamicFormValues,
  onChange: Props['onChange'],
  disabled?: boolean,
) {
  if (field.type === 'section_heading') {
    return (
      <Box pt={1}>
        <Typography variant="h6" fontWeight={700}>
          {field.label}
        </Typography>
        {field.validation?.subtitle && (
          <Typography variant="body2" color="text.secondary">
            {field.validation.subtitle}
          </Typography>
        )}
      </Box>
    );
  }

  if (field.type === 'divider') {
    return (
      <Box py={0.5}>
        {field.label ? (
          <Divider>
            <Typography variant="caption" color="text.secondary">
              {field.label}
            </Typography>
          </Divider>
        ) : (
          <Divider />
        )}
      </Box>
    );
  }

  const help = field.helpText ? <FormHelperText>{field.helpText}</FormHelperText> : null;
  const val = field.validation ?? {};

  if (field.type === 'terms') {
    return (
      <FormControl disabled={disabled} required={field.required}>
        {val.declaration && (
          <Alert severity="info" sx={{ mb: 1 }}>
            {val.declaration}
          </Alert>
        )}
        <FormControlLabel
          control={
            <Checkbox
              checked={Boolean(values[field.id])}
              onChange={(_, checked) => onChange(field.id, checked)}
            />
          }
          label={`${field.label}${field.required ? ' *' : ''}`}
        />
        {help}
      </FormControl>
    );
  }

  if (field.type === 'checkbox' || field.type === 'toggle') {
    const Control = field.type === 'toggle' ? Switch : Checkbox;
    return (
      <FormControl disabled={disabled} required={field.required}>
        <FormControlLabel
          control={
            <Control
              checked={Boolean(values[field.id])}
              onChange={(_, checked) => onChange(field.id, checked)}
            />
          }
          label={`${field.label}${field.required ? ' *' : ''}`}
        />
        {help}
      </FormControl>
    );
  }

  if (field.type === 'radio') {
    const options = field.options ?? val.options ?? {};
    return (
      <FormControl disabled={disabled} required={field.required}>
        <FormLabel>{`${field.label}${field.required ? ' *' : ''}`}</FormLabel>
        <RadioGroup
          value={String(values[field.id] ?? '')}
          onChange={(e) => onChange(field.id, e.target.value)}
        >
          {Object.entries(options).map(([value, label]) => (
            <FormControlLabel key={value} value={value} control={<Radio />} label={label} />
          ))}
        </RadioGroup>
        {help}
      </FormControl>
    );
  }

  if (field.type === 'multi_select') {
    const options = field.options ?? val.options ?? {};
    const selected = Array.isArray(values[field.id]) ? (values[field.id] as string[]) : [];
    return (
      <FormControl disabled={disabled} required={field.required}>
        <FormLabel>{`${field.label}${field.required ? ' *' : ''}`}</FormLabel>
        <FormGroup>
          {Object.entries(options).map(([value, label]) => (
            <FormControlLabel
              key={value}
              control={
                <Checkbox
                  checked={selected.includes(value)}
                  onChange={(_, checked) => {
                    const next = checked
                      ? [...selected, value]
                      : selected.filter((x) => x !== value);
                    onChange(field.id, next);
                  }}
                />
              }
              label={label}
            />
          ))}
        </FormGroup>
        {help}
      </FormControl>
    );
  }

  if (field.type === 'dropdown') {
    const options = field.options ?? val.options ?? {};
    return (
      <TextField
        select
        label={field.label}
        required={field.required}
        disabled={disabled}
        value={String(values[field.id] ?? '')}
        onChange={(e) => onChange(field.id, e.target.value)}
        fullWidth
        helperText={field.helpText || undefined}
      >
        {Object.entries(options).map(([value, label]) => (
          <MenuItem key={value} value={value}>
            {label}
          </MenuItem>
        ))}
      </TextField>
    );
  }

  if (isFileType(field.type)) {
    return (
      <FileUploadField
        label={field.label}
        required={field.required}
        disabled={disabled}
        value={
          Array.isArray(values[field.id])
            ? (values[field.id] as string[])
            : String(values[field.id] ?? '')
        }
        onChange={(v) => onChange(field.id, v)}
        multiple={field.type === 'multi_file'}
        allowedTypes={val.allowedTypes}
        maxSize={val.maxSize}
        maxFiles={val.maxFiles}
        preview={val.preview !== false}
        helpText={field.helpText}
        fieldType={field.type}
      />
    );
  }

  if (field.type === 'geolocation') {
    return (
      <GeolocationMapPicker
        label={field.label}
        required={field.required}
        value={String(values[field.id] ?? '')}
        onChange={(value) => onChange(field.id, value)}
        disabled={disabled}
        helpText={field.helpText}
        defaultLat={val.defaultLat}
        defaultLng={val.defaultLng}
        defaultZoom={val.defaultZoom}
      />
    );
  }

  if (field.type === 'address') {
    return (
      <AddressPicker
        label={field.label}
        required={field.required}
        disabled={disabled}
        value={String(values[field.id] ?? '')}
        onChange={(v) => onChange(field.id, v)}
        helpText={field.helpText}
      />
    );
  }

  const multiline = field.type === 'textarea';
  const inputType =
    field.type === 'number' || field.type === 'currency'
      ? 'number'
      : field.type === 'email'
        ? 'email'
        : field.type === 'date'
          ? 'date'
          : field.type === 'phone'
            ? 'tel'
            : field.type === 'url'
              ? 'url'
              : 'text';

  const restriction = val.inputRestriction;
  const preset =
    restriction && restriction !== 'none'
      ? INPUT_RESTRICTION_PRESETS[restriction as keyof typeof INPUT_RESTRICTION_PRESETS]
      : undefined;

  return (
    <TextField
      label={field.label}
      required={field.required && !isLayoutField(field.type)}
      disabled={disabled}
      value={String(values[field.id] ?? '')}
      onChange={(e) => {
        let next = e.target.value;
        if (preset) {
          try {
            const re = new RegExp(preset.pattern.replace(/^\^/, '').replace(/\$$/, ''));
            if (restriction === 'numeric') next = next.replace(/[^0-9]/g, '');
            else if (restriction === 'alpha') next = next.replace(/[^A-Za-z]/g, '');
            else if (restriction === 'alphanumeric') next = next.replace(/[^A-Za-z0-9]/g, '');
            void re;
          } catch {
            /* ignore */
          }
        }
        onChange(field.id, next);
      }}
      fullWidth
      multiline={multiline}
      minRows={multiline ? 3 : undefined}
      type={inputType}
      placeholder={field.placeholder || undefined}
      helperText={field.helpText || val.message || undefined}
      InputLabelProps={field.type === 'date' ? { shrink: true } : undefined}
      inputProps={{
        min: val.min,
        max: val.max,
        maxLength: val.maxLength,
        inputMode: preset?.inputMode,
        pattern: val.pattern,
      }}
    />
  );
}

export function DynamicFormFields({ fields, values, onChange, disabled }: Props) {
  return (
    <Box
      sx={{
        display: 'grid',
        gridTemplateColumns: 'repeat(12, minmax(0, 1fr))',
        columnGap: 2,
        rowGap: 2,
        alignItems: 'start',
      }}
    >
      {fields.map((field) => {
        if (!isFieldVisible(field, values)) return null;
        const span = resolveColumnSpan(field);
        return (
          <Box
            key={field.id}
            sx={{
              gridColumn: {
                xs: 'span 12',
                sm: `span ${span}`,
              },
              minWidth: 0,
            }}
          >
            {renderFieldContent(field, values, onChange, disabled)}
          </Box>
        );
      })}
    </Box>
  );
}
