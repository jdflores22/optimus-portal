import { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
  Paper,
  Stack,
  Typography,
} from '@mui/material';
import WorkOutlineOutlinedIcon from '@mui/icons-material/WorkOutlineOutlined';
import LinkOutlinedIcon from '@mui/icons-material/LinkOutlined';
import ChevronRightOutlinedIcon from '@mui/icons-material/ChevronRightOutlined';
import AddOutlinedIcon from '@mui/icons-material/AddOutlined';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import RefreshOutlinedIcon from '@mui/icons-material/RefreshOutlined';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
import { setCredentials } from '../../app/authSlice';
import type { RootState } from '../../app/store';
import { useGetWorkspacesQuery, useSwitchWorkspaceMutation } from '../../app/api';

function initials(name?: string | null): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

export function WorkspaceSelectorPage() {
  const dispatch = useDispatch();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const { data: workspaces = [], isLoading, isFetching, refetch } = useGetWorkspacesQuery(undefined, {
    skip: user?.role !== 'Broker',
  });
  const [switchWorkspace] = useSwitchWorkspaceMutation();
  const [error, setError] = useState<string | null>(null);
  const [switchingId, setSwitchingId] = useState<string | null>(null);

  const activeId = user?.activeWorkspaceConsigneeId ?? null;

  const sorted = useMemo(
    () =>
      [...workspaces].sort((a, b) =>
        (a.businessName || a.fullName).localeCompare(b.businessName || b.fullName),
      ),
    [workspaces],
  );

  useEffect(() => {
    if (user?.role !== 'Broker') {
      navigate('/', { replace: true });
    }
  }, [user?.role, navigate]);

  useEffect(() => {
    if (isLoading || isFetching || sorted.length !== 1 || activeId) return;
    const only = sorted[0];
    let cancelled = false;
    (async () => {
      try {
        setSwitchingId(only.id);
        const result = await switchWorkspace({ consigneeId: only.id }).unwrap();
        if (cancelled) return;
        dispatch(
          setCredentials({
            accessToken: result.accessToken,
            refreshToken: result.refreshToken,
            user: result.user,
          }),
        );
        navigate('/', {
          replace: true,
          state: {
            switchedWorkspace: only.businessName || only.fullName || 'workspace',
          },
        });
      } catch {
        if (!cancelled) setError('Could not open your workspace.');
      } finally {
        if (!cancelled) setSwitchingId(null);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [sorted, activeId, isLoading, isFetching, switchWorkspace, dispatch, navigate]);

  const onSelect = async (consigneeId: string) => {
    setError(null);
    setSwitchingId(consigneeId);
    try {
      const result = await switchWorkspace({ consigneeId }).unwrap();
      dispatch(
        setCredentials({
          accessToken: result.accessToken,
          refreshToken: result.refreshToken,
          user: result.user,
        }),
      );
      const ws = workspaces.find((w) => w.id === consigneeId);
      navigate('/', {
        state: {
          switchedWorkspace: ws?.businessName || ws?.fullName || 'workspace',
        },
      });
    } catch {
      setError('Unable to switch workspace.');
    } finally {
      setSwitchingId(null);
    }
  };

  if (user?.role !== 'Broker') return null;

  const openingSolo = sorted.length === 1 && !activeId;

  return (
    <Stack spacing={3} flex={1}>
      <Box textAlign="center" pt={{ xs: 1, sm: 2 }} pb={0.5}>
        <Box
          sx={{
            width: 64,
            height: 64,
            borderRadius: 3,
            bgcolor: 'primary.main',
            color: 'primary.contrastText',
            display: 'grid',
            placeItems: 'center',
            mx: 'auto',
            mb: 2.5,
            boxShadow: (t) =>
              t.palette.mode === 'dark'
                ? '0 12px 28px rgba(0,0,0,0.35)'
                : '0 12px 28px rgba(11,61,92,0.18)',
          }}
        >
          <WorkOutlineOutlinedIcon sx={{ fontSize: 30 }} />
        </Box>
        <Typography variant="h4" fontWeight={800} lineHeight={1.2}>
          Select your workspace
        </Typography>
        <Typography color="text.secondary" mt={1} maxWidth={460} mx="auto">
          Choose which consignee you&apos;d like to work with. You can switch again anytime from the
          sidebar.
        </Typography>
        {!isLoading && !openingSolo && (
          <Stack direction="row" spacing={1} justifyContent="center" mt={2}>
            <Chip
              size="small"
              label={`${sorted.length} workspace${sorted.length === 1 ? '' : 's'}`}
              color="primary"
              variant="outlined"
              sx={{ fontWeight: 700 }}
            />
            {activeId && (
              <Chip size="small" label="Active set" color="success" sx={{ fontWeight: 600 }} />
            )}
          </Stack>
        )}
      </Box>

      {error && (
        <Alert severity="error" onClose={() => setError(null)}>
          {error}
        </Alert>
      )}

      {isLoading || openingSolo ? (
        <Paper
          elevation={0}
          sx={{
            py: 10,
            border: 1,
            borderColor: 'divider',
            borderRadius: 3,
            textAlign: 'center',
            bgcolor: 'background.paper',
          }}
        >
          <Stack alignItems="center" spacing={2}>
            <CircularProgress size={32} />
            <Typography color="text.secondary">
              {openingSolo ? 'Opening your workspace…' : 'Loading workspaces…'}
            </Typography>
          </Stack>
        </Paper>
      ) : sorted.length === 0 ? (
        <Paper
          elevation={0}
          sx={{
            p: { xs: 3.5, sm: 6 },
            border: 1,
            borderColor: 'divider',
            borderRadius: 3,
            textAlign: 'center',
            bgcolor: 'background.paper',
          }}
        >
          <Box
            sx={{
              width: 88,
              height: 88,
              borderRadius: '50%',
              bgcolor: 'action.hover',
              display: 'grid',
              placeItems: 'center',
              mx: 'auto',
              mb: 3,
            }}
          >
            <WorkOutlineOutlinedIcon sx={{ fontSize: 42, color: 'text.disabled' }} />
          </Box>
          <Typography variant="h5" fontWeight={800} mb={1}>
            No workspaces yet
          </Typography>
          <Typography color="text.secondary" mb={4} maxWidth={440} mx="auto">
            You don&apos;t have any active relationships with consignees. Apply a referral code to
            link with a consignee and unlock their manifests.
          </Typography>
          <Button
            component={RouterLink}
            to="/workspace/referral"
            variant="contained"
            size="large"
            startIcon={<LinkOutlinedIcon />}
            sx={{ minWidth: 220 }}
          >
            Apply referral code
          </Button>
        </Paper>
      ) : (
        <Paper
          elevation={0}
          sx={{
            border: 1,
            borderColor: 'divider',
            borderRadius: 3,
            overflow: 'hidden',
            bgcolor: 'background.paper',
          }}
        >
          <Box sx={{ px: 2.5, py: 2 }}>
            <Typography variant="subtitle2" fontWeight={700}>
              Linked consignees
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Select a workspace to continue into the portal.
            </Typography>
          </Box>
          <Divider />

          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' },
              gap: 1.5,
              p: 2,
            }}
          >
            {sorted.map((w) => {
              const name = w.businessName || w.fullName;
              const isActive = activeId === w.id;
              const busy = switchingId === w.id;
              const isReferral = /referral/i.test(w.source);

              return (
                <Box
                  key={w.id}
                  component="button"
                  type="button"
                  onClick={() => onSelect(w.id)}
                  disabled={Boolean(switchingId)}
                  sx={{
                    all: 'unset',
                    boxSizing: 'border-box',
                    display: 'block',
                    p: 2,
                    cursor: switchingId ? 'wait' : 'pointer',
                    border: 1,
                    borderColor: isActive ? 'primary.main' : 'divider',
                    borderRadius: 2,
                    bgcolor: isActive ? 'action.selected' : 'background.paper',
                    transition: 'border-color 0.15s, background-color 0.15s, transform 0.15s',
                    opacity: switchingId && !busy ? 0.55 : 1,
                    '&:hover': {
                      borderColor: 'primary.main',
                      bgcolor: 'action.hover',
                      transform: switchingId ? 'none' : 'translateY(-1px)',
                    },
                    '&:focus-visible': {
                      outline: '2px solid',
                      outlineColor: 'primary.main',
                      outlineOffset: 2,
                    },
                  }}
                >
                  <Stack direction="row" alignItems="flex-start" spacing={1.5}>
                    <Box
                      sx={{
                        width: 48,
                        height: 48,
                        borderRadius: 2,
                        bgcolor: isActive ? 'primary.main' : 'action.hover',
                        color: isActive ? 'primary.contrastText' : 'primary.main',
                        display: 'grid',
                        placeItems: 'center',
                        flexShrink: 0,
                        fontWeight: 800,
                        fontSize: 14,
                      }}
                    >
                      {busy ? <CircularProgress size={22} color="inherit" /> : initials(name)}
                    </Box>

                    <Box flex={1} minWidth={0}>
                      <Stack direction="row" spacing={1} alignItems="center" mb={0.25}>
                        <Typography fontWeight={700} noWrap>
                          {name}
                        </Typography>
                        {isActive && (
                          <Chip
                            size="small"
                            icon={<CheckCircleOutlineOutlinedIcon sx={{ fontSize: '14px !important' }} />}
                            label="Active"
                            color="primary"
                            sx={{ height: 22, fontWeight: 700 }}
                          />
                        )}
                      </Stack>
                      <Typography variant="body2" color="text.secondary" noWrap>
                        {w.email}
                      </Typography>

                      <Stack
                        direction="row"
                        spacing={1}
                        alignItems="center"
                        flexWrap="wrap"
                        useFlexGap
                        mt={1.25}
                        pt={1.25}
                        sx={{ borderTop: 1, borderColor: 'divider' }}
                      >
                        <Stack direction="row" spacing={0.5} alignItems="center">
                          <DescriptionOutlinedIcon sx={{ fontSize: 16, color: 'text.secondary' }} />
                          <Typography variant="caption" color="text.secondary" fontWeight={600}>
                            {w.manifestCount} manifest{w.manifestCount === 1 ? '' : 's'}
                          </Typography>
                        </Stack>
                        <Chip
                          size="small"
                          label={isReferral ? 'Referral' : w.source || 'Legacy'}
                          color={isReferral ? 'primary' : 'default'}
                          variant={isReferral ? 'filled' : 'outlined'}
                          sx={{ height: 22, fontWeight: 600, fontSize: 11 }}
                        />
                      </Stack>
                    </Box>

                    <ChevronRightOutlinedIcon sx={{ color: 'text.disabled', mt: 1.25, flexShrink: 0 }} />
                  </Stack>
                </Box>
              );
            })}
          </Box>

          <Divider />

          <Box sx={{ px: 2.5, py: 2 }}>
            <Alert
              severity="info"
              icon={<InfoOutlinedIcon fontSize="inherit" />}
              sx={{ mb: 2, alignItems: 'flex-start' }}
            >
              <Typography variant="body2" fontWeight={700}>
                Tip
              </Typography>
              <Typography variant="body2">
                Switch between workspaces anytime from the workspace card in the sidebar.
              </Typography>
            </Alert>

            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.25} alignItems={{ sm: 'center' }}>
              <Button
                component={RouterLink}
                to="/workspace/referral"
                variant="outlined"
                startIcon={<AddOutlinedIcon />}
                sx={{ flex: { sm: 1 } }}
              >
                Add more consignees
              </Button>
              <Button
                variant="text"
                color="inherit"
                startIcon={<RefreshOutlinedIcon />}
                onClick={() => refetch()}
                disabled={isFetching}
              >
                Refresh
              </Button>
            </Stack>
          </Box>

          <Box
            sx={{
              px: 2.5,
              py: 1.5,
              bgcolor: (t) =>
                t.palette.mode === 'dark' ? 'rgba(255,255,255,0.04)' : 'action.hover',
              borderTop: 1,
              borderColor: 'divider',
            }}
          >
            <Typography variant="caption" color="text.secondary" fontWeight={600}>
              {sorted.length} workspace{sorted.length === 1 ? '' : 's'} available
            </Typography>
          </Box>
        </Paper>
      )}
    </Stack>
  );
}
