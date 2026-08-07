import { CircularProgress, Stack, Typography } from '@mui/material';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { useGetWorkspacesQuery } from '../../app/api';

/**
 * Paths inside AppShell that brokers may use without an active consignee workspace.
 * Cargo / dashboard / eDO / payments stay blocked until a referral relationship exists
 * and a workspace is selected.
 */
const OPEN_WITHOUT_WORKSPACE = ['/profile', '/notifications', '/sas'] as const;

function isOpenWithoutWorkspace(pathname: string): boolean {
  return OPEN_WITHOUT_WORKSPACE.some((p) => pathname === p || pathname.startsWith(`${p}/`));
}

/**
 * Brokers must have a consignee referral (workspace) and an active selection
 * before using the full broker portal. Mirrors V1 WorkspaceFilterSubscriber.
 */
export function BrokerWorkspaceGate() {
  const location = useLocation();
  const { user } = useSelector((state: RootState) => state.auth);
  const isBroker = user?.role === 'Broker';
  const { data: workspaces, isLoading } = useGetWorkspacesQuery(undefined, { skip: !isBroker });

  if (!isBroker) {
    return <Outlet />;
  }

  const openPath = isOpenWithoutWorkspace(location.pathname);
  const hasActive = Boolean(user?.activeWorkspaceConsigneeId);
  const workspaceListReady = workspaces !== undefined;
  const hasAnyWorkspace = (workspaces?.length ?? 0) > 0;
  const activeStillValid =
    hasActive &&
    workspaces?.some((w) => w.id === user?.activeWorkspaceConsigneeId);

  // Waiting on workspace list before deciding hard redirects (except open paths).
  if (isLoading && !openPath) {
    return (
      <Stack alignItems="center" py={8} spacing={2}>
        <CircularProgress size={28} />
        <Typography color="text.secondary">Checking workspace…</Typography>
      </Stack>
    );
  }

  // No consignee referral / linked workspace → lock to workspace gate flow.
  if (workspaceListReady && !hasAnyWorkspace && !openPath) {
    return <Navigate to="/workspace" replace state={{ from: location.pathname }} />;
  }

  // Has workspaces but none selected (or stale id) → must pick one.
  if (!openPath && (!hasActive || (workspaceListReady && hasAnyWorkspace && !activeStillValid))) {
    return <Navigate to="/workspace" replace state={{ from: location.pathname }} />;
  }

  return <Outlet />;
}
