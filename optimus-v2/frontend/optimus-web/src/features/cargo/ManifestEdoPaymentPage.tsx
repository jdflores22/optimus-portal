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
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import CreditCardOutlinedIcon from '@mui/icons-material/CreditCardOutlined';
import HourglassTopOutlinedIcon from '@mui/icons-material/HourglassTopOutlined';
import InsertDriveFileOutlinedIcon from '@mui/icons-material/InsertDriveFileOutlined';
import SendOutlinedIcon from '@mui/icons-material/SendOutlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import { Link as RouterLink, Navigate, useNavigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGetActivePaymentFeeQuery,
  useGetEdosQuery,
  useGetManifestQuery,
  useSubmitEdoPaymentMutation,
} from '../../app/api';
import {
  edoNeedsPayment,
  edoPaymentRejected,
  edoPaymentSubmitted,
  formatEdoStatus,
} from '../../shared/formatEdoStatus';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function money(amount: number, currency: string) {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

export function ManifestEdoPaymentPage() {
  const { id = '', edoId = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const canPay = user?.role === 'Broker' || user?.role === 'Consignee' || user?.role === 'SystemAdmin';

  const { data: manifest, error: manifestError, isLoading: manifestLoading } = useGetManifestQuery(id, {
    skip: !id,
  });
  const { data: edos = [], isLoading: edosLoading } = useGetEdosQuery({ manifestId: id }, { skip: !id });
  const { data: edoFee } = useGetActivePaymentFeeQuery('edo');
  const [submitPayment] = useSubmitEdoPaymentMutation();

  const edo = useMemo(() => edos.find((e) => e.id === edoId), [edos, edoId]);

  const [receipt, setReceipt] = useState<File | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  if (!canPay) return <Navigate to={`/manifests/${id}`} replace />;
  if (manifestError) return <Alert severity="error">Manifest not found.</Alert>;
  if (manifestLoading || edosLoading || !manifest) return <Typography>Loading...</Typography>;

  if (user?.role === 'Broker' && manifest.brokerId && manifest.brokerId !== user.id) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }
  if (user?.role === 'Consignee' && manifest.consigneeId && manifest.consigneeId !== user.id) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  if (!edo) return <Alert severity="error">eDO not found for this manifest.</Alert>;

  const paymentSubmitted = edoPaymentSubmitted(edo.currentPaymentStatus);
  const paymentRejected = edoPaymentRejected(edo.currentPaymentStatus);
  const canSubmitPayment = edoNeedsPayment(edo.status, edo.currentPaymentStatus);

  if (!canSubmitPayment && !paymentSubmitted) {
    return <Navigate to={`/manifests/${id}?tab=documents`} replace />;
  }

  const amount = edo.feeAmount ?? edoFee?.amount ?? 750;
  const currency = 'PHP';
  const displayStatus = formatEdoStatus(edo.status, edo.currentPaymentStatus);

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
        edoId: edo.id,
        amount,
        currency,
        receipt,
      }).unwrap();
      setConfirmOpen(false);
      navigate(`/manifests/${id}?tab=documents`, {
        replace: true,
        state: { flash: 'eDO payment submitted. Waiting for accounting validation.' },
      });
    } catch (err: unknown) {
      setConfirmOpen(false);
      const msg =
        err && typeof err === 'object' && 'data' in err
          ? String((err as { data?: { message?: string; error?: string } }).data?.message ??
              (err as { data?: { error?: string } }).data?.error ??
              'Failed to submit payment.')
          : 'Failed to submit payment.';
      setFormError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  if (paymentSubmitted) {
    return (
      <WorkflowPage
        eyebrow="eDO access payment"
        title="Payment submitted"
        subtitle="Your receipt is with accounting for verification. Download will be available after validation and release."
        chips={
          <>
            <Chip size="small" label={manifest.manifestNumber} variant="outlined" sx={{ fontWeight: 700 }} />
            <Chip size="small" label={edo.edoNumber} variant="outlined" sx={{ fontFamily: 'monospace' }} />
            <Chip size="small" label={displayStatus} color="info" />
          </>
        }
        actions={
          <Button
            component={RouterLink}
            to={`/manifests/${id}?tab=documents`}
            startIcon={<ArrowBackOutlinedIcon />}
            sx={{ textTransform: 'none' }}
          >
            Back to Documents
          </Button>
        }
        stats={[
          { label: 'Container', value: edo.containerNumber ?? '—', hint: 'Linked container', tone: 'primary' },
          { label: 'Fee paid', value: money(amount, currency), hint: 'eDO access fee', tone: 'info' },
          {
            label: 'Submitted',
            value: edo.paymentSubmittedAt
              ? new Date(edo.paymentSubmittedAt).toLocaleDateString()
              : '—',
            hint: 'Awaiting validation',
            tone: 'warning',
          },
        ]}
      >
        <Alert severity="info" icon={<HourglassTopOutlinedIcon />} sx={{ mb: 2 }}>
          Payment submitted successfully. Accounting will verify your receipt before the eDO can be released
          for download.
        </Alert>

        <WorkflowSection title="eDO summary" subtitle="Document awaiting payment validation.">
          <Stack spacing={1.5}>
            <Typography variant="body2">
              <strong>eDO:</strong>{' '}
              <Typography component="span" fontFamily="monospace" fontWeight={700}>
                {edo.edoNumber}
              </Typography>
            </Typography>
            <Typography variant="body2">
              <strong>Container:</strong> {edo.containerNumber ?? '—'}
            </Typography>
            <Typography variant="body2">
              <strong>Manifest:</strong> {manifest.manifestNumber}
            </Typography>
            <Typography variant="body2">
              <strong>Status:</strong> {displayStatus}
            </Typography>
            {edo.paymentSubmittedAt && (
              <Typography variant="body2">
                <strong>Submitted at:</strong> {new Date(edo.paymentSubmittedAt).toLocaleString()}
              </Typography>
            )}
          </Stack>
        </WorkflowSection>
      </WorkflowPage>
    );
  }

  return (
    <WorkflowPage
      eyebrow="eDO access payment"
      title={paymentRejected ? 'Resubmit eDO payment' : 'Pay eDO fee'}
      subtitle="Pay the eDO access fee, upload proof of payment, and wait for accounting to verify before release."
      chips={
        <>
          <Chip size="small" label={manifest.manifestNumber} variant="outlined" sx={{ fontWeight: 700 }} />
          <Chip size="small" label={edo.edoNumber} variant="outlined" sx={{ fontFamily: 'monospace' }} />
          <Chip size="small" label={displayStatus} color="warning" />
          <Chip size="small" label={money(amount, currency)} color="warning" />
        </>
      }
      actions={
        <Button
          component={RouterLink}
          to={`/manifests/${id}?tab=documents`}
          startIcon={<ArrowBackOutlinedIcon />}
          sx={{ textTransform: 'none' }}
        >
          Back to Documents
        </Button>
      }
      stats={[
        { label: 'Container', value: edo.containerNumber ?? '—', hint: 'Linked container', tone: 'primary' },
        { label: 'Fee due', value: money(amount, currency), hint: 'eDO access fee', tone: 'warning' },
        { label: 'Status', value: displayStatus, hint: 'Awaiting payment', tone: 'warning' },
      ]}
    >
      {formError && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setFormError(null)}>
          {formError}
        </Alert>
      )}

      {paymentRejected && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Your previous payment was rejected. Upload a new receipt to resubmit.
        </Alert>
      )}

      <Alert severity="info" variant="outlined" sx={{ mb: 2 }}>
        Submit your receipt below. Accounting will verify payment before the eDO is released for download.
      </Alert>

      <Box
        sx={{
          display: 'grid',
          gap: 2.5,
          gridTemplateColumns: { xs: '1fr', xl: 'minmax(0, 2fr) minmax(0, 3fr)' },
          alignItems: 'start',
        }}
      >
        <WorkflowSection title="Submit payment receipt" subtitle="Pay the exact fee amount, then upload proof.">
          <Box component="form" onSubmit={onSubmit}>
            <Stack spacing={2.25}>
              <TextField
                label="Payment amount"
                value={money(amount, currency)}
                InputProps={{ readOnly: true }}
                helperText="Pay in Philippine Pesos."
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
                    <Typography variant="caption" color="text.secondary">
                      PDF only · max 10 MB
                    </Typography>
                  </Box>
                  <input
                    type="file"
                    hidden
                    accept="application/pdf,.pdf"
                    onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
                  />
                </Box>
              </Box>

              <Button
                type="submit"
                variant="contained"
                color="success"
                size="large"
                startIcon={<SendOutlinedIcon />}
                fullWidth
                sx={{ textTransform: 'none', fontWeight: 700 }}
              >
                {paymentRejected ? 'Resubmit payment' : 'Submit payment'}
              </Button>
            </Stack>
          </Box>
        </WorkflowSection>

        <WorkflowSection title="eDO summary" subtitle="Document you are paying for.">
          <Stack spacing={1.5}>
            <Typography variant="body2">
              <strong>eDO:</strong>{' '}
              <Typography component="span" fontFamily="monospace" fontWeight={700}>
                {edo.edoNumber}
              </Typography>
            </Typography>
            <Typography variant="body2">
              <strong>Container:</strong> {edo.containerNumber ?? '—'}
            </Typography>
            <Typography variant="body2">
              <strong>Manifest:</strong> {manifest.manifestNumber}
            </Typography>
            <Typography variant="body2">
              <strong>Expires:</strong>{' '}
              {edo.expiresAt ? new Date(edo.expiresAt).toLocaleDateString() : '—'}
            </Typography>
          </Stack>
        </WorkflowSection>
      </Box>

      <Dialog open={confirmOpen} onClose={() => (submitting ? null : setConfirmOpen(false))} fullWidth maxWidth="xs">
        <DialogTitle>Confirm eDO payment</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={1.5}>
            <Typography variant="body2">
              Submit payment of <strong>{money(amount, currency)}</strong> for{' '}
              <strong>{edo.edoNumber}</strong>?
            </Typography>
            {receipt && (
              <Typography variant="caption" color="text.secondary">
                Receipt: {receipt.name}
              </Typography>
            )}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setConfirmOpen(false)} disabled={submitting} fullWidth>
            Cancel
          </Button>
          <Button
            variant="contained"
            color="success"
            onClick={() => void onConfirm()}
            disabled={submitting}
            startIcon={submitting ? undefined : <CreditCardOutlinedIcon />}
            fullWidth
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            {submitting ? 'Submitting…' : 'Yes, submit'}
          </Button>
        </DialogActions>
      </Dialog>
    </WorkflowPage>
  );
}
