import { useMemo, useState, useEffect } from 'react';
import { useTheme } from '@mui/material/styles';
import { pageHeroGradient } from '../../shared/theme';
import {
  Alert,
  Box,
  Button,
  Chip,
  Collapse,
  Paper,
  Stack,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tabs,
  Typography,
} from '@mui/material';
import CreditCardOutlinedIcon from '@mui/icons-material/CreditCardOutlined';
import HourglassEmptyOutlinedIcon from '@mui/icons-material/HourglassEmptyOutlined';
import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import RefreshOutlinedIcon from '@mui/icons-material/RefreshOutlined';
import BusinessOutlinedIcon from '@mui/icons-material/BusinessOutlined';
import SupportAgentOutlinedIcon from '@mui/icons-material/SupportAgentOutlined';
import CloudUploadOutlinedIcon from '@mui/icons-material/CloudUploadOutlined';
import LocalShippingOutlinedIcon from '@mui/icons-material/LocalShippingOutlined';
import { Link as RouterLink, useLocation, useNavigate } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGetAccreditationsQuery,
  useGetActiveFormQuery,
  useGetEdosQuery,
  useGetManifestsQuery,
  useGetWorkspacesQuery,
  useSubmitEdoPaymentMutation,
  useGetActivePaymentFeeQuery,
} from '../../app/api';
import type { EdoDto, ManifestDto } from '../../shared/types';
import { formatWorkflowState } from '../../shared/formatWorkflowState';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

type EdoTab = 'payment' | 'pending' | 'ready';

function toneForWorkflow(state: string): 'default' | 'info' | 'warning' | 'success' | 'error' {
  if (/released|generated|verified|completed/i.test(state)) return 'success';
  if (/billing|payment|pending/i.test(state)) return 'warning';
  if (/noa|bl/i.test(state)) return 'info';
  if (/reject|deny|fail/i.test(state)) return 'error';
  return 'default';
}

function isPaymentNeeded(status: string) {
  return /pending.?release/i.test(status);
}

function isPendingValidation(status: string) {
  return /pending.?validation/i.test(status);
}

function isReadyDownload(status: string) {
  return /released|^active$/i.test(status);
}

function formatDate(iso?: string | null) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

export function BrokerDashboardPage() {
  const theme = useTheme();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const location = useLocation();
  const switchedFromState = (location.state as { switchedWorkspace?: string } | null)?.switchedWorkspace;

  const [switchBanner, setSwitchBanner] = useState<{ name: string; open: boolean } | null>(
    switchedFromState ? { name: switchedFromState, open: true } : null,
  );
  const [edoTab, setEdoTab] = useState<EdoTab>('payment');
  const [payMessage, setPayMessage] = useState<string | null>(null);
  const [payError, setPayError] = useState<string | null>(null);

  useEffect(() => {
    if (!switchedFromState) return;
    setSwitchBanner({ name: switchedFromState, open: true });
    // Clear history state so refresh / back doesn't resurface the banner.
    navigate(location.pathname, { replace: true, state: {} });
  }, [switchedFromState, navigate, location.pathname]);

  useEffect(() => {
    if (!switchBanner?.open) return;
    const t = window.setTimeout(() => {
      setSwitchBanner((prev) => (prev ? { ...prev, open: false } : null));
    }, 3500);
    return () => window.clearTimeout(t);
  }, [switchBanner?.open, switchBanner?.name]);

  const {
    data: manifests = [],
    isFetching: manifestsFetching,
    refetch: refetchManifests,
  } = useGetManifestsQuery();
  const { data: edos = [], isFetching: edosFetching, refetch: refetchEdos } = useGetEdosQuery();
  const { data: workspaces = [] } = useGetWorkspacesQuery();
  const { data: accreditations = [] } = useGetAccreditationsQuery();
  const { data: activeForm } = useGetActiveFormQuery('Broker');
  const { data: edoFee } = useGetActivePaymentFeeQuery('edo');
  const [submitPayment, { isLoading: paying }] = useSubmitEdoPaymentMutation();

  const activeWorkspace = useMemo(
    () => workspaces.find((w) => w.id === user?.activeWorkspaceConsigneeId),
    [workspaces, user?.activeWorkspaceConsigneeId],
  );

  const mySas = useMemo(
    () => accreditations.find((a) => a.applicantId === user?.id),
    [accreditations, user?.id],
  );

  const filteredEdos = useMemo(() => edos, [edos]);

  const filteredManifests = useMemo(() => manifests, [manifests]);

  const paymentNeeded = useMemo(
    () => filteredEdos.filter((e) => isPaymentNeeded(e.status)),
    [filteredEdos],
  );
  const pendingValidation = useMemo(
    () => filteredEdos.filter((e) => isPendingValidation(e.status)),
    [filteredEdos],
  );
  const readyToDownload = useMemo(
    () => filteredEdos.filter((e) => isReadyDownload(e.status)),
    [filteredEdos],
  );

  const recentManifests = useMemo(
    () =>
      [...filteredManifests]
        .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
        .slice(0, 8),
    [filteredManifests],
  );

  const edosByManifest = useMemo(() => {
    const map = new Map<string, EdoDto[]>();
    for (const e of filteredEdos) {
      const list = map.get(e.manifestId) ?? [];
      list.push(e);
      map.set(e.manifestId, list);
    }
    return map;
  }, [filteredEdos]);

  const tabEdos =
    edoTab === 'payment' ? paymentNeeded : edoTab === 'pending' ? pendingValidation : readyToDownload;

  const accredApproved = Boolean(mySas && /approv/i.test(mySas.status));
  const workspaceName =
    activeWorkspace?.businessName || activeWorkspace?.fullName || 'No workspace selected';

  const onRefresh = () => {
    void refetchManifests();
    void refetchEdos();
  };

  const onPay = async (edo: EdoDto) => {
    setPayError(null);
    setPayMessage(null);
    try {
      await submitPayment({
        edoId: edo.id,
        amount: edo.feeAmount ?? edoFee?.amount ?? 750,
        currency: 'PHP',
      }).unwrap();
      setPayMessage(`Payment submitted for ${edo.edoNumber}`);
      void refetchEdos();
      setEdoTab('pending');
    } catch (e: unknown) {
      setPayError((e as { data?: { message?: string } })?.data?.message ?? 'Payment failed');
    }
  };

  const kpis = [
    {
      id: 'pay',
      label: 'Payment Needed',
      value: paymentNeeded.length,
      hint: 'eDOs awaiting payment',
      color: '#C62828',
      bg: 'rgba(198,40,40,0.08)',
      icon: CreditCardOutlinedIcon,
      onSelect: () => setEdoTab('payment') as void,
    },
    {
      id: 'pending',
      label: 'Pending Validation',
      value: pendingValidation.length,
      hint: 'Payments under review',
      color: '#EF6C00',
      bg: 'rgba(239,108,0,0.08)',
      icon: HourglassEmptyOutlinedIcon,
      onSelect: () => setEdoTab('pending') as void,
    },
    {
      id: 'ready',
      label: 'Ready to Download',
      value: readyToDownload.length,
      hint: 'Verified eDOs',
      color: '#2E7D32',
      bg: 'rgba(46,125,50,0.08)',
      icon: CheckCircleOutlineOutlinedIcon,
      onSelect: () => setEdoTab('ready') as void,
    },
    {
      id: 'manifests',
      label: 'Manifests',
      value: filteredManifests.length,
      hint: 'In this workspace',
      color: '#546E7A',
      bg: 'rgba(84,110,122,0.08)',
      icon: DescriptionOutlinedIcon,
      to: '/manifests',
    },
  ] as const;

  return (
    <Stack spacing={3}>
      {/* Hero */}
      <Paper
        elevation={0}
        sx={{
          p: { xs: 2.5, sm: 3.5 },
          borderRadius: 3,
          border: 1,
          borderColor: 'divider',
          background: pageHeroGradient(theme.palette.mode),
        }}
      >
        <Stack
          direction={{ xs: 'column', lg: 'row' }}
          justifyContent="space-between"
          alignItems={{ lg: 'flex-end' }}
          spacing={2.5}
        >
          <Box minWidth={0}>
            <Chip
              size="small"
              label="Broker Workspace"
              color="secondary"
              sx={{ mb: 1.5, fontWeight: 600 }}
            />
            <Typography variant="h4" fontWeight={800} lineHeight={1.2}>
              Welcome, {user?.fullName || 'Broker'}
            </Typography>
            <Typography color="text.secondary" mt={1} maxWidth={640}>
              Process manifests, submit eDO payments, and manage cargo release for your linked
              consignee companies.
            </Typography>
            <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1} mt={2}>
              <Chip
                size="small"
                icon={<BusinessOutlinedIcon />}
                label={workspaceName}
                color="secondary"
                variant="outlined"
              />
              {workspaces.length > 1 && (
                <Chip
                  size="small"
                  label={`${workspaces.length} workspaces`}
                  component={RouterLink}
                  to="/workspace"
                  clickable
                />
              )}
              {accredApproved ? (
                <Chip size="small" color="success" label="Broker accredited" />
              ) : mySas ? (
                <Chip
                  size="small"
                  color="warning"
                  label={mySas.status.replace(/_/g, ' ')}
                  component={RouterLink}
                  to="/sas"
                  clickable
                />
              ) : (
                <Chip
                  size="small"
                  color="error"
                  label="Accreditation required"
                  component={RouterLink}
                  to="/sas"
                  clickable
                />
              )}
              {paymentNeeded.length > 0 && (
                <Chip
                  size="small"
                  color="error"
                  icon={<CreditCardOutlinedIcon />}
                  label={`${paymentNeeded.length} payment${paymentNeeded.length > 1 ? 's' : ''} due`}
                  onClick={() => setEdoTab('payment')}
                />
              )}
            </Stack>
          </Box>
          <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1} justifyContent={{ lg: 'flex-end' }}>
            <Button
              component={RouterLink}
              to="/payments"
              variant="contained"
              color="error"
              startIcon={<CreditCardOutlinedIcon />}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              eDO Payments
            </Button>
            <Button
              component={RouterLink}
              to="/manifests"
              variant="outlined"
              color="primary"
              startIcon={<DescriptionOutlinedIcon />}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              All Manifests
            </Button>
            <Button
              variant="outlined"
              color="inherit"
              startIcon={<RefreshOutlinedIcon />}
              onClick={onRefresh}
              disabled={manifestsFetching || edosFetching}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              Refresh
            </Button>
          </Stack>
        </Stack>
      </Paper>

      <Collapse
        in={Boolean(switchBanner?.open)}
        unmountOnExit
        onExited={() => setSwitchBanner(null)}
      >
        <Alert severity="success" onClose={() => setSwitchBanner((prev) => (prev ? { ...prev, open: false } : null))}>
          Switched to workspace: {switchBanner?.name}
        </Alert>
      </Collapse>

      {!user?.activeWorkspaceConsigneeId && (
        <Alert
          severity="warning"
          action={
            <Button component={RouterLink} to="/workspace" color="inherit" size="small">
              Select workspace
            </Button>
          }
        >
          Choose a consignee workspace to see manifests and eDOs for that account.
        </Alert>
      )}

      {!accredApproved && (
        <Alert
          severity={mySas ? 'info' : 'warning'}
          action={
            <Button component={RouterLink} to="/sas" color="inherit" size="small">
              {mySas ? 'View SAS' : 'Submit now'}
            </Button>
          }
        >
          {mySas
            ? `Accreditation: ${mySas.status}. Some actions may be limited while under review.`
            : activeForm
              ? `Broker accreditation required. Active form: ${activeForm.name} v${activeForm.version}.`
              : 'Broker accreditation required. Submit your SAS to unlock full broker services.'}
        </Alert>
      )}

      {payMessage && (
        <Alert severity="success" onClose={() => setPayMessage(null)}>
          {payMessage}
        </Alert>
      )}
      {payError && (
        <Alert severity="error" onClose={() => setPayError(null)}>
          {payError}
        </Alert>
      )}

      {/* KPI cards */}
      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr 1fr', md: 'repeat(4, 1fr)' },
        }}
      >
        {kpis.map((kpi) => {
          const Icon = kpi.icon;
          const linkProps =
            'to' in kpi && kpi.to
              ? { component: RouterLink, to: kpi.to }
              : {
                  component: 'button' as const,
                  type: 'button' as const,
                  onClick: 'onSelect' in kpi ? kpi.onSelect : undefined,
                };
          return (
            <Paper
              key={kpi.id}
              elevation={0}
              {...linkProps}
              sx={{
                p: 2.25,
                border: 1,
                borderColor: 'divider',
                borderRadius: 2,
                textDecoration: 'none',
                color: 'inherit',
                display: 'block',
                width: '100%',
                textAlign: 'left',
                cursor: 'pointer',
                bgcolor: 'background.paper',
                font: 'inherit',
                transition: 'border-color .15s, transform .15s',
                '&:hover': { borderColor: kpi.color, transform: 'translateY(-1px)' },
              }}
            >
              <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
                <Box>
                  <Typography variant="caption" color="text.secondary" fontWeight={600}>
                    {kpi.label}
                  </Typography>
                  <Typography variant="h4" fontWeight={800} sx={{ color: kpi.color, mt: 0.5 }}>
                    {kpi.value}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {kpi.hint}
                  </Typography>
                </Box>
                <Box
                  sx={{
                    width: 40,
                    height: 40,
                    borderRadius: 2,
                    bgcolor: kpi.bg,
                    color: kpi.color,
                    display: 'grid',
                    placeItems: 'center',
                  }}
                >
                  <Icon fontSize="small" />
                </Box>
              </Stack>
            </Paper>
          );
        })}
      </Box>

      {/* Main + sidebar */}
      <Box
        sx={{
          display: 'grid',
          gap: 2.5,
          gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 2fr) minmax(260px, 1fr)' },
          alignItems: 'start',
        }}
      >
        <Stack spacing={2.5} minWidth={0}>
          {/* Recent Manifests */}
          <Paper elevation={0} sx={{ border: 1, borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="center"
              px={2.5}
              py={2}
            >
              <Box>
                <Typography variant="h6" fontWeight={700}>
                  Recent Manifests
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  Latest NOA-linked manifests in this workspace
                </Typography>
              </Box>
              <Button component={RouterLink} to="/manifests" size="small" sx={{ textTransform: 'none' }}>
                View all
              </Button>
            </Stack>
            <Box sx={{ overflowX: 'auto' }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>MANIFEST</TableCell>
                    <TableCell>VESSEL</TableCell>
                    <TableCell>STATUS</TableCell>
                    <TableCell>EDOS</TableCell>
                    <TableCell>PAYMENT</TableCell>
                    <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {recentManifests.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={6}>
                        <Typography variant="body2" color="text.secondary" py={2} textAlign="center">
                          No manifests in this workspace yet.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  ) : (
                    recentManifests.map((m) => (
                      <ManifestRow key={m.id} manifest={m} edos={edosByManifest.get(m.id) ?? []} />
                    ))
                  )}
                </TableBody>
              </Table>
            </Box>
          </Paper>

          {/* eDO Activity */}
          <Paper elevation={0} sx={{ border: 1, borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="center"
              px={2.5}
              pt={2}
              pb={1}
            >
              <Box>
                <Typography variant="h6" fontWeight={700}>
                  eDO Activity
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  Payments and downloads in this workspace
                </Typography>
              </Box>
              <Button component={RouterLink} to="/edo" size="small" sx={{ textTransform: 'none' }}>
                View all
              </Button>
            </Stack>
            <Tabs
              value={edoTab}
              onChange={(_, v: EdoTab) => setEdoTab(v)}
              variant="scrollable"
              scrollButtons="auto"
              sx={{ px: 1.5, borderBottom: 1, borderColor: 'divider' }}
            >
              <Tab
                value="payment"
                label={`Payment needed (${paymentNeeded.length})`}
                sx={{ textTransform: 'none', fontWeight: 600 }}
              />
              <Tab
                value="pending"
                label={`Pending (${pendingValidation.length})`}
                sx={{ textTransform: 'none', fontWeight: 600 }}
              />
              <Tab
                value="ready"
                label={`Ready (${readyToDownload.length})`}
                sx={{ textTransform: 'none', fontWeight: 600 }}
              />
            </Tabs>
            <Box sx={{ overflowX: 'auto' }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>EDO #</TableCell>
                    <TableCell>CONTAINER</TableCell>
                    <TableCell>MANIFEST</TableCell>
                    <TableCell>GENERATED</TableCell>
                    <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {tabEdos.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={5}>
                        <Typography variant="body2" color="text.secondary" py={2} textAlign="center">
                          No eDOs in this tab.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  ) : (
                    tabEdos.slice(0, 8).map((edo) => (
                      <TableRow key={edo.id} hover>
                        <TableCell>
                          <Typography fontWeight={600} variant="body2">
                            {edo.edoNumber}
                          </Typography>
                        </TableCell>
                        <TableCell>{edo.containerNumber || '—'}</TableCell>
                        <TableCell>{edo.manifestNumber}</TableCell>
                        <TableCell>{formatDate(edo.generatedAt)}</TableCell>
                        <TableCell align="right">
                          {edoTab === 'payment' && (
                            <Button
                              size="small"
                              variant="contained"
                              color="error"
                              startIcon={<CreditCardOutlinedIcon />}
                              disabled={paying}
                              onClick={() => void onPay(edo)}
                              sx={{ textTransform: 'none', fontWeight: 600 }}
                            >
                              Pay
                            </Button>
                          )}
                          {edoTab === 'pending' && (
                            <Chip size="small" color="warning" label="Under review" />
                          )}
                          {edoTab === 'ready' && <TableViewLink to={`/edo/${edo.id}`} />}
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </Box>
          </Paper>
        </Stack>

        {/* Sidebar cards */}
        <Stack spacing={2}>
          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Typography variant="subtitle2" fontWeight={700} mb={1.5}>
              Quick Actions
            </Typography>
            <Stack spacing={1}>
              <Button
                component={RouterLink}
                to="/manifests"
                variant="outlined"
                fullWidth
                startIcon={<CloudUploadOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                Upload Manifest
              </Button>
              <Button
                component={RouterLink}
                to="/payments"
                variant="outlined"
                fullWidth
                startIcon={<CreditCardOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                eDO Payments
              </Button>
              <Button
                component={RouterLink}
                to="/manifests"
                variant="outlined"
                fullWidth
                startIcon={<LocalShippingOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                All Manifests
              </Button>
              <Button
                component={RouterLink}
                to="/sas"
                variant="outlined"
                fullWidth
                startIcon={<DescriptionOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                Accreditation
              </Button>
            </Stack>
          </Paper>

          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Stack direction="row" spacing={1.5} alignItems="flex-start">
              <Box
                sx={{
                  width: 40,
                  height: 40,
                  borderRadius: 2,
                  bgcolor: 'primary.main',
                  color: 'primary.contrastText',
                  display: 'grid',
                  placeItems: 'center',
                  flexShrink: 0,
                }}
              >
                <BusinessOutlinedIcon fontSize="small" />
              </Box>
              <Box minWidth={0}>
                <Typography variant="caption" color="text.secondary" fontWeight={600}>
                  Active Workspace
                </Typography>
                <Typography fontWeight={700} noWrap>
                  {workspaceName}
                </Typography>
                <Stack direction="row" spacing={0.75} mt={1} flexWrap="wrap" useFlexGap>
                  <Chip size="small" label={`${workspaces.length} linked`} />
                  {activeWorkspace && (
                    <Chip size="small" variant="outlined" label={activeWorkspace.email} />
                  )}
                </Stack>
                <Button
                  component={RouterLink}
                  to="/workspace"
                  size="small"
                  sx={{ mt: 1.5, textTransform: 'none', px: 0 }}
                >
                  Switch workspace
                </Button>
              </Box>
            </Stack>
          </Paper>

          <Paper
            elevation={0}
            sx={{
              p: 2.25,
              border: 1,
              borderColor: 'divider',
              borderRadius: 2,
              bgcolor: 'rgba(11,61,92,0.04)',
            }}
          >
            <Stack direction="row" spacing={1.25} alignItems="flex-start" mb={1.5}>
              <SupportAgentOutlinedIcon color="primary" />
              <Box>
                <Typography fontWeight={700}>Need help?</Typography>
                <Typography variant="body2" color="text.secondary" mt={0.5}>
                  Contact support for manifests, payments, or eDO downloads.
                </Typography>
              </Box>
            </Stack>
            <Button
              href="mailto:support@optimus.local"
              variant="contained"
              size="small"
              fullWidth
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              Get Support
            </Button>
          </Paper>
        </Stack>
      </Box>
    </Stack>
  );
}

function ManifestRow({ manifest, edos }: { manifest: ManifestDto; edos: EdoDto[] }) {
  const total = edos.length;
  const needingPay = edos.filter((e) => isPaymentNeeded(e.status)).length;
  const pending = edos.filter((e) => isPendingValidation(e.status)).length;
  const ready = edos.filter((e) => isReadyDownload(e.status)).length;

  let edoLabel = total ? `${ready}/${total}` : '0 ctr';
  let edoColor: 'default' | 'warning' | 'success' = 'default';
  if (total > 0 && needingPay + pending > 0) {
    edoLabel = `${ready}/${total}`;
    edoColor = 'warning';
  } else if (total > 0 && ready === total) {
    edoColor = 'success';
  }

  const paymentLabel =
    needingPay > 0 ? 'Due' : pending > 0 ? 'Pending' : total > 0 && ready > 0 ? 'OK' : '—';
  const paymentColor =
    needingPay > 0 ? 'error' : pending > 0 ? 'warning' : paymentLabel === 'OK' ? 'success' : 'default';

  return (
    <TableRow hover>
      <TableCell>
        <Typography fontWeight={600} variant="body2">
          {manifest.manifestNumber}
        </Typography>
      </TableCell>
      <TableCell>{manifest.vesselName || '—'}</TableCell>
      <TableCell>
        <Chip
          size="small"
          label={formatWorkflowState(manifest.workflowState)}
          color={toneForWorkflow(manifest.workflowState)}
        />
      </TableCell>
      <TableCell>
        <Chip size="small" label={edoLabel} color={edoColor} />
      </TableCell>
      <TableCell>
        {paymentLabel === '—' ? (
          '—'
        ) : (
          <Chip size="small" label={paymentLabel} color={paymentColor as 'error' | 'warning' | 'success'} />
        )}
      </TableCell>
      <TableCell align="right">
        <TableViewLink to={`/manifests/${manifest.id}`} />
      </TableCell>
    </TableRow>
  );
}
