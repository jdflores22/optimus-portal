import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  MenuItem,
  Pagination,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import {
  useGetContainerInventoryDepotsQuery,
  useGetContainerInventoryQuery,
} from '../../app/api';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

function dwellTone(days: number): 'success' | 'info' | 'warning' | 'error' {
  if (days <= 7) return 'success';
  if (days <= 14) return 'info';
  if (days <= 21) return 'warning';
  return 'error';
}

function statusTone(status: string): 'success' | 'warning' | 'default' {
  if (status === 'Available') return 'success';
  if (status === 'Pre-Forecast') return 'warning';
  return 'default';
}

function conditionTone(condition: string): 'success' | 'warning' | 'error' {
  if (condition === 'Good') return 'success';
  if (condition === 'Fair') return 'warning';
  return 'error';
}

function sizeTone(label: string): 'info' | 'warning' | 'error' {
  if (label.startsWith('20')) return 'info';
  if (label.startsWith('40')) return 'warning';
  return 'error';
}

export function ContainerInventoryPage() {
  const [draftSearch, setDraftSearch] = useState('');
  const [draftDepot, setDraftDepot] = useState('');
  const [search, setSearch] = useState('');
  const [depot, setDepot] = useState('');
  const [page, setPage] = useState(1);

  const { data: depots = [] } = useGetContainerInventoryDepotsQuery();
  const { data, error, isFetching } = useGetContainerInventoryQuery({
    depot: depot || undefined,
    search: search || undefined,
    page,
    pageSize: 50,
  });

  const stats = data?.stats;
  const items = data?.items ?? [];

  const pageStats = useMemo(
    () => [
      { label: 'Total containers', value: stats?.totalContainers ?? 0, hint: 'All depots combined', tone: 'primary' as const },
      { label: 'Total TEUs', value: stats?.totalTeus ?? 0, hint: 'Twenty-foot equivalent units', tone: 'success' as const },
      { label: '20ft containers', value: stats?.total20Ft ?? 0, hint: 'Standard size', tone: 'info' as const },
      { label: '40ft containers', value: stats?.total40Ft ?? 0, hint: 'High capacity', tone: 'warning' as const },
      {
        label: 'Overall capacity',
        value: stats?.overallCapacityTeu ?? 0,
        hint: `${stats?.overallCapacity20Ft ?? 0}× 20ft, ${stats?.overallCapacity40Ft ?? 0}× 40ft`,
        tone: 'primary' as const,
      },
      {
        label: 'Terminals',
        value: stats?.terminalCount ?? 0,
        hint: `${stats?.terminalCapacityTeu ?? 0} TEU capacity`,
        tone: 'info' as const,
      },
      {
        label: 'Container yards',
        value: stats?.yardCount ?? 0,
        hint: `${stats?.yardCapacityTeu ?? 0} TEU capacity`,
        tone: 'success' as const,
      },
    ],
    [stats],
  );

  const applyFilters = () => {
    setSearch(draftSearch.trim());
    setDepot(draftDepot);
    setPage(1);
  };

  const resetFilters = () => {
    setDraftSearch('');
    setDraftDepot('');
    setSearch('');
    setDepot('');
    setPage(1);
  };

  return (
    <WorkflowPage
      eyebrow="Terminal operations"
      title="Container Inventory"
      subtitle={`All containers across all depots — ${data?.shippingLineName ?? '…'}`}
      chips={
        <>
          <Chip size="small" label={`${data?.totalCount ?? 0} containers`} color="primary" />
          {isFetching && <Chip size="small" label="Refreshing…" variant="outlined" />}
        </>
      }
      stats={pageStats}
    >
      {error && (
        <Alert severity="error" sx={{ mb: 2 }}>
          Unable to load container inventory.
        </Alert>
      )}

      <WorkflowSection title="Filters" subtitle="Search by exact container number or narrow by depot.">
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} alignItems={{ md: 'center' }}>
          <TextField
            size="small"
            label="Container number"
            placeholder="Search by container number…"
            value={draftSearch}
            onChange={(e) => setDraftSearch(e.target.value.toUpperCase())}
            onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
            sx={{ minWidth: 240 }}
          />
          <TextField
            select
            size="small"
            label="Depot"
            value={draftDepot}
            onChange={(e) => setDraftDepot(e.target.value)}
            sx={{ minWidth: 220 }}
          >
            <MenuItem value="">All Depots</MenuItem>
            {depots.map((d) => (
              <MenuItem key={d} value={d}>
                {d}
              </MenuItem>
            ))}
          </TextField>
          <Button variant="contained" onClick={applyFilters}>
            Search
          </Button>
          <Button variant="outlined" onClick={resetFilters}>
            Reset
          </Button>
        </Stack>
      </WorkflowSection>

      <Box mt={3}>
        <WorkflowSection title="Inventory" subtitle="Allocated and pre-forecast containers in active yard positions.">
          {items.length === 0 ? (
            <Alert severity="info" variant="outlined">
              No containers match the current filters.
            </Alert>
          ) : (
            <>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Container number</TableCell>
                    <TableCell>Size / Type</TableCell>
                    <TableCell>Depot / Location</TableCell>
                    <TableCell>Gate in</TableCell>
                    <TableCell>Dwell</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>Condition</TableCell>
                    <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {items.map((c) => (
                    <TableRow key={c.id} hover>
                      <TableCell>
                        <Typography
                          component={RouterLink}
                          to={`/container/${encodeURIComponent(c.containerNumber)}/details`}
                          fontWeight={700}
                          sx={{ color: 'primary.main', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}
                        >
                          {c.containerNumber}
                        </Typography>
                      </TableCell>
                      <TableCell>
                        <Chip size="small" label={c.sizeTypeLabel} color={sizeTone(c.sizeTypeLabel)} variant="outlined" />
                      </TableCell>
                      <TableCell>{c.depotName}</TableCell>
                      <TableCell>
                        {c.gateInDate
                          ? new Date(c.gateInDate).toLocaleDateString(undefined, {
                              month: 'short',
                              day: 'numeric',
                              year: 'numeric',
                            })
                          : '—'}
                      </TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.75} alignItems="center">
                          <Chip size="small" label={`${c.currentDwellDays}d`} color={dwellTone(c.currentDwellDays)} />
                          {c.totalPausedDays > 0 && (
                            <Typography variant="caption" color="text.secondary">
                              {c.totalPausedDays}d paused
                            </Typography>
                          )}
                        </Stack>
                      </TableCell>
                      <TableCell>
                        <Chip size="small" label={c.displayStatus} color={statusTone(c.displayStatus)} />
                      </TableCell>
                      <TableCell>
                        <Chip size="small" label={c.condition} color={conditionTone(c.condition)} variant="outlined" />
                      </TableCell>
                      <TableCell align="right">
                        <TableViewLink to={`/container/${encodeURIComponent(c.containerNumber)}/details`} />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {(data?.totalPages ?? 1) > 1 && (
                <Stack alignItems="center" mt={2.5}>
                  <Pagination
                    count={data?.totalPages ?? 1}
                    page={data?.page ?? page}
                    onChange={(_, value) => setPage(value)}
                    color="primary"
                  />
                </Stack>
              )}
            </>
          )}
        </WorkflowSection>
      </Box>
    </WorkflowPage>
  );
}
