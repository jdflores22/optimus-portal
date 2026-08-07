import { useMemo } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Paper,
  Stack,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import { Link as RouterLink, Navigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { useGetManifestQuery, useGetPaymentsByManifestQuery } from '../../app/api';
import { formatWorkflowState } from '../../shared/formatWorkflowState';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { ManifestPaymentHistoryTimeline } from './ManifestPaymentHistoryTimeline';
import {
  canResubmitFinalPayment,
  canViewManifestPaymentHistory,
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

export function ManifestPaymentHistoryPage() {
  const { id = '' } = useParams();
  const { user } = useSelector((state: RootState) => state.auth);
  const canView = canViewManifestPaymentHistory(user?.role);

  const { data, error, isLoading } = useGetManifestQuery(id, { skip: !id });
  const {
    data: payments = [],
    isFetching,
    isError: paymentsError,
  } = useGetPaymentsByManifestQuery(id, { skip: !id });

  const finalPayments = useMemo(() => finalPaymentsForManifest(payments), [payments]);
  const latest = latestFinalPayment(payments);
  const totalVersions = finalPayments.length;
  const totalRejections = finalPayments.filter((p) => /reject/i.test(p.status)).length;
  const currentVersion = latest?.version ?? totalVersions;
  const canResubmit = data ? canResubmitFinalPayment(user, data, latest) : false;

  if (!canView) return <Navigate to={`/manifests/${id}`} replace />;
  if (error) return <Alert severity="error">Manifest not found.</Alert>;
  if (isLoading || !data) return <Typography>Loading...</Typography>;

  if (user?.role === 'Broker' && data.brokerId && data.brokerId !== user.id) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }
  if (user?.role === 'Consignee' && data.consigneeId && data.consigneeId !== user.id) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  return (
    <WorkflowPage
      eyebrow="Final payment history"
      title={data.manifestNumber}
      subtitle="Track every payment submission, review status, and download receipts for this manifest."
      chips={
        <>
          <Chip size="small" label={formatWorkflowState(data.workflowState)} color="info" />
          {totalVersions > 0 ? (
            <Chip size="small" label={`${totalVersions} submission${totalVersions === 1 ? '' : 's'}`} color="info" />
          ) : (
            <Chip size="small" label="No submissions yet" variant="outlined" />
          )}
          {latest && <Chip size="small" label={statusLabel(latest.status)} color={statusTone(latest.status)} />}
          {totalRejections > 0 && (
            <Chip size="small" color="error" variant="outlined" label={`${totalRejections} rejection${totalRejections === 1 ? '' : 's'}`} />
          )}
        </>
      }
      actions={
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
          {canResubmit && (
            <Button
              component={RouterLink}
              to={`/manifests/${id}/final-payment`}
              variant="contained"
              color="error"
              startIcon={<ReceiptLongOutlinedIcon />}
            >
              {latest && /reject/i.test(latest.status) ? 'Resubmit Payment' : 'Submit Payment'}
            </Button>
          )}
          <Button component={RouterLink} to={`/manifests/${id}`} startIcon={<ArrowBackOutlinedIcon />}>
            Back to Manifest
          </Button>
        </Stack>
      }
      stats={
        totalVersions > 0
          ? [
              { label: 'Submissions', value: totalVersions, hint: 'Final payment attempts', tone: 'info' },
              { label: 'Current version', value: `v${currentVersion}`, hint: 'Latest attempt', tone: 'primary' },
              { label: 'Rejections', value: totalRejections, hint: 'Needs corrected receipt', tone: 'error' },
              {
                label: 'First submitted',
                value: formatWhen(finalPayments[0]?.createdAt).split(',')[0],
                hint: formatWhen(latest?.createdAt),
                tone: 'default',
              },
            ]
          : undefined
      }
    >
      {latest && /verif/i.test(latest.status) && (
        <Alert severity="success" sx={{ mb: 2 }}>
          Latest payment verified
          {latest.validatedAt ? ` on ${formatWhen(latest.validatedAt)}` : ''}
          {latest.validatedByName ? ` by ${latest.validatedByName}` : ''}.
        </Alert>
      )}
      {latest && /reject/i.test(latest.status) && (
        <Alert
          severity="error"
          sx={{ mb: 2 }}
          action={
            canResubmit ? (
              <Button
                color="inherit"
                size="small"
                component={RouterLink}
                to={`/manifests/${id}/final-payment`}
              >
                Resubmit
              </Button>
            ) : undefined
          }
        >
          Latest submission was rejected.
          {latest.rejectionReason ? ` ${latest.rejectionReason}` : ' Please resubmit with a corrected PDF receipt.'}
        </Alert>
      )}
      {latest && /pending/i.test(latest.status) && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Payment under accounting review. Version {latest.version ?? '—'} was submitted{' '}
          {formatWhen(latest.createdAt)}.
        </Alert>
      )}

      <Box
        sx={{
          display: 'grid',
          gap: 2.5,
          gridTemplateColumns: { xs: '1fr', xl: 'minmax(0, 3fr) minmax(280px, 2fr)' },
          alignItems: 'start',
        }}
      >
        <WorkflowSection
          title="Submission timeline"
          subtitle="Each version represents one payment receipt submitted for this manifest."
        >
          <ManifestPaymentHistoryTimeline
            manifestId={id}
            manifest={data}
            payments={payments}
            user={user}
            isFetching={isFetching}
            isError={paymentsError}
          />
        </WorkflowSection>

        <Stack spacing={2}>
          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Typography fontWeight={700} mb={1.5}>
              Manifest details
            </Typography>
            <Stack spacing={1.25}>
              <DetailRow label="Manifest" value={data.manifestNumber} mono />
              <DetailRow
                label="Billing amount"
                value={
                  data.billingTotal != null
                    ? money(data.billingTotal, data.billingCurrency || 'USD')
                    : '—'
                }
              />
              <DetailRow label="Consignee" value={data.consigneeName || '—'} />
              <DetailRow label="Broker" value={data.brokerName || '—'} />
              <DetailRow label="Workflow" value={formatWorkflowState(data.workflowState)} />
            </Stack>
            <Stack spacing={1} mt={2}>
              {(data.workflowState === 'BillingGenerated' || canResubmit) && (
                <Button
                  component={RouterLink}
                  to={`/manifests/${id}/final-payment`}
                  variant="outlined"
                  fullWidth
                  startIcon={<ReceiptLongOutlinedIcon />}
                >
                  Payment page
                </Button>
              )}
              <Button component={RouterLink} to={`/manifests/${id}`} variant="text" fullWidth>
                View documents
              </Button>
            </Stack>
          </Paper>

          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Typography fontWeight={700} mb={1.5}>
              Status guide
            </Typography>
            <Stack spacing={1.25}>
              <GuideRow tone="warning" label="Pending" text="Accounting is reviewing your receipt." />
              <GuideRow tone="success" label="Verified" text="Payment approved — official receipt available if issued." />
              <GuideRow tone="error" label="Rejected" text="Review the reason and resubmit a corrected PDF receipt." />
            </Stack>
          </Paper>

          <Paper
            elevation={0}
            sx={{
              p: 2.25,
              border: 1,
              borderColor: 'info.light',
              borderRadius: 2,
              bgcolor: 'rgba(2, 136, 209, 0.04)',
            }}
          >
            <Typography fontWeight={700} mb={0.75}>
              About versions
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Each resubmission creates a new version (v1, v2, v3…). Higher numbers are newer. Only the
              latest status affects your manifest workflow.
            </Typography>
          </Paper>
        </Stack>
      </Box>
    </WorkflowPage>
  );
}

function DetailRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <Stack direction="row" justifyContent="space-between" gap={2}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography
        variant="body2"
        fontWeight={700}
        textAlign="right"
        sx={mono ? { fontFamily: 'ui-monospace, monospace' } : undefined}
      >
        {value}
      </Typography>
    </Stack>
  );
}

function GuideRow({
  tone,
  label,
  text,
}: {
  tone: 'warning' | 'success' | 'error';
  label: string;
  text: string;
}) {
  return (
    <Stack direction="row" spacing={1.25} alignItems="flex-start">
      <Chip size="small" color={tone} label={label} sx={{ mt: 0.25 }} />
      <Typography variant="body2" color="text.secondary">
        {text}
      </Typography>
    </Stack>
  );
}
