import { useMemo, useState } from 'react';
import {
  Box,
  Divider,
  ListItemIcon,
  ListItemText,
  Menu,
  MenuItem,
  Typography,
} from '@mui/material';
import UnfoldMoreIcon from '@mui/icons-material/UnfoldMore';
import CheckIcon from '@mui/icons-material/Check';
import AddBusinessOutlinedIcon from '@mui/icons-material/AddBusinessOutlined';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { useGetWorkspacesQuery, useSwitchWorkspaceMutation } from '../../app/api';
import { setCredentials } from '../../app/authSlice';
import type { RootState } from '../../app/store';
import { OptimusLogo } from '../../shared/OptimusLogo';

function initials(name?: string | null): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

type SidebarBrandHeaderProps = {
  role?: string | null;
  onNavigate?: () => void;
};

export function SidebarBrandHeader({ role, onNavigate }: SidebarBrandHeaderProps) {
  const isBroker = role === 'Broker';

  return (
    <Box sx={{ px: 2, pt: 2, pb: 1.5, flexShrink: 0 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', px: 0.5, mb: isBroker ? 2 : 0.75 }}>
        <OptimusLogo size="sm" />
      </Box>

      {isBroker ? (
        <BrokerWorkspaceCard onNavigate={onNavigate} />
      ) : (
        <Typography variant="caption" color="text.secondary" sx={{ px: 0.5 }}>
          {role === 'Consignee' ? 'Consignee Portal' : 'Shipping portal'}
        </Typography>
      )}
    </Box>
  );
}

function BrokerWorkspaceCard({ onNavigate }: { onNavigate?: () => void }) {
  const dispatch = useDispatch();
  const navigate = useNavigate();
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const { data: workspaces = [] } = useGetWorkspacesQuery(undefined, {
    skip: !accessToken,
  });
  const [switchWorkspace, { isLoading }] = useSwitchWorkspaceMutation();
  const [anchorEl, setAnchorEl] = useState<null | HTMLElement>(null);
  const open = Boolean(anchorEl);

  const active = useMemo(
    () => workspaces.find((w) => w.id === user?.activeWorkspaceConsigneeId),
    [workspaces, user?.activeWorkspaceConsigneeId],
  );

  const displayName = active
    ? active.businessName || active.fullName
    : workspaces.length
      ? 'Select workspace'
      : 'No workspace';

  const onSelect = async (consigneeId: string) => {
    if (consigneeId === user?.activeWorkspaceConsigneeId) {
      setAnchorEl(null);
      return;
    }
    try {
      const result = await switchWorkspace({ consigneeId }).unwrap();
      dispatch(
        setCredentials({
          accessToken: result.accessToken,
          refreshToken: result.refreshToken,
          user: result.user,
        }),
      );
      setAnchorEl(null);
      const ws = workspaces.find((w) => w.id === consigneeId);
      navigate('/', {
        state: {
          switchedWorkspace: ws?.businessName || ws?.fullName || 'workspace',
        },
      });
      onNavigate?.();
    } catch {
      setAnchorEl(null);
      navigate('/workspace');
      onNavigate?.();
    }
  };

  return (
    <>
      <Box
        component="button"
        type="button"
        onClick={(e) => setAnchorEl(e.currentTarget)}
        aria-haspopup="menu"
        aria-expanded={open ? 'true' : undefined}
        disabled={isLoading}
        sx={{
          all: 'unset',
          boxSizing: 'border-box',
          display: 'flex',
          alignItems: 'center',
          gap: 1.25,
          width: '100%',
          px: 1.25,
          py: 1.1,
          borderRadius: 2,
          border: 1,
          borderColor: open ? 'primary.main' : 'divider',
          bgcolor: 'background.default',
          cursor: 'pointer',
          transition: 'border-color 0.15s, background-color 0.15s',
          '&:hover': { bgcolor: 'action.hover' },
          '&:disabled': { opacity: 0.7, cursor: 'wait' },
        }}
      >
        <Box
          sx={{
            width: 36,
            height: 36,
            borderRadius: 1.25,
            bgcolor: 'secondary.main',
            color: 'secondary.contrastText',
            display: 'grid',
            placeItems: 'center',
            fontWeight: 700,
            fontSize: 12,
            flexShrink: 0,
          }}
        >
          {initials(displayName === 'Select workspace' || displayName === 'No workspace' ? 'WS' : displayName)}
        </Box>
        <Box minWidth={0} flex={1}>
          <Typography
            variant="caption"
            color="text.secondary"
            display="block"
            lineHeight={1.2}
            fontWeight={500}
          >
            Workspace
          </Typography>
          <Typography variant="body2" fontWeight={700} noWrap lineHeight={1.3}>
            {displayName}
          </Typography>
        </Box>
        <UnfoldMoreIcon sx={{ fontSize: 20, color: 'text.disabled', flexShrink: 0 }} />
      </Box>

      <Menu
        anchorEl={anchorEl}
        open={open}
        onClose={() => setAnchorEl(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}
        transformOrigin={{ vertical: 'top', horizontal: 'left' }}
        PaperProps={{ sx: { width: 260, mt: 0.75, borderRadius: 2 } }}
      >
        <Typography
          variant="caption"
          color="text.secondary"
          fontWeight={600}
          sx={{ px: 2, py: 1, display: 'block' }}
        >
          Switch workspace
        </Typography>
        {workspaces.length === 0 && (
          <MenuItem disabled>
            <ListItemText primary="No linked consignees" secondary="Apply a referral code" />
          </MenuItem>
        )}
        {workspaces.map((w) => {
          const name = w.businessName || w.fullName;
          const selected = w.id === user?.activeWorkspaceConsigneeId;
          return (
            <MenuItem key={w.id} selected={selected} onClick={() => void onSelect(w.id)}>
              <ListItemIcon sx={{ minWidth: 36 }}>
                {selected ? <CheckIcon fontSize="small" color="primary" /> : <Box width={20} />}
              </ListItemIcon>
              <ListItemText
                primary={name}
                primaryTypographyProps={{ variant: 'body2', fontWeight: selected ? 700 : 500, noWrap: true }}
              />
            </MenuItem>
          );
        })}
        <Divider sx={{ my: 0.5 }} />
        <MenuItem
          onClick={() => {
            setAnchorEl(null);
            navigate('/workspace');
            onNavigate?.();
          }}
        >
          <ListItemIcon sx={{ minWidth: 36 }}>
            <AddBusinessOutlinedIcon fontSize="small" />
          </ListItemIcon>
          <ListItemText
            primary="Manage workspaces"
            primaryTypographyProps={{ variant: 'body2', fontWeight: 500 }}
          />
        </MenuItem>
      </Menu>
    </>
  );
}
