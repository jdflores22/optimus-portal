import { FormEvent, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import InsertDriveFileOutlinedIcon from '@mui/icons-material/InsertDriveFileOutlined';
import PictureAsPdfOutlinedIcon from '@mui/icons-material/PictureAsPdfOutlined';
import HistoryOutlinedIcon from '@mui/icons-material/HistoryOutlined';
import SendOutlinedIcon from '@mui/icons-material/SendOutlined';
import { Link as RouterLink, Navigate, useNavigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGetManifestQuery,
  useGetPaymentsByManifestQuery,
  useSubmitPaymentMutation,
} from '../../app/api';
import { API_BASE_URL } from '../../shared/types';
import { formatWorkflowState } from '../../shared/formatWorkflowState';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

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

export function ManifestFinalPaymentPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const canPay = user?.role === 'Broker' || user?.role === 'Consignee' || user?.role === 'SystemAdmin';
  const { data, error, isLoading } = useGetManifestQuery(id, { skip: !id });
  const { data: payments = [] } = useGetPaymentsByManifestQuery(id, { skip: !id });
  const [submitPayment] = useSubmitPaymentMutation();

  const [receipt, setReceipt] = useState<File | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const rejectedPayment = useMemo(
    () =>
      [...payments]
        .filter((p) => p.paymentType === 'FinalPayment' && /reject/i.test(p.status))
        .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())[0],
    [payments],
  );

  const pendingPayment = useMemo(
    () => payments.find((p) => p.paymentType === 'FinalPayment' && /pending/i.test(p.status)),
    [payments],
  );

  if (!canPay) return <Navigate to={`/manifests/${id}`} replace />;
  if (error) return <Alert severity="error">Manifest not found.</Alert>;
  if (isLoading || !data) return <Typography>Loading...</Typography>;

  if (user?.role === 'Broker' && data.brokerId && data.brokerId !== user.id) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }
  if (user?.role === 'Consignee' && data.consigneeId && data.consigneeId !== user.id) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  if (data.workflowState !== 'BillingGenerated' || !data.billingPdfPath || data.billingTotal == null) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  if (pendingPayment) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  const currency = (data.billingCurrency || 'USD').toUpperCase();
  const amount = data.billingTotal;
  const isUsd = currency === 'USD';
  const phpTotal =
    data.billingTotalPhp ??
    (isUsd && data.billingExchangeRate ? amount * data.billingExchangeRate : null);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setFormError(null);
    if (!receipt) {
      setFormError('Please upload a PDF payment receipt before submitting.');
      return;
    }
    if (!/\.pdf$/i.test(receipt.name) && receipt.type !== 'application/pdf') {
      setFormError('Receipt must be a PDF file.');
      return;
    }
    setConfirmOpen(true);
  };

  const onConfirm = async () => {
    if (!receipt) return;
    setSubmitting(true);
    setFormError(null);
    try {
      await submitPayment({
        manifestId: id,
        paymentType: 'FinalPayment',
        amount,
        currency,
        receipt,
      }).unwrap();
      setConfirmOpen(false);
      navigate(`/manifests/${id}`, {
        replace: true,
        state: {
          flash: rejectedPayment
            ? 'Payment resubmitted. Waiting for accounting validation.'
            : 'Final payment submitted. Waiting for accounting validation.',
        },
      });
    } catch (err: unknown) {
      setConfirmOpen(false);
      const msg =
        err && typeof err === 'object' && 'data' in err
          ? String((err as { data?: { error?: string } }).data?.error ?? 'Failed to submit payment.')
          : 'Failed to submit payment.';
      setFormError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <WorkflowPage
      eyebrow="Manifest workflow · Step 5"
      title={rejectedPayment ? 'Payment Resubmission' : 'Final Payment'}
      subtitle="Upload your payment receipt for the billing invoice. Accounting will verify payment before eDO release proceeds."
      chips={
        <>
          <Chip size="small" label={data.manifestNumber} variant="outlined" sx={{ fontWeight: 700 }} />
          <Chip size="small" label={formatWorkflowState(data.workflowState)} color="warning" />
          <Chip size="small" label={money(amount, currency)} color="warning" />
          <Chip size="small" label={`Pay in ${currency}`} variant="outlined" />
          {rejectedPayment && <Chip size="small" color="error" label="Resubmit" />}
        </>
      }
      actions={
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
          {(payments.length > 0 || rejectedPayment) && (
            <Button
              component={RouterLink}
              to={`/manifests/${id}/payment-history`}
              variant="outlined"
              startIcon={<HistoryOutlinedIcon />}
            >
              My Submissions
            </Button>
          )}
          <Button component={RouterLink} to={`/manifests/${id}`} startIcon={<ArrowBackOutlinedIcon />}>
            Back to Manifest
          </Button>
        </Stack>
      }
      stats={[
        {
          label: 'Amount due',
          value: money(amount, currency),
          hint: phpTotal != null && isUsd ? `≈ ${money(phpTotal, 'PHP')}` : 'Exact amount required',
          tone: 'warning',
        },
        {
          label: 'Payment currency',
          value: currency,
          hint: isUsd ? 'US Dollars' : 'Philippine Pesos',
          tone: 'info',
        },
        {
          label: 'Billing reference',
          value: data.billingId ? `#${data.billingId.slice(0, 8)}` : '—',
          hint: data.manifestNumber,
          tone: 'primary',
        },
      ]}
    >
      {formError && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setFormError(null)}>
          {formError}
        </Alert>
      )}

      {rejectedPayment && (
        <Alert severity="error" sx={{ mb: 2 }}>
          <Typography fontWeight={700}>Previous payment was rejected</Typography>
          <Typography variant="body2" mt={0.5}>
            Reason: {rejectedPayment.rejectionReason || 'No reason provided.'}
          </Typography>
        </Alert>
      )}

      <Box
        sx={{
          display: 'grid',
          gap: 2.5,
          gridTemplateColumns: { xs: '1fr', xl: 'minmax(0, 2fr) minmax(0, 3fr)' },
          alignItems: 'start',
        }}
      >
        <Stack spacing={2.5}>
          <WorkflowSection
            title="Submit payment receipt"
            subtitle="Pay the exact amount shown, then upload proof of payment."
          >
            <Box component="form" onSubmit={onSubmit}>
              <Stack spacing={2.25}>
                <TextField
                  label="Payment amount"
                  value={money(amount, currency)}
                  InputProps={{ readOnly: true }}
                  helperText={
                    isUsd
                      ? 'Pay in US Dollars only — do not convert to another currency.'
                      : 'Pay in Philippine Pesos.'
                  }
                  fullWidth
                />

                <Box>
                  <Typography variant="body2" fontWeight={700} mb={1}>
                    Payment receipt (PDF) *
                  </Typography>
                  <Box
                    component="label"
                    sx={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 1.75,
                      px: 2,
                      py: 2.25,
                      border: 1,
                      borderStyle: 'dashed',
                      borderColor: receipt ? 'success.main' : 'divider',
                      borderRadius: 2,
                      bgcolor: receipt ? 'action.hover' : 'background.default',
                      cursor: 'pointer',
                      transition: 'border-color 160ms ease, background-color 160ms ease',
                      '&:hover': { borderColor: 'primary.main' },
                    }}
                  >
                    <Box
                      sx={{
                        width: 44,
                        height: 44,
                        borderRadius: 1.5,
                        display: 'grid',
                        placeItems: 'center',
                        flexShrink: 0,
                        bgcolor: receipt ? 'success.main' : 'action.selected',
                        color: receipt ? 'success.contrastText' : 'text.secondary',
                      }}
                    >
                      {receipt ? <InsertDriveFileOutlinedIcon /> : <UploadFileOutlinedIcon />}
                    </Box>
                    <Box minWidth={0} flex={1}>
                      <Typography fontWeight={700} noWrap>
                        {receipt ? receipt.name : 'Choose PDF receipt'}
                      </Typography>
                      <Typography variant="body2" color="text.secondary">
                        {receipt
                          ? `${Math.max(1, Math.round(receipt.size / 1024))} KB · click to replace`
                          : 'PDF only · max recommended 5MB'}
                      </Typography>
                    </Box>
                    <input
                      hidden
                      type="file"
                      accept=".pdf,application/pdf"
                      onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
                    />
                  </Box>
                </Box>

                <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="flex-end" spacing={1.25}>
                  <Button component={RouterLink} to={`/manifests/${id}`} disabled={submitting}>
                    Cancel
                  </Button>
                  <Button
                    type="submit"
                    variant="contained"
                    color="warning"
                    disabled={submitting}
                    startIcon={<SendOutlinedIcon />}
                  >
                    {rejectedPayment ? 'Resubmit Payment' : 'Submit Payment'}
                  </Button>
                </Stack>
              </Stack>
            </Box>
          </WorkflowSection>

          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Typography fontWeight={700} mb={1.5}>
              Charge breakdown
            </Typography>
            <Stack spacing={1.25} divider={<Box sx={{ borderBottom: 1, borderColor: 'divider' }} />}>
              <BreakdownRow
                label="Freight"
                amount={money(data.billingFreightCharges ?? 0, currency)}
                php={
                  isUsd && data.billingExchangeRate
                    ? money((data.billingFreightCharges ?? 0) * data.billingExchangeRate, 'PHP')
                    : null
                }
              />
              <BreakdownRow
                label="THC"
                amount={money(data.billingThcCharges ?? 0, currency)}
                php={
                  isUsd && data.billingExchangeRate
                    ? money((data.billingThcCharges ?? 0) * data.billingExchangeRate, 'PHP')
                    : null
                }
              />
              {(data.billingAdditionalCharges ?? 0) > 0 && (
                <BreakdownRow
                  label="Additional charges"
                  amount={money(data.billingAdditionalCharges ?? 0, currency)}
                  php={
                    isUsd && data.billingExchangeRate
                      ? money((data.billingAdditionalCharges ?? 0) * data.billingExchangeRate, 'PHP')
                      : null
                  }
                />
              )}
            </Stack>
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="flex-end"
              mt={2}
              p={1.75}
              sx={{ borderRadius: 2, bgcolor: 'rgba(237, 108, 2, 0.08)', border: 1, borderColor: 'warning.light' }}
            >
              <Typography fontWeight={700}>Total due</Typography>
              <Box textAlign="right">
                <Typography fontWeight={800} fontSize={22} color="warning.main" lineHeight={1.1}>
                  {money(amount, currency)}
                </Typography>
                {phpTotal != null && isUsd && (
                  <Typography variant="body2" color="text.secondary" mt={0.5}>
                    ≈ {money(phpTotal, 'PHP')}
                  </Typography>
                )}
              </Box>
            </Stack>
          </Paper>
        </Stack>

        <Paper
          elevation={0}
          sx={{
            border: 1,
            borderColor: 'divider',
            borderRadius: 2,
            overflow: 'hidden',
            position: { xl: 'sticky' },
            top: { xl: 88 },
          }}
        >
          <Stack
            direction={{ xs: 'column', sm: 'row' }}
            justifyContent="space-between"
            alignItems={{ sm: 'center' }}
            gap={1.5}
            px={2.25}
            py={2}
            sx={{ borderBottom: 1, borderColor: 'divider' }}
          >
            <Box>
              <Stack direction="row" spacing={1} alignItems="center">
                <PictureAsPdfOutlinedIcon color="error" fontSize="small" />
                <Typography fontWeight={700}>Billing document</Typography>
              </Stack>
              <Typography variant="body2" color="text.secondary">
                Review the invoice before submitting your receipt
              </Typography>
            </Box>
            <Stack direction="row" spacing={1}>
              <Button
                size="small"
                href={fileUrl(data.billingPdfPath)}
                target="_blank"
                rel="noopener noreferrer"
              >
                Open
              </Button>
              <Button
                size="small"
                variant="outlined"
                color="warning"
                href={fileUrl(data.billingPdfPath)}
                download
              >
                Download
              </Button>
            </Stack>
          </Stack>
          <Box sx={{ bgcolor: 'action.hover', p: { xs: 1, sm: 1.5 } }}>
            <Box
              component="iframe"
              title="Billing document preview"
              src={fileUrl(data.billingPdfPath)}
              sx={{
                width: '100%',
                height: { xs: 420, md: 560, xl: 'min(72vh, 720px)' },
                border: 0,
                borderRadius: 2,
                bgcolor: 'background.paper',
                display: 'block',
              }}
            />
          </Box>
        </Paper>
      </Box>

      <Dialog open={confirmOpen} onClose={() => (submitting ? null : setConfirmOpen(false))} fullWidth maxWidth="xs">
        <DialogTitle>
          {rejectedPayment ? 'Confirm payment resubmission' : 'Confirm payment submission'}
        </DialogTitle>
        <DialogContent dividers>
          <Stack spacing={1.25}>
            <Typography variant="body2" color="text.secondary">
              Please verify the details below before submitting.
            </Typography>
            <Box>
              <Typography variant="caption" color="text.secondary" fontWeight={600}>
                Manifest
              </Typography>
              <Typography fontWeight={700}>{data.manifestNumber}</Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary" fontWeight={600}>
                Amount
              </Typography>
              <Typography fontWeight={700} color="warning.main">
                {money(amount, currency)}
              </Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary" fontWeight={600}>
                Receipt file
              </Typography>
              <Typography fontWeight={700} sx={{ wordBreak: 'break-word' }}>
                {receipt?.name}
              </Typography>
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setConfirmOpen(false)} disabled={submitting}>
            Back
          </Button>
          <Button
            variant="contained"
            color="warning"
            onClick={() => void onConfirm()}
            disabled={submitting}
            startIcon={<SendOutlinedIcon />}
          >
            {submitting ? 'Submitting…' : rejectedPayment ? 'Resubmit' : 'Confirm Submit'}
          </Button>
        </DialogActions>
      </Dialog>
    </WorkflowPage>
  );
}

function BreakdownRow({
  label,
  amount,
  php,
}: {
  label: string;
  amount: string;
  php: string | null;
}) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="flex-start" gap={2} py={0.5}>
      <Typography color="text.secondary">{label}</Typography>
      <Box textAlign="right">
        <Typography fontWeight={700}>{amount}</Typography>
        {php && (
          <Typography variant="caption" color="text.secondary" display="block">
            {php}
          </Typography>
        )}
      </Box>
    </Stack>
  );
}
