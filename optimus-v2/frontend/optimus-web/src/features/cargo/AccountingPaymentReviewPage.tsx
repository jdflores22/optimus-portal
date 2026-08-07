import { useMemo, useState, type ReactNode } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Grid,
  Paper,
  Stack,
  Tab,
  Tabs,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import CancelOutlinedIcon from '@mui/icons-material/CancelOutlined';
import WarningAmberOutlinedIcon from '@mui/icons-material/WarningAmberOutlined';
import CircularProgress from '@mui/material/CircularProgress';
import { Link as RouterLink, Navigate, useNavigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGetManifestQuery,
  useGetPaymentQuery,
  useValidatePaymentMutation,
} from '../../app/api';
import { API_BASE_URL } from '../../shared/types';
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

function paymentStatusTone(status: string): 'warning' | 'success' | 'error' | 'default' {
  if (/pending/i.test(status)) return 'warning';
  if (/verified|approved/i.test(status)) return 'success';
  if (/reject/i.test(status)) return 'error';
  return 'default';
}

type ModalStep = 'confirm' | 'loading' | 'success';

export function AccountingPaymentReviewPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const isAccounting = user?.role === 'Accounting' || user?.role === 'SystemAdmin';

  const { data: payment, error, isLoading } = useGetPaymentQuery(id, { skip: !id || !isAccounting });
  const manifestId = payment?.manifestId ?? '';
  const { data: manifest } = useGetManifestQuery(manifestId, { skip: !manifestId });
  const [validatePayment, { isLoading: validating }] = useValidatePaymentMutation();

  const [docTab, setDocTab] = useState(0);
  const [approveOpen, setApproveOpen] = useState(false);
  const [approveStep, setApproveStep] = useState<ModalStep>('confirm');
  const [approvalNote, setApprovalNote] = useState('');
  const [rejectOpen, setRejectOpen] = useState(false);
  const [rejectStep, setRejectStep] = useState<ModalStep>('confirm');
  const [rejectionReason, setRejectionReason] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  const billingTotal = manifest?.billingTotal ?? null;
  const billingCurrency = manifest?.billingCurrency ?? payment?.currency ?? 'USD';
  const discrepancy =
    billingTotal != null && payment ? Math.abs(payment.amount - billingTotal) : null;
  const amountsMatch = discrepancy == null || discrepancy <= 0.01;
  const isPending = payment ? /pending/i.test(payment.status) : false;

  const receiptUrl = payment?.receiptFilePath ? fileUrl(payment.receiptFilePath) : null;
  const billingUrl = manifest?.billingPdfPath ? fileUrl(manifest.billingPdfPath) : null;

  const statusLabel = useMemo(() => {
    if (!payment) return '';
    if (/pending/i.test(payment.status)) return 'Needs review';
    if (/verified|approved/i.test(payment.status)) return 'Approved';
    if (/reject/i.test(payment.status)) return 'Rejected';
    return payment.status;
  }, [payment]);

  if (!isAccounting) return <Navigate to="/payments" replace />;
  if (error) return <Alert severity="error">Payment not found.</Alert>;
  if (isLoading || !payment) return <Typography p={3}>Loading...</Typography>;

  const openApproveModal = () => {
    setActionError(null);
    setApprovalNote('');
    setApproveStep('confirm');
    setApproveOpen(true);
  };

  const closeApproveModal = () => {
    if (validating) return;
    setApproveOpen(false);
    setApproveStep('confirm');
    setApprovalNote('');
  };

  const openRejectModal = () => {
    setActionError(null);
    setRejectionReason('');
    setRejectStep('confirm');
    setRejectOpen(true);
  };

  const closeRejectModal = () => {
    if (validating) return;
    setRejectOpen(false);
    setRejectStep('confirm');
    setRejectionReason('');
  };

  const handleApprove = async () => {
    if (!amountsMatch && !approvalNote.trim()) {
      setActionError('Please add a note explaining why you are approving despite the amount mismatch.');
      return;
    }
    setActionError(null);
    setApproveStep('loading');
    try {
      await validatePayment({
        id: payment.id,
        approve: true,
        rejectionReason: approvalNote.trim() || undefined,
      }).unwrap();
      setApproveStep('success');
      window.setTimeout(() => navigate('/payments'), 1600);
    } catch {
      setApproveStep('confirm');
      setActionError('Could not approve payment. Please try again.');
    }
  };

  const handleReject = async () => {
    if (!rejectionReason.trim()) {
      setActionError('Please provide a rejection reason.');
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
      window.setTimeout(() => navigate('/payments'), 1600);
    } catch {
      setRejectStep('confirm');
      setActionError('Could not reject payment. Please try again.');
    }
  };

  return (
    <WorkflowPage
      eyebrow="Accounting"
      title={`Review Payment — ${payment.manifestNumber}`}
      subtitle={`Payment submitted by ${payment.submittedByName}. Compare the broker receipt against the billing invoice, then approve or reject.`}
      chips={
        <>
          <Chip size="small" color={paymentStatusTone(payment.status)} label={statusLabel} />
          {payment.version ? (
            <Chip size="small" variant="outlined" label={`Attempt #${payment.version}`} />
          ) : null}
          {!amountsMatch && (
            <Chip
              size="small"
              color="warning"
              icon={<WarningAmberOutlinedIcon />}
              label="Amount mismatch"
            />
          )}
        </>
      }
      actions={
        <Button
          component={RouterLink}
          to="/payments"
          startIcon={<ArrowBackOutlinedIcon />}
          sx={{ textTransform: 'none', fontWeight: 600 }}
        >
          Back to list
        </Button>
      }
    >
      {actionError && !approveOpen && !rejectOpen && <Alert severity="error">{actionError}</Alert>}

      {isPending && (
        <Alert severity="warning" icon={<WarningAmberOutlinedIcon />}>
          Review this payment — check that the broker&apos;s receipt matches the billing amount, then
          approve or reject.
        </Alert>
      )}

      <Grid container spacing={3}>
        <Grid item xs={12} lg={5}>
          <WorkflowSection title="Payment details" subtitle="Submitted receipt and billing comparison.">
            <Stack spacing={2}>
              <Paper variant="outlined" sx={{ p: 2 }}>
                <Typography variant="overline" color="text.secondary">
                  Manifest
                </Typography>
                <Typography variant="h6" fontFamily="monospace">
                  {payment.manifestNumber}
                </Typography>
                <Button
                  component={RouterLink}
                  to={`/manifests/${payment.manifestId}`}
                  size="small"
                  sx={{ mt: 1, textTransform: 'none' }}
                >
                  Open manifest
                </Button>
              </Paper>

              <Paper variant="outlined" sx={{ p: 2 }}>
                <Stack spacing={1.5}>
                  <Row label="Type" value={payment.paymentType} />
                  <Row label="Submitted by" value={payment.submittedByName} />
                  <Row
                    label="Submitted at"
                    value={new Date(payment.createdAt).toLocaleString()}
                  />
                  <Row
                    label="Payment amount"
                    value={`${money(payment.amount, payment.currency)} ${payment.currency}`}
                    strong
                  />
                  {billingTotal != null && (
                    <Row
                      label="Billing amount"
                      value={`${money(billingTotal, billingCurrency)} ${billingCurrency}`}
                      strong
                    />
                  )}
                  <Row
                    label="Match"
                    value={
                      amountsMatch ? (
                        <Chip size="small" color="success" label="Match" />
                      ) : (
                        <Chip
                          size="small"
                          color="warning"
                          label={`Diff ${money(discrepancy!, billingCurrency)}`}
                        />
                      )
                    }
                  />
                  {payment.rejectionReason && (
                    <Row label="Rejection reason" value={payment.rejectionReason} />
                  )}
                  {payment.validatedByName && (
                    <Row
                      label="Validated by"
                      value={`${payment.validatedByName}${
                        payment.validatedAt
                          ? ` · ${new Date(payment.validatedAt).toLocaleString()}`
                          : ''
                      }`}
                    />
                  )}
                </Stack>
              </Paper>

              {manifest && billingTotal != null && (
                <Paper variant="outlined" sx={{ p: 2 }}>
                  <Typography variant="subtitle2" gutterBottom>
                    Billing breakdown
                  </Typography>
                  <Stack spacing={0.75}>
                    {manifest.billingFreightCharges != null && (
                      <Row
                        label="Freight"
                        value={money(manifest.billingFreightCharges, billingCurrency)}
                      />
                    )}
                    {manifest.billingThcCharges != null && (
                      <Row label="THC" value={money(manifest.billingThcCharges, billingCurrency)} />
                    )}
                    {manifest.billingAdditionalCharges != null &&
                      manifest.billingAdditionalCharges > 0 && (
                        <Row
                          label="Additional"
                          value={money(manifest.billingAdditionalCharges, billingCurrency)}
                        />
                      )}
                    {manifest.billingExchangeRate != null && billingCurrency === 'USD' && (
                      <Row label="FX rate" value={String(manifest.billingExchangeRate)} />
                    )}
                  </Stack>
                </Paper>
              )}

              {isPending && (
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
                  <Button
                    variant="contained"
                    color="success"
                    fullWidth
                    startIcon={<CheckCircleOutlineOutlinedIcon />}
                    onClick={openApproveModal}
                    sx={{ textTransform: 'none', fontWeight: 600 }}
                  >
                    Approve payment
                  </Button>
                  <Button
                    variant="outlined"
                    color="error"
                    fullWidth
                    startIcon={<CancelOutlinedIcon />}
                    onClick={openRejectModal}
                    sx={{ textTransform: 'none', fontWeight: 600 }}
                  >
                    Reject payment
                  </Button>
                </Stack>
              )}
            </Stack>
          </WorkflowSection>
        </Grid>

        <Grid item xs={12} lg={7}>
          <WorkflowSection
            title="Documents"
            subtitle="Compare the submitted receipt against the billing PDF side by side."
          >
            <Tabs value={docTab} onChange={(_, v) => setDocTab(v)} sx={{ mb: 2 }}>
              <Tab label="Payment receipt" />
              <Tab label="Billing invoice" />
            </Tabs>

            {docTab === 0 && (
              <Box>
                {receiptUrl ? (
                  <Box
                    component="iframe"
                    src={receiptUrl}
                    title="Payment receipt"
                    sx={{
                      width: '100%',
                      height: { xs: 420, md: 640 },
                      border: 1,
                      borderColor: 'divider',
                      borderRadius: 1,
                      bgcolor: 'background.default',
                    }}
                  />
                ) : (
                  <Alert severity="info">No receipt file attached to this payment.</Alert>
                )}
              </Box>
            )}

            {docTab === 1 && (
              <Box>
                {billingUrl ? (
                  <Box
                    component="iframe"
                    src={billingUrl}
                    title="Billing invoice"
                    sx={{
                      width: '100%',
                      height: { xs: 420, md: 640 },
                      border: 1,
                      borderColor: 'divider',
                      borderRadius: 1,
                      bgcolor: 'background.default',
                    }}
                  />
                ) : (
                  <Alert severity="info">Billing PDF is not available for this manifest.</Alert>
                )}
              </Box>
            )}
          </WorkflowSection>
        </Grid>
      </Grid>

      <Dialog
        open={approveOpen}
        onClose={closeApproveModal}
        maxWidth="sm"
        fullWidth
        disableEscapeKeyDown={validating || approveStep === 'loading'}
      >
        {approveStep === 'confirm' && (
          <>
            <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <CheckCircleOutlineOutlinedIcon color="success" />
              Approve this payment?
            </DialogTitle>
            <DialogContent dividers>
              <Stack spacing={2}>
                <Typography variant="body2" color="text.secondary">
                  The broker and consignee will be notified. The shipment moves forward for eDO
                  processing.
                </Typography>

                <Paper variant="outlined" sx={{ p: 2, bgcolor: 'background.default' }}>
                  <Stack spacing={1.5}>
                    <Row label="Manifest" value={payment.manifestNumber} strong />
                    <Row label="Submitted by" value={payment.submittedByName} />
                    {billingTotal != null && (
                      <Row
                        label="Billing amount"
                        value={`${money(billingTotal, billingCurrency)} ${billingCurrency}`}
                        strong
                      />
                    )}
                    <Row
                      label="Payment amount"
                      value={`${money(payment.amount, payment.currency)} ${payment.currency}`}
                      strong
                    />
                    <Row
                      label="Match"
                      value={
                        amountsMatch ? (
                          <Chip size="small" color="success" label="Match" />
                        ) : (
                          <Chip
                            size="small"
                            color="warning"
                            label={`Diff ${money(discrepancy!, billingCurrency)}`}
                          />
                        )
                      }
                    />
                  </Stack>
                </Paper>

                {!amountsMatch && (
                  <Alert severity="warning">
                    Amounts do not match — please explain why you are approving anyway.
                  </Alert>
                )}

                {actionError && approveOpen && <Alert severity="error">{actionError}</Alert>}

                <TextField
                  label={
                    !amountsMatch
                      ? 'Approval note (required)'
                      : 'Approval note (optional)'
                  }
                  value={approvalNote}
                  onChange={(e) => setApprovalNote(e.target.value)}
                  multiline
                  minRows={3}
                  fullWidth
                  required={!amountsMatch}
                  placeholder="e.g. Verified with bank confirmation"
                />
              </Stack>
            </DialogContent>
            <DialogActions sx={{ px: 3, py: 2 }}>
              <Button onClick={closeApproveModal} fullWidth sx={{ textTransform: 'none' }}>
                Cancel
              </Button>
              <Button
                variant="contained"
                color="success"
                fullWidth
                disabled={!amountsMatch && !approvalNote.trim()}
                onClick={() => void handleApprove()}
                sx={{ textTransform: 'none', fontWeight: 600 }}
              >
                Yes, approve
              </Button>
            </DialogActions>
          </>
        )}

        {approveStep === 'loading' && (
          <DialogContent sx={{ py: 6, textAlign: 'center' }}>
            <CircularProgress color="success" size={48} />
            <Typography fontWeight={700} mt={2}>
              Approving payment…
            </Typography>
            <Typography variant="body2" color="text.secondary" mt={0.5}>
              Please wait while we process your decision.
            </Typography>
          </DialogContent>
        )}

        {approveStep === 'success' && (
          <DialogContent sx={{ py: 6, textAlign: 'center' }}>
            <CheckCircleOutlineOutlinedIcon color="success" sx={{ fontSize: 56 }} />
            <Typography variant="h6" fontWeight={800} color="success.main" mt={1}>
              Payment approved!
            </Typography>
            <Typography variant="body2" color="text.secondary" mt={0.5}>
              Returning to the payments list…
            </Typography>
          </DialogContent>
        )}
      </Dialog>

      <Dialog
        open={rejectOpen}
        onClose={closeRejectModal}
        maxWidth="sm"
        fullWidth
        disableEscapeKeyDown={validating || rejectStep === 'loading'}
      >
        {rejectStep === 'confirm' && (
          <>
            <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <CancelOutlinedIcon color="error" />
              Reject this payment?
            </DialogTitle>
            <DialogContent dividers>
              <Stack spacing={2}>
                <Typography variant="body2" color="text.secondary">
                  The broker will need to upload a new payment proof. Tell them what was wrong.
                </Typography>

                <Paper variant="outlined" sx={{ p: 2, bgcolor: 'background.default' }}>
                  <Stack spacing={1.5}>
                    <Row label="Manifest" value={payment.manifestNumber} strong />
                    <Row label="Submitted by" value={payment.submittedByName} />
                    <Row
                      label="Payment amount"
                      value={`${money(payment.amount, payment.currency)} ${payment.currency}`}
                      strong
                    />
                  </Stack>
                </Paper>

                {actionError && rejectOpen && <Alert severity="error">{actionError}</Alert>}

                <TextField
                  label="Rejection reason (required)"
                  value={rejectionReason}
                  onChange={(e) => setRejectionReason(e.target.value)}
                  multiline
                  minRows={4}
                  fullWidth
                  required
                  placeholder="e.g. Receipt amount does not match billing"
                />
              </Stack>
            </DialogContent>
            <DialogActions sx={{ px: 3, py: 2 }}>
              <Button onClick={closeRejectModal} fullWidth sx={{ textTransform: 'none' }}>
                Cancel
              </Button>
              <Button
                variant="contained"
                color="error"
                fullWidth
                disabled={!rejectionReason.trim()}
                onClick={() => void handleReject()}
                sx={{ textTransform: 'none', fontWeight: 600 }}
              >
                Yes, reject
              </Button>
            </DialogActions>
          </>
        )}

        {rejectStep === 'loading' && (
          <DialogContent sx={{ py: 6, textAlign: 'center' }}>
            <CircularProgress color="error" size={48} />
            <Typography fontWeight={700} mt={2}>
              Rejecting payment…
            </Typography>
            <Typography variant="body2" color="text.secondary" mt={0.5}>
              Please wait while we process your decision.
            </Typography>
          </DialogContent>
        )}

        {rejectStep === 'success' && (
          <DialogContent sx={{ py: 6, textAlign: 'center' }}>
            <CancelOutlinedIcon color="warning" sx={{ fontSize: 56 }} />
            <Typography variant="h6" fontWeight={800} color="warning.main" mt={1}>
              Payment rejected
            </Typography>
            <Typography variant="body2" color="text.secondary" mt={0.5}>
              The broker has been notified. Returning to the payments list…
            </Typography>
          </DialogContent>
        )}
      </Dialog>
    </WorkflowPage>
  );
}

function Row({
  label,
  value,
  strong,
}: {
  label: string;
  value: ReactNode;
  strong?: boolean;
}) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={2}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      {typeof value === 'string' ? (
        <Typography variant="body2" fontWeight={strong ? 700 : 400}>
          {value}
        </Typography>
      ) : (
        value
      )}
    </Stack>
  );
}
