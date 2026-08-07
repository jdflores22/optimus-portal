import { useMemo, type ReactNode } from 'react';
import { Alert, Box, Button, Chip, Paper, Stack, Typography } from '@mui/material';
import DirectionsBoatOutlinedIcon from '@mui/icons-material/DirectionsBoatOutlined';
import PlaceOutlinedIcon from '@mui/icons-material/PlaceOutlined';
import LocalShippingOutlinedIcon from '@mui/icons-material/LocalShippingOutlined';
import WarehouseOutlinedIcon from '@mui/icons-material/WarehouseOutlined';
import Inventory2OutlinedIcon from '@mui/icons-material/Inventory2Outlined';
import ScheduleOutlinedIcon from '@mui/icons-material/ScheduleOutlined';
import CalendarMonthOutlinedIcon from '@mui/icons-material/CalendarMonthOutlined';
import QueryStatsOutlinedIcon from '@mui/icons-material/QueryStatsOutlined';
import { Link as RouterLink } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGetAccreditationsQuery,
  useGetContainersQuery,
  useGetCyAllocationsQuery,
  useGetEdoReleaseQueueQuery,
  useGetEdosQuery,
  useGetManifestsQuery,
  useGetNotificationsQuery,
  useGetPendingEdoPaymentsQuery,
  useGetPendingPaymentsQuery,
  useGetPreAdvicesQuery,
  useGetRepositioningQuery,
  useGetTerminalsQuery,
  useGetUtilizationQuery,
  useMarkNotificationsReadMutation,
} from '../../app/api';
import { getQuickActions } from '../layout/navConfig';
import { WorkflowPage, WorkflowSection, type WorkflowStat } from '../shared/WorkflowPage';

function greetingForNow(): string {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

type QueueItem = {
  label: string;
  value: string | number;
  hint: string;
  to: string;
};

export function DashboardPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const role = user?.role ?? '';
  const quickActions = getQuickActions(role);

  const isStaff = ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff'].includes(role);
  const isShippingAdmin = role === 'ShippingLinesAdmin';
  const isLocationDashboard = role === 'SlStaff' || isShippingAdmin;
  const isAccounting = role === 'Accounting' || role === 'SystemAdmin';
  const isRelease = ['SlStaff', 'ShippingLinesAdmin', 'TerminalTeam'].includes(role);
  const isEdoPaymentAdmin = role === 'SystemAdmin';
  const isCargo = role === 'SystemAdmin' || isStaff || isAccounting;
  const isTerminal = role === 'TerminalTeam' || role === 'SystemAdmin';
  const isTrucker = role === 'Trucker' || role === 'SystemAdmin';
  const isEvaluator = role === 'Evaluator' || role === 'SystemAdmin' || isShippingAdmin;

  const { data: notifications = [] } = useGetNotificationsQuery();
  const [markRead] = useMarkNotificationsReadMutation();
  const unread = useMemo(() => notifications.filter((n) => !n.isRead), [notifications]);

  const { data: manifests = [], refetch: refetchManifests } = useGetManifestsQuery(undefined, {
    skip: !isCargo,
  });
  const { data: edos = [], refetch: refetchEdos } = useGetEdosQuery(undefined, {
    skip: !isStaff && !isCargo,
  });
  const { data: releaseQueue, refetch: refetchReleaseQueue } = useGetEdoReleaseQueueQuery(undefined, {
    skip: !isRelease,
  });
  const { data: pendingPayments = [] } = useGetPendingPaymentsQuery(undefined, { skip: !isAccounting });
  const { data: pendingEdoPayments = [] } = useGetPendingEdoPaymentsQuery(undefined, {
    skip: !isEdoPaymentAdmin,
  });
  const { data: preAdvices = [], refetch: refetchPreAdvices } = useGetPreAdvicesQuery(undefined, {
    skip: !(isTerminal || isTrucker || isStaff),
  });
  const { data: accreditations = [] } = useGetAccreditationsQuery(undefined, {
    skip: !isEvaluator,
  });
  const { data: terminals = [], refetch: refetchTerminals } = useGetTerminalsQuery(undefined, {
    skip: !isLocationDashboard,
  });
  const { data: allocations = [], refetch: refetchAllocations } = useGetCyAllocationsQuery(undefined, {
    skip: !isLocationDashboard,
  });
  const { data: containers = [], refetch: refetchContainers } = useGetContainersQuery(undefined, {
    skip: !isLocationDashboard,
  });
  const { data: utilization = [], refetch: refetchUtilization } = useGetUtilizationQuery(undefined, {
    skip: !isLocationDashboard,
  });
  const { data: repositioning = [], refetch: refetchRepositioning } = useGetRepositioningQuery(undefined, {
    skip: !isLocationDashboard,
  });

  const stats: WorkflowStat[] = [];
  if (isCargo) {
    stats.push({ label: 'Manifests', value: manifests.length, hint: 'Cargo workflow load', tone: 'primary' });
  }
  if (isStaff || isCargo) {
    stats.push({ label: 'eDO / CRO', value: edos.length, hint: 'Document issuance queue', tone: 'info' });
  }
  if (isRelease) {
    stats.push({
      label: 'Ready to release',
      value: releaseQueue?.readyToRelease ?? 0,
      hint: 'Verified eDOs',
      tone: 'success',
    });
  }
  if (isEdoPaymentAdmin) {
    stats.push({
      label: 'eDO payments',
      value: pendingEdoPayments.length,
      hint: 'Awaiting validation',
      tone: 'warning',
    });
  }
  if (isAccounting) {
    stats.push({ label: 'Pending payments', value: pendingPayments.length, hint: 'Awaiting validation', tone: 'warning' });
  }
  if (isTerminal || isTrucker || isStaff) {
    const pendingPa = preAdvices.filter((p) =>
      ['Submitted', 'Pending', 'PendingVerification', 'InProgress'].includes(p.status),
    );
    stats.push({
      label: 'Pre-advice',
      value: pendingPa.length || preAdvices.length,
      hint: 'Terminal handoff queue',
      tone: 'info',
    });
  }
  if (isEvaluator) {
    const pendingSas = accreditations.filter((a) =>
      /pending|submitted|evaluator/i.test(a.status ?? ''),
    );
    stats.push({
      label: 'SAS in review',
      value: pendingSas.length || accreditations.length,
      hint: 'Applications needing review',
      tone: 'warning',
    });
  }
  stats.push({ label: 'Unread alerts', value: unread.length, hint: 'Operational notifications', tone: unread.length ? 'error' : 'success' });

  const uniqueStats: WorkflowStat[] = stats.filter(
    (s, i, arr) => arr.findIndex((x) => x.label === s.label) === i,
  );

  const roleSummary = {
    ShippingLinesAdmin: {
      eyebrow: 'Shipping oversight',
      subtitle: 'Track cargo flow, approvals, brand operations, and release pressure across your shipping line.',
      focus: [
        { label: 'Accreditations', value: accreditations.length, hint: 'Evaluator + final approval pipeline', to: '/sas' },
        { label: 'Release queue', value: releaseQueue?.readyToRelease ?? 0, hint: 'eDOs ready after admin validation', to: '/edo/release' },
        { label: 'Inventory', value: preAdvices.length, hint: 'Terminal requests and yard pressure', to: '/container-inventory' },
      ],
    },
    SlStaff: {
      eyebrow: 'Execution desk',
      subtitle: 'Monitor manifest intake, document generation, and terminal-ready activity for today’s operations.',
      focus: [
        { label: 'Manifest actions', value: manifests.length, hint: 'NOA, BL, billing, and consignee declarations', to: '/manifests' },
        { label: 'Ready to release', value: releaseQueue?.readyToRelease ?? 0, hint: 'Payment verified eDOs', to: '/edo/release' },
        { label: 'Document generation', value: edos.length, hint: 'eDO and renewal work now in queue', to: '/edo' },
      ],
    },
    SystemAdmin: {
      eyebrow: 'Platform provider',
      subtitle: 'Validate broker eDO payments and oversee cargo workflow across shipping lines.',
      focus: [
        { label: 'eDO payments', value: pendingEdoPayments.length, hint: 'Awaiting validation', to: '/edo/payment-validation' },
        { label: 'Manifests', value: manifests.length, hint: 'Cargo workflow load', to: '/manifests' },
        { label: 'Unread alerts', value: unread.length, hint: 'Operational notifications', to: '/notifications' },
      ],
    },
    Accounting: {
      eyebrow: 'Finance control',
      subtitle: 'Keep manifest payment validations and billing moving without handoff delays.',
      focus: [
        { label: 'Manifest payments', value: pendingPayments.length, hint: 'Billing receipts to validate', to: '/payments' },
        { label: 'Unread alerts', value: unread.length, hint: 'Exceptions and failed submissions', to: '/notifications' },
      ],
    },
    Evaluator: {
      eyebrow: 'Compliance review',
      subtitle: 'Work the accreditation queue with a clean view of pending submissions, review health, and follow-ups.',
      focus: [
        { label: 'Applications', value: accreditations.length, hint: 'Submitted or pending evaluator work', to: '/sas' },
        { label: 'Hierarchy', value: 'Review', hint: 'User and role access governance', to: '/admin/hierarchy' },
        { label: 'Alerts', value: unread.length, hint: 'Escalations and operator notices', to: '/notifications' },
      ],
    },
    TerminalTeam: {
      eyebrow: 'Terminal operations',
      subtitle: 'Handle pre-advice verification, release readiness, and dwell pressure from one terminal-focused hub.',
      focus: [
        { label: 'Pending pre-advice', value: preAdvices.length, hint: 'Requests awaiting terminal action', to: '/pre-advice' },
        { label: 'Release queue', value: releaseQueue?.readyToRelease ?? 0, hint: 'Packages ready for gate release', to: '/edo/release' },
        { label: 'Dwell watch', value: preAdvices.filter((p) => p.status === 'Pending').length, hint: 'Containers needing attention', to: '/dwell' },
      ],
    },
  } as const;

  const summary =
    roleSummary[role as keyof typeof roleSummary] ?? {
      eyebrow: 'Operations overview',
      subtitle: 'Keep the shipping portal moving across documents, payments, yard activity, and role-specific alerts.',
      focus: [
        { label: 'Cargo workflow', value: manifests.length, hint: 'Manifest and billing queue', to: '/manifests' },
        { label: 'Notifications', value: unread.length, hint: 'Unread alerts and updates', to: '/notifications' },
      ],
    };

  const queueCards: QueueItem[] = [...summary.focus];
  const roleChip = user?.businessName ? `${role} · ${user.businessName}` : role || 'Operations';
  const refreshDashboard = async () => {
    await Promise.all([
      refetchManifests(),
      refetchEdos(),
      refetchPreAdvices(),
      refetchTerminals(),
      refetchAllocations(),
      refetchContainers(),
      refetchUtilization(),
      refetchRepositioning(),
      refetchReleaseQueue(),
    ]);
  };

  const slStaffDashboard = useMemo(() => {
    if (!isLocationDashboard) return null;

    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startOfWeek = new Date(startOfToday);
    startOfWeek.setDate(startOfWeek.getDate() - startOfWeek.getDay());
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);

    const manifestsToday = manifests.filter((m) => new Date(m.createdAt) >= startOfToday);
    const manifestsWeek = manifests.filter((m) => new Date(m.createdAt) >= startOfWeek);
    const manifestsMonth = manifests.filter((m) => new Date(m.createdAt) >= startOfMonth);

    const portTerminals = terminals.filter((t) => !/cy|containeryard/i.test(`${t.kind}${t.identity}`));
    const cyTerminals = terminals.filter((t) => /cy|containeryard/i.test(`${t.kind}${t.identity}`));
    const totalAtPort = utilization.reduce((sum, row) => sum + row.atTerminal, 0);
    const totalPreForecast = utilization.reduce((sum, row) => sum + row.pendingPreAdvice, 0);
    const totalUsedTeu = utilization.reduce((sum, row) => sum + row.usedTeu, 0);
    const totalAllocatedTeu = utilization.reduce((sum, row) => sum + row.allocatedTeu, 0);
    const containersAtCy = containers.filter((c) => Boolean(c.cyAllocationId || c.cyTerminalName));
    const outboundRequests = repositioning.filter((r) => !/completed|rejected/i.test(r.status));
    const pendingOutbound = repositioning.filter((r) => /pending/i.test(r.status)).length;
    const inTransitOutbound = repositioning.filter((r) => /transit/i.test(r.status)).length;
    const readyEdos = edos.filter((e) => /generated|ready|active/i.test(e.status)).length;
    const unreadAlerts = unread.length;

    const locationNotes = {
      port: 'Port cards use current V2 terminal, utilization, container, and repositioning data to approximate the V1 port view.',
      cy: 'CY cards use live allocation, container, and utilization data, so this section is closer to the V1 dashboard behavior.',
    };

    const portCards = portTerminals.map((terminal) => {
      const utilizationRow = utilization.find((row) => row.terminalId === terminal.id || row.terminalName === terminal.name);
      const localContainers = containers.filter(
        (c) =>
          (c.currentLocation ?? '').toLowerCase().includes(terminal.name.toLowerCase()) ||
          (c.currentLocation ?? '').toLowerCase().includes(terminal.code.toLowerCase()),
      );
      const small = localContainers.filter((c) => c.sizeCode?.includes('20'));
      const large = localContainers.filter((c) => c.sizeCode?.includes('40'));
      const inboundTotal = manifests.filter((m) => !m.billingId).length;
      const outboundTotal = outboundRequests
        .filter((r) => r.destinationTerminalName === terminal.name)
        .reduce((sum, r) => sum + r.containerCount, 0);
      const inbound20 = splitCount(inboundTotal, 0.55);
      const inbound40 = Math.max(inboundTotal - inbound20, 0);
      const outbound20 = splitCount(outboundTotal, 0.5);
      const outbound40 = Math.max(outboundTotal - outbound20, 0);
      const available20 = Math.max(Math.round(terminal.dailyCapacity / 2) - small.length, 0);
      const available40 = Math.max(Math.round(terminal.dailyCapacity / 2) - large.length, 0);
      return {
        id: terminal.id,
        name: terminal.name,
        code: terminal.code,
        inbound: manifests.filter((m) => !m.billingId).length,
        atPort: utilizationRow?.atTerminal ?? localContainers.length,
        outbound: outboundRequests.filter((r) => r.destinationTerminalName === terminal.name).reduce((sum, r) => sum + r.containerCount, 0),
        available: Math.max(terminal.dailyCapacity - (utilizationRow?.atTerminal ?? 0), 0),
        utilizationPct: terminal.dailyCapacity ? Math.round((((utilizationRow?.atTerminal ?? 0) / terminal.dailyCapacity) * 100) * 10) / 10 : 0,
        twenty: {
          inbound: inbound20,
          atPort: small.length,
          outbound: outbound20,
          available: available20,
          utilizationPct: available20 + small.length ? Math.round((small.length / (available20 + small.length)) * 1000) / 10 : 0,
        },
        forty: {
          inbound: inbound40,
          atPort: large.length,
          outbound: outbound40,
          available: available40,
          utilizationPct: available40 + large.length ? Math.round((large.length / (available40 + large.length)) * 1000) / 10 : 0,
        },
      };
    });

    const cyCards = allocations.map((allocation) => {
      const utilizationRow = utilization.find(
        (row) => row.terminalId === allocation.terminalId || row.terminalName === allocation.terminalName,
      );
      const terminalMeta = cyTerminals.find(
        (terminal) => terminal.id === allocation.terminalId || terminal.name === allocation.terminalName,
      );
      const localContainers = containers.filter((c) => c.cyAllocationId === allocation.id || c.cyTerminalName === allocation.terminalName);
      const twentyAllocated = localContainers.filter((c) => c.sizeCode?.includes('20')).length;
      const fortyAllocated = localContainers.filter((c) => c.sizeCode?.includes('40')).length;
      return {
        id: allocation.id,
        name: allocation.terminalName,
        location: [terminalMeta?.city, terminalMeta?.region].filter(Boolean).join(', '),
        capacity20: allocation.capacity20Ft,
        capacity40: allocation.capacity40Ft,
        allocated20: twentyAllocated,
        allocated40: fortyAllocated,
        preForecast: utilizationRow?.pendingPreAdvice ?? 0,
        usedTeu: allocation.usedTeu,
        capacityTeu: allocation.allocatedCapacityTeu,
        available20: Math.max(allocation.capacity20Ft - twentyAllocated, 0),
        available40: Math.max(allocation.capacity40Ft - fortyAllocated, 0),
        utilizationPct: allocation.allocatedCapacityTeu ? Math.round((allocation.usedTeu / allocation.allocatedCapacityTeu) * 1000) / 10 : 0,
      };
    });

    return {
      manifestsToday,
      manifestsWeek,
      manifestsMonth,
      portTerminals,
      cyTerminals,
      totalAtPort,
      totalPreForecast,
      totalUsedTeu,
      totalAllocatedTeu,
      containersAtCy,
      outboundRequests,
      pendingOutbound,
      inTransitOutbound,
      readyEdos,
      unreadAlerts,
      portCards,
      cyCards,
      locationNotes,
    };
  }, [isLocationDashboard, manifests, terminals, utilization, containers, allocations, repositioning, edos, unread]);

  if (isLocationDashboard && slStaffDashboard) {
    const awaitingFinal = accreditations.filter((a) => a.status === 'AwaitingFinalApproval').length;
    return (
      <WorkflowPage
        eyebrow={isShippingAdmin ? 'Shipping Lines Admin' : 'SL Staff operations'}
        title={isShippingAdmin ? 'Shipping Line Dashboard' : 'SL Staff Dashboard'}
        subtitle={
          isShippingAdmin
            ? 'Complete overview of your shipping line operations, container yards, and terminal management.'
            : 'Manage daily shipping-line operations from one place: NOA activity, terminal and CY visibility, and outbound container movement.'
        }
        chips={
          <>
            {isShippingAdmin && (
              <Chip label={`${awaitingFinal} awaiting final`} size="small" color="warning" component={RouterLink} to="/approvals" clickable />
            )}
            <Chip label={`${slStaffDashboard.readyEdos} ready`} size="small" color="primary" />
            <Chip label={`${slStaffDashboard.manifestsToday.length} created today`} size="small" color="success" />
          </>
        }
        actions={
          <>
            <Button component={RouterLink} to="/manifests" variant="contained">
              New NOA
            </Button>
            <Button component={RouterLink} to="/manifests" variant="outlined">
              NOA List
            </Button>
            {isShippingAdmin && (
              <Button component={RouterLink} to="/approvals" variant="outlined">
                Accreditations
              </Button>
            )}
            <Button variant="outlined" onClick={refreshDashboard}>
              Refresh
            </Button>
          </>
        }
      >
        <Box sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr 1fr', lg: 'repeat(4, 1fr)' } }}>
          <StatCard label="Total NOAs" value={manifests.length} hint="All time" icon={<LocalShippingOutlinedIcon fontSize="small" />} />
          <StatCard label="Created today" value={slStaffDashboard.manifestsToday.length} hint="Today" icon={<ScheduleOutlinedIcon fontSize="small" />} accentColor="success.main" />
          <StatCard label="This week" value={slStaffDashboard.manifestsWeek.length} hint="Last 7 days" icon={<CalendarMonthOutlinedIcon fontSize="small" />} accentColor="info.main" />
          <StatCard label="This month" value={slStaffDashboard.manifestsMonth.length} hint="Last 30 days" icon={<QueryStatsOutlinedIcon fontSize="small" />} accentColor="warning.main" />
        </Box>

        <WorkflowSection title="Port / terminal" subtitle="Live shipping-line visibility across discharge terminals and outbound repositioning.">
          <Box sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)', xl: 'repeat(4, 1fr)' } }}>
            <SummaryCard
              label="Port terminals"
              value={slStaffDashboard.portTerminals.length}
              hint="Discharge locations"
              icon={<DirectionsBoatOutlinedIcon fontSize="small" />}
              tone="primary"
            />
            <SummaryCard
              label="Inbound"
              value={manifests.filter((m) => !m.billingId).length}
              hint="Laden expected - ETA today onward"
              detail={`${manifests.filter((m) => !m.billingId && m.blNumber).length}x with BL · ${manifests.filter((m) => !m.billingId && !m.blNumber).length}x awaiting BL`}
              badges={slStaffDashboard.portCards.slice(0, 2).map((terminal) => `${terminal.code} ${terminal.inbound}`)}
              icon={<DirectionsBoatOutlinedIcon fontSize="small" />}
              tone="info"
            />
            <SummaryCard
              label="At port"
              value={slStaffDashboard.totalAtPort}
              hint="Import + export/repo at terminal"
              detail={`${containers.filter((c) => c.currentDwellDays > 0).length} with dwell tracking`}
              badges={slStaffDashboard.portCards.slice(0, 2).map((terminal) => `${terminal.code} ${terminal.atPort}`)}
              icon={<PlaceOutlinedIcon fontSize="small" />}
              tone="success"
            />
            <SummaryCard
              label="Outbound"
              value={slStaffDashboard.outboundRequests.reduce((sum, item) => sum + item.containerCount, 0)}
              hint={`${slStaffDashboard.inTransitOutbound} in transit · ${slStaffDashboard.pendingOutbound} pending approval`}
              detail="CY to port repositioning"
              badges={slStaffDashboard.portCards.slice(0, 2).map((terminal) => `${terminal.code} ${terminal.outbound}`)}
              icon={<LocalShippingOutlinedIcon fontSize="small" />}
              tone="warning"
            />
          </Box>
        </WorkflowSection>

        <WorkflowSection title="Container yards (CY)" subtitle="Return-location capacity, current occupancy, and near-term intake pressure.">
          <Box sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)', xl: 'repeat(4, 1fr)' } }}>
            <SummaryCard
              label="Container yards"
              value={Math.max(slStaffDashboard.cyCards.length, slStaffDashboard.cyTerminals.length)}
              hint="Empty return locations"
              icon={<WarehouseOutlinedIcon fontSize="small" />}
              tone="primary"
            />
            <SummaryCard
              label="At CY"
              value={slStaffDashboard.containersAtCy.length}
              hint="Physically allocated at yard"
              detail={`${containers.filter((c) => c.sizeCode?.includes('20')).length}x20ft · ${containers.filter((c) => c.sizeCode?.includes('40')).length}x40ft`}
              badges={slStaffDashboard.cyCards.slice(0, 2).map((yard) => `${yard.name} ${yard.allocated20 + yard.allocated40}`)}
              icon={<Inventory2OutlinedIcon fontSize="small" />}
              tone="success"
            />
            <SummaryCard
              label="Pre-forecast"
              value={slStaffDashboard.totalPreForecast}
              hint="Announced - pending arrival"
              detail={`${slStaffDashboard.pendingOutbound} tied to outbound movement`}
              badges={slStaffDashboard.cyCards.slice(0, 2).map((yard) => `${yard.name} ${yard.preForecast}`)}
              icon={<ScheduleOutlinedIcon fontSize="small" />}
              tone="warning"
            />
            <SummaryCard
              label="CY capacity"
              value={`${slStaffDashboard.totalUsedTeu} / ${slStaffDashboard.totalAllocatedTeu || 0} TEUs`}
              hint={`${slStaffDashboard.totalAllocatedTeu ? Math.round((slStaffDashboard.totalUsedTeu / slStaffDashboard.totalAllocatedTeu) * 1000) / 10 : 0}% full`}
              detail={`${slStaffDashboard.containersAtCy.length} at yard · ${slStaffDashboard.totalPreForecast} pre-forecast`}
              badges={slStaffDashboard.cyCards.slice(0, 2).map((yard) => `${yard.name} ${yard.utilizationPct}%`)}
              icon={<QueryStatsOutlinedIcon fontSize="small" />}
              tone="info"
            />
          </Box>
        </WorkflowSection>

        <WorkflowSection
          title="Port / Terminal Locations"
          subtitle="Laden inbound, at-port, and CY-to-port outbound volume by discharge terminal."
          actions={
            <Stack direction="row" spacing={1} alignItems="center">
              <Chip size="small" label={`${slStaffDashboard.portCards.length} Ports`} color="primary" />
              <Button component={RouterLink} to="/repositioning" size="small" variant="outlined">
                Outbound Requests
              </Button>
            </Stack>
          }
        >
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)', xl: 'repeat(3, 1fr)' } }}>
            {slStaffDashboard.portCards.map((terminal) => (
              <Paper key={terminal.id} elevation={0} sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2.5 }}>
                <Stack direction="row" spacing={1.25} alignItems="flex-start" mb={1.5}>
                  <Box
                    sx={{
                      width: 30,
                      height: 30,
                      borderRadius: 2,
                      bgcolor: 'rgba(103, 80, 164, 0.10)',
                      color: 'primary.main',
                      display: 'grid',
                      placeItems: 'center',
                      flexShrink: 0,
                    }}
                  >
                    <DirectionsBoatOutlinedIcon fontSize="small" />
                  </Box>
                  <Box minWidth={0} flex={1}>
                    <Typography fontWeight={800} fontSize="1.05rem" lineHeight={1.2}>
                      {terminal.name}
                    </Typography>
                    <Stack direction="row" spacing={0.75} alignItems="center" mt={0.5}>
                      <Chip
                        size="small"
                        label="TERMINAL"
                        sx={{
                          height: 18,
                          bgcolor: 'primary.main',
                          color: 'primary.contrastText',
                          '& .MuiChip-label': { px: 0.85, fontSize: 9, fontWeight: 700 },
                        }}
                      />
                      <Typography variant="caption" color="text.secondary">
                        {terminal.code}
                      </Typography>
                    </Stack>
                  </Box>
                </Stack>

                <TerminalSizeBlock
                  label="20ft Containers"
                  badge="20ft"
                  inbound={terminal.twenty.inbound}
                  atPort={terminal.twenty.atPort}
                  outbound={terminal.twenty.outbound}
                  available={terminal.twenty.available}
                  utilizationPct={terminal.twenty.utilizationPct}
                />

                <Box sx={{ my: 1.5 }} />

                <TerminalSizeBlock
                  label="40ft Containers"
                  badge="40ft"
                  inbound={terminal.forty.inbound}
                  atPort={terminal.forty.atPort}
                  outbound={terminal.forty.outbound}
                  available={terminal.forty.available}
                  utilizationPct={terminal.forty.utilizationPct}
                />
              </Paper>
            ))}
          </Box>
          <Alert severity="info" variant="outlined" sx={{ mt: 2 }}>
            {slStaffDashboard.locationNotes.port}
          </Alert>
        </WorkflowSection>

        <WorkflowSection
          title="CY Empty Return Locations"
          subtitle="Container yard capacity for empty returns - CY type only, excludes port terminals."
          actions={
            <Chip size="small" label={`${slStaffDashboard.cyCards.length} CY`} color="primary" />
          }
        >
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)', xl: 'repeat(3, 1fr)' } }}>
            {slStaffDashboard.cyCards.map((yard) => (
              <Paper key={yard.id} elevation={0} sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2.5 }}>
                <Stack direction="row" justifyContent="space-between" spacing={1} mb={1.5} alignItems="flex-start">
                  <Stack direction="row" spacing={1.25} alignItems="flex-start" minWidth={0}>
                    <Box
                      sx={{
                        width: 30,
                        height: 30,
                        borderRadius: 2,
                        bgcolor: 'rgba(55, 71, 79, 0.08)',
                        color: 'text.secondary',
                        display: 'grid',
                        placeItems: 'center',
                        flexShrink: 0,
                      }}
                    >
                      <WarehouseOutlinedIcon fontSize="small" />
                    </Box>
                    <Box minWidth={0}>
                      <Typography fontWeight={800} fontSize="1.05rem" lineHeight={1.2}>
                        {yard.name}
                      </Typography>
                      <Stack direction="row" spacing={0.75} alignItems="center" mt={0.5}>
                        <Chip
                          size="small"
                          label="CONTAINER YARD"
                          sx={{
                            height: 18,
                            bgcolor: 'text.primary',
                            color: 'background.paper',
                            '& .MuiChip-label': { px: 0.85, fontSize: 9, fontWeight: 700 },
                          }}
                        />
                      </Stack>
                      <Typography variant="caption" color="text.secondary" display="block" mt={0.5}>
                        {yard.location || 'Return location'}
                      </Typography>
                    </Box>
                  </Stack>
                  <Chip
                    size="small"
                    label={yard.utilizationPct >= 90 ? 'Near capacity' : 'Available'}
                    color={yard.utilizationPct >= 90 ? 'warning' : 'success'}
                  />
                </Stack>

                <CySizeBlock
                  label="20ft Containers"
                  badge="20ft"
                  capacity={yard.capacity20}
                  allocated={yard.allocated20}
                  preForecast={Math.round(yard.preForecast / 2)}
                  available={yard.available20}
                  utilizationPct={yard.capacity20 ? Math.round((yard.allocated20 / yard.capacity20) * 1000) / 10 : 0}
                />

                <Box sx={{ my: 1.5 }} />

                <CySizeBlock
                  label="40ft Containers"
                  badge="40ft"
                  capacity={yard.capacity40}
                  allocated={yard.allocated40}
                  preForecast={Math.max(yard.preForecast - Math.round(yard.preForecast / 2), 0)}
                  available={yard.available40}
                  utilizationPct={yard.capacity40 ? Math.round((yard.allocated40 / yard.capacity40) * 1000) / 10 : 0}
                />

                <Button component={RouterLink} to="/repositioning" variant="outlined" size="small" fullWidth sx={{ mt: 1.5 }}>
                  Request Export / Repo
                </Button>
              </Paper>
            ))}
          </Box>
          <Alert severity="info" variant="outlined" sx={{ mt: 2 }}>
            {slStaffDashboard.locationNotes.cy}
          </Alert>
        </WorkflowSection>

        <WorkflowSection title="Operational follow-up" subtitle="Fast links back into the core workflow queues used by SL Staff.">
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(4, 1fr)' } }}>
            {quickActions.map((action) => {
              const Icon = action.icon;
              return (
                <Button
                  key={action.id}
                  component={RouterLink}
                  to={action.path}
                  variant="outlined"
                  sx={{ justifyContent: 'flex-start', gap: 1.25, py: 1.5, textTransform: 'none', borderRadius: 2 }}
                >
                  <Icon fontSize="small" />
                  {action.label}
                </Button>
              );
            })}
          </Box>
        </WorkflowSection>

        <WorkflowSection title="Unread alerts" subtitle="Keep exceptions and release-related updates visible from the dashboard.">
          {unread.length === 0 ? (
            <Alert severity="success" variant="outlined">
              No unread alerts right now.
            </Alert>
          ) : (
            <Stack spacing={1.25}>
              {unread.slice(0, 5).map((n) => (
                <Paper
                  key={n.id}
                  elevation={0}
                  sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.default' }}
                >
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} justifyContent="space-between">
                    <Box component={RouterLink} to={`/notifications/${n.id}`} sx={{ textDecoration: 'none', color: 'inherit', minWidth: 0 }}>
                      <Typography fontWeight={700}>{n.title}</Typography>
                      <Typography variant="body2" color="text.secondary">
                        {n.message}
                      </Typography>
                    </Box>
                    <Button
                      size="small"
                      onClick={async () => {
                        await markRead({ notificationId: n.id });
                      }}
                    >
                      Mark read
                    </Button>
                  </Stack>
                </Paper>
              ))}
            </Stack>
          )}
        </WorkflowSection>
      </WorkflowPage>
    );
  }

  return (
    <WorkflowPage
      eyebrow={summary.eyebrow}
      title={`${greetingForNow()}, ${user?.fullName?.split(' ')[0] ?? 'there'}`}
      subtitle={summary.subtitle}
      chips={
        <>
          <Chip label={roleChip} color="primary" size="small" />
          <Chip label={`${unread.length} unread alerts`} size="small" color={unread.length ? 'warning' : 'success'} />
        </>
      }
      stats={uniqueStats}
    >
      <Box
        sx={{
          display: 'grid',
          gap: 3,
          gridTemplateColumns: { xs: '1fr', xl: 'minmax(0, 2fr) minmax(280px, 360px)' },
        }}
      >
        <Stack spacing={3}>
          <WorkflowSection
            title="Priority work"
            subtitle="The main queues and responsibilities for your role today."
          >
            <Box
              sx={{
                display: 'grid',
                gap: 1.5,
                gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' },
              }}
            >
              {queueCards.map((item) => (
                <Paper
                  key={item.label}
                  component={RouterLink}
                  to={item.to}
                  elevation={0}
                  sx={{
                    p: 2,
                    border: 1,
                    borderColor: 'divider',
                    borderRadius: 2,
                    textDecoration: 'none',
                    color: 'inherit',
                    bgcolor: 'background.default',
                  }}
                >
                  <Typography variant="caption" color="text.secondary" fontWeight={600}>
                    {item.label}
                  </Typography>
                  <Typography variant="h4" fontWeight={800} color="primary.main" mt={0.5}>
                    {item.value}
                  </Typography>
                  <Typography variant="body2" color="text.secondary" mt={0.75}>
                    {item.hint}
                  </Typography>
                </Paper>
              ))}
            </Box>
          </WorkflowSection>

          <WorkflowSection title="Unread alerts" subtitle="Escalations, failed actions, and workflow updates needing attention.">
            {unread.length === 0 ? (
              <Alert severity="success" variant="outlined">
                You are caught up. No unread shipping alerts right now.
              </Alert>
            ) : (
              <Stack spacing={1.25}>
                {unread.slice(0, 5).map((n) => (
                  <Paper
                    key={n.id}
                    elevation={0}
                    sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.default' }}
                  >
                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} justifyContent="space-between">
                      <Box component={RouterLink} to={`/notifications/${n.id}`} sx={{ textDecoration: 'none', color: 'inherit', minWidth: 0 }}>
                        <Typography fontWeight={700}>{n.title}</Typography>
                        <Typography variant="body2" color="text.secondary">
                          {n.message}
                        </Typography>
                      </Box>
                      <Button
                        size="small"
                        onClick={async () => {
                          await markRead({ notificationId: n.id });
                        }}
                      >
                        Mark read
                      </Button>
                    </Stack>
                  </Paper>
                ))}
              </Stack>
            )}
          </WorkflowSection>
        </Stack>

        <Stack spacing={3}>
          <WorkflowSection title="Quick actions" subtitle="Common links for this role.">
            <Stack spacing={1.25}>
              {quickActions.map((action) => {
                const Icon = action.icon;
                return (
                  <Button
                    key={action.id}
                    component={RouterLink}
                    to={action.path}
                    variant="outlined"
                    color="primary"
                    sx={{ justifyContent: 'flex-start', gap: 1.25, py: 1.25, textTransform: 'none', borderRadius: 2 }}
                  >
                    <Icon fontSize="small" />
                    {action.label}
                  </Button>
                );
              })}
            </Stack>
          </WorkflowSection>

          <WorkflowSection title="Today’s operating posture" subtitle="Use this page as the starting point before moving into a queue.">
            <Stack spacing={1}>
              <Typography variant="body2" color="text.secondary">
                Shipping-side work in V2 is organized around the same operational lanes as V1: cargo workflow, release workflow, terminal operations, review queues, and governance.
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Focus first on overdue alerts, then clear the primary queue for your role, then finish secondary maintenance work such as hierarchy, platform settings, or reporting.
              </Typography>
            </Stack>
          </WorkflowSection>
        </Stack>
      </Box>
    </WorkflowPage>
  );
}

function StatCard({
  label,
  value,
  hint,
  icon,
  accentColor = 'text.primary',
}: {
  label: string;
  value: string | number;
  hint: string;
  icon?: ReactNode;
  accentColor?: string;
}) {
  return (
    <Paper elevation={0} sx={{ px: 1.75, py: 1.35, border: 1, borderColor: 'divider', borderRadius: 1.5 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={1}>
        <Box minWidth={0}>
          <Typography
            variant="caption"
            color="text.secondary"
            fontWeight={700}
            sx={{ textTransform: 'uppercase', letterSpacing: 0.35, fontSize: 11 }}
          >
            {label}
          </Typography>
          <Typography fontWeight={800} mt={0.25} sx={{ fontSize: 22, lineHeight: 1.15, letterSpacing: '-0.02em' }}>
            {value}
          </Typography>
          <Typography variant="caption" sx={{ color: accentColor, fontSize: 11 }}>
            {hint}
          </Typography>
        </Box>
        {icon && (
          <Box sx={{ color: 'text.secondary', mt: 0.25, opacity: 0.75 }}>
            {icon}
          </Box>
        )}
      </Stack>
    </Paper>
  );
}

function SummaryCard({
  label,
  value,
  hint,
  detail,
  badges,
  icon,
  tone = 'primary',
}: {
  label: string;
  value: string | number;
  hint: string;
  detail?: string;
  badges?: string[];
  icon?: ReactNode;
  tone?: 'primary' | 'info' | 'success' | 'warning';
}) {
  const colorMap = {
    primary: 'primary.main',
    info: 'info.main',
    success: 'success.main',
    warning: 'warning.main',
  } as const;
  const bgMap = {
    primary: 'rgba(11, 61, 92, 0.10)',
    info: 'rgba(2, 136, 209, 0.10)',
    success: 'rgba(46, 125, 50, 0.10)',
    warning: 'rgba(237, 108, 2, 0.10)',
  } as const;

  return (
    <Paper elevation={0} sx={{ px: 1.75, py: 1.5, border: 1, borderColor: 'divider', borderRadius: 1.5 }}>
      <Stack direction="row" spacing={1} alignItems="flex-start">
        {icon && (
          <Box
            sx={{
              width: 26,
              height: 26,
              borderRadius: 1.25,
              bgcolor: bgMap[tone],
              color: colorMap[tone],
              display: 'grid',
              placeItems: 'center',
              flexShrink: 0,
              mt: 0.15,
            }}
          >
            {icon}
          </Box>
        )}
        <Box minWidth={0}>
          <Typography
            variant="caption"
            color="text.secondary"
            fontWeight={700}
            sx={{ textTransform: 'uppercase', letterSpacing: 0.3, fontSize: 11 }}
          >
            {label}
          </Typography>
          <Typography
            fontWeight={800}
            color={colorMap[tone]}
            mt={0.15}
            sx={{
              fontSize: String(value).length > 8 ? 18 : 22,
              lineHeight: 1.15,
              letterSpacing: '-0.02em',
              wordBreak: 'break-word',
            }}
          >
            {value}
          </Typography>
          <Typography variant="caption" sx={{ color: colorMap[tone], display: 'block', mt: 0.35, fontSize: 11 }}>
            {hint}
          </Typography>
          {detail && (
            <Typography variant="caption" color="text.secondary" display="block" mt={0.25} sx={{ fontSize: 11 }}>
              {detail}
            </Typography>
          )}
          {badges && badges.length > 0 && (
            <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap mt={0.75}>
              {badges.map((badge) => (
                <Chip
                  key={badge}
                  size="small"
                  label={badge}
                  sx={{
                    height: 20,
                    borderRadius: 1,
                    bgcolor: `${tone}.light`,
                    color: `${tone}.contrastText`,
                    '& .MuiChip-label': { px: 0.75, fontWeight: 700, fontSize: 10 },
                  }}
                />
              ))}
            </Stack>
          )}
        </Box>
      </Stack>
    </Paper>
  );
}

function TerminalSizeBlock({
  label,
  badge,
  inbound,
  atPort,
  outbound,
  available,
  utilizationPct,
}: {
  label: string;
  badge: string;
  inbound: number;
  atPort: number;
  outbound: number;
  available: number;
  utilizationPct: number;
}) {
  return (
    <Box>
      <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1}>
        <Typography fontWeight={700} fontSize="0.95rem">
          {label}
        </Typography>
        <Chip
          size="small"
          label={badge}
          sx={{
            height: 22,
            borderRadius: 999,
            bgcolor: 'text.primary',
            color: 'background.paper',
            '& .MuiChip-label': { px: 0.9, fontSize: 10, fontWeight: 700 },
          }}
        />
      </Stack>
      <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 1 }}>
        <MetricColumn label="Inbound" value={inbound} color="info.main" />
        <MetricColumn label="At Port" value={atPort} color="success.main" />
        <MetricColumn label="Outbound" value={outbound} color="warning.main" />
        <MetricColumn label="Avail." value={available} color="text.primary" />
      </Box>
      <Typography variant="caption" color="text.secondary" display="block" mt={1.25}>
        Utilization
      </Typography>
      <Stack direction="row" spacing={1} alignItems="center" mt={0.5}>
        <Box sx={{ flex: 1, height: 10, borderRadius: 999, bgcolor: 'rgba(18,18,18,0.75)', overflow: 'hidden' }}>
          <Box
            sx={{
              width: `${Math.max(Math.min(utilizationPct, 100), 0)}%`,
              height: '100%',
              borderRadius: 999,
              bgcolor: 'primary.main',
            }}
          />
        </Box>
        <Typography variant="body2" fontWeight={700} color="text.secondary">
          {utilizationPct}%
        </Typography>
      </Stack>
    </Box>
  );
}

function MetricColumn({
  label,
  value,
  color,
}: {
  label: string;
  value: number;
  color: string;
}) {
  return (
    <Box textAlign="center">
      <Typography variant="caption" color="text.secondary">
        {label}
      </Typography>
      <Typography fontWeight={800} color={color} fontSize="1.1rem" lineHeight={1.1} mt={0.25}>
        {value}
      </Typography>
    </Box>
  );
}

function CySizeBlock({
  label,
  badge,
  capacity,
  allocated,
  preForecast,
  available,
  utilizationPct,
}: {
  label: string;
  badge: string;
  capacity: number;
  allocated: number;
  preForecast: number;
  available: number;
  utilizationPct: number;
}) {
  return (
    <Box>
      <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1}>
        <Typography fontWeight={700} fontSize="0.95rem">
          {label}
        </Typography>
        <Chip
          size="small"
          label={badge}
          sx={{
            height: 22,
            borderRadius: 999,
            bgcolor: 'text.primary',
            color: 'background.paper',
            '& .MuiChip-label': { px: 0.9, fontSize: 10, fontWeight: 700 },
          }}
        />
      </Stack>
      <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 1 }}>
        <MetricColumn label="Capacity" value={capacity} color="text.primary" />
        <MetricColumn label="Allocated" value={allocated} color="info.main" />
        <MetricColumn label="Pre-Forecast" value={preForecast} color="warning.main" />
        <MetricColumn label="Available" value={available} color="success.main" />
      </Box>
      <Typography variant="caption" color="text.secondary" display="block" mt={1.25}>
        Utilization
      </Typography>
      <Stack direction="row" spacing={1} alignItems="center" mt={0.5}>
        <Box sx={{ flex: 1, height: 10, borderRadius: 999, bgcolor: 'rgba(18,18,18,0.75)', overflow: 'hidden' }}>
          <Box
            sx={{
              width: `${Math.max(Math.min(utilizationPct, 100), 0)}%`,
              height: '100%',
              borderRadius: 999,
              bgcolor: 'success.main',
            }}
          />
        </Box>
        <Typography variant="body2" fontWeight={700} color="text.secondary">
          {utilizationPct}%
        </Typography>
      </Stack>
    </Box>
  );
}

function splitCount(total: number, ratio: number): number {
  return Math.max(Math.round(total * ratio), 0);
}
