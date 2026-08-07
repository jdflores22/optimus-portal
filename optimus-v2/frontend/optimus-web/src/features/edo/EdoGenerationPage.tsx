import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Collapse,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  InputAdornment,
  Paper,
  Stack,
  Tab,
  Tabs,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import ChevronRightOutlinedIcon from '@mui/icons-material/ChevronRightOutlined';
import ExpandMoreOutlinedIcon from '@mui/icons-material/ExpandMoreOutlined';
import FileCopyOutlinedIcon from '@mui/icons-material/FileCopyOutlined';
import PlayArrowOutlinedIcon from '@mui/icons-material/PlayArrowOutlined';
import RefreshOutlinedIcon from '@mui/icons-material/RefreshOutlined';
import SearchOutlinedIcon from '@mui/icons-material/SearchOutlined';
import { Link as RouterLink } from 'react-router-dom';
import {
  useBatchGenerateEdoMutation,
  useGenerateEdoMutation,
  useGetEdoGenerationQueueQuery,
} from '../../app/api';
import type { EdoGenerationContainerDto, EdoGenerationGroupDto } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

type TabKey = 'pending' | 'generated';

type GenerateIntent = {
  mode: 'single' | 'batch';
  manifestId: string;
  manifestNumber: string;
  label: string;
  containerNumbers: string[];
};

function localDateInputValue(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function formatDate(value?: string | null) {
  if (!value) return '—';
  return new Date(value).toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function edoStatusTone(status?: string | null): 'success' | 'warning' | 'default' {
  if (!status) return 'default';
  if (/released|active/i.test(status)) return 'success';
  if (/pending/i.test(status)) return 'warning';
  return 'default';
}

function rowSearchText(row: EdoGenerationContainerDto) {
  return [
    row.containerNumber,
    row.manifestNumber,
    row.brokerName,
    row.consigneeName,
    row.edoNumber,
    row.shippingLineName,
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();
}

function groupSearchText(group: EdoGenerationGroupDto) {
  return [
    group.manifestNumber,
    group.brokerName,
    group.consigneeName,
    group.shippingLineName,
    ...group.pendingContainers.map((c) => c.containerNumber),
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();
}

export function EdoGenerationPage() {
  const { data, isLoading, isFetching, refetch } = useGetEdoGenerationQueueQuery();
  const [generateEdo, { isLoading: generatingOne }] = useGenerateEdoMutation();
  const [batchGenerate, { isLoading: generatingBatch }] = useBatchGenerateEdoMutation();

  const [tab, setTab] = useState<TabKey>('pending');
  const [search, setSearch] = useState('');
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [confirmStep, setConfirmStep] = useState<'form' | 'loading'>('form');
  const [intent, setIntent] = useState<GenerateIntent | null>(null);
  const [expirationDate, setExpirationDate] = useState(localDateInputValue());
  const [confirmError, setConfirmError] = useState<string | null>(null);

  const pendingGroups = data?.pendingGroups ?? [];
  const generated = data?.generated ?? [];
  const pendingCount = data?.pendingCount ?? 0;
  const generatedCount = data?.generatedCount ?? 0;
  const totalEligible = data?.totalEligible ?? 0;

  const searchLower = search.trim().toLowerCase();

  const filteredGroups = useMemo(() => {
    if (!searchLower) return pendingGroups;
    return pendingGroups.filter((g) => groupSearchText(g).includes(searchLower));
  }, [pendingGroups, searchLower]);

  const filteredGenerated = useMemo(() => {
    if (!searchLower) return generated;
    return generated.filter((r) => rowSearchText(r).includes(searchLower));
  }, [generated, searchLower]);

  const toggleGroup = (manifestId: string) => {
    setExpanded((prev) => ({ ...prev, [manifestId]: prev[manifestId] === false }));
  };

  const isGroupOpen = (manifestId: string) => expanded[manifestId] !== false;

  const openGenerateConfirm = (payload: GenerateIntent) => {
    setIntent(payload);
    setExpirationDate(localDateInputValue());
    setConfirmError(null);
    setConfirmStep('form');
    setConfirmOpen(true);
  };

  const openSingleConfirm = (row: EdoGenerationContainerDto) => {
    openGenerateConfirm({
      mode: 'single',
      manifestId: row.manifestId,
      manifestNumber: row.manifestNumber,
      label: row.containerNumber,
      containerNumbers: [row.containerNumber],
    });
  };

  const openBatchConfirm = (group: EdoGenerationGroupDto) => {
    openGenerateConfirm({
      mode: 'batch',
      manifestId: group.manifestId,
      manifestNumber: group.manifestNumber,
      label: `${group.manifestNumber} (${group.pendingCount} container${group.pendingCount === 1 ? '' : 's'})`,
      containerNumbers: group.pendingContainers.map((c) => c.containerNumber),
    });
  };

  const closeConfirmModal = () => {
    if (confirmStep === 'loading') return;
    setConfirmOpen(false);
    setIntent(null);
    setConfirmError(null);
  };

  const onConfirmGenerate = async () => {
    if (!intent) return;

    const today = localDateInputValue();
    if (!expirationDate) {
      setConfirmError('Please select an expiration date.');
      return;
    }
    if (expirationDate < today) {
      setConfirmError('Expiration date cannot be in the past. Choose today or a future date.');
      setExpirationDate(today);
      return;
    }

    setConfirmError(null);
    setConfirmStep('loading');

    const expiresAt = new Date(`${expirationDate}T23:59:59`).toISOString();

    try {
      if (intent.mode === 'single') {
        const edo = await generateEdo({
          manifestId: intent.manifestId,
          containerNumber: intent.containerNumbers[0],
          cyLocation: 'CY-Manila',
          expiresAt,
        }).unwrap();
        setMessage(`Generated ${edo.edoNumber} for ${intent.containerNumbers[0]}`);
      } else {
        const session = await batchGenerate({
          manifestId: intent.manifestId,
          containerNumbers: intent.containerNumbers,
          cyLocation: 'CY-Manila',
          expiresAt,
        }).unwrap();
        setMessage(
          `Batch ${session.status}: ${session.completedItems}/${session.totalItems} completed` +
            (session.failedItems > 0 ? ` (${session.failedItems} failed)` : ''),
        );
      }
      setConfirmOpen(false);
      setIntent(null);
      refetch();
    } catch (e: unknown) {
      setConfirmStep('form');
      setConfirmError((e as { data?: { message?: string } })?.data?.message ?? 'Generate failed');
    }
  };

  const busy = generatingOne || generatingBatch || isFetching;

  return (
    <WorkflowPage
      eyebrow="Electronic Delivery Orders"
      title="eDO Generation"
      subtitle="Issue electronic delivery orders for containers with verified final payments. Process pending containers or review already generated eDOs."
      chips={
        <>
          {pendingCount > 0 ? (
            <Chip size="small" color="warning" label={`${pendingCount} pending`} />
          ) : (
            <Chip size="small" color="success" label="All caught up" />
          )}
          <Chip size="small" variant="outlined" label={`${totalEligible} eligible`} />
          <Chip size="small" color="success" label={`${generatedCount} generated`} />
        </>
      }
      actions={
        <>
          <Button
            component={RouterLink}
            to="/edo/renewals"
            variant="outlined"
            startIcon={<RefreshOutlinedIcon />}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            Renewal Requests
          </Button>
          {pendingCount > 0 && (
            <Button
              variant="contained"
              color="success"
              startIcon={<PlayArrowOutlinedIcon />}
              onClick={() => setTab('pending')}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              Process Pending ({pendingCount})
            </Button>
          )}
        </>
      }
      stats={[
        { label: 'Pending', value: pendingCount, hint: 'Awaiting eDO', tone: 'warning' },
        { label: 'Generated', value: generatedCount, hint: 'eDOs issued', tone: 'success' },
        { label: 'Total eligible', value: totalEligible, hint: 'Payment verified', tone: 'info' },
      ]}
    >
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

      {isLoading ? (
        <Box display="flex" justifyContent="center" py={6}>
          <CircularProgress />
        </Box>
      ) : totalEligible === 0 ? (
        <WorkflowSection title="No containers ready" subtitle="Containers appear here after final payment is verified.">
          <Typography variant="body2" color="text.secondary" textAlign="center" py={4}>
            No manifests with verified final payment and linked containers were found.
          </Typography>
          <Stack direction="row" justifyContent="center">
            <Button component={RouterLink} to="/manifests" variant="outlined" sx={{ textTransform: 'none' }}>
              View manifests
            </Button>
          </Stack>
        </WorkflowSection>
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: 'hidden' }}>
          <Stack
            direction={{ xs: 'column', lg: 'row' }}
            alignItems={{ lg: 'center' }}
            justifyContent="space-between"
            spacing={2}
            sx={{ p: 2, borderBottom: 1, borderColor: 'divider' }}
          >
            <Tabs
              value={tab}
              onChange={(_, v: TabKey) => setTab(v)}
              sx={{ minHeight: 40 }}
            >
              <Tab
                value="pending"
                label={
                  <Stack direction="row" spacing={1} alignItems="center">
                    <span>Pending</span>
                    {pendingCount > 0 && <Chip size="small" color="warning" label={pendingCount} />}
                  </Stack>
                }
                sx={{ textTransform: 'none', minHeight: 40 }}
              />
              <Tab
                value="generated"
                label={
                  <Stack direction="row" spacing={1} alignItems="center">
                    <span>Generated</span>
                    {generatedCount > 0 && <Chip size="small" color="success" label={generatedCount} />}
                  </Stack>
                }
                sx={{ textTransform: 'none', minHeight: 40 }}
              />
            </Tabs>
            <TextField
              size="small"
              placeholder="Search container, manifest, broker…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              sx={{ width: { xs: '100%', lg: 320 } }}
              InputProps={{
                startAdornment: (
                  <InputAdornment position="start">
                    <SearchOutlinedIcon fontSize="small" color="action" />
                  </InputAdornment>
                ),
              }}
            />
          </Stack>

          {tab === 'pending' && (
            <Box sx={{ p: { xs: 2, sm: 2.5 } }}>
              {pendingCount === 0 ? (
                <Stack alignItems="center" py={6} spacing={2}>
                  <Typography variant="h6" fontWeight={600}>
                    All caught up
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    Every eligible container already has an eDO.
                  </Typography>
                  <Button
                    endIcon={<ChevronRightOutlinedIcon />}
                    onClick={() => setTab('generated')}
                    sx={{ textTransform: 'none' }}
                  >
                    View generated eDOs
                  </Button>
                </Stack>
              ) : filteredGroups.length === 0 ? (
                <Typography variant="body2" color="text.secondary" textAlign="center" py={4}>
                  No pending containers match your search.
                </Typography>
              ) : (
                <Stack spacing={2}>
                  {filteredGroups.map((group) => (
                    <Paper
                      key={group.manifestId}
                      variant="outlined"
                      sx={{ overflow: 'hidden', borderColor: 'warning.light' }}
                    >
                      <Stack
                        direction={{ xs: 'column', lg: 'row' }}
                        alignItems={{ lg: 'center' }}
                        justifyContent="space-between"
                        spacing={2}
                        sx={{ p: 2, bgcolor: 'warning.50' }}
                      >
                        <Stack direction="row" spacing={1} alignItems="flex-start" minWidth={0}>
                          <IconButton
                            size="small"
                            onClick={() => toggleGroup(group.manifestId)}
                            aria-label="Toggle manifest group"
                          >
                            {isGroupOpen(group.manifestId) ? (
                              <ExpandMoreOutlinedIcon />
                            ) : (
                              <ChevronRightOutlinedIcon />
                            )}
                          </IconButton>
                          <Box minWidth={0}>
                            <Stack direction="row" flexWrap="wrap" alignItems="center" gap={1}>
                              <Button
                                component={RouterLink}
                                to={`/manifests/${group.manifestId}`}
                                size="small"
                                sx={{ fontFamily: 'monospace', fontWeight: 700, p: 0, minWidth: 0 }}
                              >
                                {group.manifestNumber}
                              </Button>
                              <Chip size="small" color="warning" label={`${group.pendingCount} pending`} />
                              <Chip
                                size="small"
                                variant="outlined"
                                label={`${group.edoCountInManifest}/${group.totalInManifest} eDOs`}
                              />
                            </Stack>
                            <Typography variant="body2" color="text.secondary" mt={0.5}>
                              {group.brokerName} · {group.consigneeName}
                              {group.shippingLineName ? ` · ${group.shippingLineName}` : ''}
                            </Typography>
                          </Box>
                        </Stack>
                        <Button
                          variant="contained"
                          color="success"
                          size="small"
                          startIcon={<FileCopyOutlinedIcon />}
                          disabled={busy}
                          onClick={() => openBatchConfirm(group)}
                          sx={{ textTransform: 'none', fontWeight: 600, flexShrink: 0 }}
                        >
                          Generate All ({group.pendingCount})
                        </Button>
                      </Stack>
                      <Collapse in={isGroupOpen(group.manifestId)}>
                        <Box sx={{ overflowX: 'auto' }}>
                          <Table size="small">
                            <TableHead>
                              <TableRow>
                                <TableCell>Container</TableCell>
                                <TableCell>Size / Type</TableCell>
                                <TableCell>Payment verified</TableCell>
                                <TableCell align="right">Action</TableCell>
                              </TableRow>
                            </TableHead>
                            <TableBody>
                              {group.pendingContainers.map((row) => (
                                <TableRow key={`${row.manifestId}-${row.containerNumber}`} hover>
                                  <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                    {row.containerNumber}
                                  </TableCell>
                                  <TableCell>
                                    <Typography variant="body2">{row.containerSize ?? '—'}</Typography>
                                    <Typography variant="caption" color="text.secondary">
                                      {row.containerType ?? '—'}
                                    </Typography>
                                  </TableCell>
                                  <TableCell>{formatDate(row.paymentVerifiedAt)}</TableCell>
                                  <TableCell align="right">
                                    <Button
                                      size="small"
                                      variant="contained"
                                      color="success"
                                      disabled={busy}
                                      onClick={() => openSingleConfirm(row)}
                                      sx={{ textTransform: 'none' }}
                                    >
                                      Generate
                                    </Button>
                                  </TableCell>
                                </TableRow>
                              ))}
                            </TableBody>
                          </Table>
                        </Box>
                      </Collapse>
                    </Paper>
                  ))}
                </Stack>
              )}
            </Box>
          )}

          {tab === 'generated' && (
            <Box sx={{ p: { xs: 2, sm: 2.5 } }}>
              {generatedCount === 0 ? (
                <Stack alignItems="center" py={6} spacing={2}>
                  <Typography variant="h6" fontWeight={600}>
                    No eDOs generated yet
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    Generated eDOs will appear here after you process pending containers.
                  </Typography>
                  {pendingCount > 0 && (
                    <Button
                      variant="contained"
                      color="success"
                      startIcon={<PlayArrowOutlinedIcon />}
                      onClick={() => setTab('pending')}
                      sx={{ textTransform: 'none' }}
                    >
                      Process pending
                    </Button>
                  )}
                </Stack>
              ) : filteredGenerated.length === 0 ? (
                <Typography variant="body2" color="text.secondary" textAlign="center" py={4}>
                  No generated eDOs match your search.
                </Typography>
              ) : (
                <Box sx={{ overflowX: 'auto' }}>
                  <Table size="small">
                    <TableHead>
                      <TableRow sx={{ bgcolor: 'success.50' }}>
                        <TableCell>Container</TableCell>
                        <TableCell>Manifest</TableCell>
                        <TableCell>Broker / Consignee</TableCell>
                        <TableCell>eDO number</TableCell>
                        <TableCell>Status</TableCell>
                        <TableCell>Generated</TableCell>
                        <TableCell>Expires</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {filteredGenerated.map((row) => (
                        <TableRow key={`${row.manifestId}-${row.containerNumber}`} hover>
                          <TableCell>
                            <Typography variant="body2" fontFamily="monospace" fontWeight={600}>
                              {row.containerNumber}
                            </Typography>
                            <Typography variant="caption" color="text.secondary">
                              {[row.containerSize, row.containerType].filter(Boolean).join(' · ') || '—'}
                            </Typography>
                          </TableCell>
                          <TableCell>
                            <Button
                              component={RouterLink}
                              to={`/manifests/${row.manifestId}`}
                              size="small"
                              sx={{ fontFamily: 'monospace', textTransform: 'none' }}
                            >
                              {row.manifestNumber}
                            </Button>
                          </TableCell>
                          <TableCell>
                            <Typography variant="body2">{row.brokerName ?? 'N/A'}</Typography>
                            <Typography variant="caption" color="text.secondary">
                              {row.consigneeName ?? 'N/A'}
                            </Typography>
                          </TableCell>
                          <TableCell sx={{ fontFamily: 'monospace' }}>{row.edoNumber ?? '—'}</TableCell>
                          <TableCell>
                            <Chip
                              size="small"
                              label={row.edoStatus ?? '—'}
                              color={edoStatusTone(row.edoStatus)}
                              variant="outlined"
                            />
                          </TableCell>
                          <TableCell>{formatDate(row.edoGeneratedAt)}</TableCell>
                          <TableCell>{formatDate(row.edoExpiresAt)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </Box>
              )}
            </Box>
          )}
        </Paper>
      )}

      <Dialog
        open={confirmOpen}
        onClose={closeConfirmModal}
        fullWidth
        maxWidth="sm"
        aria-labelledby="edo-generate-dialog-title"
      >
        {confirmStep === 'form' ? (
          <>
            <DialogTitle id="edo-generate-dialog-title" sx={{ pb: 1 }}>
              Generate eDO
            </DialogTitle>
            <DialogContent>
              <Typography variant="body2" color="text.secondary" mb={2}>
                Set the expiration date for the new eDO document.
              </Typography>

              <Paper variant="outlined" sx={{ p: 2, mb: 2.5, bgcolor: 'info.50', borderColor: 'info.light' }}>
                <Typography variant="caption" color="info.main" fontWeight={700} letterSpacing={0.5}>
                  TARGET
                </Typography>
                <Typography variant="body2" fontFamily="monospace" fontWeight={600} mt={0.5}>
                  {intent?.label ?? '—'}
                </Typography>
                {intent && intent.mode === 'batch' && (
                  <Typography variant="caption" color="text.secondary" display="block" mt={0.5}>
                    Manifest {intent.manifestNumber} · {intent.containerNumbers.length} containers
                  </Typography>
                )}
              </Paper>

              <TextField
                label="Expiration date"
                type="date"
                value={expirationDate}
                onChange={(e) => setExpirationDate(e.target.value)}
                inputProps={{ min: localDateInputValue() }}
                fullWidth
                required
                helperText="Must be today or a future date"
                InputLabelProps={{ shrink: true }}
              />

              {confirmError && (
                <Alert severity="error" sx={{ mt: 2 }}>
                  {confirmError}
                </Alert>
              )}
            </DialogContent>
            <DialogActions sx={{ px: 3, py: 2, gap: 1 }}>
              <Button onClick={closeConfirmModal} fullWidth sx={{ textTransform: 'none' }}>
                Cancel
              </Button>
              <Button
                variant="contained"
                color="success"
                fullWidth
                disabled={!expirationDate || busy}
                onClick={() => void onConfirmGenerate()}
                sx={{ textTransform: 'none', fontWeight: 600 }}
              >
                Generate eDO
              </Button>
            </DialogActions>
          </>
        ) : (
          <DialogContent sx={{ py: 6, textAlign: 'center' }}>
            <CircularProgress color="success" size={48} />
            <Typography fontWeight={700} mt={2}>
              Generating eDO…
            </Typography>
            <Typography variant="body2" color="text.secondary" mt={0.5}>
              {intent?.label}
            </Typography>
          </DialogContent>
        )}
      </Dialog>
    </WorkflowPage>
  );
}
