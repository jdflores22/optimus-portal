import { useMemo, useState, useEffect } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Paper,
  Stack,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tabs,
  TextField,
  Typography,
} from '@mui/material';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import PictureAsPdfOutlinedIcon from '@mui/icons-material/PictureAsPdfOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import PersonAddAlt1OutlinedIcon from '@mui/icons-material/PersonAddAlt1Outlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import CreditCardOutlinedIcon from '@mui/icons-material/CreditCardOutlined';
import { useParams, Link as RouterLink, useSearchParams, useLocation } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import VerifiedOutlinedIcon from '@mui/icons-material/VerifiedOutlined';
import {
  useAssignBrokerMutation,
  useGetConsigneeBrokersQuery,
  useGetContainersQuery,
  useGetEdosQuery,
  useGetManifestHistoryQuery,
  useGetManifestQuery,
  useGetPaymentsByManifestQuery,
} from '../../app/api';
import { API_BASE_URL } from '../../shared/types';
import { openEdoFile } from '../../shared/edoDownload';
import { formatWorkflowState } from '../../shared/formatWorkflowState';
import {
  edoCanDownload,
  edoDownloadBlockedMessage,
  edoNeedsPayment,
  edoStatusChipColor,
  formatEdoStatus,
} from '../../shared/formatEdoStatus';
import { WorkflowHistoryTimeline } from '../shared/WorkflowHistoryTimeline';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { ManifestPaymentHistoryTimeline } from './ManifestPaymentHistoryTimeline';
import { finalPaymentsForManifest } from './manifestPaymentUtils';

type DocItem = {
  id: string;
  label: string;
  description: string;
  ready: boolean;
  path?: string | null;
  qrPath?: string | null;
  meta?: string;
  badgeLabel?: string;
  badgeColor?: 'success' | 'warning' | 'error' | 'default' | 'info';
  edoId?: string;
  edoStatus?: string;
  edoPaymentStatus?: string | null;
};

function fileUrl(path: string): string {
  return `${API_BASE_URL}${path.startsWith('/') ? '' : '/'}${path}`;
}

function teuFromSize(sizeCode?: string | null): number {
  if (!sizeCode) return 1;
  if (/40|45|hc/i.test(sizeCode)) return 2;
  return 1;
}

export function ManifestDetailPage() {
  const { id = '' } = useParams();
  const [searchParams] = useSearchParams();
  const location = useLocation();
  const flash = (location.state as { flash?: string } | null)?.flash;
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const isStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');
  const isBroker = user?.role === 'Broker' || user?.role === 'SystemAdmin';
  const isConsignee = user?.role === 'Consignee' || user?.role === 'SystemAdmin';
  const canPayEdo = isBroker || isConsignee;
  const isAccounting = user?.role === 'Accounting' || user?.role === 'SystemAdmin';
  const { data, error } = useGetManifestQuery(id, { skip: !id });
  const { data: history = [] } = useGetManifestHistoryQuery(id, { skip: !id });
  const {
    data: payments = [],
    isError: paymentsError,
    isFetching: paymentsFetching,
  } = useGetPaymentsByManifestQuery(id, { skip: !id });
  const { data: edos = [] } = useGetEdosQuery({ manifestId: id }, { skip: !id });
  const { data: containers = [] } = useGetContainersQuery();
  const [assignBroker, { isLoading: assigningBroker }] = useAssignBrokerMutation();
  const [tab, setTab] = useState(0);
  const [assignOpen, setAssignOpen] = useState(false);
  const [selectedBrokerId, setSelectedBrokerId] = useState('');
  const [assignError, setAssignError] = useState<string | null>(null);
  const [pageMessage, setPageMessage] = useState<string | null>(flash ?? null);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const openEdoDocument = async (edoId: string, kind: 'download' | 'qr') => {
    if (!accessToken) return;
    setDownloadError(null);
    try {
      await openEdoFile(edoId, kind, accessToken);
    } catch (e: unknown) {
      setDownloadError(e instanceof Error ? e.message : 'Could not open eDO file.');
    }
  };

  useEffect(() => {
    if (searchParams.get('tab') === 'documents') {
      setTab(1);
    }
  }, [searchParams]);

  useEffect(() => {
    if (flash) setPageMessage(flash);
  }, [flash]);

  const needsBroker = Boolean(
    data?.consigneeId &&
      !data?.brokerId &&
      data?.workflowState === 'NoaGenerated',
  );
  const { data: linkedBrokers = [], isFetching: brokersLoading } = useGetConsigneeBrokersQuery(
    data?.consigneeId ?? '',
    { skip: !data?.consigneeId || !needsBroker || !isConsignee },
  );

  const linkedContainers = useMemo(
    () => containers.filter((c) => c.manifestId === id),
    [containers, id],
  );
  const totalTeu = useMemo(
    () => linkedContainers.reduce((sum, c) => sum + teuFromSize(c.sizeCode), 0),
    [linkedContainers],
  );

  const waitingForBroker =
    isStaff &&
    data?.workflowState === 'NoaGenerated' &&
    !data.brokerId &&
    !data.blPdfPath &&
    !data.manifestFilePath;

  const canGenerateManifestBl =
    isStaff &&
    data?.workflowState === 'NoaGenerated' &&
    Boolean(data.brokerId) &&
    !data.blPdfPath &&
    !data.manifestFilePath;

  const canAssignBroker = isConsignee && needsBroker;

  const canUploadBl =
    isBroker &&
    data?.workflowState === 'BlGenerated' &&
    !data.blFilePath &&
    (user?.role === 'SystemAdmin' || !data.brokerId || data.brokerId === user?.id);

  const waitingForBlUpload =
    !isBroker &&
    data?.workflowState === 'BlGenerated' &&
    !data.blFilePath;

  const canGenerateBilling =
    isAccounting && data?.workflowState === 'BlUploaded' && !data.billingPdfPath;

  const waitingForBilling =
    !isAccounting && data?.workflowState === 'BlUploaded' && !data.billingPdfPath;

  const canSubmitPayment =
    (isBroker || isConsignee) &&
    data?.workflowState === 'BillingGenerated' &&
    Boolean(data.billingPdfPath);

  const waitingForPayment =
    isAccounting && data?.workflowState === 'BillingGenerated';

  const onAssignBroker = async () => {
    if (!selectedBrokerId) {
      setAssignError('Select a connected broker.');
      return;
    }
    setAssignError(null);
    try {
      await assignBroker({
        id,
        brokerId: selectedBrokerId,
      }).unwrap();
      setAssignOpen(false);
      setSelectedBrokerId('');
    } catch (err: unknown) {
      const msg =
        err && typeof err === 'object' && 'data' in err
          ? String((err as { data?: { error?: string } }).data?.error ?? 'Failed to assign broker.')
          : 'Failed to assign broker.';
      setAssignError(msg);
    }
  };

  const finalPayments = useMemo(() => finalPaymentsForManifest(payments), [payments]);

  const edoByContainer = useMemo(() => {
    const map = new Map<string, (typeof edos)[number]>();
    edos.forEach((edo) => {
      if (edo.containerNumber) {
        map.set(edo.containerNumber.trim().toUpperCase(), edo);
      }
    });
    return map;
  }, [edos]);

  const documents = useMemo<DocItem[]>(() => {
    if (!data) return [];
    const generatedBlPath = data.blPdfPath || data.manifestFilePath;
    const docs: DocItem[] = [
      {
        id: 'noa',
        label: 'Notice of Arrival (NOA)',
        description: 'Generated when the NOA is created for this manifest.',
        ready: Boolean(data.noaPdfPath),
        path: data.noaPdfPath,
        meta: data.noaNumber ?? undefined,
      },
      {
        id: 'manifest-bl',
        label: 'Manifest / Bill of Lading',
        description: 'Generated Manifest/BL PDF issued by the shipping line.',
        ready: Boolean(generatedBlPath),
        path: generatedBlPath,
        meta: data.manifestNumber ?? data.blNumber ?? undefined,
      },
      {
        id: 'bl-upload',
        label: 'Uploaded BL (broker)',
        description: 'Signed Bill of Lading file uploaded by the broker.',
        ready: Boolean(data.blFilePath),
        path: data.blFilePath,
        meta: data.blNumber ?? undefined,
      },
      {
        id: 'billing',
        label: 'Billing statement',
        description: 'Billing PDF prepared by accounting for payment collection.',
        ready: Boolean(data.billingPdfPath),
        path: data.billingPdfPath,
        meta:
          data.billingTotal != null
            ? `${data.billingTotal} ${data.billingCurrency ?? ''}`.trim()
            : undefined,
      },
    ];

    [...edos]
      .sort((a, b) => new Date(b.generatedAt).getTime() - new Date(a.generatedAt).getTime())
      .forEach((edo) => {
        docs.push({
          id: `edo-${edo.id}`,
          label: `eDO / CRO${edo.containerNumber ? ` · ${edo.containerNumber}` : ''}`,
          description: `Electronic delivery order · generated ${new Date(edo.generatedAt).toLocaleString()}`,
          ready: Boolean(edo.pdfPath),
          path: edo.pdfPath,
          qrPath: edo.qrImagePath,
          badgeLabel: formatEdoStatus(edo.status, edo.currentPaymentStatus),
          badgeColor: edoStatusChipColor(edo.status, edo.currentPaymentStatus),
          edoId: edo.id,
          edoStatus: edo.status,
          edoPaymentStatus: edo.currentPaymentStatus,
          meta: [
            edo.edoNumber,
            edo.expiresAt ? `expires ${new Date(edo.expiresAt).toLocaleDateString()}` : null,
          ]
            .filter(Boolean)
            .join(' · '),
        });
      });

    payments
      .filter((p) => p.officialReceiptPath || p.receiptFilePath)
      .forEach((p) => {
        docs.push({
          id: `payment-${p.id}`,
          label: `${p.paymentType} receipt`,
          description: `Payment artifact · ${p.status} · submitted by ${p.submittedByName}`,
          ready: true,
          path: p.officialReceiptPath || p.receiptFilePath,
          meta: `${p.amount} ${p.currency}`,
        });
      });

    return docs;
  }, [data, payments, edos]);

  const readyCount = documents.filter((d) => d.ready).length;

  if (error) return <Alert severity="error">Manifest not found.</Alert>;
  if (!data) return <Typography>Loading...</Typography>;

  return (
    <WorkflowPage
      eyebrow="Manifest workflow"
      title={data.manifestNumber}
      subtitle="Review the cargo record, supporting documents, and payment trail before moving the shipment into its next workflow step."
      chips={
        <>
          <Chip size="small" label={formatWorkflowState(data.workflowState)} color="primary" />
          <Chip size="small" label={data.vesselName ?? 'Vessel pending'} variant="outlined" />
        </>
      }
      stats={[
        { label: 'Workflow state', value: formatWorkflowState(data.workflowState), hint: 'Current cargo stage', tone: 'primary' },
        { label: 'Consignee', value: data.consigneeName ?? 'Pending', hint: 'Declared cargo owner', tone: data.consigneeName ? 'success' : 'warning' },
        { label: 'Broker', value: data.brokerName ?? 'Pending', hint: 'Assigned filing partner', tone: data.brokerName ? 'success' : 'warning' },
        {
          label: 'Containers',
          value: linkedContainers.length,
          hint: linkedContainers.length ? `${totalTeu} TEU linked` : 'No containers linked yet',
          tone: linkedContainers.length ? 'info' : 'warning',
        },
      ]}
    >
      {pageMessage && (
        <Alert severity="success" sx={{ mb: 1 }} onClose={() => setPageMessage(null)}>
          {pageMessage}
        </Alert>
      )}

      {downloadError && (
        <Alert severity="error" sx={{ mb: 1 }} onClose={() => setDownloadError(null)}>
          {downloadError}
        </Alert>
      )}

      <Tabs
        value={tab}
        onChange={(_, value: number) => setTab(value)}
        variant="scrollable"
        scrollButtons="auto"
        sx={{
          mb: 2.5,
          minHeight: 44,
          borderBottom: 1,
          borderColor: 'divider',
          '& .MuiTab-root': { minHeight: 44, textTransform: 'none', fontWeight: 600, px: 2 },
        }}
      >
        <Tab label="Overview" />
        <Tab
          label={
            <Stack direction="row" alignItems="center" spacing={1}>
              <span>Documents</span>
              <Chip
                size="small"
                label={`${readyCount}/${documents.length}`}
                color={readyCount > 0 ? 'primary' : 'default'}
                sx={{ height: 20, fontWeight: 700 }}
              />
            </Stack>
          }
        />
        <Tab
          label={
            <Stack direction="row" alignItems="center" spacing={1}>
              <span>Activity Log</span>
              {history.length > 0 && (
                <Chip size="small" label={history.length} color="primary" sx={{ height: 20, fontWeight: 700 }} />
              )}
            </Stack>
          }
        />
        <Tab
          label={
            <Stack direction="row" alignItems="center" spacing={1}>
              <span>Payments</span>
              {finalPayments.length > 0 && (
                <Chip
                  size="small"
                  label={finalPayments.length}
                  color="primary"
                  sx={{ height: 20, fontWeight: 700 }}
                />
              )}
            </Stack>
          }
        />
      </Tabs>

      {tab === 0 && (
        <Box
          sx={{
            display: 'grid',
            gap: 3,
            gridTemplateColumns: { xs: '1fr', xl: 'minmax(0, 2fr) minmax(280px, 360px)' },
          }}
        >
          <Stack spacing={3}>
            <WorkflowSection title="Shipment context" subtitle="Core manifest fields that determine who owns the next action.">
              <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' } }}>
                {[
                  ['Manifest number', data.manifestNumber],
                  ['NOA number', data.noaNumber ?? '—'],
                  ['Vessel', data.vesselName ?? '—'],
                  ['Voyage', data.voyageNumber ?? '—'],
                  ['Arrival', data.arrivalDate ? new Date(data.arrivalDate).toLocaleString() : '—'],
                  ['BL number', data.blNumber ?? '—'],
                  ['Consignee', data.consigneeName ?? '—'],
                  ['Broker', data.brokerName ?? '—'],
                ].map(([label, value]) => (
                  <Paper
                    key={label}
                    elevation={0}
                    sx={{
                      p: 1.75,
                      border: 1,
                      borderColor: 'divider',
                      borderRadius: 2,
                      bgcolor: 'background.default',
                    }}
                  >
                    <Typography variant="caption" color="text.secondary" fontWeight={600}>
                      {label}
                    </Typography>
                    <Typography fontWeight={700}>{value}</Typography>
                  </Paper>
                ))}
              </Box>
            </WorkflowSection>

            <WorkflowSection
              title="Containers"
              subtitle="Containers linked to this manifest for CY allocation and downstream eDO steps."
              actions={
                <Chip
                  size="small"
                  color={linkedContainers.length ? 'primary' : 'default'}
                  label={`${linkedContainers.length} · ${totalTeu} TEU`}
                  sx={{ fontWeight: 700 }}
                />
              }
            >
              {linkedContainers.length === 0 ? (
                <Alert severity="info" variant="outlined">
                  No containers are linked to this manifest yet. Add containers when creating the NOA, or assign them from
                  yard inventory.
                </Alert>
              ) : (
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>#</TableCell>
                      <TableCell>Container</TableCell>
                      <TableCell>Type</TableCell>
                      <TableCell>Size</TableCell>
                      <TableCell>TEU</TableCell>
                      <TableCell>CY / Location</TableCell>
                      <TableCell>Status</TableCell>
                      <TableCell>eDO</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {linkedContainers.map((c, index) => {
                      const edo = edoByContainer.get(c.containerNumber.trim().toUpperCase());
                      return (
                      <TableRow key={c.id}>
                        <TableCell>{index + 1}</TableCell>
                        <TableCell>
                          <Typography fontFamily="monospace" fontWeight={700}>
                            {c.containerNumber}
                          </Typography>
                        </TableCell>
                        <TableCell>{c.typeCode ?? '—'}</TableCell>
                        <TableCell>{c.sizeCode ?? '—'}</TableCell>
                        <TableCell>{teuFromSize(c.sizeCode)}</TableCell>
                        <TableCell>{c.cyTerminalName || c.currentLocation || '—'}</TableCell>
                        <TableCell>
                          <Chip size="small" label={c.allocationStatus || c.status} sx={{ fontWeight: 600 }} />
                        </TableCell>
                        <TableCell>
                          {edo ? (
                            <Chip
                              size="small"
                              label={formatEdoStatus(edo.status, edo.currentPaymentStatus)}
                              color={edoStatusChipColor(edo.status, edo.currentPaymentStatus)}
                              sx={{ fontWeight: 600 }}
                            />
                          ) : (
                            <Typography variant="caption" color="text.secondary">
                              Not generated
                            </Typography>
                          )}
                        </TableCell>
                      </TableRow>
                    );
                    })}
                  </TableBody>
                </Table>
              )}
            </WorkflowSection>
          </Stack>

          <Stack spacing={3}>
            <WorkflowSection
              title="Quick actions"
              subtitle="Next workflow steps available for this manifest."
            >
              <Stack spacing={1.25}>
                {waitingForBroker && (
                  <Alert severity="warning" variant="outlined">
                    Waiting for the consignee to assign a broker before Manifest/BL can be generated.
                  </Alert>
                )}
                {waitingForBlUpload && (
                  <Alert severity="info" variant="outlined">
                    Waiting for the assigned broker to upload the Bill of Lading document.
                  </Alert>
                )}
                {waitingForBilling && (
                  <Alert severity="info" variant="outlined">
                    BL uploaded. Waiting for accounting to generate billing.
                  </Alert>
                )}
                {waitingForPayment && (
                  <Alert severity="info" variant="outlined">
                    Billing generated. Waiting for the broker (or consignee) to submit payment.
                  </Alert>
                )}
                {canGenerateManifestBl && (
                  <Button
                    component={RouterLink}
                    to={`/manifests/${id}/generate-manifest`}
                    variant="contained"
                    color="success"
                    fullWidth
                    startIcon={<PictureAsPdfOutlinedIcon />}
                  >
                    Generate Manifest/BL
                  </Button>
                )}
                {canAssignBroker && (
                  <Button
                    variant="contained"
                    color="primary"
                    fullWidth
                    startIcon={<PersonAddAlt1OutlinedIcon />}
                    onClick={() => {
                      setAssignError(null);
                      setSelectedBrokerId(linkedBrokers.length === 1 ? linkedBrokers[0].id : '');
                      setAssignOpen(true);
                    }}
                  >
                    Assign broker
                  </Button>
                )}
                {canUploadBl && (
                  <Button
                    component={RouterLink}
                    to={`/manifests/${id}/upload-bl`}
                    variant="contained"
                    color="primary"
                    fullWidth
                    startIcon={<UploadFileOutlinedIcon />}
                  >
                    Upload BL Document
                  </Button>
                )}
                {canGenerateBilling && (
                  <Button
                    component={RouterLink}
                    to={`/manifests/${id}/generate-billing`}
                    variant="contained"
                    color="warning"
                    fullWidth
                    startIcon={<ReceiptLongOutlinedIcon />}
                  >
                    Generate Billing
                  </Button>
                )}
                {canSubmitPayment && (
                  <Button
                    component={RouterLink}
                    to={`/manifests/${id}/final-payment`}
                    variant="contained"
                    color="error"
                    fullWidth
                    startIcon={<ReceiptLongOutlinedIcon />}
                  >
                    Submit Payment
                    {data.billingTotal != null
                      ? ` · ${data.billingTotal} ${data.billingCurrency ?? ''}`.trimEnd()
                      : ''}
                  </Button>
                )}
                {(finalPayments.length > 0 ||
                  data.workflowState === 'BillingGenerated' ||
                  data.workflowState === 'PaymentSubmitted' ||
                  data.workflowState === 'PaymentVerified') && (
                  <Button
                    onClick={() => setTab(3)}
                    variant="outlined"
                    fullWidth
                    startIcon={<ReceiptLongOutlinedIcon />}
                  >
                    Payment History
                  </Button>
                )}
                {!canGenerateManifestBl &&
                  !canAssignBroker &&
                  !canUploadBl &&
                  !canGenerateBilling &&
                  !canSubmitPayment &&
                  !waitingForBroker &&
                  !waitingForBlUpload &&
                  !waitingForBilling &&
                  !waitingForPayment && (
                  <Alert severity="info" variant="outlined">
                    No actions available at this workflow stage.
                  </Alert>
                )}
                <Button component={RouterLink} to="/manifests" variant="outlined" fullWidth>
                  Back to manifests
                </Button>
              </Stack>
            </WorkflowSection>

            <WorkflowSection
              title="Payment trail"
              subtitle="Final payment submissions and validation status for this manifest."
              actions={
                finalPayments.length > 0 ? (
                  <Button size="small" onClick={() => setTab(3)} sx={{ textTransform: 'none' }}>
                    View full history
                  </Button>
                ) : undefined
              }
            >
              <Stack spacing={1.25}>
                {paymentsError && (
                  <Alert severity="error" variant="outlined">
                    Could not load payments for this manifest.
                  </Alert>
                )}
                {!paymentsError && finalPayments.length === 0 ? (
                  <Alert severity="info" variant="outlined">
                    No final payments have been submitted for this manifest yet.
                  </Alert>
                ) : (
                  finalPayments.map((p) => (
                    <Paper
                      key={p.id}
                      elevation={0}
                      sx={{
                        p: 1.75,
                        border: 1,
                        borderColor: 'divider',
                        borderRadius: 2,
                        bgcolor: 'background.default',
                      }}
                    >
                      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap mb={0.5}>
                        <Typography fontWeight={700}>
                          Final payment · {p.amount} {p.currency}
                        </Typography>
                        {p.version ? <Chip size="small" label={`v${p.version}`} /> : null}
                        <Chip
                          size="small"
                          color={
                            /verif/i.test(p.status)
                              ? 'success'
                              : /reject/i.test(p.status)
                                ? 'error'
                                : 'warning'
                          }
                          label={p.status}
                        />
                      </Stack>
                      <Typography variant="body2" color="text.secondary">
                        Submitted by {p.submittedByName} ·{' '}
                        {new Date(p.createdAt).toLocaleString()}
                      </Typography>
                      {p.rejectionReason && (
                        <Alert severity="error" variant="outlined" sx={{ mt: 1 }}>
                          {p.rejectionReason}
                        </Alert>
                      )}
                      {(p.officialReceiptPath || p.receiptFilePath) && (
                        <Button
                          size="small"
                          href={fileUrl((p.officialReceiptPath || p.receiptFilePath)!)}
                          target="_blank"
                          sx={{ mt: 1 }}
                        >
                          Open receipt
                        </Button>
                      )}
                    </Paper>
                  ))
                )}
              </Stack>
            </WorkflowSection>
          </Stack>
        </Box>
      )}

      {tab === 1 && (
        <WorkflowSection
          title="Documents"
          subtitle="Generated and uploaded artifacts used downstream in the cargo workflow."
          actions={
            <Chip
              size="small"
              color={readyCount ? 'success' : 'default'}
              label={`${readyCount} ready`}
              sx={{ fontWeight: 700 }}
            />
          }
        >
          <Box
            sx={{
              display: 'grid',
              gap: 1.5,
              gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' },
            }}
          >
            {documents.map((doc) => (
              <Paper
                key={doc.id}
                elevation={0}
                sx={{
                  p: 2,
                  border: 1,
                  borderColor: 'divider',
                  borderRadius: 2,
                  bgcolor: 'background.paper',
                  opacity: doc.ready ? 1 : 0.85,
                }}
              >
                <Stack direction="row" spacing={1.5} alignItems="flex-start">
                  <Box
                    sx={{
                      width: 40,
                      height: 40,
                      borderRadius: 2,
                      display: 'grid',
                      placeItems: 'center',
                      flexShrink: 0,
                      bgcolor: doc.ready ? 'primary.main' : 'action.hover',
                      color: doc.ready ? 'primary.contrastText' : 'text.secondary',
                    }}
                  >
                    {doc.id.startsWith('payment') ? (
                      <ReceiptLongOutlinedIcon fontSize="small" />
                    ) : doc.id.startsWith('edo') ? (
                      <VerifiedOutlinedIcon fontSize="small" />
                    ) : doc.ready ? (
                      <PictureAsPdfOutlinedIcon fontSize="small" />
                    ) : (
                      <DescriptionOutlinedIcon fontSize="small" />
                    )}
                  </Box>
                  <Box minWidth={0} flex={1}>
                    <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                      <Typography fontWeight={700}>{doc.label}</Typography>
                      <Chip
                        size="small"
                        label={doc.badgeLabel ?? (doc.ready ? 'Ready' : 'Pending')}
                        color={doc.badgeColor ?? (doc.ready ? 'success' : 'default')}
                        sx={{ height: 22, fontWeight: 700 }}
                      />
                    </Stack>
                    <Typography variant="body2" color="text.secondary" mt={0.5}>
                      {doc.description}
                    </Typography>
                    {doc.meta && (
                      <Typography variant="caption" color="text.secondary" display="block" mt={0.75} fontWeight={600}>
                        {doc.meta}
                      </Typography>
                    )}
                    <Box mt={1.5}>
                      {doc.id.startsWith('edo-') ? (
                        <Stack spacing={1}>
                          {doc.edoStatus &&
                            edoDownloadBlockedMessage(doc.edoStatus, user?.role, doc.edoPaymentStatus) && (
                            <Typography variant="caption" color="text.secondary" fontWeight={600}>
                              {edoDownloadBlockedMessage(doc.edoStatus, user?.role, doc.edoPaymentStatus)}
                            </Typography>
                          )}
                          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                            {doc.edoId &&
                              doc.edoStatus &&
                              canPayEdo &&
                              edoNeedsPayment(doc.edoStatus, doc.edoPaymentStatus) && (
                              <Button
                                size="small"
                                variant="contained"
                                color="warning"
                                component={RouterLink}
                                to={`/manifests/${id}/edo-payment/${doc.edoId}`}
                                startIcon={<CreditCardOutlinedIcon />}
                                sx={{ textTransform: 'none', fontWeight: 600 }}
                              >
                                Pay now
                              </Button>
                            )}
                            {doc.edoId &&
                              doc.edoStatus &&
                              edoCanDownload(doc.edoStatus, user?.role) && (
                                <>
                                  <Button
                                    size="small"
                                    variant="outlined"
                                    startIcon={<PictureAsPdfOutlinedIcon />}
                                    onClick={() => void openEdoDocument(doc.edoId!, 'download')}
                                    sx={{ textTransform: 'none' }}
                                  >
                                    Download PDF
                                  </Button>
                                  {doc.qrPath && (
                                    <Button
                                      size="small"
                                      variant="text"
                                      onClick={() => void openEdoDocument(doc.edoId!, 'qr')}
                                      sx={{ textTransform: 'none' }}
                                    >
                                      QR code
                                    </Button>
                                  )}
                                </>
                              )}
                          </Stack>
                        </Stack>
                      ) : doc.ready && doc.path ? (
                        <Button
                          size="small"
                          variant="outlined"
                          href={fileUrl(doc.path)}
                          target="_blank"
                          rel="noopener noreferrer"
                          startIcon={<PictureAsPdfOutlinedIcon />}
                        >
                          Open / download
                        </Button>
                      ) : (
                        <Typography variant="caption" color="text.disabled" fontWeight={600}>
                          Not available yet
                        </Typography>
                      )}
                    </Box>
                  </Box>
                </Stack>
              </Paper>
            ))}
          </Box>
        </WorkflowSection>
      )}

      {tab === 2 && (
        <WorkflowSection title="Activity log" subtitle="Track state changes and see which role touched the manifest.">
          <Box sx={{ maxWidth: 720 }}>
            <WorkflowHistoryTimeline history={history} />
          </Box>
        </WorkflowSection>
      )}

      {tab === 3 && data && (
        <WorkflowSection
          title="Payment history"
          subtitle="Every final payment submission, review outcome, and receipt for this manifest."
          actions={
            finalPayments.length > 0 ? (
              <Button
                component={RouterLink}
                to={`/manifests/${id}/payment-history`}
                size="small"
                sx={{ textTransform: 'none' }}
              >
                Open full page
              </Button>
            ) : undefined
          }
        >
          <ManifestPaymentHistoryTimeline
            manifestId={id}
            manifest={data}
            payments={payments}
            user={user}
            isFetching={paymentsFetching}
            isError={paymentsError}
          />
        </WorkflowSection>
      )}

      <Dialog open={assignOpen} onClose={() => (assigningBroker ? null : setAssignOpen(false))} fullWidth maxWidth="sm">
        <DialogTitle>Assign broker</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2}>
            <Alert severity="info" variant="outlined">
              At NOA Generated, choose an active broker connected to your account. That broker will process this cargo
              transaction and receive workflow notifications.
            </Alert>
            {assignError && <Alert severity="error">{assignError}</Alert>}
            <TextField
              select
              label="Connected broker"
              value={selectedBrokerId}
              onChange={(e) => setSelectedBrokerId(e.target.value)}
              fullWidth
              disabled={brokersLoading || linkedBrokers.length === 0}
              helperText={
                brokersLoading
                  ? 'Loading brokers...'
                  : linkedBrokers.length === 0
                    ? 'No active broker relationship for this consignee.'
                    : 'Only brokers linked via referral are listed.'
              }
            >
              {linkedBrokers.map((broker) => (
                <MenuItem key={broker.id} value={broker.id}>
                  {broker.fullName} ({broker.email})
                </MenuItem>
              ))}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setAssignOpen(false)} disabled={assigningBroker}>
            Cancel
          </Button>
          <Button
            variant="contained"
            onClick={onAssignBroker}
            disabled={assigningBroker || !selectedBrokerId}
            startIcon={<PersonAddAlt1OutlinedIcon />}
          >
            {assigningBroker ? 'Assigning...' : 'Confirm assign'}
          </Button>
        </DialogActions>
      </Dialog>
    </WorkflowPage>
  );
}
