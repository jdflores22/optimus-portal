import { FormEvent, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import AddOutlinedIcon from '@mui/icons-material/AddOutlined';
import WarehouseOutlinedIcon from '@mui/icons-material/WarehouseOutlined';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import {
  useAllocateContainerMutation,
  useCreateContainerMutation,
  useCreateManifestMutation,
  useGetAccreditedConsigneesQuery,
  useGetContainerCatalogQuery,
  useGetContainersQuery,
  useGetCyAllocationsQuery,
  useGetTerminalsQuery,
  useGetUtilizationQuery,
} from '../../app/api';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { API_BASE_URL } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function ManifestCreatePage() {
  const navigate = useNavigate();
  const { shippingLineId } = useDefaultShippingLine();
  const [createManifest] = useCreateManifestMutation();
  const [createContainer] = useCreateContainerMutation();
  const [allocateContainer] = useAllocateContainerMutation();
  const [form, setForm] = useState({
    manifestNumber: `MF-${Date.now().toString().slice(-6)}`,
    vesselName: '',
    voyageNumber: '',
    blNumber: '',
    arrivalDate: new Date().toISOString().slice(0, 10),
    consigneeId: '',
    terminalId: '',
  });
  const { data: consignees = [], isLoading: consigneesLoading } = useGetAccreditedConsigneesQuery();
  const { data: catalog } = useGetContainerCatalogQuery();
  const { data: terminals = [] } = useGetTerminalsQuery();
  const { data: allocations = [] } = useGetCyAllocationsQuery();
  const { data: containers = [] } = useGetContainersQuery();
  const { data: utilization = [] } = useGetUtilizationQuery();
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reviewOpen, setReviewOpen] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [draftContainers, setDraftContainers] = useState<Array<{
    id: string;
    containerNumber: string;
    sizeCode: string;
    type: string;
    cyLocationId: string;
  }>>([]);

  const portTerminals = useMemo(
    () => terminals.filter((terminal) => !/cy|containeryard/i.test(`${terminal.kind}${terminal.identity}`)),
    [terminals],
  );
  const selectedConsignee = useMemo(
    () => consignees.find((consignee) => consignee.id === form.consigneeId) ?? null,
    [consignees, form.consigneeId],
  );
  const selectedTerminal = useMemo(
    () => portTerminals.find((terminal) => terminal.id === form.terminalId) ?? null,
    [form.terminalId, portTerminals],
  );

  const cyCards = useMemo(() => {
    return allocations.map((allocation) => {
      const utilizationRow = utilization.find(
        (row) => row.terminalId === allocation.terminalId || row.terminalName === allocation.terminalName,
      );
      const terminalMeta = terminals.find(
        (terminal) => terminal.id === allocation.terminalId || terminal.name === allocation.terminalName,
      );
      const localContainers = containers.filter(
        (container) => container.cyAllocationId === allocation.id || container.cyTerminalName === allocation.terminalName,
      );
      const allocated20 = localContainers.filter((container) => container.sizeCode?.includes('20')).length;
      const allocated40 = localContainers.filter((container) => container.sizeCode?.includes('40')).length;
      return {
        id: allocation.id,
        name: allocation.terminalName,
        location: [terminalMeta?.city, terminalMeta?.region].filter(Boolean).join(', '),
        capacity20: allocation.capacity20Ft,
        capacity40: allocation.capacity40Ft,
        allocated20,
        allocated40,
        preForecast: utilizationRow?.pendingPreAdvice ?? 0,
        available20: Math.max(allocation.capacity20Ft - allocated20, 0),
        available40: Math.max(allocation.capacity40Ft - allocated40, 0),
        utilizationPct:
          allocation.allocatedCapacityTeu
            ? Math.round((allocation.usedTeu / allocation.allocatedCapacityTeu) * 1000) / 10
            : 0,
      };
    });
  }, [allocations, containers, terminals, utilization]);

  const onCreate = async (e: FormEvent) => {
    e.preventDefault();
    setMessage(null);
    setError(null);
    if (!shippingLineId) {
      setError('Shipping line is not configured.');
      return;
    }
    if (!form.consigneeId) {
      setError('Select a consignee before creating the NOA.');
      return;
    }
    setReviewOpen(true);
  };

  const confirmCreate = async () => {
    setMessage(null);
    setError(null);
    if (!shippingLineId) {
      setError('Shipping line is not configured.');
      return;
    }
    if (!form.consigneeId) {
      setError('Select a consignee before creating the NOA.');
      return;
    }
    const rows = draftContainers.filter((row) => row.containerNumber.trim());
    for (const row of rows) {
      if (!row.cyLocationId) {
        setError(`Assign a CY location for container ${row.containerNumber.trim()}.`);
        return;
      }
    }

    setIsSubmitting(true);
    try {
      const created = await createManifest({
        manifestNumber: form.manifestNumber,
        vesselName: form.vesselName,
        voyageNumber: form.voyageNumber,
        blNumber: form.blNumber || undefined,
        arrivalDate: new Date(`${form.arrivalDate}T00:00:00`).toISOString(),
        shippingLineId,
        consigneeId: form.consigneeId,
        portLocation: selectedTerminal?.name || selectedTerminal?.identity || undefined,
      }).unwrap();

      const types = catalog?.types ?? [];
      const sizes = catalog?.sizes ?? [];
      const resolveSizeId = (sizeCode: string) => {
        const needle = sizeCode.includes('40') ? '40' : '20';
        return (
          sizes.find((s) => s.code?.includes(needle) || s.name?.includes(needle))?.id ??
          sizes[0]?.id
        );
      };
      const resolveTypeId = (typeName: string) => {
        const needle = typeName.toLowerCase();
        return (
          types.find((t) => t.name?.toLowerCase().includes(needle) || t.code?.toLowerCase().includes(needle))?.id ??
          types[0]?.id
        );
      };

      for (const row of rows) {
        const createdContainer = await createContainer({
          containerNumber: row.containerNumber.trim(),
          shippingLineId,
          manifestId: created.id,
          containerSizeId: resolveSizeId(row.sizeCode),
          containerTypeId: resolveTypeId(row.type),
        }).unwrap();

        if (row.cyLocationId) {
          await allocateContainer({
            id: createdContainer.id,
            cyAllocationId: row.cyLocationId,
            reason: 'Assigned during NOA creation',
          }).unwrap();
        }
      }

      setReviewOpen(false);
      setMessage(
        created.noaNumber
          ? `NOA ${created.noaNumber} created and PDF generated.`
          : 'NOA / manifest record created.',
      );

      if (created.noaPdfPath) {
        window.open(`${API_BASE_URL}${created.noaPdfPath}`, '_blank', 'noopener,noreferrer');
      }

      navigate(`/manifests/${created.id}`, {
        state: {
          flash: created.noaNumber
            ? `NOA ${created.noaNumber} created. PDF is ready to download.`
            : 'Manifest created.',
        },
      });
    } catch {
      setError('Create failed. Check consignee accreditation, container numbers, and CY capacity.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const addContainerRow = () => {
    setDraftContainers((current) => [
      ...current,
      {
        id: crypto.randomUUID(),
        containerNumber: '',
        sizeCode: '20ft',
        type: 'Dry',
        cyLocationId: '',
      },
    ]);
  };

  const reviewContainers = draftContainers.map((container) => ({
    ...container,
    cyLocationName: cyCards.find((yard) => yard.id === container.cyLocationId)?.name ?? 'Not assigned',
  }));

  return (
    <WorkflowPage
      eyebrow="NOA Workflow"
      title="Create Notice of Arrival"
      subtitle="Register a new NOA, assign containers, and allocate CY capacity before continuing the manifest workflow."
      chips={
        <>
          <Chip size="small" color="secondary" label="Step 1 of 8" />
          <Chip size="small" variant="outlined" label="CY allocation required per container" />
        </>
      }
      actions={
        <Stack direction="row" spacing={1}>
          <Button component={RouterLink} to="/manifests" startIcon={<ArrowBackOutlinedIcon />}>
            Back
          </Button>
          <Button component={RouterLink} to="/manifests" variant="outlined">
            NOA List
          </Button>
        </Stack>
      }
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      <WorkflowSection
        title="Workflow order"
        subtitle="This page starts the same operational chain used across the shipping workflow."
      >
        <Box>
          <Typography
            variant="caption"
            color="text.secondary"
            fontWeight={700}
            sx={{ textTransform: 'uppercase', letterSpacing: 0.35, display: 'block', mb: 1 }}
          >
            Order
          </Typography>
          <Box
            sx={{
              display: 'flex',
              alignItems: 'center',
              gap: { xs: 0.5, sm: 0.75 },
              overflowX: 'auto',
              pb: 0.25,
              '&::-webkit-scrollbar': { height: 4 },
            }}
          >
            {[
              'Create NOA',
              'Generate BL',
              'Upload BL',
              'Billing',
              'Submit payment',
              'Validate',
              'Generate eDO',
              'Release eDO',
            ].map((step, index, arr) => {
              const active = index === 0;
              return (
                <Box
                  key={step}
                  sx={{ display: 'flex', alignItems: 'center', gap: { xs: 0.5, sm: 0.75 }, flexShrink: 0 }}
                >
                  <Stack direction="row" spacing={0.75} alignItems="center">
                    <Box
                      sx={{
                        width: 20,
                        height: 20,
                        borderRadius: '50%',
                        bgcolor: active ? 'primary.main' : 'action.hover',
                        color: active ? 'primary.contrastText' : 'text.secondary',
                        display: 'grid',
                        placeItems: 'center',
                        fontSize: 10,
                        fontWeight: 700,
                        flexShrink: 0,
                        boxShadow: active
                          ? (t) =>
                              t.palette.mode === 'dark'
                                ? '0 0 0 3px rgba(91,163,201,0.28)'
                                : '0 0 0 3px rgba(11,61,92,0.14)'
                          : 'none',
                      }}
                    >
                      {index + 1}
                    </Box>
                    <Typography
                      variant="body2"
                      fontWeight={active ? 700 : 600}
                      sx={{
                        whiteSpace: 'nowrap',
                        fontSize: 12.5,
                        color: active ? 'primary.main' : 'text.primary',
                      }}
                    >
                      {step}
                    </Typography>
                  </Stack>
                  {index < arr.length - 1 && (
                    <Box
                      sx={{
                        width: { xs: 10, sm: 14 },
                        height: 2,
                        borderRadius: 1,
                        bgcolor: (t) =>
                          t.palette.mode === 'dark' ? 'rgba(154,173,184,0.28)' : 'rgba(11,61,92,0.18)',
                        mx: 0.15,
                      }}
                    />
                  )}
                </Box>
              );
            })}
          </Box>
        </Box>
      </WorkflowSection>

      <Stack component="form" onSubmit={onCreate} spacing={3}>
        <WorkflowSection
          title="Consignee"
          subtitle="Select the accredited consignee. After NOA is generated, the consignee assigns their connected broker."
        >
          <Stack spacing={2}>
            <TextField
              select
              label="Consignee"
              value={form.consigneeId}
              onChange={(e) => setForm({ ...form, consigneeId: e.target.value })}
              fullWidth
              required
              disabled={consigneesLoading}
              helperText={
                consigneesLoading
                  ? 'Loading accredited consignees...'
                  : consignees.length === 0
                    ? 'No accredited consignees for your shipping line yet.'
                    : 'Broker assignment happens at NOA Generated by the consignee.'
              }
            >
              {consignees.map((consignee) => (
                <MenuItem key={consignee.id} value={consignee.id}>
                  {consignee.businessName || consignee.fullName || consignee.email}
                </MenuItem>
              ))}
            </TextField>
          </Stack>
        </WorkflowSection>

        <WorkflowSection
          title="Shipment Details"
          subtitle="Bill of lading, vessel, port, and arrival information."
        >
          <Stack spacing={2}>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
              <TextField
                label="BL Number"
                value={form.blNumber}
                onChange={(e) => setForm({ ...form, blNumber: e.target.value })}
                required
                fullWidth
              />
              <TextField
                label="Vessel Number"
                value={form.vesselName}
                onChange={(e) => setForm({ ...form, vesselName: e.target.value })}
                required
                fullWidth
              />
            </Stack>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
              <TextField
                label="Manifest #"
                value={form.manifestNumber}
                onChange={(e) => setForm({ ...form, manifestNumber: e.target.value })}
                required
                fullWidth
              />
              <TextField
                label="Voyage #"
                value={form.voyageNumber}
                onChange={(e) => setForm({ ...form, voyageNumber: e.target.value })}
                required
                fullWidth
              />
            </Stack>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
              <TextField
                select
                label="Port Location"
                value={form.terminalId}
                onChange={(e) => setForm({ ...form, terminalId: e.target.value })}
                fullWidth
                required
              >
                {portTerminals.map((terminal) => (
                  <MenuItem key={terminal.id} value={terminal.id}>
                    {terminal.name}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                type="date"
                label="Estimated Time of Arrival (ETA)"
                value={form.arrivalDate}
                onChange={(e) => setForm({ ...form, arrivalDate: e.target.value })}
                InputLabelProps={{ shrink: true }}
                required
                fullWidth
              />
            </Stack>
          </Stack>
        </WorkflowSection>

        <WorkflowSection
          title="CY Empty Return Locations"
          subtitle="Container yards only. Use the same compact 3-column location cards from the SL Staff dashboard."
          actions={<Chip size="small" label={`${cyCards.length} CY`} color="primary" />}
        >
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)', xl: 'repeat(3, 1fr)' } }}>
            {cyCards.map((yard) => (
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
              </Paper>
            ))}
          </Box>
        </WorkflowSection>

        <WorkflowSection
          title="Containers"
          subtitle="Add one row per container, then map each one to a selected CY location."
          actions={
            <Button size="small" variant="contained" startIcon={<AddOutlinedIcon />} onClick={addContainerRow}>
              Add Container
            </Button>
          }
        >
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>#</TableCell>
                <TableCell>Container Number</TableCell>
                <TableCell>Size</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>CY Location</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {draftContainers.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5}>
                    <Typography variant="body2" color="text.secondary">
                      No containers added yet.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                draftContainers.map((container, index) => (
                  <TableRow key={container.id}>
                    <TableCell>{index + 1}</TableCell>
                    <TableCell>
                      <TextField
                        size="small"
                        value={container.containerNumber}
                        onChange={(e) =>
                          setDraftContainers((current) =>
                            current.map((row) =>
                              row.id === container.id ? { ...row, containerNumber: e.target.value } : row,
                            ),
                          )
                        }
                        placeholder="Enter container number"
                      />
                    </TableCell>
                    <TableCell>
                      <TextField
                        select
                        size="small"
                        value={container.sizeCode}
                        onChange={(e) =>
                          setDraftContainers((current) =>
                            current.map((row) => (row.id === container.id ? { ...row, sizeCode: e.target.value } : row)),
                          )
                        }
                      >
                        <MenuItem value="20ft">20ft</MenuItem>
                        <MenuItem value="40ft">40ft</MenuItem>
                      </TextField>
                    </TableCell>
                    <TableCell>
                      <TextField
                        select
                        size="small"
                        value={container.type}
                        onChange={(e) =>
                          setDraftContainers((current) =>
                            current.map((row) => (row.id === container.id ? { ...row, type: e.target.value } : row)),
                          )
                        }
                      >
                        <MenuItem value="Dry">Dry</MenuItem>
                        <MenuItem value="Reefer">Reefer</MenuItem>
                      </TextField>
                    </TableCell>
                    <TableCell>
                      <TextField
                        select
                        size="small"
                        value={container.cyLocationId}
                        onChange={(e) =>
                          setDraftContainers((current) =>
                            current.map((row) =>
                              row.id === container.id ? { ...row, cyLocationId: e.target.value } : row,
                            ),
                          )
                        }
                        sx={{ minWidth: 220 }}
                      >
                        {cyCards.map((yard) => (
                          <MenuItem key={yard.id} value={yard.id}>
                            {yard.name}
                          </MenuItem>
                        ))}
                      </TextField>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </WorkflowSection>

        <Paper elevation={0} sx={{ border: 1, borderColor: 'divider', borderRadius: 2, px: 2.5, py: 2 }}>
          <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={2}>
            <Stack direction="row" alignItems="center" spacing={1.5} minWidth={0} flexWrap="wrap" useFlexGap>
              <Typography variant="h6" fontWeight={700} noWrap>
                Ready to create
              </Typography>
              <Typography variant="body2" color="text.secondary" noWrap>
                All required shipment fields must be completed before creating the NOA.
              </Typography>
            </Stack>
            <Stack direction="row" spacing={1.5} flexShrink={0}>
              <Button component={RouterLink} to="/manifests" variant="outlined">
                Cancel
              </Button>
              <Button type="submit" variant="contained" startIcon={<AddOutlinedIcon />} disabled={!shippingLineId}>
                Create NOA
              </Button>
            </Stack>
          </Stack>
        </Paper>
      </Stack>
      <Dialog open={reviewOpen} onClose={() => (isSubmitting ? null : setReviewOpen(false))} fullWidth maxWidth="md">
        <DialogTitle>Review NOA details</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={3}>
            <Alert severity="info" variant="outlined">
              Review the details below before confirming this NOA creation.
            </Alert>

            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' } }}>
              <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
                <Typography variant="overline" color="text.secondary">
                  Consignee
                </Typography>
                <Typography fontWeight={700}>
                  {selectedConsignee?.businessName || selectedConsignee?.fullName || selectedConsignee?.email || 'Not selected'}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {selectedConsignee?.email || 'No email on record'}
                </Typography>
              </Paper>
              <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
                <Typography variant="overline" color="text.secondary">
                  Broker
                </Typography>
                <Typography fontWeight={700}>Pending consignee assignment</Typography>
                <Typography variant="body2" color="text.secondary">
                  Assigned after NOA Generated
                </Typography>
              </Paper>
              <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
                <Typography variant="overline" color="text.secondary">
                  Port location
                </Typography>
                <Typography fontWeight={700}>{selectedTerminal?.name || 'Not selected'}</Typography>
                <Typography variant="body2" color="text.secondary">
                  ETA: {form.arrivalDate || 'Not set'}
                </Typography>
              </Paper>
            </Box>

            <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
              <Typography variant="overline" color="text.secondary">
                Shipment summary
              </Typography>
              <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' }, mt: 1 }}>
                <ReviewValue label="Manifest #" value={form.manifestNumber} />
                <ReviewValue label="BL Number" value={form.blNumber} />
                <ReviewValue label="Vessel Number" value={form.vesselName} />
                <ReviewValue label="Voyage #" value={form.voyageNumber} />
              </Box>
            </Paper>

            <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
              <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1.5}>
                <Typography variant="overline" color="text.secondary">
                  Container review
                </Typography>
                <Chip size="small" label={`${reviewContainers.length} container${reviewContainers.length === 1 ? '' : 's'}`} />
              </Stack>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>#</TableCell>
                    <TableCell>Container Number</TableCell>
                    <TableCell>Size</TableCell>
                    <TableCell>Type</TableCell>
                    <TableCell>CY Location</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {reviewContainers.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={5}>
                        <Typography variant="body2" color="text.secondary">
                          No containers added yet.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  ) : (
                    reviewContainers.map((container, index) => (
                      <TableRow key={container.id}>
                        <TableCell>{index + 1}</TableCell>
                        <TableCell>{container.containerNumber || 'Pending'}</TableCell>
                        <TableCell>{container.sizeCode}</TableCell>
                        <TableCell>{container.type}</TableCell>
                        <TableCell>{container.cyLocationName}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </Paper>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setReviewOpen(false)} disabled={isSubmitting}>
            Back to edit
          </Button>
          <Button onClick={confirmCreate} variant="contained" disabled={isSubmitting || !shippingLineId}>
            {isSubmitting ? 'Creating...' : 'Confirm Create NOA'}
          </Button>
        </DialogActions>
      </Dialog>
    </WorkflowPage>
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
      <Typography fontWeight={800} color={color} fontSize="1.25rem" lineHeight={1.1} mt={0.35}>
        {value}
      </Typography>
    </Box>
  );
}

function ReviewValue({ label, value }: { label: string; value: string }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">
        {label}
      </Typography>
      <Typography fontWeight={700}>{value || 'Not provided'}</Typography>
    </Box>
  );
}
