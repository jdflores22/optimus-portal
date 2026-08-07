import { useEffect, useMemo, type ReactNode } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Paper,
  Stack,
  Tab,
  Tabs,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import OpenInNewOutlinedIcon from '@mui/icons-material/OpenInNewOutlined';
import PictureAsPdfOutlinedIcon from '@mui/icons-material/PictureAsPdfOutlined';
import QrCode2OutlinedIcon from '@mui/icons-material/QrCode2Outlined';
import { Link as RouterLink, useParams, useSearchParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { useGetEdoAuditQuery, useGetEdoQuery } from '../../app/api';
import { openEdoFile } from '../../shared/edoDownload';
import {
  edoCanDownload,
  edoDownloadBlockedMessage,
  edoNeedsPayment,
  formatEdoStatus,
} from '../../shared/formatEdoStatus';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

type TabKey = 'overview' | 'payment' | 'files' | 'activity';

const TAB_KEYS: TabKey[] = ['overview', 'payment', 'files', 'activity'];

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <Stack direction="row" justifyContent="space-between" spacing={2} py={0.75}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600} textAlign="right">
        {value}
      </Typography>
    </Stack>
  );
}

function formatWhen(value?: string | null) {
  return value ? new Date(value).toLocaleString() : '—';
}

function money(amount: number, currency = 'PHP') {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function statusTone(status: string): 'success' | 'warning' | 'error' | 'info' | 'default' {
  if (/released|active/i.test(status)) return 'success';
  if (/pending/i.test(status)) return 'warning';
  if (/reject|expired|locked/i.test(status)) return 'error';
  return 'info';
}

function parseTab(value: string | null): TabKey {
  if (value && TAB_KEYS.includes(value as TabKey)) {
    return value as TabKey;
  }
  return 'overview';
}

export function EdoDetailPage() {
  const { id = '' } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const from = searchParams.get('from');
  const tab = parseTab(searchParams.get('tab'));
  const { user, accessToken } = useSelector((state: RootState) => state.auth);

  const { data: edo, isLoading, error } = useGetEdoQuery(id, { skip: !id });
  const { data: audit = [] } = useGetEdoAuditQuery(id, { skip: !id });

  const backPath =
    from === 'release'
      ? '/edo/release'
      : from === 'validation'
        ? '/edo/payment-validation'
        : '/edo';

  const canPay = ['Broker', 'Consignee', 'SystemAdmin'].includes(user?.role ?? '');
  const canDownload = edo ? edoCanDownload(edo.status, user?.role) : false;
  const needsPayment = edo ? edoNeedsPayment(edo.status, edo.currentPaymentStatus) : false;

  const setTab = (next: TabKey) => {
    const params = new URLSearchParams(searchParams);
    params.set('tab', next);
    setSearchParams(params, { replace: true });
  };

  useEffect(() => {
    if (!searchParams.get('tab')) {
      const params = new URLSearchParams(searchParams);
      params.set('tab', 'overview');
      setSearchParams(params, { replace: true });
    }
  }, [searchParams, setSearchParams]);

  const openDocument = async (kind: 'download' | 'qr') => {
    if (!accessToken || !edo) return;
    await openEdoFile(edo.id, kind, accessToken);
  };

  const statusLabel = useMemo(
    () => (edo ? formatEdoStatus(edo.status, edo.currentPaymentStatus) : ''),
    [edo],
  );

  if (isLoading) {
    return <Typography py={4}>Loading eDO details…</Typography>;
  }

  if (error || !edo) {
    return (
      <Alert severity="error">
        Could not load this eDO/CRO. It may have been removed or you may not have access.
      </Alert>
    );
  }

  return (
    <WorkflowPage
      eyebrow="eDO / CRO details"
      title={edo.edoNumber}
      subtitle="Document record with payment validation, release status, files, and activity."
      chips={
        <>
          <Chip size="small" label={statusLabel} color={statusTone(edo.status)} />
          <Chip size="small" label={edo.manifestNumber} variant="outlined" sx={{ fontFamily: 'monospace' }} />
        </>
      }
      actions={
        <Button
          component={RouterLink}
          to={backPath}
          startIcon={<ArrowBackOutlinedIcon />}
          sx={{ textTransform: 'none' }}
        >
          Back
        </Button>
      }
      stats={[
        { label: 'Container', value: edo.containerNumber ?? '—', hint: 'Linked container', tone: 'info' },
        {
          label: 'Generated',
          value: new Date(edo.generatedAt).toLocaleDateString(),
          hint: formatWhen(edo.generatedAt),
          tone: 'primary',
        },
        {
          label: 'Released',
          value: edo.releasedAt ? new Date(edo.releasedAt).toLocaleDateString() : '—',
          hint: edo.releasedByName ?? 'Not released yet',
          tone: edo.releasedAt ? 'success' : 'warning',
        },
      ]}
    >
      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: 'hidden' }}>
        <Tabs
          value={tab}
          onChange={(_, value: TabKey) => setTab(value)}
          variant="scrollable"
          scrollButtons="auto"
          sx={{
            px: 1,
            borderBottom: 1,
            borderColor: 'divider',
            minHeight: 44,
            '& .MuiTab-root': { minHeight: 44, textTransform: 'none', fontWeight: 600 },
          }}
        >
          <Tab value="overview" label="Overview" />
          <Tab value="payment" label="Payment" />
          <Tab
            value="files"
            label={
              canDownload ? (
                <Stack direction="row" spacing={0.75} alignItems="center">
                  <span>Files</span>
                  <Chip size="small" label="Ready" color="success" sx={{ height: 20, fontWeight: 700 }} />
                </Stack>
              ) : (
                'Files'
              )
            }
          />
          <Tab
            value="activity"
            label={
              audit.length > 0 ? (
                <Stack direction="row" spacing={0.75} alignItems="center">
                  <span>Activity</span>
                  <Chip size="small" label={audit.length} color="primary" sx={{ height: 20, fontWeight: 700 }} />
                </Stack>
              ) : (
                'Activity'
              )
            }
          />
        </Tabs>

        <Box sx={{ p: { xs: 2, md: 2.5 } }}>
          {tab === 'overview' && (
            <WorkflowSection title="Document" subtitle="Core eDO/CRO identifiers and lifecycle dates.">
              <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
                <DetailRow label="eDO / CRO number" value={edo.edoNumber} />
                <DetailRow
                  label="Manifest"
                  value={
                    <Button
                      component={RouterLink}
                      to={`/manifests/${edo.manifestId}?tab=documents`}
                      size="small"
                      endIcon={<OpenInNewOutlinedIcon />}
                      sx={{ textTransform: 'none', p: 0, minWidth: 0 }}
                    >
                      {edo.manifestNumber}
                    </Button>
                  }
                />
                <DetailRow label="Container" value={edo.containerNumber ?? '—'} />
                <DetailRow label="CY location" value={edo.cyLocation ?? '—'} />
                <DetailRow label="Status" value={statusLabel} />
                <DetailRow label="Generated" value={formatWhen(edo.generatedAt)} />
                <DetailRow label="Expires" value={formatWhen(edo.expiresAt)} />
                <DetailRow label="Released" value={formatWhen(edo.releasedAt)} />
                <DetailRow label="Released by" value={edo.releasedByName ?? '—'} />
                {edo.rejectionReason && (
                  <DetailRow label="Rejection reason" value={edo.rejectionReason} />
                )}
              </Paper>
            </WorkflowSection>
          )}

          {tab === 'payment' && (
            <WorkflowSection title="Payment" subtitle="Broker eDO access fee and provider validation.">
              <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
                <DetailRow
                  label="Fee amount"
                  value={edo.feeAmount != null ? money(edo.feeAmount) : '—'}
                />
                <DetailRow label="Payment status" value={edo.currentPaymentStatus ?? '—'} />
                <DetailRow label="Submitted" value={formatWhen(edo.paymentSubmittedAt)} />
                <DetailRow label="Validated" value={formatWhen(edo.paymentValidatedAt)} />
                <DetailRow label="Validated by" value={edo.paymentValidatedByName ?? '—'} />
              </Paper>
              {canPay && needsPayment && (
                <Box mt={2}>
                  <Button
                    component={RouterLink}
                    to={`/manifests/${edo.manifestId}/edo-payment/${edo.id}`}
                    variant="contained"
                    color="warning"
                    sx={{ textTransform: 'none' }}
                  >
                    Pay eDO fee
                  </Button>
                </Box>
              )}
            </WorkflowSection>
          )}

          {tab === 'files' && (
            <WorkflowSection title="Files" subtitle="PDF and QR are available after release (or for platform admin).">
              {edoDownloadBlockedMessage(edo.status, user?.role, edo.currentPaymentStatus) && (
                <Alert severity="info" sx={{ mb: 2 }}>
                  {edoDownloadBlockedMessage(edo.status, user?.role, edo.currentPaymentStatus)}
                </Alert>
              )}
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                {canDownload && (
                  <>
                    <Button
                      variant="contained"
                      startIcon={<PictureAsPdfOutlinedIcon />}
                      onClick={() => void openDocument('download')}
                      sx={{ textTransform: 'none' }}
                    >
                      Download PDF
                    </Button>
                    {edo.qrImagePath && (
                      <Button
                        variant="outlined"
                        startIcon={<QrCode2OutlinedIcon />}
                        onClick={() => void openDocument('qr')}
                        sx={{ textTransform: 'none' }}
                      >
                        Download QR
                      </Button>
                    )}
                  </>
                )}
                {!canDownload && !needsPayment && (
                  <Typography variant="body2" color="text.secondary">
                    No files available for this document yet.
                  </Typography>
                )}
              </Stack>
            </WorkflowSection>
          )}

          {tab === 'activity' && (
            <WorkflowSection title="Activity trail" subtitle="Generation, payment, and release events.">
              {audit.length === 0 ? (
                <Typography variant="body2" color="text.secondary" py={2}>
                  No activity recorded yet.
                </Typography>
              ) : (
                <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
                  <Stack spacing={1.5}>
                    {audit.map((entry, index) => (
                      <Box
                        key={`${entry.at}-${index}`}
                        sx={{
                          pb: 1.5,
                          borderBottom: index < audit.length - 1 ? 1 : 0,
                          borderColor: 'divider',
                        }}
                      >
                        <Typography variant="body2" fontWeight={700}>
                          {entry.event}
                          {entry.to ? ` → ${entry.to}` : ''}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {formatWhen(entry.at)}
                          {entry.actor ? ` · ${entry.actor}` : ''}
                          {entry.notes ? ` · ${entry.notes}` : ''}
                        </Typography>
                      </Box>
                    ))}
                  </Stack>
                </Paper>
              )}
            </WorkflowSection>
          )}
        </Box>
      </Paper>
    </WorkflowPage>
  );
}
