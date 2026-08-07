import { useEffect, useState } from 'react';
import {
  Avatar,
  Box,
  BottomNavigation,
  BottomNavigationAction,
  Container,
  Drawer,
  Paper,
  Toolbar,
  Typography,
  useMediaQuery,
} from '@mui/material';
import { useTheme } from '@mui/material/styles';
import { useSelector } from 'react-redux';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import type { RootState } from '../../app/store';
import { formatRoleLabel } from '../../shared/roleLabels';
import { DRAWER_WIDTH, getBottomNavItems } from './navConfig';
import { SideNav } from './SideNav';
import { SidebarBrandHeader } from './SidebarBrandHeader';
import { TopBar } from './TopBar';

function initials(name?: string | null): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

export function AppShell() {
  const theme = useTheme();
  const isDesktop = useMediaQuery(theme.breakpoints.up('md'));
  const [mobileOpen, setMobileOpen] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const { user } = useSelector((state: RootState) => state.auth);
  const bottomItems = getBottomNavItems(user?.role);

  useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  const bottomValue =
    bottomItems.findIndex(
      (item) =>
        item.path === location.pathname ||
        (item.path !== '/' && location.pathname.startsWith(item.path)),
    ) ?? -1;

  const drawerContent = (
    <Box
      sx={{
        display: 'flex',
        flexDirection: 'column',
        height: '100%',
        minHeight: 0,
        overflow: 'hidden',
      }}
    >
      <SidebarBrandHeader role={user?.role} onNavigate={() => setMobileOpen(false)} />
      <SideNav role={user?.role} onNavigate={() => setMobileOpen(false)} dense />
      {user && (
        <Box
          sx={{
            mt: 'auto',
            flexShrink: 0,
            px: 2,
            py: 1.75,
            borderTop: 1,
            borderColor: 'divider',
            display: 'flex',
            alignItems: 'center',
            gap: 1.25,
          }}
        >
          <Avatar
            sx={{
              width: 36,
              height: 36,
              bgcolor: 'primary.main',
              fontSize: 13,
              fontWeight: 700,
            }}
          >
            {initials(user.fullName)}
          </Avatar>
          <Box minWidth={0}>
            <Typography variant="body2" fontWeight={600} noWrap>
              {user.email}
            </Typography>
            <Typography variant="caption" color="text.secondary" noWrap display="block">
              {formatRoleLabel(user.role)}
            </Typography>
          </Box>
        </Box>
      )}
    </Box>
  );

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh', bgcolor: 'background.default' }}>
      <TopBar isDesktop={isDesktop} onOpenNav={() => setMobileOpen(true)} />

      <Box component="nav" sx={{ width: { md: DRAWER_WIDTH }, flexShrink: { md: 0 } }}>
        {isDesktop ? (
          <Drawer
            variant="permanent"
            open
            sx={{
              '& .MuiDrawer-paper': {
                width: DRAWER_WIDTH,
                boxSizing: 'border-box',
                borderRight: 1,
                borderColor: 'divider',
                bgcolor: 'background.paper',
                height: '100vh',
                overflow: 'hidden',
                display: 'flex',
                flexDirection: 'column',
              },
            }}
          >
            {drawerContent}
          </Drawer>
        ) : (
          <Drawer
            variant="temporary"
            open={mobileOpen}
            onClose={() => setMobileOpen(false)}
            ModalProps={{ keepMounted: true }}
            sx={{
              '& .MuiDrawer-paper': {
                width: DRAWER_WIDTH,
                boxSizing: 'border-box',
                height: '100%',
                overflow: 'hidden',
                display: 'flex',
                flexDirection: 'column',
              },
            }}
          >
            <Box sx={{ flex: 1, minHeight: 0, overflow: 'hidden' }}>{drawerContent}</Box>
          </Drawer>
        )}
      </Box>

      <Box
        component="main"
        sx={{
          flexGrow: 1,
          width: { md: `calc(100% - ${DRAWER_WIDTH}px)` },
          minWidth: 0,
        }}
      >
        <Toolbar />
        <Container maxWidth="lg" sx={{ py: 3, pb: { xs: 10, md: 3 } }}>
          <Outlet />
        </Container>
      </Box>

      {!isDesktop && bottomItems.length > 0 && (
        <Paper
          elevation={8}
          sx={{
            position: 'fixed',
            bottom: 0,
            left: 0,
            right: 0,
            zIndex: (t) => t.zIndex.appBar,
            borderTop: 1,
            borderColor: 'divider',
          }}
        >
          <BottomNavigation
            showLabels
            value={bottomValue >= 0 ? bottomValue : false}
            onChange={(_, value: number) => {
              const item = bottomItems[value];
              if (item) navigate(item.path);
            }}
          >
            {bottomItems.map((item) => {
              const Icon = item.icon;
              return (
                <BottomNavigationAction key={item.id} label={item.label} icon={<Icon fontSize="small" />} />
              );
            })}
          </BottomNavigation>
        </Paper>
      )}
    </Box>
  );
}
