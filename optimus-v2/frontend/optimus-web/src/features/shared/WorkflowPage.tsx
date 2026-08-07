import { ReactNode } from 'react';
import { Box, Chip, Paper, Stack, Typography } from '@mui/material';
import { useTheme } from '@mui/material/styles';
import { pageHeroGradient } from '../../shared/theme';

export type WorkflowStat = {
  label: string;
  value: string | number;
  hint?: string;
  tone?: 'default' | 'primary' | 'success' | 'warning' | 'error' | 'info';
};

function toneColor(tone: WorkflowStat['tone'], mode: 'light' | 'dark') {
  const dark = mode === 'dark';
  switch (tone) {
    case 'primary':
      return dark ? '#5BA3C9' : '#0B3D5C';
    case 'success':
      return dark ? '#66BB6A' : '#2E7D32';
    case 'warning':
      return dark ? '#FFA726' : '#EF6C00';
    case 'error':
      return dark ? '#EF5350' : '#C62828';
    case 'info':
      return dark ? '#29B6F6' : '#0277BD';
    default:
      return dark ? '#90A4AE' : '#37474F';
  }
}

export function WorkflowPage({
  title,
  subtitle,
  eyebrow,
  chips,
  actions,
  stats,
  children,
}: {
  title: string;
  subtitle?: string;
  eyebrow?: string;
  chips?: ReactNode;
  actions?: ReactNode;
  stats?: WorkflowStat[];
  children: ReactNode;
}) {
  const theme = useTheme();
  const mode = theme.palette.mode;

  return (
    <Stack spacing={3}>
      <Paper
        elevation={0}
        sx={{
          p: { xs: 2.5, sm: 3.25 },
          borderRadius: 3,
          border: 1,
          borderColor: 'divider',
          background: pageHeroGradient(mode),
        }}
      >
        <Stack
          direction={{ xs: 'column', lg: 'row' }}
          justifyContent="space-between"
          alignItems={{ lg: 'flex-end' }}
          spacing={2}
        >
          <Box minWidth={0}>
            {eyebrow && (
              <Chip
                size="small"
                label={eyebrow}
                color="primary"
                variant="outlined"
                sx={{ mb: 1.5, fontWeight: 600 }}
              />
            )}
            <Typography variant="h4" fontWeight={800} lineHeight={1.2}>
              {title}
            </Typography>
            {subtitle && (
              <Typography color="text.secondary" mt={1} maxWidth={720}>
                {subtitle}
              </Typography>
            )}
            {chips && (
              <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1} mt={2}>
                {chips}
              </Stack>
            )}
          </Box>
          {actions && (
            <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1} justifyContent={{ lg: 'flex-end' }}>
              {actions}
            </Stack>
          )}
        </Stack>
      </Paper>

      {stats && stats.length > 0 && (
        <Box
          sx={{
            display: 'grid',
            gap: { xs: 1, sm: 1.5 },
            gridTemplateColumns: {
              xs: '1fr 1fr',
              sm: '1fr 1fr',
              md: `repeat(${Math.min(stats.length, 4)}, 1fr)`,
            },
          }}
        >
          {stats.map((stat) => {
            const tone = stat.tone ?? 'default';
            const color = toneColor(tone, mode);
            const valueText = String(stat.value);
            const isNumeric = valueText.length <= 8 && /^[\d,.%+\-]+$/.test(valueText.trim());

            return (
              <Paper
                key={stat.label}
                elevation={0}
                sx={{
                  px: { xs: 1.5, sm: 2.25 },
                  py: { xs: 1.35, sm: 1.75 },
                  border: 1,
                  borderColor: 'divider',
                  borderRadius: 1,
                  bgcolor: 'background.paper',
                  position: 'relative',
                  overflow: 'hidden',
                  transition: 'border-color 160ms ease, box-shadow 160ms ease',
                  '&:hover': {
                    borderColor: 'primary.main',
                    boxShadow: (t) =>
                      t.palette.mode === 'dark'
                        ? '0 1px 2px rgba(0,0,0,0.35)'
                        : '0 1px 2px rgba(11,61,92,0.06)',
                  },
                  '&::before': {
                    content: '""',
                    position: 'absolute',
                    left: 0,
                    top: 10,
                    bottom: 10,
                    width: 3,
                    borderRadius: 2,
                    bgcolor: color,
                  },
                }}
              >
                <Stack spacing={0.5} pl={1} minWidth={0}>
                  <Stack direction="row" alignItems="baseline" spacing={1} minWidth={0}>
                    <Typography
                      variant="caption"
                      noWrap
                      sx={{
                        color: 'text.secondary',
                        fontWeight: 600,
                        letterSpacing: '0.04em',
                        textTransform: 'uppercase',
                        fontSize: { xs: 10, sm: 11 },
                        minWidth: 0,
                      }}
                    >
                      {stat.label}
                    </Typography>
                    {isNumeric ? (
                      <Typography
                        fontWeight={700}
                        sx={{
                          color,
                          fontSize: { xs: 20, sm: 22 },
                          lineHeight: 1,
                          letterSpacing: '-0.02em',
                          fontVariantNumeric: 'tabular-nums',
                          ml: 'auto !important',
                          flexShrink: 0,
                        }}
                      >
                        {valueText}
                      </Typography>
                    ) : (
                      stat.hint && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          noWrap
                          sx={{ ml: 'auto !important', fontSize: 11, opacity: 0.85 }}
                        >
                          {stat.hint}
                        </Typography>
                      )
                    )}
                  </Stack>

                  {isNumeric ? (
                    stat.hint && (
                      <Typography
                        variant="caption"
                        color="text.secondary"
                        noWrap
                        sx={{ fontSize: { xs: 11, sm: 12 } }}
                      >
                        {stat.hint}
                      </Typography>
                    )
                  ) : (
                    <Typography
                      fontWeight={700}
                      sx={{
                        color,
                        fontSize: { xs: 15, sm: 16 },
                        lineHeight: 1.25,
                        letterSpacing: '-0.01em',
                        wordBreak: 'break-word',
                      }}
                    >
                      {valueText}
                    </Typography>
                  )}
                </Stack>
              </Paper>
            );
          })}
        </Box>
      )}

      {children}
    </Stack>
  );
}

export function WorkflowSection({
  title,
  subtitle,
  actions,
  children,
}: {
  title: string;
  subtitle?: string;
  actions?: ReactNode;
  children: ReactNode;
}) {
  return (
    <Paper elevation={0} sx={{ border: 1, borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
      <Stack
        direction="row"
        justifyContent="space-between"
        alignItems="center"
        spacing={1.5}
        px={2.5}
        py={2}
      >
        <Box minWidth={0}>
          <Typography variant="h6" fontWeight={700}>
            {title}
          </Typography>
          {subtitle && (
            <Typography variant="body2" color="text.secondary">
              {subtitle}
            </Typography>
          )}
        </Box>
        {actions}
      </Stack>
      <Box px={2.5} pb={2.5}>
        {children}
      </Box>
    </Paper>
  );
}
