import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Chip,
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
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import {
  useCreateRepositioningMutation,
  useGetRepositioningEligibleContainersQuery,
  useGetTerminalsQuery,
} from '../../app/api';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function dwellTone(days: number): 'default' | 'warning' | 'error' {
  if (days >= 90) return 'error';
  if (days >= 60) return 'warning';
  return 'default';
}

export function RepositioningCreatePage() {
  const navigate = useNavigate();
  const { shippingLineId } = useDefaultShippingLine();
  const { data: terminals = [] } = useGetTerminalsQuery();
  const [create] = useCreateRepositioningMutation();

  const cyTerminals = useMemo(
    () => terminals.filter((t) => t.identity === 'ContainerYard' && t.isActive),
    [terminals],
  );
  const portTerminals = useMemo(
    () => terminals.filter((t) => t.identity === 'Terminal' && t.isActive),
    [terminals],
  );

  const [form, setForm] = useState({
    requestType: 'Export',
    sourceTerminalId: '',
    destinationTerminalId: '',
    purpose: '',
    search: '',
  });
  const [letter, setLetter] = useState<File | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [draftSearch, setDraftSearch] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const { data: eligible = [], isFetching } = useGetRepositioningEligibleContainersQuery(
    {
      sourceTerminalId: form.sourceTerminalId || undefined,
      search: form.search || undefined,
    },
    { skip: !form.sourceTerminalId },
  );

  const toggle = (id: string) => {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const toggleAll = () => {
    if (selected.length === eligible.length) setSelected([]);
    else setSelected(eligible.map((c) => c.id));
  };

  return (
    <WorkflowPage
      eyebrow="Outbound movement"
      title="New Outbound Request"
      subtitle="Select a CY, destination port, purpose, and eligible high-dwell containers."
      actions={
        <Button component={RouterLink} to="/repositioning" variant="outlined">
          Back to requests
        </Button>
      }
    >
      {message && <Alert severity="success" sx={{ mb: 2 }}>{message}</Alert>}
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

      <WorkflowSection title="Request details" subtitle="Export or repositioning moves from CY to port.">
        <Stack spacing={2}>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <TextField
              select
              label="Request type"
              value={form.requestType}
              onChange={(e) => setForm({ ...form, requestType: e.target.value })}
              sx={{ minWidth: 180 }}
            >
              <MenuItem value="Export">Export</MenuItem>
              <MenuItem value="Repositioning">Repositioning</MenuItem>
            </TextField>
            <TextField
              select
              label="Source CY"
              value={form.sourceTerminalId}
              onChange={(e) => {
                setForm({ ...form, sourceTerminalId: e.target.value });
                setSelected([]);
              }}
              sx={{ minWidth: 220 }}
            >
              {cyTerminals.map((t) => (
                <MenuItem key={t.id} value={t.id}>
                  {t.name}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="Destination port"
              value={form.destinationTerminalId}
              onChange={(e) => setForm({ ...form, destinationTerminalId: e.target.value })}
              sx={{ minWidth: 220 }}
            >
              {portTerminals.map((t) => (
                <MenuItem key={t.id} value={t.id}>
                  {t.name} ({t.code})
                </MenuItem>
              ))}
            </TextField>
          </Stack>
          <TextField
            label="Purpose"
            value={form.purpose}
            onChange={(e) => setForm({ ...form, purpose: e.target.value })}
            multiline
            minRows={3}
            fullWidth
            required
          />
          <Button variant="outlined" component="label" sx={{ alignSelf: 'flex-start' }}>
            {letter ? `Letter: ${letter.name}` : 'Attach request letter (optional)'}
            <input
              hidden
              type="file"
              accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/*"
              onChange={(e) => setLetter(e.target.files?.[0] ?? null)}
            />
          </Button>
        </Stack>
      </WorkflowSection>

      <Box mt={3}>
        <WorkflowSection
          title="Select containers"
          subtitle="Eligible containers at the selected CY, sorted by dwell days (CAO 8-2019)."
          actions={
            <Stack direction="row" spacing={1}>
              <TextField
                size="small"
                label="Search container #"
                value={draftSearch}
                onChange={(e) => setDraftSearch(e.target.value.toUpperCase())}
                onKeyDown={(e) => e.key === 'Enter' && setForm({ ...form, search: draftSearch.trim() })}
              />
              <Button
                variant="outlined"
                disabled={!form.sourceTerminalId}
                onClick={() => setForm({ ...form, search: draftSearch.trim() })}
              >
                Filter
              </Button>
            </Stack>
          }
        >
          {!form.sourceTerminalId ? (
            <Alert severity="info" variant="outlined">
              Choose a source CY to load eligible containers.
            </Alert>
          ) : eligible.length === 0 && !isFetching ? (
            <Alert severity="info" variant="outlined">
              No eligible containers found for this CY.
            </Alert>
          ) : (
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell padding="checkbox">
                    <Checkbox
                      checked={eligible.length > 0 && selected.length === eligible.length}
                      indeterminate={selected.length > 0 && selected.length < eligible.length}
                      onChange={toggleAll}
                    />
                  </TableCell>
                  <TableCell>#</TableCell>
                  <TableCell>Container</TableCell>
                  <TableCell>Size</TableCell>
                  <TableCell>CY</TableCell>
                  <TableCell>Dwell days</TableCell>
                  <TableCell>Discharge date</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {eligible.map((c, index) => (
                  <TableRow key={c.id} hover selected={selected.includes(c.id)}>
                    <TableCell padding="checkbox">
                      <Checkbox checked={selected.includes(c.id)} onChange={() => toggle(c.id)} />
                    </TableCell>
                    <TableCell>{index + 1}</TableCell>
                    <TableCell>
                      <Typography fontFamily="ui-monospace, monospace" fontWeight={700}>
                        {c.containerNumber}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      {[c.sizeCode, c.typeCode].filter(Boolean).join(' / ') || '—'}
                    </TableCell>
                    <TableCell>{c.depotName}</TableCell>
                    <TableCell>
                      <Chip size="small" label={`${c.currentDwellDays}d`} color={dwellTone(c.currentDwellDays)} />
                    </TableCell>
                    <TableCell>
                      {c.dischargeDate
                        ? new Date(c.dischargeDate).toLocaleDateString(undefined, {
                            month: 'short',
                            day: 'numeric',
                            year: 'numeric',
                          })
                        : '—'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </WorkflowSection>
      </Box>

      <Stack direction="row" spacing={1.5} mt={3} justifyContent="flex-end">
        <Button component={RouterLink} to="/repositioning" variant="outlined">
          Cancel
        </Button>
        <Button
          variant="contained"
          disabled={
            busy ||
            !shippingLineId ||
            !form.sourceTerminalId ||
            !form.destinationTerminalId ||
            !form.purpose.trim() ||
            selected.length === 0
          }
          onClick={async () => {
            try {
              setBusy(true);
              setError(null);
              const created = await create({
                shippingLineId: shippingLineId!,
                requestType: form.requestType,
                sourceTerminalId: form.sourceTerminalId,
                destinationTerminalId: form.destinationTerminalId,
                purpose: form.purpose.trim(),
                containerIds: selected,
                letter,
              }).unwrap();
              setMessage(`Created ${created.requestNumber}`);
              navigate(`/repositioning/${created.id}`);
            } catch (e: unknown) {
              setError((e as { data?: { message?: string } })?.data?.message ?? 'Failed to create request');
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? 'Submitting…' : `Submit request (${selected.length})`}
        </Button>
      </Stack>
    </WorkflowPage>
  );
}
