import { Alert, Box, Chip, Stack, Typography } from '@mui/material';
import type { WorkflowHistoryDto } from '../../shared/types';
import { formatRoleLabel } from '../../shared/roleLabels';
import { formatWorkflowState } from '../../shared/formatWorkflowState';

function formatHistoryTime(iso: string): string {
  const date = new Date(iso);
  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function roleChipColor(role: string): 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'default' {
  switch (role) {
    case 'SlStaff':
    case 'ShippingLinesAdmin':
      return 'primary';
    case 'Broker':
      return 'secondary';
    case 'Accounting':
      return 'info';
    case 'Consignee':
      return 'success';
    case 'Evaluator':
      return 'warning';
    default:
      return 'default';
  }
}

function entryTitle(entry: WorkflowHistoryDto): string {
  if (entry.reason?.trim()) return entry.reason.trim();
  if (entry.fromState === entry.toState) return formatWorkflowState(entry.toState);
  return formatWorkflowState(entry.toState);
}

export function WorkflowHistoryTimeline({ history }: { history: WorkflowHistoryDto[] }) {
  if (history.length === 0) {
    return (
      <Alert severity="info" variant="outlined">
        No workflow history has been recorded yet.
      </Alert>
    );
  }

  const latestIndex = history.length - 1;

  return (
    <Box component="ol" sx={{ m: 0, p: 0, listStyle: 'none' }}>
      {history.map((entry, index) => {
        const isLatest = index === latestIndex;
        const isFirst = index === 0;
        const title = entryTitle(entry);
        const showTransition = entry.fromState !== entry.toState;

        return (
          <Box
            key={`${entry.createdAt}-${index}`}
            component="li"
            sx={{
              display: 'grid',
              gridTemplateColumns: '20px minmax(0, 1fr)',
              columnGap: 1.75,
              opacity: 0,
              animation: 'wfHistoryIn 320ms ease forwards',
              animationDelay: `${Math.min(index, 8) * 40}ms`,
              '@keyframes wfHistoryIn': {
                from: { opacity: 0, transform: 'translateY(6px)' },
                to: { opacity: 1, transform: 'translateY(0)' },
              },
            }}
          >
            <Box sx={{ position: 'relative', display: 'flex', justifyContent: 'center' }}>
              {!isFirst && (
                <Box
                  sx={{
                    position: 'absolute',
                    top: 0,
                    bottom: '50%',
                    width: 2,
                    bgcolor: 'rgba(11,61,92,0.16)',
                  }}
                />
              )}
              {!isLatest && (
                <Box
                  sx={{
                    position: 'absolute',
                    top: '50%',
                    bottom: 0,
                    width: 2,
                    bgcolor: 'rgba(11,61,92,0.16)',
                  }}
                />
              )}
              <Box
                sx={{
                  position: 'relative',
                  zIndex: 1,
                  mt: 1.65,
                  width: 12,
                  height: 12,
                  borderRadius: '50%',
                  bgcolor: isLatest ? 'primary.main' : 'background.paper',
                  border: 2,
                  borderColor: isLatest ? 'primary.main' : 'rgba(11,61,92,0.35)',
                  boxShadow: isLatest ? '0 0 0 4px rgba(11,61,92,0.12)' : 'none',
                  flexShrink: 0,
                }}
              />
            </Box>

            <Box
              sx={{
                mb: isLatest ? 0 : 1.5,
                px: 2,
                py: 1.5,
                border: 1,
                borderColor: isLatest ? 'rgba(11,61,92,0.28)' : 'divider',
                borderRadius: 1.5,
                bgcolor: isLatest ? 'rgba(11,61,92,0.03)' : 'background.default',
                transition: 'border-color 160ms ease, background-color 160ms ease',
                '&:hover': {
                  borderColor: 'rgba(11,61,92,0.32)',
                  bgcolor: 'rgba(11,61,92,0.02)',
                },
              }}
            >
              <Stack spacing={0.75} minWidth={0}>
                <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={1.5}>
                  <Typography fontWeight={700} sx={{ fontSize: 15, lineHeight: 1.3, textWrap: 'pretty' }}>
                    {title}
                  </Typography>
                  {isLatest && (
                    <Chip
                      size="small"
                      label="Current"
                      color="primary"
                      sx={{ height: 22, fontWeight: 600, flexShrink: 0 }}
                    />
                  )}
                </Stack>

                {showTransition && (
                  <Typography
                    variant="body2"
                    color="text.secondary"
                    sx={{ fontSize: 12.5, letterSpacing: '0.01em' }}
                  >
                    {formatWorkflowState(entry.fromState)}
                    <Box component="span" sx={{ mx: 0.75, color: 'text.disabled' }}>
                      →
                    </Box>
                    {formatWorkflowState(entry.toState)}
                  </Typography>
                )}

                <Stack direction="row" alignItems="center" flexWrap="wrap" gap={1} pt={0.25}>
                  <Chip
                    size="small"
                    label={formatRoleLabel(entry.actorRole)}
                    color={roleChipColor(entry.actorRole)}
                    variant="outlined"
                    sx={{ height: 22, fontWeight: 600 }}
                  />
                  <Typography
                    variant="caption"
                    color="text.secondary"
                    sx={{ fontVariantNumeric: 'tabular-nums', fontSize: 12 }}
                  >
                    {formatHistoryTime(entry.createdAt)}
                  </Typography>
                </Stack>
              </Stack>
            </Box>
          </Box>
        );
      })}
    </Box>
  );
}
