import { Box, Link, Paper, Stack, Typography } from '@mui/material';
import type { SasFormField } from '../../shared/types';
import { API_BASE_URL } from '../../shared/types';
import { buildSubmissionPreview } from '../../shared/formSchema';
import type { DynamicFormValues } from './DynamicFormFields';
import { GeolocationMapPreview } from './GeolocationMapPicker';

type Props = {
  fields: SasFormField[];
  values: DynamicFormValues;
  /** Optional max height for scrollable preview (e.g. confirm modal). */
  maxHeight?: number | { xs?: number; sm?: number };
  title?: string;
};

function fileUrl(path: string): string {
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  return `${API_BASE_URL}${path.startsWith('/') ? '' : '/'}${path}`;
}

function displayFileName(path: string): string {
  const base = path.split('/').pop() || path;
  const match = base.match(/^\d{14}_[a-f0-9]{32}_(.+)$/i);
  return match?.[1] || base;
}

function filePathsFromValue(raw: string | boolean | string[] | undefined): string[] {
  if (Array.isArray(raw)) return raw.filter(Boolean).map(String);
  if (raw == null || typeof raw === 'boolean') return [];
  return String(raw)
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
}

/** Read-only submitted-details layout (not a disabled form). */
export function SubmissionDetailsPreview({ fields, values, maxHeight, title }: Props) {
  const rows = buildSubmissionPreview(fields, values);
  const fieldById = new Map(fields.map((f) => [f.id, f]));

  return (
    <Box>
      {title && (
        <Typography variant="subtitle2" fontWeight={700} mb={1}>
          {title}
        </Typography>
      )}
      <Paper
        variant="outlined"
        sx={{
          maxHeight,
          overflowY: maxHeight ? 'auto' : undefined,
          p: 0,
        }}
      >
        <Stack divider={<Box sx={{ borderBottom: 1, borderColor: 'divider' }} />} spacing={0}>
          {rows.map((row) => {
            if (row.kind === 'section') {
              return (
                <Box
                  key={row.id}
                  sx={{
                    px: 2,
                    py: 1.25,
                    bgcolor: (t) =>
                      t.palette.mode === 'dark' ? 'rgba(255,255,255,0.06)' : 'action.hover',
                    borderBottom: 1,
                    borderColor: 'divider',
                  }}
                >
                  <Typography variant="subtitle2" fontWeight={700} color="text.primary">
                    {row.label}
                  </Typography>
                  {row.subtitle && (
                    <Typography variant="caption" color="text.secondary">
                      {row.subtitle}
                    </Typography>
                  )}
                </Box>
              );
            }

            const fieldType = fieldById.get(row.id)?.type;
            const isGeo = fieldType === 'geolocation';
            const paths = row.isFile ? filePathsFromValue(values[row.id]) : [];
            const geoRaw = String(values[row.id] ?? '');

            return (
              <Stack
                key={row.id}
                direction={{ xs: 'column', sm: isGeo ? 'column' : 'row' }}
                spacing={{ xs: 0.5, sm: isGeo ? 1 : 2 }}
                sx={{ px: 2, py: 1.5 }}
              >
                <Typography
                  variant="body2"
                  color="text.secondary"
                  sx={{
                    minWidth: { sm: isGeo ? undefined : 180 },
                    maxWidth: { sm: isGeo ? undefined : 240 },
                    flexShrink: 0,
                  }}
                >
                  {row.label}
                </Typography>

                {isGeo ? (
                  <Box sx={{ width: '100%', minWidth: 0 }}>
                    <GeolocationMapPreview value={geoRaw} height={240} />
                  </Box>
                ) : row.isFile && paths.length > 0 ? (
                  <Stack spacing={0.5}>
                    {paths.map((path) => (
                      <Link
                        key={path}
                        href={fileUrl(path)}
                        target="_blank"
                        rel="noopener noreferrer"
                        variant="body2"
                        fontWeight={500}
                        underline="hover"
                      >
                        {displayFileName(path)}
                      </Link>
                    ))}
                  </Stack>
                ) : (
                  <Typography
                    variant="body2"
                    fontWeight={600}
                    color="text.primary"
                    sx={{ wordBreak: 'break-word' }}
                  >
                    {row.value}
                  </Typography>
                )}
              </Stack>
            );
          })}
          {rows.length === 0 && (
            <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>
              No details to show.
            </Typography>
          )}
        </Stack>
      </Paper>
    </Box>
  );
}
