import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Collapse,
  Divider,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import AddOutlinedIcon from '@mui/icons-material/AddOutlined';
import CloudUploadOutlinedIcon from '@mui/icons-material/CloudUploadOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import FilterListOutlinedIcon from '@mui/icons-material/FilterListOutlined';
import ExpandMoreOutlinedIcon from '@mui/icons-material/ExpandMoreOutlined';
import ExpandLessOutlinedIcon from '@mui/icons-material/ExpandLessOutlined';
import PersonAddAltOutlinedIcon from '@mui/icons-material/PersonAddAltOutlined';
import { Link as RouterLink } from 'react-router-dom';
import { useSelector } from 'react-redux';
import {
  useDeclareConsigneeMutation,
  useGetAccreditedConsigneesQuery,
  useGetEdosQuery,
  useGenerateBillingMutation,
  useGetHierarchyUsersQuery,
  useGetManifestsQuery,
  useGetPendingPaymentsQuery,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { formatWorkflowState } from '../../shared/formatWorkflowState';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

function toneForState(state: string): 'default' | 'info' | 'warning' | 'success' | 'error' | 'secondary' {
  if (/released|generated|verified|completed/i.test(state)) return 'success';
  if (/billing|payment|pending/i.test(state)) return 'warning';
  if (/noa|bl/i.test(state)) return 'info';
  if (/reject|deny|fail/i.test(state)) return 'error';
  return 'default';
}

function stageForManifest(
  manifest: {
    id: string;
    workflowState: string;
    billingTotal?: number | null;
  },
  edos: Array<{ manifestId: string }>,
  pendingPayments: Array<{ manifestId: string }>,
): string {
  if (/released|completed/i.test(manifest.workflowState)) return 'edo_released';
  if (edos.some((edo) => edo.manifestId === manifest.id)) return 'edo_generated';
  if (pendingPayments.some((payment) => payment.manifestId === manifest.id)) return 'payment_validated';
  if (manifest.billingTotal != null) return 'billing';
  if (/BlUploaded/i.test(manifest.workflowState)) return 'bl_uploaded';
  if (/BlGenerated/i.test(manifest.workflowState)) return 'bl_generated';
  if (/NoaGenerated|NoaCreated/i.test(manifest.workflowState)) return 'noa_created';
  return 'all';
}

export function ManifestsPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const isAccounting = ['Accounting', 'SystemAdmin'].includes(user?.role ?? '');
  const isBroker = user?.role === 'Broker';
  const isConsignee = user?.role === 'Consignee';

  const { data: manifests = [], refetch } = useGetManifestsQuery();
  const { data: edos = [] } = useGetEdosQuery();
  const { data: pendingPayments = [] } = useGetPendingPaymentsQuery(undefined, { skip: !isAccounting });
  const { shippingLineId } = useDefaultShippingLine();
  const isStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');
  const { data: users = [] } = useGetHierarchyUsersQuery(undefined, { skip: !isStaff });
  const { data: accreditedConsignees = [] } = useGetAccreditedConsigneesQuery(undefined, {
    skip: !isStaff,
  });
  const consignees = accreditedConsignees;
  const brokers = useMemo(() => users.filter((u) => u.role === 'Broker'), [users]);

  const [declareConsignee] = useDeclareConsigneeMutation();
  const [generateBilling] = useGenerateBillingMutation();

  const [declare, setDeclare] = useState({ manifestId: '', consigneeId: '', brokerId: '' });
  const [billing, setBilling] = useState({
    manifestId: '',
    freightCharges: 1000,
    thcCharges: 200,
    additionalCharges: 50,
    currency: 'USD',
  });
  const [filters, setFilters] = useState({
    dateFrom: '',
    dateTo: '',
    consigneeId: '',
    blNumber: '',
  });
  const [activeStage, setActiveStage] = useState<string>('all');
  const [showFilters, setShowFilters] = useState(false);
  const [showDeclare, setShowDeclare] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const activeManifests = useMemo(
    () => manifests.filter((m) => !/released|completed/i.test(m.workflowState)),
    [manifests],
  );
  const billed = useMemo(() => manifests.filter((m) => m.billingTotal != null), [manifests]);
  const completed = useMemo(
    () => manifests.filter((m) => /released|completed/i.test(m.workflowState)),
    [manifests],
  );
  const filteredManifests = useMemo(() => {
    return manifests.filter((m) => {
      const createdAt = new Date(m.createdAt);
      const matchesDateFrom = !filters.dateFrom || createdAt >= new Date(filters.dateFrom);
      const matchesDateTo = !filters.dateTo || createdAt <= new Date(`${filters.dateTo}T23:59:59`);
      const matchesConsignee = !filters.consigneeId || m.consigneeId === filters.consigneeId;
      const matchesBl = !filters.blNumber || (m.blNumber ?? '').toLowerCase().includes(filters.blNumber.toLowerCase());
      const matchesStage = activeStage === 'all' || stageForManifest(m, edos, pendingPayments) === activeStage;
      return matchesDateFrom && matchesDateTo && matchesConsignee && matchesBl && matchesStage;
    });
  }, [manifests, filters, activeStage, edos, pendingPayments]);

  const stageCards = useMemo(
    () => [
      { id: 'all', label: 'Total', count: manifests.length },
      { id: 'noa_created', label: 'NOA', count: manifests.filter((m) => /NoaGenerated|NoaCreated/i.test(m.workflowState)).length },
      { id: 'bl_generated', label: 'BL gen', count: manifests.filter((m) => /BlGenerated/i.test(m.workflowState)).length },
      { id: 'bl_uploaded', label: 'BL up', count: manifests.filter((m) => /BlUploaded/i.test(m.workflowState)).length },
      { id: 'billing', label: 'Billing', count: manifests.filter((m) => m.billingTotal != null).length },
      { id: 'payment_submitted', label: 'Pay', count: manifests.filter((m) => m.billingTotal != null && !pendingPayments.some((p) => p.manifestId === m.id)).length },
      { id: 'payment_validated', label: 'Validate', count: pendingPayments.length },
      { id: 'edo_generated', label: 'eDO', count: edos.length },
      { id: 'edo_released', label: 'Release', count: manifests.filter((m) => /released|completed/i.test(m.workflowState)).length },
    ],
    [manifests, pendingPayments, edos],
  );

  return (
    <WorkflowPage
      eyebrow={isBroker ? 'Broker Workflow' : isConsignee ? 'Consignee Workflow' : 'Operations'}
      title={isBroker || isConsignee ? 'My Manifests' : 'Manifest Workflow Hub'}
      subtitle={
        isBroker || isConsignee
          ? 'Track shipment state, billing readiness, and next operational actions.'
          : 'Create, assign, bill, and move manifests through the shipping workflow.'
      }
      chips={
        <>
          <Chip size="small" color="primary" label={`${activeManifests.length} active`} />
          <Chip size="small" color="success" label={`${completed.length} completed`} />
          {shippingLineId && <Chip size="small" variant="outlined" label="Single shipping line" />}
        </>
      }
      actions={
        <>
          {isStaff && (
            <Button
              component={RouterLink}
              to="/manifests/create"
              variant="contained"
              startIcon={<AddOutlinedIcon />}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              Create NOA
            </Button>
          )}
          {isStaff && (
            <Button
              component={RouterLink}
              to="/manifests/bulk-import"
              variant="outlined"
              startIcon={<CloudUploadOutlinedIcon />}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              Bulk Import NOAs
            </Button>
          )}
          {isStaff && (
            <Button
              component={RouterLink}
              to="/manifests/bulk-import-manifests"
              variant="outlined"
              startIcon={<CloudUploadOutlinedIcon />}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              Bulk Import Manifests
            </Button>
          )}
          {isAccounting && (
            <Button
              component={RouterLink}
              to="/payments"
              variant="outlined"
              startIcon={<ReceiptLongOutlinedIcon />}
              sx={{ textTransform: 'none', fontWeight: 600 }}
            >
              Validate Payments
            </Button>
          )}
          <Button
            component={RouterLink}
            to="/edo"
            variant="outlined"
            startIcon={<DescriptionOutlinedIcon />}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            My eDOs
          </Button>
        </>
      }
      stats={[
        { label: 'All Manifests', value: manifests.length, hint: 'Records in scope', tone: 'primary' },
        { label: 'Active', value: activeManifests.length, hint: 'Still in workflow', tone: 'info' },
        { label: 'Billed', value: billed.length, hint: 'With generated billing', tone: 'warning' },
        { label: 'Completed', value: completed.length, hint: 'Released or closed', tone: 'success' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      <WorkflowSection
        title="Workflow controls"
        subtitle="Sequence, stage filters, search, and declare — all in one place."
        actions={
          <Button
            size="small"
            startIcon={<FilterListOutlinedIcon />}
            onClick={() => {
              setFilters({ dateFrom: '', dateTo: '', consigneeId: '', blNumber: '' });
              setActiveStage('all');
            }}
          >
            Clear filters
          </Button>
        }
      >
        <Stack spacing={1.75} divider={<Divider flexItem />}>
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
              ].map((step, index, arr) => (
                <Box key={step} sx={{ display: 'flex', alignItems: 'center', gap: { xs: 0.5, sm: 0.75 }, flexShrink: 0 }}>
                  <Stack direction="row" spacing={0.75} alignItems="center">
                    <Box
                      sx={{
                        width: 20,
                        height: 20,
                        borderRadius: '50%',
                        bgcolor: 'primary.main',
                        color: 'primary.contrastText',
                        display: 'grid',
                        placeItems: 'center',
                        fontSize: 10,
                        fontWeight: 700,
                        flexShrink: 0,
                      }}
                    >
                      {index + 1}
                    </Box>
                    <Typography
                      variant="body2"
                      fontWeight={600}
                      sx={{ whiteSpace: 'nowrap', fontSize: 12.5, color: 'text.primary' }}
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
                        bgcolor: 'rgba(11,61,92,0.18)',
                        mx: 0.15,
                      }}
                    />
                  )}
                </Box>
              ))}
            </Box>
          </Box>

          <Box>
            <Typography
              variant="caption"
              color="text.secondary"
              fontWeight={700}
              sx={{ textTransform: 'uppercase', letterSpacing: 0.35, display: 'block', mb: 1 }}
            >
              Stages
            </Typography>
            <Box
              sx={{
                display: 'flex',
                flexWrap: 'nowrap',
                gap: 0.75,
                overflowX: 'auto',
                pb: 0.25,
                '&::-webkit-scrollbar': { height: 4 },
              }}
            >
              {stageCards.map((stage) => {
                const active = activeStage === stage.id;
                return (
                  <Button
                    key={stage.id}
                    size="small"
                    variant={active ? 'contained' : 'outlined'}
                    color={active ? 'primary' : 'inherit'}
                    onClick={() => setActiveStage(stage.id)}
                    sx={{
                      flexShrink: 0,
                      textTransform: 'none',
                      fontWeight: 600,
                      fontSize: 12.5,
                      px: 1.25,
                      py: 0.55,
                      minHeight: 32,
                      borderRadius: 1.5,
                      borderColor: active ? 'primary.main' : 'divider',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    {stage.label}
                    <Box
                      component="span"
                      sx={{
                        ml: 0.75,
                        px: 0.65,
                        py: 0.05,
                        borderRadius: 1,
                        fontVariantNumeric: 'tabular-nums',
                        fontWeight: 800,
                        fontSize: 11.5,
                        bgcolor: active ? 'rgba(255,255,255,0.22)' : 'rgba(11,61,92,0.08)',
                        color: active ? 'inherit' : 'primary.main',
                      }}
                    >
                      {stage.count}
                    </Box>
                  </Button>
                );
              })}
            </Box>
          </Box>

          <Box>
            <Button
              size="small"
              color="inherit"
              onClick={() => setShowFilters((v) => !v)}
              startIcon={<FilterListOutlinedIcon sx={{ fontSize: 16 }} />}
              endIcon={showFilters ? <ExpandLessOutlinedIcon /> : <ExpandMoreOutlinedIcon />}
              sx={{
                textTransform: 'uppercase',
                letterSpacing: 0.35,
                fontWeight: 700,
                fontSize: 12,
                color: 'text.secondary',
                px: 0.5,
                mb: showFilters ? 1 : 0,
                '&:hover': { bgcolor: 'action.hover' },
              }}
            >
              Filters
              {(filters.dateFrom || filters.dateTo || filters.consigneeId || filters.blNumber) && (
                <Box
                  component="span"
                  sx={{
                    ml: 1,
                    px: 0.75,
                    py: 0.1,
                    borderRadius: 1,
                    fontSize: 10,
                    fontWeight: 800,
                    bgcolor: 'primary.main',
                    color: 'primary.contrastText',
                  }}
                >
                  On
                </Box>
              )}
            </Button>
            <Collapse in={showFilters}>
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5}>
                <TextField
                  size="small"
                  type="date"
                  label="Date from"
                  value={filters.dateFrom}
                  onChange={(e) => setFilters({ ...filters, dateFrom: e.target.value })}
                  InputLabelProps={{ shrink: true }}
                />
                <TextField
                  size="small"
                  type="date"
                  label="Date to"
                  value={filters.dateTo}
                  onChange={(e) => setFilters({ ...filters, dateTo: e.target.value })}
                  InputLabelProps={{ shrink: true }}
                />
                <TextField
                  select
                  size="small"
                  label="Consignee"
                  value={filters.consigneeId}
                  onChange={(e) => setFilters({ ...filters, consigneeId: e.target.value })}
                  sx={{ minWidth: 160 }}
                >
                  <MenuItem value="">All consignees</MenuItem>
                  {consignees.map((c) => (
                    <MenuItem key={c.id} value={c.id}>
                      {c.businessName || c.fullName}
                    </MenuItem>
                  ))}
                </TextField>
                <TextField
                  size="small"
                  label="BL Number"
                  value={filters.blNumber}
                  onChange={(e) => setFilters({ ...filters, blNumber: e.target.value })}
                />
              </Stack>
            </Collapse>
          </Box>

          {isStaff && (
            <Box>
              <Button
                size="small"
                color="inherit"
                onClick={() => setShowDeclare((v) => !v)}
                startIcon={<PersonAddAltOutlinedIcon sx={{ fontSize: 16 }} />}
                endIcon={showDeclare ? <ExpandLessOutlinedIcon /> : <ExpandMoreOutlinedIcon />}
                sx={{
                  textTransform: 'uppercase',
                  letterSpacing: 0.35,
                  fontWeight: 700,
                  fontSize: 12,
                  color: 'text.secondary',
                  px: 0.5,
                  mb: showDeclare ? 1 : 0,
                  '&:hover': { bgcolor: 'action.hover' },
                }}
              >
                Declare consignee
              </Button>
              <Collapse in={showDeclare}>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} alignItems={{ md: 'center' }}>
                  <TextField
                    select
                    size="small"
                    label="Manifest"
                    value={declare.manifestId}
                    onChange={(e) => setDeclare({ ...declare, manifestId: e.target.value })}
                    sx={{ minWidth: 160 }}
                  >
                    {manifests.map((m) => (
                      <MenuItem key={m.id} value={m.id}>
                        {m.manifestNumber}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    select
                    size="small"
                    label="Consignee"
                    value={declare.consigneeId}
                    onChange={(e) => setDeclare({ ...declare, consigneeId: e.target.value })}
                    sx={{ minWidth: 160 }}
                  >
                    {consignees.map((c) => (
                      <MenuItem key={c.id} value={c.id}>
                        {c.businessName || c.fullName}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    select
                    size="small"
                    label="Broker"
                    value={declare.brokerId}
                    onChange={(e) => setDeclare({ ...declare, brokerId: e.target.value })}
                    sx={{ minWidth: 160 }}
                  >
                    {brokers.map((b) => (
                      <MenuItem key={b.id} value={b.id}>
                        {b.fullName}
                      </MenuItem>
                    ))}
                  </TextField>
                  <Button
                    variant="contained"
                    size="small"
                    sx={{ minHeight: 40, px: 2 }}
                    onClick={async () => {
                      try {
                        await declareConsignee({
                          id: declare.manifestId,
                          consigneeId: declare.consigneeId,
                          brokerId: declare.brokerId || undefined,
                        }).unwrap();
                        setMessage('Consignee declared.');
                        refetch();
                      } catch {
                        setError('Declare failed.');
                      }
                    }}
                  >
                    Declare
                  </Button>
                </Stack>
              </Collapse>
            </Box>
          )}
        </Stack>
      </WorkflowSection>

      {isAccounting && (
        <WorkflowSection
          title="Generate Billing"
          subtitle="Prepare billing once manifest documentation is complete."
        >
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <TextField
              select
              label="Manifest"
              value={billing.manifestId}
              onChange={(e) => setBilling({ ...billing, manifestId: e.target.value })}
              sx={{ minWidth: 180 }}
            >
              {manifests
                .filter((m) => m.workflowState === 'BlUploaded')
                .map((m) => (
                  <MenuItem key={m.id} value={m.id}>
                    {m.manifestNumber}
                  </MenuItem>
                ))}
            </TextField>
            <TextField
              label="Freight"
              type="number"
              value={billing.freightCharges}
              onChange={(e) => setBilling({ ...billing, freightCharges: Number(e.target.value) })}
            />
            <TextField
              label="THC"
              type="number"
              value={billing.thcCharges}
              onChange={(e) => setBilling({ ...billing, thcCharges: Number(e.target.value) })}
            />
            <Button
              variant="contained"
              onClick={async () => {
                try {
                  await generateBilling(billing).unwrap();
                  setMessage('Billing generated.');
                  refetch();
                } catch {
                  setError('Billing failed.');
                }
              }}
            >
              Generate
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      <WorkflowSection
        title={isBroker || isConsignee ? 'Manifest Queue' : 'All Manifests'}
        subtitle="NOA-centric queue with richer workflow visibility, closer to the V1 manifest-workflow view."
        actions={
          <Button component={RouterLink} to="/payments" size="small" startIcon={<ReceiptLongOutlinedIcon />} sx={{ textTransform: 'none' }}>
            Payments
          </Button>
        }
      >
        <Box sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>NOA Number</TableCell>
              <TableCell>BL Number</TableCell>
              <TableCell>Vessel</TableCell>
              <TableCell>State</TableCell>
              <TableCell>Consignee</TableCell>
              <TableCell>ETA</TableCell>
              <TableCell>eDO Progress</TableCell>
              <TableCell>Created</TableCell>
              <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {filteredManifests.length === 0 && (
              <TableRow>
                <TableCell colSpan={9}>
                  <Typography variant="body2" color="text.secondary" py={2} textAlign="center">
                    No manifests yet.
                  </Typography>
                </TableCell>
              </TableRow>
            )}
            {filteredManifests.map((m) => (
              <TableRow key={m.id}>
                <TableCell>
                  <Button component={RouterLink} to={`/manifests/${m.id}`} size="small">
                    {m.noaNumber ?? m.manifestNumber}
                  </Button>
                </TableCell>
                <TableCell>{m.blNumber ?? '-'}</TableCell>
                <TableCell>
                  <Typography variant="body2">{m.vesselName ?? '-'}</Typography>
                  {m.voyageNumber && (
                    <Typography variant="caption" color="text.secondary">
                      Voyage {m.voyageNumber}
                    </Typography>
                  )}
                </TableCell>
                <TableCell>
                  <Chip size="small" label={formatWorkflowState(m.workflowState)} color={toneForState(m.workflowState)} />
                </TableCell>
                <TableCell>{m.consigneeName ?? '-'}</TableCell>
                <TableCell>
                  {m.arrivalDate ? new Date(m.arrivalDate).toLocaleDateString() : '-'}
                </TableCell>
                <TableCell>
                  {(() => {
                    const manifestEdos = edos.filter((edo) => edo.manifestId === m.id);
                    const released = manifestEdos.filter((edo) => /released|completed/i.test(edo.status)).length;
                    return manifestEdos.length
                      ? `${released}/${manifestEdos.length} eDOs`
                      : m.billingTotal != null
                        ? 'Ready for eDO'
                        : '-';
                  })()}
                </TableCell>
                <TableCell>
                  <Typography variant="body2">{new Date(m.createdAt).toLocaleDateString()}</Typography>
                  {m.billingTotal != null && (
                    <Typography variant="caption" color="text.secondary">
                      {m.billingTotal} {m.billingCurrency}
                    </Typography>
                  )}
                </TableCell>
                <TableCell align="right">
                  <TableViewLink to={`/manifests/${m.id}`} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        </Box>
      </WorkflowSection>
    </WorkflowPage>
  );
}
