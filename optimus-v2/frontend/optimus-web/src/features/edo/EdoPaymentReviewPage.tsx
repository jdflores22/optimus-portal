import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Grid,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import CancelOutlinedIcon from '@mui/icons-material/CancelOutlined';
import { Link as RouterLink, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { useGetEdoPaymentQuery, useValidateEdoPaymentMutation } from '../../app/api';
import { loadEdoPaymentReceiptBlob } from '../../shared/edoPaymentReceipt';
import { formatEdoStatus } from '../../shared/formatEdoStatus';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { TableViewLink } from '../shared/TableViewLink';

function money(amount: number, currency: string) {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function paymentStatusTone(status: string): 'warning' | 'success' | 'error' | 'default' {
  if (/pending/i.test(status)) return 'warning';
  if (/verified|approved/i.test(status)) return 'success';
  if (/reject/i.test(status)) return 'error';
  return 'default';
}

type ModalStep = 'confirm' | 'loading' | 'success';

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <Stack direction="row" justifyContent="space-between" spacing={2}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600} textAlign="right">
        {value}
      </Typography>
    </Stack>
  );
}

export function EdoPaymentReviewPage() {
  const { id = '' } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const backPath = searchParams.get('from') === 'validation' ? '/edo/payment-validation' : '/edo/payment-validation';
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const canValidate = user?.role === 'SystemAdmin';

  const { data: payment, error, isLoading, refetch } = useGetEdoPaymentQuery(id, {
    skip: !id || !canValidate,
  });
  const [validatePayment, { isLoading: validating }] = useValidateEdoPaymentMutation();

  const [receiptUrl, setReceiptUrl] = useState<string | null>(null);
  const [receiptError, setReceiptError] = useState<string | null>(null);
  const [receiptLoading, setReceiptLoading] = useState(false);

  const [approveOpen, setApproveOpen] = useState(false);
  const [approveStep, setApproveStep] = useState<ModalStep>('confirm');
  const [rejectOpen, setRejectOpen] = useState(false);
  const [rejectStep, setRejectStep] = useState<ModalStep>('confirm');
  const [rejectionReason, setRejectionReason] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  const isPending = payment ? /pending/i.test(payment.status) : false;

  const statusLabel = useMemo(() => {
    if (!payment) return '';
    if (/pending/i.test(payment.status)) return 'Needs review';
    if (/verified/i.test(payment.status)) return 'Verified';
    if (/reject/i.test(payment.status)) return 'Rejected';
    return payment.status;
  }, [payment]);

  useEffect(() => {
    if (!payment?.receiptFilePath || !accessToken || !isPending) {
      setReceiptUrl(null);
      return;
    }

    let active = true;
    let objectUrl: string | null = null;
    setReceiptLoading(true);
    setReceiptError(null);

    void loadEdoPaymentReceiptBlob(payment.id, accessToken)
      .then(({ url }) => {
        if (!active) {
          URL.revokeObjectURL(url);
          return;
        }
        objectUrl = url;
        setReceiptUrl(url);
      })
      .catch(() => {
        if (active) setReceiptError('Could not load payment receipt.');
      })
      .finally(() => {
        if (active) setReceiptLoading(false);
      });

    return () => {
      active = false;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [payment, accessToken, isPending]);

  if (!canValidate) return <Navigate to="/edo/payment-validation" replace />;
  if (error) return <Alert severity="error">Payment not found.</Alert>;
  if (isLoading || !payment) return <Typography p={3}>Loading...</Typography>;

  const onApprove = async () => {
    setActionError(null);
    setApproveStep('loading');
    try {
      await validatePayment({ id: payment.id, approve: true }).unwrap();
      setApproveStep('success');
      await refetch();
    } catch (e: unknown) {
      setApproveStep('confirm');
      setActionError((e as { data?: { message?: string } })?.data?.message ?? 'Approval failed.');
    }
  };

  const onReject = async () => {
    if (!rejectionReason.trim()) {
      setActionError('Rejection reason is required.');
      return;
    }
    setActionError(null);
    setRejectStep('loading');
    try {
      await validatePayment({
        id: payment.id,
        approve: false,
        rejectionReason: rejectionReason.trim(),
      }).unwrap();
      setRejectStep('success');
      await refetch();
    } catch (e: unknown) {
      setRejectStep('confirm');
      setActionError((e as { data?: { message?: string } })?.data?.message ?? 'Rejection failed.');
    }
  };

  return (
    <WorkflowPage
      eyebrow="eDO payment validation"
      title="Confirm eDO / CRO payment"
      subtitle="Review the broker receipt, verify the fee amount, then approve or reject the payment."
      chips={
        <>
          <Chip size="small" label={payment.edoNumber ?? 'eDO'} sx={{ fontFamily: 'monospace' }} />
          <Chip size="small" label={statusLabel} color={paymentStatusTone(payment.status)} />
          <Chip size="small" label={money(payment.amount, payment.currency)} color="warning" />
        </>
      }
      actions={
        <Stack direction="row" spacing={1} alignItems="center">
          <Button
            component={RouterLink}
            to={backPath}
            startIcon={<ArrowBackOutlinedIcon />}
            sx={{ textTransform: 'none' }}
          >
            Back
          </Button>
          {payment.edoId && (
            <TableViewLink to={`/edo/${payment.edoId}?from=validation`} />
          )}
        </Stack>
      }
      stats={[
        { label: 'Manifest', value: payment.manifestNumber ?? '—', hint: 'Linked manifest', tone: 'primary' },
        { label: 'Container', value: payment.containerNumber ?? '—', hint: 'eDO container', tone: 'info' },
        {
          label: 'Submitted',
          value: new Date(payment.createdAt).toLocaleDateString(),
          hint: payment.submittedByName ?? 'Broker',
          tone: 'warning',
        },
      ]}
    >
      {actionError && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setActionError(null)}>
          {actionError}
        </Alert>
      )}

      {!isPending && (
        <Alert severity={/reject/i.test(payment.status) ? 'warning' : 'success'} sx={{ mb: 2 }}>
          {/reject/i.test(payment.status)
            ? `Payment was rejected${payment.rejectionReason ? `: ${payment.rejectionReason}` : '.'}`
            : 'Payment has been verified. The eDO can proceed to release once ready.'}
        </Alert>
      )}

      <Grid container spacing={2.5}>
        <Grid item xs={12} lg={7}>
          <WorkflowSection title="Payment receipt" subtitle="Uploaded proof of payment from the broker.">
            {receiptLoading && (
              <Box display="flex" justifyContent="center" py={8}>
                <CircularProgress />
              </Box>
            )}
            {receiptError && <Alert severity="error">{receiptError}</Alert>}
            {!receiptLoading && receiptUrl && (
              <Paper
                variant="outlined"
                sx={{ height: 520, overflow: 'hidden', borderRadius: 2, bgcolor: 'grey.100' }}
              >
                <Box
                  component="iframe"
                  src={receiptUrl}
                  title="Payment receipt"
                  sx={{ width: '100%', height: '100%', border: 0, bgcolor: 'background.paper' }}
                />
              </Paper>
            )}
            {!receiptLoading && !receiptUrl && !receiptError && (
              <Typography variant="body2" color="text.secondary">
                No receipt file attached.
              </Typography>
            )}
          </WorkflowSection>
        </Grid>

        <Grid item xs={12} lg={5}>
          <Stack spacing={2.5}>
            <WorkflowSection title="Payment details" subtitle="Compare against the configured eDO access fee.">
              <Stack spacing={1.25}>
                <DetailRow label="Amount" value={money(payment.amount, payment.currency)} />
                <DetailRow label="Submitted by" value={payment.submittedByName ?? '—'} />
                <DetailRow
                  label="Submitted at"
                  value={new Date(payment.createdAt).toLocaleString()}
                />
                <DetailRow label="eDO number" value={payment.edoNumber ?? '—'} />
                <DetailRow
                  label="eDO status"
                  value={formatEdoStatus(payment.edoStatus ?? '', payment.status)}
                />
                {payment.validatedAt && (
                  <DetailRow
                    label="Validated at"
                    value={new Date(payment.validatedAt).toLocaleString()}
                  />
                )}
                {payment.validatedByName && (
                  <DetailRow label="Validated by" value={payment.validatedByName} />
                )}
              </Stack>
            </WorkflowSection>

            {isPending && (
              <WorkflowSection title="Decision" subtitle="Approve to move the eDO to pending release.">
                <Stack spacing={1.25}>
                  <Button
                    variant="contained"
                    color="success"
                    startIcon={<CheckCircleOutlineOutlinedIcon />}
                    onClick={() => {
                      setActionError(null);
                      setApproveStep('confirm');
                      setApproveOpen(true);
                    }}
                    sx={{ textTransform: 'none', fontWeight: 700 }}
                  >
                    Approve payment
                  </Button>
                  <Button
                    variant="outlined"
                    color="error"
                    startIcon={<CancelOutlinedIcon />}
                    onClick={() => {
                      setActionError(null);
                      setRejectionReason('');
                      setRejectStep('confirm');
                      setRejectOpen(true);
                    }}
                    sx={{ textTransform: 'none', fontWeight: 700 }}
                  >
                    Reject payment
                  </Button>
                </Stack>
              </WorkflowSection>
            )}
          </Stack>
        </Grid>
      </Grid>

      <Dialog open={approveOpen} onClose={() => (validating ? null : setApproveOpen(false))} fullWidth maxWidth="xs">
        <DialogTitle>Approve eDO payment</DialogTitle>
        <DialogContent dividers>
          {approveStep === 'confirm' && (
            <Typography variant="body2">
              Confirm payment of <strong>{money(payment.amount, payment.currency)}</strong> for{' '}
              <strong>{payment.edoNumber}</strong>? The eDO will move to pending release.
            </Typography>
          )}
          {approveStep === 'loading' && (
            <Stack alignItems="center" py={3} spacing={2}>
              <CircularProgress />
              <Typography>Verifying payment…</Typography>
            </Stack>
          )}
          {approveStep === 'success' && (
            <Alert severity="success">Payment approved. Return to the release queue to release the eDO.</Alert>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          {approveStep === 'confirm' && (
            <>
              <Button onClick={() => setApproveOpen(false)} fullWidth>
                Cancel
              </Button>
              <Button variant="contained" color="success" onClick={() => void onApprove()} fullWidth>
                Approve
              </Button>
            </>
          )}
          {approveStep === 'success' && (
            <Button
              variant="contained"
              fullWidth
              onClick={() => {
                setApproveOpen(false);
                navigate('/edo/payment-validation');
              }}
            >
              Back to queue
            </Button>
          )}
        </DialogActions>
      </Dialog>

      <Dialog open={rejectOpen} onClose={() => (validating ? null : setRejectOpen(false))} fullWidth maxWidth="sm">
        <DialogTitle>Reject eDO payment</DialogTitle>
        <DialogContent dividers>
          {rejectStep === 'confirm' && (
            <Stack spacing={2}>
              <Typography variant="body2">
                Reject payment for <strong>{payment.edoNumber}</strong>? The broker will need to resubmit.
              </Typography>
              <TextField
                label="Rejection reason"
                value={rejectionReason}
                onChange={(e) => setRejectionReason(e.target.value)}
                multiline
                minRows={3}
                required
                fullWidth
              />
            </Stack>
          )}
          {rejectStep === 'loading' && (
            <Stack alignItems="center" py={3} spacing={2}>
              <CircularProgress color="error" />
              <Typography>Rejecting payment…</Typography>
            </Stack>
          )}
          {rejectStep === 'success' && (
            <Alert severity="warning">Payment rejected. The broker can upload a new receipt.</Alert>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          {rejectStep === 'confirm' && (
            <>
              <Button onClick={() => setRejectOpen(false)} fullWidth>
                Cancel
              </Button>
              <Button variant="contained" color="error" onClick={() => void onReject()} fullWidth>
                Reject
              </Button>
            </>
          )}
          {rejectStep === 'success' && (
            <Button variant="contained" fullWidth onClick={() => setRejectOpen(false)}>
              Close
            </Button>
          )}
        </DialogActions>
      </Dialog>
    </WorkflowPage>
  );
}
