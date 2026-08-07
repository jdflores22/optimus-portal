import { useRef, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  IconButton,
  Stack,
  Typography,
} from '@mui/material';
import CloudUploadOutlinedIcon from '@mui/icons-material/CloudUploadOutlined';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import InsertDriveFileOutlinedIcon from '@mui/icons-material/InsertDriveFileOutlined';
import { useUploadFileMutation } from '../../app/api';
import { API_BASE_URL } from '../../shared/types';

type Props = {
  label: string;
  required?: boolean;
  disabled?: boolean;
  value: string | string[];
  onChange: (value: string | string[]) => void;
  multiple?: boolean;
  allowedTypes?: string[];
  maxSize?: number;
  maxFiles?: number;
  preview?: boolean;
  helpText?: string;
  fieldType?: string;
};

function toPaths(value: string | string[]): string[] {
  if (Array.isArray(value)) return value.filter(Boolean);
  if (!value || !String(value).trim()) return [];
  const raw = String(value).trim();
  if (raw.startsWith('[')) {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) return parsed.map(String).filter(Boolean);
    } catch {
      /* fall through */
    }
  }
  return raw.split(',').map((s) => s.trim()).filter(Boolean);
}

function fileUrl(path: string): string {
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  return `${API_BASE_URL}${path.startsWith('/') ? '' : '/'}${path}`;
}

function displayName(path: string): string {
  const base = path.split('/').pop() || path;
  // Strip timestamp_guid_ prefix when present
  const match = base.match(/^\d{14}_[a-f0-9]{32}_(.+)$/i);
  return match?.[1] || base;
}

function isImagePath(path: string): boolean {
  return /\.(png|jpe?g|gif|webp|svg)$/i.test(path);
}

export function FileUploadField({
  label,
  required,
  disabled,
  value,
  onChange,
  multiple = false,
  allowedTypes,
  maxSize,
  maxFiles = 5,
  preview = true,
  helpText,
  fieldType,
}: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploadFile, { isLoading }] = useUploadFileMutation();
  const [error, setError] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);

  const paths = toPaths(value);
  const accept = (allowedTypes ?? []).map((t) => (t.startsWith('.') ? t : `.${t}`)).join(',');
  const maxMb = maxSize ? Math.round(maxSize / (1024 * 1024)) : undefined;

  const emit = (next: string[]) => {
    if (multiple) onChange(next);
    else onChange(next[0] ?? '');
  };

  const validateLocal = (file: File): string | null => {
    if (maxSize && file.size > maxSize) {
      return `File exceeds maximum size of ${maxMb} MB.`;
    }
    if (allowedTypes && allowedTypes.length > 0) {
      const ext = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : '';
      const ok = allowedTypes.some((t) => t.replace(/^\./, '').toLowerCase() === ext);
      if (!ok) return `File type not allowed. Accepted: ${allowedTypes.join(', ')}`;
    }
    return null;
  };

  const handleFiles = async (fileList: FileList | File[]) => {
    setError(null);
    const files = Array.from(fileList);
    if (!files.length) return;

    if (!multiple && files.length > 1) {
      setError('Only one file is allowed.');
      return;
    }

    const remaining = multiple ? Math.max(0, maxFiles - paths.length) : 1;
    if (multiple && files.length > remaining) {
      setError(`You can upload up to ${maxFiles} files.`);
      return;
    }

    const next = multiple ? [...paths] : [];
    for (const file of files.slice(0, remaining)) {
      const localErr = validateLocal(file);
      if (localErr) {
        setError(localErr);
        return;
      }
      try {
        const result = await uploadFile({
          file,
          category: 'accreditation',
          allowedTypes: allowedTypes?.join(','),
        }).unwrap();
        next.push(result.path);
      } catch (e: unknown) {
        const msg =
          e && typeof e === 'object' && 'data' in e
            ? String((e as { data?: { message?: string } }).data?.message ?? 'Upload failed.')
            : 'Upload failed.';
        setError(msg);
        return;
      }
    }
    emit(next);
    if (inputRef.current) inputRef.current.value = '';
  };

  const removeAt = (index: number) => {
    const next = paths.filter((_, i) => i !== index);
    emit(next);
  };

  const hint =
    helpText ||
    [
      accept ? `Accepted: ${(allowedTypes ?? []).join(', ')}` : null,
      maxMb ? `Max ${maxMb} MB` : null,
      multiple ? `Up to ${maxFiles} files` : null,
    ]
      .filter(Boolean)
      .join(' · ');

  const dropPrompt =
    fieldType === 'signature'
      ? 'Draw or upload your signature'
      : multiple
        ? 'Click to upload or drag and drop files'
        : 'Click to upload or drag and drop';

  // Single-file fields (File Upload, image, signature): hide dropzone once attached.
  // Multi-file: hide when at max capacity.
  const showDropzone =
    isLoading ||
    (multiple ? paths.length < maxFiles : paths.length === 0);

  return (
    <Box>
      <Typography variant="subtitle2" mb={1}>
        {label}
        {required ? ' *' : ''}
      </Typography>

      {/* Keep input mounted so replace-after-remove still works */}
      <input
        ref={inputRef}
        type="file"
        hidden
        multiple={multiple}
        accept={accept || undefined}
        disabled={disabled || isLoading}
        onChange={(e) => {
          if (e.target.files?.length) void handleFiles(e.target.files);
        }}
      />

      {showDropzone && (
        <Box
          onClick={() => !disabled && !isLoading && inputRef.current?.click()}
          onDragOver={(e) => {
            e.preventDefault();
            if (!disabled) setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            if (disabled || isLoading) return;
            if (e.dataTransfer.files?.length) void handleFiles(e.dataTransfer.files);
          }}
          sx={{
            border: '1px dashed',
            borderColor: dragOver ? 'primary.main' : 'divider',
            bgcolor: dragOver ? 'action.hover' : 'background.paper',
            borderRadius: 2,
            p: 3,
            textAlign: 'center',
            cursor: disabled || isLoading ? 'default' : 'pointer',
            opacity: disabled ? 0.6 : 1,
            transition: 'border-color 0.15s, background-color 0.15s',
            '&:hover':
              disabled || isLoading ? undefined : { borderColor: 'primary.main', bgcolor: 'action.hover' },
          }}
        >
          {isLoading ? (
            <Stack alignItems="center" spacing={1}>
              <CircularProgress size={28} />
              <Typography variant="body2" color="text.secondary">
                Uploading…
              </Typography>
            </Stack>
          ) : (
            <Stack alignItems="center" spacing={0.5}>
              <CloudUploadOutlinedIcon sx={{ fontSize: 40, color: 'text.secondary' }} />
              <Typography variant="body2" fontWeight={600}>
                {dropPrompt}
              </Typography>
              {hint && (
                <Typography variant="caption" color="text.secondary">
                  {hint}
                </Typography>
              )}
            </Stack>
          )}
        </Box>
      )}

      {error && (
        <Alert severity="error" sx={{ mt: 1.5 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}

      {paths.length > 0 && (
        <Stack spacing={1} mt={showDropzone ? 1.5 : 0}>
          {paths.map((path, index) => (
            <Stack
              key={`${path}-${index}`}
              direction="row"
              alignItems="center"
              spacing={1}
              sx={{
                border: '1px solid',
                borderColor: 'divider',
                borderRadius: 1.5,
                px: 1.5,
                py: 1,
              }}
            >
              {preview && isImagePath(path) ? (
                <Box
                  component="img"
                  src={fileUrl(path)}
                  alt={displayName(path)}
                  sx={{ width: 40, height: 40, objectFit: 'cover', borderRadius: 1 }}
                />
              ) : (
                <InsertDriveFileOutlinedIcon color="action" />
              )}
              <Box flex={1} minWidth={0}>
                <Typography variant="body2" noWrap title={displayName(path)}>
                  {displayName(path)}
                </Typography>
                <Button
                  size="small"
                  href={fileUrl(path)}
                  target="_blank"
                  rel="noopener noreferrer"
                  sx={{ p: 0, minWidth: 0, textTransform: 'none' }}
                  onClick={(e) => e.stopPropagation()}
                >
                  View
                </Button>
              </Box>
              {!disabled && (
                <IconButton
                  size="small"
                  aria-label="Remove file"
                  onClick={(e) => {
                    e.stopPropagation();
                    removeAt(index);
                  }}
                >
                  <DeleteOutlineIcon fontSize="small" />
                </IconButton>
              )}
            </Stack>
          ))}
        </Stack>
      )}
    </Box>
  );
}
