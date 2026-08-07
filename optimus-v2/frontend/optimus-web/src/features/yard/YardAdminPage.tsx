import { useState } from 'react';
import {
  Alert,
  Button,
  Chip,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
} from '@mui/material';
import { useSelector } from 'react-redux';
import {
  useAllocateContainerMutation,
  useCreateContainerMutation,
  useGetContainerCatalogQuery,
  useGetContainersQuery,
  useGetCyAllocationsQuery,
  useGetTerminalsQuery,
  useMarkAvailableForReturnMutation,
  useUpsertTerminalMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function YardAdminPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const isAdmin = ['SystemAdmin', 'ShippingLinesAdmin'].includes(user?.role ?? '');
  const isStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');

  const { data: terminals = [], refetch: refetchTerminals } = useGetTerminalsQuery();
  const { data: containers = [], refetch } = useGetContainersQuery();
  const { data: allocations = [] } = useGetCyAllocationsQuery();
  const { data: catalog } = useGetContainerCatalogQuery();
  const { shippingLineId } = useDefaultShippingLine();
  const [upsertTerminal] = useUpsertTerminalMutation();
  const [createContainer] = useCreateContainerMutation();
  const [allocate] = useAllocateContainerMutation();
  const [markAvailable] = useMarkAvailableForReturnMutation();

  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [terminalForm, setTerminalForm] = useState({
    name: '',
    code: '',
    identity: 'ContainerYard',
    kind: 'Cy',
    dailyCapacity: 100,
  });
  const [containerForm, setContainerForm] = useState({
    containerNumber: '',
    containerTypeId: '',
    containerSizeId: '',
    cyAllocationId: '',
  });

  const typeOptions = catalog?.types ?? [];
  const sizeOptions = catalog?.sizes ?? [];

  return (
    <WorkflowPage
      eyebrow="Terminal operations"
      title="Yard administration"
      subtitle="Manage terminals, allocate containers, and keep your return inventory ready for terminal operations."
      chips={
        <>
          <Chip size="small" label={user?.role ?? 'User'} color="primary" />
          {shippingLineId && <Chip size="small" label="Shipping line active" variant="outlined" />}
        </>
      }
      stats={[
        { label: 'Terminals', value: terminals.length, hint: 'Active yard locations', tone: 'primary' },
        { label: 'Containers', value: containers.length, hint: 'Tracked inventory', tone: 'info' },
        { label: 'Allocations', value: allocations.length, hint: 'CY capacity allocations', tone: 'success' },
        { label: 'Unallocated', value: containers.filter((c) => !c.cyAllocationId).length, hint: 'Needs yard placement', tone: 'warning' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      {isAdmin && (
        <WorkflowSection title="Terminal setup" subtitle="Shipping admin users can register and maintain operating terminals.">
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <TextField
              label="Name"
              value={terminalForm.name}
              onChange={(e) => setTerminalForm({ ...terminalForm, name: e.target.value })}
            />
            <TextField
              label="Code"
              value={terminalForm.code}
              onChange={(e) => setTerminalForm({ ...terminalForm, code: e.target.value })}
            />
            <Button
              variant="contained"
              onClick={async () => {
                try {
                  await upsertTerminal(terminalForm).unwrap();
                  setMessage('Terminal saved');
                  refetchTerminals();
                } catch (e: unknown) {
                  setError((e as { data?: { message?: string } })?.data?.message ?? 'Save failed');
                }
              }}
            >
              Save
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      <WorkflowSection title="Terminal register" subtitle="Use this list to confirm location coverage and daily capacity.">
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Code</TableCell>
              <TableCell>Name</TableCell>
              <TableCell>Kind</TableCell>
              <TableCell>Capacity</TableCell>
              <TableCell>Active</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {terminals.map((t) => (
              <TableRow key={t.id}>
                <TableCell>{t.code}</TableCell>
                <TableCell>{t.name}</TableCell>
                <TableCell>{t.kind}</TableCell>
                <TableCell>{t.dailyCapacity}</TableCell>
                <TableCell>{t.isActive ? 'Yes' : 'No'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>

      {isStaff && (
        <WorkflowSection title="Container intake" subtitle="Create inventory records and place them directly into a CY allocation.">
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} mb={2}>
            <TextField
              label="Container #"
              value={containerForm.containerNumber}
              onChange={(e) => setContainerForm({ ...containerForm, containerNumber: e.target.value })}
            />
            <TextField
              select
              label="Type"
              value={containerForm.containerTypeId}
              onChange={(e) => setContainerForm({ ...containerForm, containerTypeId: e.target.value })}
              sx={{ minWidth: 120 }}
            >
              {typeOptions.map((t) => (
                <MenuItem key={t.id} value={t.id}>
                  {t.code}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="Size"
              value={containerForm.containerSizeId}
              onChange={(e) => setContainerForm({ ...containerForm, containerSizeId: e.target.value })}
              sx={{ minWidth: 120 }}
            >
              {sizeOptions.map((s) => (
                <MenuItem key={s.id} value={s.id}>
                  {s.code}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="CY allocation"
              value={containerForm.cyAllocationId}
              onChange={(e) => setContainerForm({ ...containerForm, cyAllocationId: e.target.value })}
              sx={{ minWidth: 180 }}
            >
              {allocations.map((a) => (
                <MenuItem key={a.id} value={a.id}>
                  {a.terminalName} ({a.usedTeu}/{a.allocatedCapacityTeu})
                </MenuItem>
              ))}
            </TextField>
            <Button
              variant="contained"
              disabled={!shippingLineId}
              onClick={async () => {
                try {
                  const c = await createContainer({
                    containerNumber: containerForm.containerNumber,
                    shippingLineId,
                    containerTypeId: containerForm.containerTypeId || undefined,
                    containerSizeId: containerForm.containerSizeId || undefined,
                  }).unwrap();
                  if (containerForm.cyAllocationId) {
                    await allocate({
                      id: c.id,
                      cyAllocationId: containerForm.cyAllocationId,
                    }).unwrap();
                  }
                  await markAvailable(c.id).unwrap();
                  setMessage(`Container ${c.containerNumber} ready`);
                  refetch();
                } catch (e: unknown) {
                  setError((e as { data?: { message?: string } })?.data?.message ?? 'Create failed');
                }
              }}
            >
              Create
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      <WorkflowSection title="Inventory list" subtitle="Spot return-ready units, dwell pressure, and containers missing stack details.">
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Number</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Allocation</TableCell>
              <TableCell>CY</TableCell>
              <TableCell>Stack</TableCell>
              <TableCell>Dwell</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {containers.map((c) => (
              <TableRow key={c.id}>
                <TableCell>{c.containerNumber}</TableCell>
                <TableCell>{c.status}</TableCell>
                <TableCell>{c.allocationStatus}</TableCell>
                <TableCell>{c.cyTerminalName ?? '-'}</TableCell>
                <TableCell>
                  {[c.stackBay, c.stackRow, c.stackTier].filter(Boolean).join('/') || '-'}
                </TableCell>
                <TableCell>{c.currentDwellDays}d</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>
    </WorkflowPage>
  );
}
