import { FormEvent, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import ContentCopyOutlinedIcon from '@mui/icons-material/ContentCopyOutlined';
import RefreshOutlinedIcon from '@mui/icons-material/RefreshOutlined';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import { Link as RouterLink } from 'react-router-dom';
import {
  useDeactivateReferralMutation,
  useGenerateReferralMutation,
  useGetReferralsQuery,
  useGetRelationshipsQuery,
} from '../../app/api';
import type { RelationshipDto } from '../../shared/types';

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

export function BrokersPage() {
  const { data: relationships = [], refetch: refetchRels, isFetching: relsFetching } =
    useGetRelationshipsQuery();
  const { data: referrals = [], refetch: refetchRefs, isFetching: refsFetching } =
    useGetReferralsQuery();
  const [generate, { isLoading: generating }] = useGenerateReferralMutation();
  const [deactivate, { isLoading: deactivating }] = useDeactivateReferralMutation();

  const [maxUses, setMaxUses] = useState('1');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [deactivateId, setDeactivateId] = useState<string | null>(null);
  const [viewingBroker, setViewingBroker] = useState<RelationshipDto | null>(null);

  const active = useMemo(
    () => relationships.filter((r) => /active/i.test(r.status)),
    [relationships],
  );
  const suspended = useMemo(
    () => relationships.filter((r) => /suspend/i.test(r.status)),
    [relationships],
  );
  const activeCodes = useMemo(() => referrals.filter((r) => r.isActive), [referrals]);
  const inactiveCodes = useMemo(() => referrals.filter((r) => !r.isActive), [referrals]);

  const refreshing = relsFetching || refsFetching;

  const onGenerate = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setMessage(null);
    try {
      const uses = Math.max(1, Number(maxUses) || 1);
      const res = await generate({ maxUses: uses }).unwrap();
      setMessage(`Generated code ${res.code}. Share it with your broker.`);
      setMaxUses('1');
      refetchRefs();
    } catch {
      setError('Could not generate referral code.');
    }
  };

  const copyCode = async (code: string) => {
    try {
      await navigator.clipboard.writeText(code);
      setMessage(`Copied ${code} to clipboard.`);
      setError(null);
    } catch {
      setError('Could not copy code. Select and copy it manually.');
    }
  };

  const confirmDeactivate = async () => {
    if (!deactivateId) return;
    try {
      await deactivate(deactivateId).unwrap();
      setMessage('Referral code deactivated.');
      setDeactivateId(null);
      refetchRefs();
    } catch {
      setError('Could not deactivate referral code.');
      setDeactivateId(null);
    }
  };

  const refreshAll = () => {
    refetchRels();
    refetchRefs();
  };

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: 'column', sm: 'row' }}
        justifyContent="space-between"
        alignItems={{ sm: 'center' }}
        spacing={1.5}
      >
        <Box>
          <Typography variant="h4" fontWeight={700}>
            My Brokers
          </Typography>
          <Typography color="text.secondary">
            Manage linked brokers and referral codes
          </Typography>
        </Box>
        <Stack direction="row" spacing={1} alignItems="center">
          <Tooltip title="Refresh">
            <span>
              <IconButton onClick={refreshAll} disabled={refreshing} aria-label="Refresh">
                {refreshing ? <CircularProgress size={18} /> : <RefreshOutlinedIcon />}
              </IconButton>
            </span>
          </Tooltip>
          <Button component={RouterLink} to="/transfers" variant="outlined" size="small">
            Transfer requests
          </Button>
        </Stack>
      </Stack>

      {message && (
        <Alert severity="success" onClose={() => setMessage(null)}>
          {message}
        </Alert>
      )}
      {error && (
        <Alert severity="error" onClose={() => setError(null)}>
          {error}
        </Alert>
      )}

      {active.length === 0 && (
        <Alert severity="info">
          No active brokers yet. Generate a referral code below and share it with a broker so they
          can link to your account.
        </Alert>
      )}

      <Box
        sx={{
          display: 'grid',
          gridTemplateColumns: { xs: '1fr 1fr', md: 'repeat(3, 1fr)' },
          gap: 2,
        }}
      >
        <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
          <Typography variant="overline" color="text.secondary">
            Active brokers
          </Typography>
          <Typography variant="h4" fontWeight={700} color="success.main">
            {active.length}
          </Typography>
        </Paper>
        <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
          <Typography variant="overline" color="text.secondary">
            Suspended
          </Typography>
          <Typography
            variant="h4"
            fontWeight={700}
            color={suspended.length ? 'error.main' : 'text.primary'}
          >
            {suspended.length}
          </Typography>
        </Paper>
        <Paper
          elevation={0}
          sx={{
            p: 2,
            border: 1,
            borderColor: 'divider',
            borderRadius: 2,
            gridColumn: { xs: '1 / -1', md: 'auto' },
          }}
        >
          <Typography variant="overline" color="text.secondary">
            Active codes
          </Typography>
          <Typography variant="h4" fontWeight={700}>
            {activeCodes.length}
          </Typography>
        </Paper>
      </Box>

      <Box
        sx={{
          display: 'grid',
          gridTemplateColumns: { xs: '1fr', lg: '1.2fr 1fr' },
          gap: 2,
          alignItems: 'start',
        }}
      >
        <Paper
          component="form"
          onSubmit={onGenerate}
          elevation={0}
          sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}
        >
          <Typography variant="h6" fontWeight={700}>
            Referral codes
          </Typography>
          <Typography variant="body2" color="text.secondary" mb={2}>
            Generate a code and share it with a broker so they can link to your account.
          </Typography>
          <Stack
            direction={{ xs: 'column', sm: 'row' }}
            spacing={1.5}
            alignItems={{ sm: 'center' }}
            mb={2.5}
          >
            <TextField
              label="Max uses"
              type="number"
              size="small"
              value={maxUses}
              onChange={(e) => setMaxUses(e.target.value)}
              sx={{ width: { xs: '100%', sm: 140 } }}
              inputProps={{ min: 1 }}
              helperText="How many brokers can use this code"
            />
            <Button
              type="submit"
              variant="contained"
              disabled={generating}
              startIcon={generating ? <CircularProgress size={16} color="inherit" /> : undefined}
              sx={{ alignSelf: { sm: 'flex-start' }, mt: { sm: 0.5 } }}
            >
              {generating ? 'Generating…' : 'Generate new code'}
            </Button>
          </Stack>

          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Code</TableCell>
                <TableCell>Uses</TableCell>
                <TableCell>Status</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {referrals.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={4}>
                    <Typography variant="body2" color="text.secondary">
                      No referral codes yet. Generate one to invite a broker.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                [...activeCodes, ...inactiveCodes].map((r) => (
                  <TableRow key={r.id} sx={{ opacity: r.isActive ? 1 : 0.65 }}>
                    <TableCell>
                      <Stack direction="row" spacing={0.5} alignItems="center">
                        <Typography fontFamily="ui-monospace, monospace" fontWeight={700}>
                          {r.code}
                        </Typography>
                        <Tooltip title="Copy code">
                          <IconButton
                            size="small"
                            aria-label={`Copy ${r.code}`}
                            onClick={() => void copyCode(r.code)}
                          >
                            <ContentCopyOutlinedIcon fontSize="inherit" />
                          </IconButton>
                        </Tooltip>
                      </Stack>
                    </TableCell>
                    <TableCell>
                      {r.currentUses}
                      {r.maxUses != null ? ` / ${r.maxUses}` : ''}
                    </TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        label={r.isActive ? 'Active' : 'Inactive'}
                        color={r.isActive ? 'success' : 'default'}
                      />
                    </TableCell>
                    <TableCell align="right">
                      {r.isActive ? (
                        <Button size="small" color="warning" onClick={() => setDeactivateId(r.id)}>
                          Deactivate
                        </Button>
                      ) : (
                        <Typography variant="caption" color="text.secondary">
                          —
                        </Typography>
                      )}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </Paper>

        <Stack spacing={2}>
          <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Typography variant="h6" fontWeight={700} mb={0.5}>
              Linked brokers
            </Typography>
            <Typography variant="body2" color="text.secondary" mb={2}>
              Brokers currently connected to your account
            </Typography>

            {active.length === 0 ? (
              <Alert severity="info">
                No active brokers linked yet. Share a referral code to get started.
              </Alert>
            ) : (
              <Stack spacing={1.5}>
                {active.map((r) => (
                  <Paper
                    key={r.id}
                    elevation={0}
                    sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2 }}
                  >
                    <Stack direction="row" spacing={1.5} alignItems="center">
                      <Box
                        sx={{
                          width: 40,
                          height: 40,
                          borderRadius: 2,
                          bgcolor: 'primary.main',
                          color: 'primary.contrastText',
                          display: 'grid',
                          placeItems: 'center',
                          fontWeight: 700,
                          fontSize: 14,
                          flexShrink: 0,
                        }}
                      >
                        {initials(r.brokerName)}
                      </Box>
                      <Box flex={1} minWidth={0}>
                        <Typography fontWeight={700} noWrap>
                          {r.brokerName}
                        </Typography>
                        <Typography variant="caption" color="text.secondary" noWrap display="block">
                          {r.brokerEmail || 'Linked relationship'}
                        </Typography>
                      </Box>
                      <Chip size="small" label="Active" color="success" />
                    </Stack>
                    <Stack direction="row" justifyContent="flex-end" mt={1.25}>
                      <Button
                        size="small"
                        startIcon={<VisibilityOutlinedIcon />}
                        onClick={() => setViewingBroker(r)}
                      >
                        View
                      </Button>
                    </Stack>
                  </Paper>
                ))}
              </Stack>
            )}
          </Paper>

          {suspended.length > 0 && (
            <Paper
              elevation={0}
              sx={{
                p: 2.5,
                border: 1,
                borderColor: 'error.light',
                borderRadius: 2,
                bgcolor: 'rgba(211,47,47,0.04)',
              }}
            >
              <Typography variant="h6" mb={1} color="error.main" fontWeight={700}>
                Suspended brokers
              </Typography>
              <Typography variant="body2" color="text.secondary" mb={2}>
                Suspended brokers cannot be assigned to new manifests. Transfer affected manifests
                to another broker.
              </Typography>
              <Stack spacing={1}>
                {suspended.map((r) => (
                  <Paper
                    key={r.id}
                    elevation={0}
                    sx={{ p: 1.75, border: 1, borderColor: 'error.main', borderRadius: 2 }}
                  >
                    <Stack direction="row" justifyContent="space-between" alignItems="center" spacing={1}>
                      <Box minWidth={0}>
                        <Typography fontWeight={700}>{r.brokerName}</Typography>
                        {r.suspensionReason && (
                          <Typography variant="body2" color="text.secondary">
                            {r.suspensionReason}
                          </Typography>
                        )}
                      </Box>
                      <Stack direction="row" spacing={0.5} alignItems="center">
                        <Chip size="small" label="Suspended" color="error" />
                        <Button size="small" onClick={() => setViewingBroker(r)}>
                          View
                        </Button>
                      </Stack>
                    </Stack>
                  </Paper>
                ))}
              </Stack>
              <Button
                component={RouterLink}
                to="/transfers"
                sx={{ mt: 2 }}
                variant="outlined"
                color="error"
                size="small"
              >
                Request transfer
              </Button>
            </Paper>
          )}
        </Stack>
      </Box>

      <Dialog open={Boolean(deactivateId)} onClose={() => setDeactivateId(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Deactivate referral code?</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary">
            Brokers will no longer be able to use this code. Existing linked relationships stay
            active.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeactivateId(null)} disabled={deactivating}>
            Cancel
          </Button>
          <Button
            color="warning"
            variant="contained"
            onClick={() => void confirmDeactivate()}
            disabled={deactivating}
            startIcon={deactivating ? <CircularProgress size={16} color="inherit" /> : undefined}
          >
            {deactivating ? 'Deactivating…' : 'Deactivate'}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={Boolean(viewingBroker)}
        onClose={() => setViewingBroker(null)}
        maxWidth="sm"
        fullWidth
      >
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          {viewingBroker && (
            <Box
              sx={{
                width: 44,
                height: 44,
                borderRadius: 2,
                bgcolor: /suspend/i.test(viewingBroker.status) ? 'error.main' : 'primary.main',
                color: 'primary.contrastText',
                display: 'grid',
                placeItems: 'center',
                fontWeight: 700,
                fontSize: 15,
                flexShrink: 0,
              }}
            >
              {initials(viewingBroker.brokerName)}
            </Box>
          )}
          <Box>
            Broker details
            {viewingBroker && (
              <Typography variant="body2" color="text.secondary" fontWeight={400}>
                {viewingBroker.brokerName}
              </Typography>
            )}
          </Box>
        </DialogTitle>
        <DialogContent dividers>
          {viewingBroker && (
            <Stack spacing={2}>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                <Chip
                  size="small"
                  label={viewingBroker.status}
                  color={/suspend/i.test(viewingBroker.status) ? 'error' : 'success'}
                />
                {viewingBroker.brokerIsActive === false && (
                  <Chip size="small" label="Account inactive" color="warning" />
                )}
              </Stack>

              <Box>
                <Typography variant="overline" color="text.secondary">
                  Full name
                </Typography>
                <Typography fontWeight={600}>{viewingBroker.brokerName}</Typography>
              </Box>

              <Box>
                <Typography variant="overline" color="text.secondary">
                  Email
                </Typography>
                {viewingBroker.brokerEmail ? (
                  <Typography fontWeight={600}>
                    <Box
                      component="a"
                      href={`mailto:${viewingBroker.brokerEmail}`}
                      sx={{ color: 'primary.main', textDecoration: 'none' }}
                    >
                      {viewingBroker.brokerEmail}
                    </Box>
                  </Typography>
                ) : (
                  <Typography color="text.secondary">Not available</Typography>
                )}
              </Box>

              <Box>
                <Typography variant="overline" color="text.secondary">
                  Business address
                </Typography>
                <Typography fontWeight={600}>
                  {viewingBroker.brokerBusinessAddress?.trim() || 'Not provided'}
                </Typography>
              </Box>

              <Box>
                <Typography variant="overline" color="text.secondary">
                  Linked since
                </Typography>
                <Typography fontWeight={600}>
                  {viewingBroker.linkedAt
                    ? new Date(viewingBroker.linkedAt).toLocaleString()
                    : '—'}
                </Typography>
              </Box>

              {viewingBroker.suspensionReason && (
                <Alert severity="error">
                  <Typography fontWeight={700} variant="body2">
                    Suspension reason
                  </Typography>
                  {viewingBroker.suspensionReason}
                </Alert>
              )}

              <Typography variant="caption" color="text.secondary">
                Contact the broker via email for shipment coordination.
              </Typography>
            </Stack>
          )}
        </DialogContent>
        <DialogActions>
          {viewingBroker?.brokerEmail && (
            <Button
              href={`mailto:${viewingBroker.brokerEmail}`}
              component="a"
              variant="outlined"
            >
              Email broker
            </Button>
          )}
          <Button onClick={() => setViewingBroker(null)} variant="contained">
            Close
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
