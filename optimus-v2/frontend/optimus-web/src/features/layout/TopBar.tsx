import { useMemo, useState } from 'react';
import {
  AppBar,
  Avatar,
  Badge,
  Box,
  Chip,
  Divider,
  IconButton,
  ListItemIcon,
  ListItemText,
  Menu,
  MenuItem,
  Toolbar,
  Tooltip,
  Typography,
} from '@mui/material';
import DashboardOutlinedIcon from '@mui/icons-material/DashboardOutlined';
import PersonOutlineOutlinedIcon from '@mui/icons-material/PersonOutlineOutlined';
import LogoutOutlinedIcon from '@mui/icons-material/LogoutOutlined';
import NotificationsNoneOutlinedIcon from '@mui/icons-material/NotificationsNoneOutlined';
import MenuIcon from '@mui/icons-material/Menu';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
import { logout } from '../../app/authSlice';
import type { RootState } from '../../app/store';
import {
  useGetNotificationsQuery,
  useMarkNotificationsReadMutation,
} from '../../app/api';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { relativeTime } from '../../shared/dateTime';
import { formatRoleLabel } from '../../shared/roleLabels';
import { OptimusLogo } from '../../shared/OptimusLogo';
import { ColorModeToggle } from '../../shared/ColorModeToggle';
import { DRAWER_WIDTH } from './navConfig';

type TopBarProps = {
  isDesktop: boolean;
  onOpenNav: () => void;
};

function initials(name?: string | null): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

export function TopBar({ isDesktop, onOpenNav }: TopBarProps) {
  const dispatch = useDispatch();
  const navigate = useNavigate();
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const { shippingLine } = useDefaultShippingLine();
  const { data: notifications = [] } = useGetNotificationsQuery(undefined, {
    skip: !accessToken,
    pollingInterval: 30_000,
  });
  const [markRead] = useMarkNotificationsReadMutation();
  const unreadCount = useMemo(() => notifications.filter((n) => !n.isRead).length, [notifications]);
  const recentNotifications = useMemo(() => notifications.slice(0, 8), [notifications]);

  const [accountEl, setAccountEl] = useState<null | HTMLElement>(null);
  const [notifEl, setNotifEl] = useState<null | HTMLElement>(null);
  const accountOpen = Boolean(accountEl);
  const notifOpen = Boolean(notifEl);

  const lineLabel = shippingLine?.brandName ?? 'Shipping line';
  const roleLabel = formatRoleLabel(user?.role);
  const contextLabel =
    user?.role === 'ShippingLinesAdmin'
      ? roleLabel
      : ['Broker', 'Consignee'].includes(user?.role ?? '')
        ? lineLabel
        : roleLabel || lineLabel;
  const portalLabel = ['Broker', 'Consignee'].includes(user?.role ?? '') ? 'Workspace' : 'Shipping Portal';

  const onLogout = () => {
    setAccountEl(null);
    dispatch(logout());
    navigate('/login');
  };

  const onMarkOne = async (id: string) => {
    await markRead({ notificationId: id });
  };

  const onMarkAll = async () => {
    await markRead({});
  };

  return (
    <AppBar
      position="fixed"
      elevation={0}
      color="inherit"
      sx={{
        zIndex: (t) => t.zIndex.drawer + 1,
        bgcolor: 'background.paper',
        color: 'text.primary',
        borderBottom: 1,
        borderColor: 'divider',
        ml: { md: `${DRAWER_WIDTH}px` },
        width: { md: `calc(100% - ${DRAWER_WIDTH}px)` },
      }}
    >
      <Toolbar
        sx={{
          gap: { xs: 1, sm: 1.5 },
          minHeight: { xs: 56, sm: 64 },
          px: { xs: 1.5, sm: 2.5 },
        }}
      >
        {!isDesktop && (
          <IconButton edge="start" onClick={onOpenNav} aria-label="Open menu" sx={{ color: 'primary.main' }}>
            <MenuIcon />
          </IconButton>
        )}

        <Box
          component={RouterLink}
          to="/"
          sx={{
            display: 'flex',
            alignItems: 'center',
            gap: 1.25,
            textDecoration: 'none',
            color: 'inherit',
            mr: { xs: 0, sm: 1 },
            minWidth: 0,
          }}
        >
          <OptimusLogo size="xs" />
          <Typography
            component="span"
            sx={{
              display: { xs: 'none', sm: 'inline' },
              color: 'text.disabled',
              fontWeight: 500,
              px: 0.25,
            }}
          >
            |
          </Typography>
          <Typography
            variant="body2"
            noWrap
            sx={{
              display: { xs: 'none', sm: 'inline' },
              color: 'text.secondary',
              fontWeight: 600,
              maxWidth: { sm: 180, md: 280 },
            }}
          >
            {contextLabel}
          </Typography>
        </Box>

        <Box flexGrow={1} />

        <Chip
          label={portalLabel}
          size="small"
          color="primary"
          variant="outlined"
          sx={{ display: { xs: 'none', md: 'inline-flex' }, fontWeight: 600 }}
        />

        <ColorModeToggle />

        <Tooltip title={unreadCount ? `${unreadCount} unread` : 'Alerts'}>
          <IconButton
            onClick={(e) => setNotifEl(e.currentTarget)}
            aria-label="Alerts"
            aria-controls={notifOpen ? 'notifications-menu' : undefined}
            aria-haspopup="true"
            aria-expanded={notifOpen ? 'true' : undefined}
            sx={{
              color: notifOpen ? 'primary.main' : 'text.secondary',
              border: 1,
              borderColor: notifOpen ? 'primary.main' : 'divider',
              borderRadius: 2,
              width: 40,
              height: 40,
            }}
          >
            <Badge color="secondary" badgeContent={unreadCount} max={9} overlap="circular">
              <NotificationsNoneOutlinedIcon fontSize="small" />
            </Badge>
          </IconButton>
        </Tooltip>

        <Menu
          id="notifications-menu"
          anchorEl={notifEl}
          open={notifOpen}
          onClose={() => setNotifEl(null)}
          anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
          transformOrigin={{ vertical: 'top', horizontal: 'right' }}
          slotProps={{
            paper: {
              elevation: 0,
              sx: {
                mt: 1,
                width: { xs: 320, sm: 380 },
                maxWidth: 'calc(100vw - 24px)',
                border: 1,
                borderColor: 'divider',
                borderRadius: 2,
                overflow: 'hidden',
              },
            },
          }}
        >
          <Box
            sx={{
              px: 2,
              py: 1.5,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 1,
            }}
          >
            <Typography variant="subtitle2" fontWeight={700}>
              Notifications
            </Typography>
            {unreadCount > 0 && (
              <Typography
                component="button"
                variant="caption"
                onClick={onMarkAll}
                sx={{
                  border: 0,
                  bgcolor: 'transparent',
                  color: 'primary.main',
                  cursor: 'pointer',
                  fontWeight: 600,
                  p: 0,
                }}
              >
                Mark all read
              </Typography>
            )}
          </Box>
          <Divider />
          {recentNotifications.length === 0 ? (
            <Box sx={{ px: 2, py: 3 }}>
              <Typography variant="body2" color="text.secondary" textAlign="center">
                No notifications yet.
              </Typography>
            </Box>
          ) : (
            <Box sx={{ maxHeight: 360, overflowY: 'auto' }}>
              {recentNotifications.map((n) => (
                <MenuItem
                  key={n.id}
                  onClick={async () => {
                    setNotifEl(null);
                    if (!n.isRead) await onMarkOne(n.id);
                    if (n.category === 'sas') {
                      navigate(user?.role === 'ShippingLinesAdmin' ? '/approvals' : '/sas');
                      return;
                    }
                    navigate(`/notifications/${n.id}`);
                  }}
                  sx={{
                    alignItems: 'flex-start',
                    py: 1.25,
                    px: 2,
                    whiteSpace: 'normal',
                    bgcolor: n.isRead ? 'transparent' : 'action.selected',
                    borderBottom: 1,
                    borderColor: 'divider',
                    '&:last-of-type': { borderBottom: 0 },
                  }}
                >
                  <Box sx={{ width: '100%', minWidth: 0 }}>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 1, mb: 0.25 }}>
                      <Typography variant="body2" fontWeight={n.isRead ? 500 : 700} noWrap>
                        {n.title}
                      </Typography>
                      <Typography variant="caption" color="text.secondary" sx={{ flexShrink: 0 }}>
                        {relativeTime(n.createdAt)}
                      </Typography>
                    </Box>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                      {n.message}
                    </Typography>
                  </Box>
                </MenuItem>
              ))}
            </Box>
          )}
          <Divider />
          <MenuItem
            onClick={() => {
              setNotifEl(null);
              navigate('/notifications');
            }}
            sx={{ justifyContent: 'center', py: 1.25 }}
          >
            <Typography variant="body2" fontWeight={600} color="primary.main">
              View all alerts
            </Typography>
          </MenuItem>
        </Menu>

        <Tooltip title="Account">
          <IconButton
            onClick={(e) => setAccountEl(e.currentTarget)}
            aria-controls={accountOpen ? 'account-menu' : undefined}
            aria-haspopup="true"
            aria-expanded={accountOpen ? 'true' : undefined}
            sx={{
              p: 0.5,
              border: 1,
              borderColor: 'divider',
              borderRadius: 2,
              gap: 1,
              pl: { xs: 0.5, sm: 0.75 },
              pr: { xs: 0.5, sm: 1.25 },
            }}
          >
            <Avatar
              sx={{
                width: 32,
                height: 32,
                bgcolor: 'primary.main',
                fontSize: 13,
                fontWeight: 600,
              }}
            >
              {initials(user?.fullName)}
            </Avatar>
            <Box sx={{ display: { xs: 'none', sm: 'block' }, textAlign: 'left', minWidth: 0 }}>
              <Typography variant="body2" fontWeight={600} noWrap sx={{ maxWidth: 140, lineHeight: 1.2 }}>
                {user?.fullName}
              </Typography>
              <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block', lineHeight: 1.2 }}>
                {roleLabel || user?.role}
              </Typography>
            </Box>
          </IconButton>
        </Tooltip>

        <Menu
          id="account-menu"
          anchorEl={accountEl}
          open={accountOpen}
          onClose={() => setAccountEl(null)}
          anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
          transformOrigin={{ vertical: 'top', horizontal: 'right' }}
          slotProps={{
            paper: {
              elevation: 0,
              sx: {
                mt: 1,
                minWidth: 240,
                border: 1,
                borderColor: 'divider',
                borderRadius: 2,
                overflow: 'hidden',
              },
            },
          }}
        >
          <Box sx={{ px: 2, py: 1.5 }}>
            <Typography variant="subtitle2" fontWeight={700}>
              {user?.fullName}
            </Typography>
            <Typography variant="caption" color="text.secondary" display="block">
              {user?.email}
            </Typography>
            <Typography
              variant="caption"
              sx={{
                mt: 0.75,
                display: 'inline-block',
                px: 1,
                py: 0.25,
                borderRadius: 1,
                bgcolor: 'primary.main',
                color: 'primary.contrastText',
                fontWeight: 600,
              }}
            >
              {roleLabel || user?.role}
            </Typography>
          </Box>
          <Divider />
          <MenuItem
            onClick={() => {
              setAccountEl(null);
              navigate('/profile');
            }}
          >
            <ListItemIcon>
              <PersonOutlineOutlinedIcon fontSize="small" />
            </ListItemIcon>
            <ListItemText>Profile</ListItemText>
          </MenuItem>
          <MenuItem
            onClick={() => {
              setAccountEl(null);
              navigate('/');
            }}
          >
            <ListItemIcon>
              <DashboardOutlinedIcon fontSize="small" />
            </ListItemIcon>
            <ListItemText>Dashboard</ListItemText>
          </MenuItem>
          <Divider />
          <MenuItem onClick={onLogout}>
            <ListItemIcon>
              <LogoutOutlinedIcon fontSize="small" />
            </ListItemIcon>
            <ListItemText>Log out</ListItemText>
          </MenuItem>
        </Menu>
      </Toolbar>
    </AppBar>
  );
}
