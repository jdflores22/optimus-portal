import { useMemo } from 'react';
import { useTheme } from '@mui/material/styles';
import { pageHeroGradient } from '../../shared/theme';
import {
  Alert,
  Box,
  Button,
  Chip,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import VerifiedOutlinedIcon from '@mui/icons-material/VerifiedOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import CreditCardOutlinedIcon from '@mui/icons-material/CreditCardOutlined';
import SupportAgentOutlinedIcon from '@mui/icons-material/SupportAgentOutlined';
import LinkOutlinedIcon from '@mui/icons-material/LinkOutlined';
import AssignmentOutlinedIcon from '@mui/icons-material/AssignmentOutlined';
import PersonOutlineOutlinedIcon from '@mui/icons-material/PersonOutlineOutlined';
import { Link as RouterLink } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGetAccreditationsQuery,
  useGetActiveFormQuery,
  useGetEdosQuery,
  useGetManifestsQuery,
  useGetRelationshipsQuery,
} from '../../app/api';
import { parseFormFields } from '../../shared/formSchema';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

function needsPayment(state: string): boolean {
  return /payment|billing|awaiting|pending.?pay/i.test(state);
}

function statusChipColor(state: string): 'success' | 'default' | 'warning' | 'info' | 'error' | 'secondary' {
  if (/edo.?released|edo.?generated|released|completed|approved/i.test(state)) return 'success';
  if (/reject|fail|cancel|denied/i.test(state)) return 'error';
  if (/noa/i.test(state)) return 'secondary';
  if (/billing|payment|pending|await/i.test(state)) return 'warning';
  if (/uploaded|bl/i.test(state)) return 'info';
  return 'default';
}

function accredChipColor(status: string): 'success' | 'default' | 'warning' | 'info' | 'error' {
  if (/approv/i.test(status)) return 'success';
  if (/denied|reject/i.test(status)) return 'error';
  if (/compliance/i.test(status)) return 'info';
  if (/pending|await/i.test(status)) return 'warning';
  return 'default';
}

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString(undefined, {
    month: 'short',
    day: '2-digit',
    year: 'numeric',
  });
}

function formatStatus(state: string) {
  return state.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/_/g, ' ');
}

export function ConsigneeDashboardPage() {
  const theme = useTheme();
  const { user } = useSelector((state: RootState) => state.auth);

  const { data: manifests = [], isFetching, refetch } = useGetManifestsQuery();
  const { data: relationships = [] } = useGetRelationshipsQuery();
  const { data: accreditations = [] } = useGetAccreditationsQuery();
  const { data: edos = [] } = useGetEdosQuery();
  const { data: activeForm } = useGetActiveFormQuery('Consignee', { skip: !user });

  const filtered = useMemo(() => manifests, [manifests]);

  const myAccreds = useMemo(
    () => accreditations.filter((a) => a.applicantId === user?.id),
    [accreditations, user?.id],
  );
  const myAccred = myAccreds[0];
  const approvedCount = myAccreds.filter((a) => /approv/i.test(a.status)).length;
  const pendingCount = myAccreds.filter((a) => /pending|await|submitted/i.test(a.status)).length;
  const needsActionCount = myAccreds.filter((a) => /compliance|denied|reject/i.test(a.status)).length;

  const activeBrokers = useMemo(
    () => relationships.filter((r) => /active/i.test(r.status)),
    [relationships],
  );
  const suspendedBrokers = useMemo(
    () => relationships.filter((r) => /suspend/i.test(r.status)),
    [relationships],
  );

  const paymentNeeded = useMemo(() => {
    const byWorkflow = filtered.filter((m) => needsPayment(m.workflowState));
    if (byWorkflow.length > 0) return byWorkflow;
    // Fallback: manifests that have eDOs still awaiting payment/release
    const needing = new Set(
      edos
        .filter((e) => /pending.?release|pending.?validation/i.test(e.status))
        .map((e) => e.manifestId),
    );
    return filtered.filter((m) => needing.has(m.id));
  }, [filtered, edos]);

  const recent = useMemo(
    () =>
      [...filtered]
        .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
        .slice(0, 5),
    [filtered],
  );

  const activeFieldCount = useMemo(
    () => parseFormFields(activeForm?.fieldsJson).length,
    [activeForm?.fieldsJson],
  );

  const displayName = user?.businessName || user?.fullName || 'there';
  const nowLabel = new Date().toLocaleString(undefined, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });

  const accredApproved = approvedCount > 0;
  const accredPending = pendingCount > 0 && !accredApproved;
  const accredCompliance = Boolean(myAccred && /compliance/i.test(myAccred.status));
  const accredDenied = Boolean(myAccred && /denied|reject/i.test(myAccred.status));

  const banner = (() => {
    if (accredApproved) {
      return {
        severity: 'success' as const,
        actionLabel: 'View SAS',
        message:
          'Your consignee accreditation is approved. You can continue managing shipments and brokers.',
      };
    }
    if (accredCompliance) {
      return {
        severity: 'warning' as const,
        actionLabel: 'Fix & resubmit',
        message: `Compliance required on your accreditation${
          activeForm ? ` (${activeForm.name} v${activeForm.version})` : ''
        }. Please update the requested fields and resubmit.`,
      };
    }
    if (accredDenied) {
      return {
        severity: 'error' as const,
        actionLabel: 'Review & resubmit',
        message: 'Your accreditation was denied. Review the notes and resubmit when ready.',
      };
    }
    if (accredPending) {
      return {
        severity: 'info' as const,
        actionLabel: 'View status',
        message: `Your accreditation is under review (${myAccred?.status})${
          activeForm ? ` · ${activeForm.name} v${activeForm.version}` : ''
        }. We'll notify you when there's an update.`,
      };
    }
    if (activeForm) {
      return {
        severity: 'info' as const,
        actionLabel: 'Open form',
        message: `Active Consignee form ready: ${activeForm.name} v${activeForm.version} (${activeFieldCount} fields) · Not submitted yet.`,
      };
    }
    return {
      severity: 'warning' as const,
      actionLabel: 'Open SAS',
      message: 'No active Consignee SAS form yet. Ask your shipping line admin to activate one.',
    };
  })();

  const showQuickActions =
    myAccreds.length === 0 || activeBrokers.length === 0 || paymentNeeded.length > 0;

  const kpis = [
    {
      id: 'manifests',
      label: 'Total Manifests',
      value: filtered.length,
      hint: 'Active shipments',
      to: '/manifests',
      color: '#0B3D5C',
      bg: 'rgba(11,61,92,0.08)',
      icon: DescriptionOutlinedIcon,
    },
    {
      id: 'accred',
      label: 'Accreditations',
      value: myAccreds.length,
      hint: myAccreds.length
        ? [
            approvedCount ? `${approvedCount} approved` : null,
            pendingCount ? `${pendingCount} pending` : null,
            needsActionCount ? `${needsActionCount} action needed` : null,
          ]
            .filter(Boolean)
            .join(' · ') || 'Submitted'
        : 'Submit now',
      to: '/sas',
      color: accredApproved ? '#2E7D32' : pendingCount ? '#0288D1' : '#78909C',
      bg: accredApproved
        ? 'rgba(46,125,50,0.08)'
        : pendingCount
          ? 'rgba(2,136,209,0.08)'
          : 'rgba(120,144,156,0.1)',
      icon: VerifiedOutlinedIcon,
      alert: needsActionCount > 0 || myAccreds.length === 0,
    },
    {
      id: 'brokers',
      label: 'Approved Brokers',
      value: activeBrokers.length,
      hint: activeBrokers.length ? 'Active relationships' : 'Use referral code',
      to: '/brokers',
      color: activeBrokers.length ? '#0277BD' : '#EF6C00',
      bg: activeBrokers.length ? 'rgba(2,119,189,0.08)' : 'rgba(239,108,0,0.08)',
      icon: PeopleOutlineOutlinedIcon,
      alert: activeBrokers.length === 0,
    },
    {
      id: 'payments',
      label: 'Payment Required',
      value: paymentNeeded.length,
      hint: paymentNeeded.length ? 'Action needed' : 'All paid',
      to: '/payments',
      color: paymentNeeded.length ? '#C62828' : '#2E7D32',
      bg: paymentNeeded.length ? 'rgba(198,40,40,0.08)' : 'rgba(46,125,50,0.08)',
      icon: CreditCardOutlinedIcon,
      alert: paymentNeeded.length > 0,
    },
  ];

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
          direction={{ xs: 'column', md: 'row' }}
          justifyContent="space-between"
          alignItems={{ md: 'flex-end' }}
          spacing={2}
        >
          <Box minWidth={0}>
            <Chip
              size="small"
              label="Consignee Portal"
              color="primary"
              sx={{ mb: 1.5, fontWeight: 600 }}
            />
            <Typography variant="h4" fontWeight={800} lineHeight={1.2}>
              Welcome back, {displayName}
            </Typography>
            <Typography color="text.secondary" mt={1} maxWidth={560}>
              Here&apos;s what&apos;s happening with your shipments today.
            </Typography>
            <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1} mt={2}>
              {accredApproved ? (
                <Chip size="small" color="success" label="Accredited" />
              ) : myAccred ? (
                <Chip
                  size="small"
                  color={accredChipColor(myAccred.status)}
                  label={myAccred.status.replace(/_/g, ' ')}
                  component={RouterLink}
                  to="/sas"
                  clickable
                />
              ) : (
                <Chip
                  size="small"
                  color="warning"
                  label="Accreditation required"
                  component={RouterLink}
                  to="/sas"
                  clickable
                />
              )}
              <Chip
                size="small"
                color={activeBrokers.length ? 'info' : 'warning'}
                variant="outlined"
                label={`${activeBrokers.length} broker${activeBrokers.length === 1 ? '' : 's'}`}
                component={RouterLink}
                to="/brokers"
                clickable
              />
              {paymentNeeded.length > 0 && (
                <Chip
                  size="small"
                  color="error"
                  icon={<CreditCardOutlinedIcon />}
                  label={`${paymentNeeded.length} payment${paymentNeeded.length > 1 ? 's' : ''} due`}
                  component={RouterLink}
                  to="/payments"
                  clickable
                />
              )}
            </Stack>
          </Box>
          <Stack spacing={0.5} alignItems={{ xs: 'flex-start', md: 'flex-end' }}>
            <Typography
              variant="body2"
              color="text.secondary"
              sx={{ display: { xs: 'none', md: 'block' }, textAlign: 'right' }}
            >
              {nowLabel}
            </Typography>
            <Button
              size="small"
              onClick={() => void refetch()}
              disabled={isFetching}
              sx={{ textTransform: 'none' }}
            >
              Refresh
            </Button>
          </Stack>
        </Stack>
      </Paper>

      {suspendedBrokers.length > 0 && (
        <Alert
          severity="error"
          action={
            <Stack direction="row" spacing={1}>
              <Button component={RouterLink} to="/manifests" color="inherit" size="small">
                View manifests
              </Button>
              <Button component={RouterLink} to="/brokers" color="inherit" size="small">
                Add New Broker
              </Button>
            </Stack>
          }
        >
          <Typography fontWeight={700}>Action Required: Suspended Broker(s)</Typography>
          You have {suspendedBrokers.length} suspended broker relationship(s). Assign a new broker
          or request a transfer for affected manifests.
        </Alert>
      )}

      <Alert
        severity={banner.severity}
        action={
          <Button component={RouterLink} to="/sas" color="inherit" size="small">
            {banner.actionLabel}
          </Button>
        }
      >
        {banner.message}
      </Alert>

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
          return (
            <Paper
              key={kpi.id}
              component={RouterLink}
              to={kpi.to}
              elevation={0}
              sx={{
                p: 2.25,
                border: 1,
                borderColor: kpi.alert ? 'warning.light' : 'divider',
                borderRadius: 2,
                textDecoration: 'none',
                color: 'inherit',
                bgcolor: kpi.id === 'payments' && paymentNeeded.length > 0 ? kpi.bg : 'background.paper',
                transition: 'border-color .15s, transform .15s',
                '&:hover': { borderColor: kpi.color, transform: 'translateY(-1px)' },
              }}
            >
              <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
                <Box minWidth={0}>
                  <Typography variant="caption" color="text.secondary" fontWeight={600}>
                    {kpi.label}
                  </Typography>
                  <Typography variant="h4" fontWeight={800} sx={{ color: kpi.color, mt: 0.5 }}>
                    {kpi.value}
                  </Typography>
                  <Typography variant="caption" color="text.secondary" display="block">
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
                    flexShrink: 0,
                  }}
                >
                  <Icon fontSize="small" />
                </Box>
              </Stack>
            </Paper>
          );
        })}
      </Box>

      {/* Conditional Quick Actions */}
      {showQuickActions && (
        <Paper
          elevation={0}
          sx={{
            p: { xs: 2.5, sm: 3 },
            borderRadius: 3,
            border: 1,
            borderColor: 'divider',
            bgcolor: 'background.paper',
          }}
        >
          <Typography variant="h6" fontWeight={700}>
            Quick Actions
          </Typography>
          <Typography variant="body2" color="text.secondary" mb={2}>
            Complete these tasks to keep your account moving.
          </Typography>
          <Box
            sx={{
              display: 'grid',
              gap: 1.5,
              gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' },
            }}
          >
            {myAccreds.length === 0 && (
              <Paper
                elevation={0}
                sx={{
                  p: 2,
                  border: 1,
                  borderColor: 'divider',
                  borderRadius: 2,
                }}
              >
                <Stack spacing={1.25}>
                  <Stack direction="row" spacing={1.25} alignItems="center">
                    <Box
                      sx={{
                        width: 38,
                        height: 38,
                        borderRadius: 2,
                        bgcolor: 'rgba(11,61,92,0.08)',
                        color: 'primary.main',
                        display: 'grid',
                        placeItems: 'center',
                        flexShrink: 0,
                      }}
                    >
                      <AssignmentOutlinedIcon fontSize="small" />
                    </Box>
                    <Box minWidth={0}>
                      <Typography fontWeight={700}>Submit Accreditation</Typography>
                      <Typography variant="body2" color="text.secondary">
                        Get verified to access all consignee workflows.
                      </Typography>
                    </Box>
                  </Stack>
                  <Button
                    component={RouterLink}
                    to="/sas"
                    variant="outlined"
                    size="small"
                    sx={{ alignSelf: 'flex-start', textTransform: 'none', fontWeight: 600 }}
                  >
                    Open SAS
                  </Button>
                </Stack>
              </Paper>
            )}
            {activeBrokers.length === 0 && (
              <Paper
                elevation={0}
                sx={{
                  p: 2,
                  border: 1,
                  borderColor: activeBrokers.length === 0 ? 'warning.light' : 'divider',
                  borderRadius: 2,
                }}
              >
                <Stack spacing={1.25}>
                  <Stack direction="row" spacing={1.25} alignItems="center">
                    <Box
                      sx={{
                        width: 38,
                        height: 38,
                        borderRadius: 2,
                        bgcolor: 'rgba(239,108,0,0.10)',
                        color: 'warning.main',
                        display: 'grid',
                        placeItems: 'center',
                        flexShrink: 0,
                      }}
                    >
                      <LinkOutlinedIcon fontSize="small" />
                    </Box>
                    <Box minWidth={0}>
                      <Typography fontWeight={700}>Link Brokers</Typography>
                      <Typography variant="body2" color="text.secondary">
                        Connect an approved broker before shipment work starts.
                      </Typography>
                    </Box>
                  </Stack>
                  <Button
                    component={RouterLink}
                    to="/brokers"
                    variant="outlined"
                    size="small"
                    color="warning"
                    sx={{ alignSelf: 'flex-start', textTransform: 'none', fontWeight: 600 }}
                  >
                    Manage brokers
                  </Button>
                </Stack>
              </Paper>
            )}
            {paymentNeeded.length > 0 && (
              <Paper
                elevation={0}
                sx={{
                  p: 2,
                  border: 1,
                  borderColor: 'error.light',
                  borderRadius: 2,
                }}
              >
                <Stack spacing={1.25}>
                  <Stack direction="row" spacing={1.25} alignItems="center">
                    <Box
                      sx={{
                        width: 38,
                        height: 38,
                        borderRadius: 2,
                        bgcolor: 'rgba(198,40,40,0.10)',
                        color: 'error.main',
                        display: 'grid',
                        placeItems: 'center',
                        flexShrink: 0,
                      }}
                    >
                      <CreditCardOutlinedIcon fontSize="small" />
                    </Box>
                    <Box minWidth={0}>
                      <Typography fontWeight={700}>Complete Payments</Typography>
                      <Typography variant="body2" color="text.secondary">
                        {paymentNeeded.length} manifest{paymentNeeded.length === 1 ? '' : 's'} need payment review.
                      </Typography>
                    </Box>
                  </Stack>
                  <Button
                    component={RouterLink}
                    to="/payments"
                    variant="contained"
                    size="small"
                    color="error"
                    sx={{ alignSelf: 'flex-start', textTransform: 'none', fontWeight: 600 }}
                  >
                    Go to payments
                  </Button>
                </Stack>
              </Paper>
            )}
          </Box>
        </Paper>
      )}

      {/* Main + sidebar */}
      <Box
        sx={{
          display: 'grid',
          gap: 2.5,
          gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 2fr) minmax(280px, 1fr)' },
          alignItems: 'start',
        }}
      >
        <Paper
          elevation={0}
          sx={{ border: 1, borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}
        >
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
                Your latest shipment manifests
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
                  <TableCell>MANIFEST #</TableCell>
                  <TableCell>SHIPPING LINE</TableCell>
                  <TableCell>BL #</TableCell>
                  <TableCell>STATUS</TableCell>
                  <TableCell>CREATED</TableCell>
                  <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {recent.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6}>
                      <Typography variant="body2" color="text.secondary" py={3} textAlign="center">
                        No manifests yet. They will appear here after uploads.
                      </Typography>
                    </TableCell>
                  </TableRow>
                ) : (
                  recent.map((m) => (
                    <TableRow key={m.id} hover>
                      <TableCell>
                        <Typography fontWeight={600} variant="body2">
                          {m.manifestNumber}
                        </Typography>
                      </TableCell>
                      <TableCell>{m.shippingLineName ?? '—'}</TableCell>
                      <TableCell>{m.blNumber ?? '—'}</TableCell>
                      <TableCell>
                        <Chip
                          size="small"
                          label={formatStatus(m.workflowState)}
                          color={statusChipColor(m.workflowState)}
                        />
                      </TableCell>
                      <TableCell>{formatDate(m.createdAt)}</TableCell>
                      <TableCell align="right">
                        <TableViewLink to={`/manifests/${m.id}`} />
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </Box>
        </Paper>

        <Stack spacing={2}>
          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1.5}>
              <Typography variant="subtitle1" fontWeight={700}>
                Account Information
              </Typography>
              <Button
                component={RouterLink}
                to="/profile"
                size="small"
                sx={{ textTransform: 'none' }}
              >
                Edit
              </Button>
            </Stack>
            <Typography variant="caption" color="text.secondary" fontWeight={600}>
              Business name
            </Typography>
            <Typography fontWeight={700} mb={1.25}>
              {user?.businessName || user?.fullName || '—'}
            </Typography>
            <Typography variant="caption" color="text.secondary" fontWeight={600}>
              Email
            </Typography>
            <Typography fontWeight={600} mb={2} sx={{ wordBreak: 'break-word' }}>
              {user?.email}
            </Typography>

            <Typography variant="subtitle2" fontWeight={700} mb={1}>
              Brokers
            </Typography>
            {relationships.length === 0 ? (
              <Typography variant="body2" color="text.secondary" mb={1.5}>
                No brokers linked yet.
              </Typography>
            ) : (
              <Stack spacing={1} mb={1.5}>
                {relationships.slice(0, 4).map((r) => (
                  <Paper
                    key={r.id}
                    elevation={0}
                    sx={{
                      p: 1.25,
                      border: 1,
                      borderColor: /suspend/i.test(r.status) ? 'error.main' : 'divider',
                      borderRadius: 1.5,
                    }}
                  >
                    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1}>
                      <Typography variant="body2" fontWeight={600} noWrap>
                        {r.brokerName}
                      </Typography>
                      <Chip
                        size="small"
                        label={r.status}
                        color={/suspend/i.test(r.status) ? 'error' : 'success'}
                      />
                    </Stack>
                  </Paper>
                ))}
              </Stack>
            )}
            <Button
              component={RouterLink}
              to="/brokers"
              size="small"
              sx={{ textTransform: 'none', mb: 2 }}
            >
              + Add more brokers
            </Button>

            <Typography variant="subtitle2" fontWeight={700} mb={1}>
              Accreditation
            </Typography>
            {myAccreds.length === 0 ? (
              <Typography variant="body2" color="text.secondary">
                No accreditation submissions yet.
              </Typography>
            ) : (
              <Stack spacing={1}>
                {myAccreds.slice(0, 3).map((a) => (
                  <Paper
                    key={a.id}
                    elevation={0}
                    sx={{ p: 1.25, border: 1, borderColor: 'divider', borderRadius: 1.5 }}
                  >
                    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1}>
                      <Typography variant="body2" fontWeight={600} noWrap>
                        {a.shippingLineName || 'Shipping line'}
                      </Typography>
                      <Chip size="small" label={a.status} color={accredChipColor(a.status)} />
                    </Stack>
                  </Paper>
                ))}
                <Button
                  component={RouterLink}
                  to="/sas"
                  size="small"
                  sx={{ textTransform: 'none', alignSelf: 'flex-start' }}
                >
                  View details
                </Button>
              </Stack>
            )}
          </Paper>

          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Typography variant="subtitle2" fontWeight={700} mb={1.5}>
              Quick Links
            </Typography>
            <Stack spacing={1}>
              <Button
                component={RouterLink}
                to="/manifests"
                variant="outlined"
                fullWidth
                startIcon={<DescriptionOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                All Manifests
              </Button>
              <Button
                component={RouterLink}
                to="/sas"
                variant="outlined"
                fullWidth
                startIcon={<AssignmentOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                Accreditation
              </Button>
              <Button
                component={RouterLink}
                to="/brokers"
                variant="outlined"
                fullWidth
                startIcon={<PeopleOutlineOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                My Brokers
              </Button>
              <Button
                component={RouterLink}
                to="/profile"
                variant="outlined"
                fullWidth
                startIcon={<PersonOutlineOutlinedIcon />}
                sx={{ justifyContent: 'flex-start', textTransform: 'none', fontWeight: 600 }}
              >
                Account Settings
              </Button>
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
                  Contact support for manifests, brokers, or accreditation.
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
