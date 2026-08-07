import { ReactNode } from 'react';
import { Box, Button, Stack, Typography } from '@mui/material';
import LogoutOutlinedIcon from '@mui/icons-material/LogoutOutlined';
import { Link as RouterLink, Outlet, useNavigate } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
import { logout } from '../../app/authSlice';
import type { RootState } from '../../app/store';
import { OptimusLogo } from '../../shared/OptimusLogo';
import { ColorModeToggle } from '../../shared/ColorModeToggle';
import { authPanelGradient } from '../../shared/theme';
import { useTheme } from '@mui/material/styles';

type WorkspaceGateLayoutProps = {
  children?: ReactNode;
};

/**
 * Focused full-viewport chrome for broker workspace selection / referral linking.
 * Intentionally outside AppShell so brokers without an active workspace aren't
 * dropped into a full nav with nowhere to go.
 */
export function WorkspaceGateLayout({ children }: WorkspaceGateLayoutProps) {
  const theme = useTheme();
  const dispatch = useDispatch();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const mode = theme.palette.mode;

  const onSignOut = () => {
    dispatch(logout());
    navigate('/login');
  };

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        flexDirection: 'column',
        bgcolor: 'background.default',
        backgroundImage: authPanelGradient(mode),
      }}
    >
      <Box
        component="header"
        sx={{
          px: { xs: 2, sm: 3 },
          py: 1.5,
          display: 'flex',
          alignItems: 'center',
          gap: 1.5,
          borderBottom: 1,
          borderColor: 'divider',
          bgcolor: (t) =>
            t.palette.mode === 'dark' ? 'rgba(21,32,40,0.92)' : 'rgba(255,255,255,0.92)',
          backdropFilter: 'blur(10px)',
          position: 'sticky',
          top: 0,
          zIndex: 10,
        }}
      >
        <Box
          component={RouterLink}
          to="/workspace"
          sx={{ display: 'flex', alignItems: 'center', textDecoration: 'none', color: 'inherit' }}
        >
          <OptimusLogo size="xs" />
        </Box>
        <Box sx={{ display: { xs: 'none', sm: 'block' }, color: 'text.disabled', px: 0.5 }}>|</Box>
        <Typography
          variant="body2"
          fontWeight={600}
          color="text.secondary"
          sx={{ display: { xs: 'none', sm: 'block' } }}
        >
          Broker workspace
        </Typography>
        <Box flexGrow={1} />
        <Typography
          variant="caption"
          color="text.secondary"
          noWrap
          sx={{ display: { xs: 'none', md: 'block' }, maxWidth: 220 }}
        >
          {user?.email}
        </Typography>
        <ColorModeToggle size="small" />
        <Button
          size="small"
          color="inherit"
          startIcon={<LogoutOutlinedIcon />}
          onClick={onSignOut}
          sx={{ textTransform: 'none', fontWeight: 600 }}
        >
          Sign out
        </Button>
      </Box>

      <Box
        component="main"
        sx={{
          flex: 1,
          display: 'flex',
          flexDirection: 'column',
          px: { xs: 2, sm: 3 },
          py: { xs: 3, sm: 4 },
        }}
      >
        <Stack spacing={0} maxWidth={880} mx="auto" width="100%" flex={1}>
          {children ?? <Outlet />}
        </Stack>
      </Box>
    </Box>
  );
}
