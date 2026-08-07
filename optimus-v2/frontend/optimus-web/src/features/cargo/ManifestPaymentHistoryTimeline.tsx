import {
  Alert,
  Box,
  Button,
  Chip,
  Paper,
  Stack,
  Typography,
} from '@mui/material';
import HistoryOutlinedIcon from '@mui/icons-material/HistoryOutlined';
import PictureAsPdfOutlinedIcon from '@mui/icons-material/PictureAsPdfOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import { Link as RouterLink } from 'react-router-dom';
import type { ManifestDto, PaymentDto } from '../../shared/types';
import { API_BASE_URL } from '../../shared/types';
import {
  canResubmitFinalPayment,
  finalPaymentsForManifest,
  latestFinalPayment,
} from './manifestPaymentUtils';

function money(amount: number, currency: string) {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function fileUrl(path: string) {
  if (/^https?:\/\//i.test(path)) return path;
  return `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
}

function formatWhen(value?: string | null) {
  if (!value) return '—';
  return new Date(value).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function statusTone(status: string): 'default' | 'warning' | 'success' | 'error' | 'info' {
  if (/verif/i.test(status)) return 'success';
  if (/reject/i.test(status)) return 'error';
  if (/pending/i.test(status)) return 'warning';
  return 'info';
}

function statusLabel(status: string) {
  if (/pending/i.test(status)) return 'Pending review';
  if (/verif/i.test(status)) return 'Verified';
  if (/reject/i.test(status)) return 'Rejected';
  return status;
}

export function ManifestPaymentHistoryTimeline({
  manifestId,
  manifest,
  payments,
  user,
  isFetching,
  isError,
}: {
  manifestId: string;
  manifest: ManifestDto;
  payments: PaymentDto[];
  user?: { role?: string | null; id?: string } | null;
  isFetching?: boolean;
  isError?: boolean;
}) {
  const finalPayments = finalPaymentsForManifest(payments);
  const latest = latestFinalPayment(payments);
  const canResubmit = canResubmitFinalPayment(user, manifest, latest);

  if (isError) {
    return (
      <Alert severity="error">
        Could not load payment history. Refresh the page or try again later.
      </Alert>
    );
  }

  if (isFetching && finalPayments.length === 0) {
    return <Typography color="text.secondary">Loading payment history…</Typography>;
  }

  if (finalPayments.length === 0) {
    return (
      <Paper
        elevation={0}
        sx={{
          py: 6,
          px: 3,
          textAlign: 'center',
          border: 1,
          borderStyle: 'dashed',
          borderColor: 'divider',
          borderRadius: 2,
        }}
      >
        <HistoryOutlinedIcon color="disabled" sx={{ fontSize: 42, mb: 1 }} />
        <Typography fontWeight={700}>No payment submissions yet</Typography>
        <Typography variant="body2" color="text.secondary" mt={0.75} mb={2}>
          When a final payment receipt is submitted, each attempt will appear here with its status.
        </Typography>
        {canResubmit && (
          <Button
            component={RouterLink}
            to={`/manifests/${manifestId}/final-payment`}
            variant="contained"
            startIcon={<ReceiptLongOutlinedIcon />}
          >
            Submit Payment
          </Button>
        )}
      </Paper>
    );
  }

  return (
    <Stack spacing={0}>
      {finalPayments.map((payment, index) => {
        const isLatest = index === finalPayments.length - 1;
        return (
          <Box
            key={payment.id}
            sx={{
              display: 'grid',
              gridTemplateColumns: '28px 1fr',
              gap: 1.5,
              position: 'relative',
            }}
          >
            <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
              <Box
                sx={{
                  width: 14,
                  height: 14,
                  borderRadius: '50%',
                  bgcolor:
                    statusTone(payment.status) === 'success'
                      ? 'success.main'
                      : statusTone(payment.status) === 'error'
                        ? 'error.main'
                        : 'warning.main',
                  mt: 0.75,
                  flexShrink: 0,
                }}
              />
              {index < finalPayments.length - 1 && (
                <Box sx={{ flex: 1, width: 2, bgcolor: 'divider', my: 0.5, minHeight: 48 }} />
              )}
            </Box>

            <Paper
              elevation={0}
              sx={{
                mb: 1.75,
                p: 2,
                border: 1,
                borderColor: isLatest ? 'primary.main' : 'divider',
                borderRadius: 2,
                bgcolor: 'background.paper',
              }}
            >
              <Stack
                direction={{ xs: 'column', sm: 'row' }}
                justifyContent="space-between"
                alignItems={{ sm: 'flex-start' }}
                gap={1}
                mb={1}
              >
                <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                  <Chip size="small" label={`v${payment.version ?? index + 1}`} color="primary" />
                  <Chip size="small" label={statusLabel(payment.status)} color={statusTone(payment.status)} />
                  {isLatest && <Chip size="small" label="Latest" variant="outlined" />}
                </Stack>
                <Typography variant="caption" color="text.secondary" fontWeight={600}>
                  {formatWhen(payment.createdAt)}
                </Typography>
              </Stack>

              <Typography fontWeight={700}>
                {money(payment.amount, payment.currency)} · Final payment
              </Typography>
              <Typography variant="body2" color="text.secondary" mt={0.5}>
                Submitted by {payment.submittedByName}
              </Typography>

              {payment.rejectionReason && (
                <Alert severity="error" variant="outlined" sx={{ mt: 1.5 }}>
                  {payment.rejectionReason}
                </Alert>
              )}

              {payment.validatedAt && (
                <Typography variant="caption" color="text.secondary" display="block" mt={1}>
                  Reviewed {formatWhen(payment.validatedAt)}
                  {payment.validatedByName ? ` · ${payment.validatedByName}` : ''}
                </Typography>
              )}

              <Stack direction="row" spacing={1} mt={1.5} flexWrap="wrap" useFlexGap>
                {payment.receiptFilePath && (
                  <Button
                    size="small"
                    variant="outlined"
                    href={fileUrl(payment.receiptFilePath)}
                    target="_blank"
                    rel="noopener noreferrer"
                    startIcon={<PictureAsPdfOutlinedIcon />}
                  >
                    Receipt
                  </Button>
                )}
                {payment.officialReceiptPath && (
                  <Button
                    size="small"
                    variant="outlined"
                    color="success"
                    href={fileUrl(payment.officialReceiptPath)}
                    target="_blank"
                    rel="noopener noreferrer"
                    startIcon={<PictureAsPdfOutlinedIcon />}
                  >
                    Official receipt
                  </Button>
                )}
                {user?.role === 'Accounting' || user?.role === 'SystemAdmin' ? (
                  /pending/i.test(payment.status) ? (
                    <Button
                      size="small"
                      variant="contained"
                      component={RouterLink}
                      to={`/payments/final/${payment.id}`}
                    >
                      Review
                    </Button>
                  ) : (
                    <Button
                      size="small"
                      variant="outlined"
                      component={RouterLink}
                      to={`/payments/final/${payment.id}`}
                    >
                      View
                    </Button>
                  )
                ) : null}
                {isLatest && /reject/i.test(payment.status) && canResubmit && (
                  <Button
                    size="small"
                    variant="contained"
                    color="error"
                    component={RouterLink}
                    to={`/manifests/${manifestId}/final-payment`}
                  >
                    Resubmit
                  </Button>
                )}
              </Stack>
            </Paper>
          </Box>
        );
      })}
    </Stack>
  );
}
