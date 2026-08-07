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
  Typography,
} from '@mui/material';
import { useSelector } from 'react-redux';
import {
  useCancelPreAdviceMutation,
  useCompletePreAdviceMutation,
  useGenerateTruckerTokenMutation,
  useGetPreAdvicesQuery,
  useGetTerminalSlotsQuery,
  useGetTerminalsQuery,
  useRevokeTruckerTokenMutation,
  useSearchReturnContainersQuery,
  useSubmitPreAdviceMutation,
  useVerifyPreAdviceMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { API_BASE_URL } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function PreAdvicePage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const isTrucker = user?.role === 'Trucker' || user?.role === 'SystemAdmin';
  const isTerminal = ['TerminalTeam', 'SystemAdmin', 'ShippingLinesAdmin'].includes(user?.role ?? '');

  const { data: list = [], refetch } = useGetPreAdvicesQuery(
    isTerminal && !isTrucker ? { status: 'Pending' } : undefined,
  );
  const { data: terminals = [] } = useGetTerminalsQuery();
  const [search, setSearch] = useState('');
  const { data: matches = [] } = useSearchReturnContainersQuery(search, { skip: search.length < 3 });
  const [terminalId, setTerminalId] = useState('');
  const { data: slots = [] } = useGetTerminalSlotsQuery(terminalId, { skip: !terminalId });
  const [containerId, setContainerId] = useState('');
  const [slotId, setSlotId] = useState('');
  const [lat, setLat] = useState('14.5995');
  const [lng, setLng] = useState('120.9842');
  const [photo, setPhoto] = useState<File | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [submit] = useSubmitPreAdviceMutation();
  const [verify] = useVerifyPreAdviceMutation();
  const [complete] = useCompletePreAdviceMutation();
  const [cancel] = useCancelPreAdviceMutation();
  const [genToken] = useGenerateTruckerTokenMutation();
  const [revokeToken] = useRevokeTruckerTokenMutation();
  const pendingCount = list.filter((p) => p.status === 'Pending').length;
  const verifiedCount = list.filter((p) => p.status === 'Verified').length;
  const completedCount = list.filter((p) => p.status === 'Completed').length;

  return (
    <WorkflowPage
      eyebrow="Terminal intake"
      title="Pre-advice operations"
      subtitle="Coordinate trucker submissions, terminal verification, and package generation from one queue."
      chips={
        <>
          <Chip size="small" label={user?.role ?? 'User'} color="primary" />
          <Chip size="small" label={isTerminal ? 'Terminal review enabled' : 'Submission mode'} variant="outlined" />
        </>
      }
      stats={[
        { label: 'Requests', value: list.length, hint: 'Visible pre-advice records', tone: 'primary' },
        { label: 'Pending', value: pendingCount, hint: 'Awaiting terminal action', tone: pendingCount ? 'warning' : 'success' },
        { label: 'Verified', value: verifiedCount, hint: 'Ready for package generation', tone: 'info' },
        { label: 'Completed', value: completedCount, hint: 'Package already released', tone: 'success' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      {isTrucker && (
        <WorkflowSection title="Trucker submission tools" subtitle="Generate the token and capture the terminal booking details before dispatch.">
          <Stack direction="row" spacing={2} mb={2}>
            <Button
              variant="outlined"
              onClick={async () => {
                const r = await genToken().unwrap();
                setToken(r.apiToken);
                setMessage(`Token expires ${new Date(r.expiresAt).toLocaleString()}`);
              }}
            >
              Generate token
            </Button>
            <Button
              color="error"
              onClick={async () => {
                await revokeToken().unwrap();
                setToken(null);
                setMessage('Token revoked');
              }}
            >
              Revoke
            </Button>
          </Stack>
          {token && (
            <Typography variant="body2" sx={{ wordBreak: 'break-all' }}>
              {token}
            </Typography>
          )}

          <Typography variant="h6" mt={3} mb={2}>
            Submit pre-advice
          </Typography>
          <Stack spacing={2}>
            <TextField
              label="Search container"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              helperText="Min 3 chars, AvailableForReturn only"
            />
            <TextField
              select
              label="Container"
              value={containerId}
              onChange={(e) => setContainerId(e.target.value)}
            >
              {matches.map((c) => (
                <MenuItem key={c.id} value={c.id}>
                  {c.containerNumber}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="Terminal"
              value={terminalId}
              onChange={(e) => setTerminalId(e.target.value)}
            >
              {terminals.map((t) => (
                <MenuItem key={t.id} value={t.id}>
                  {t.name}
                </MenuItem>
              ))}
            </TextField>
            <TextField select label="Slot" value={slotId} onChange={(e) => setSlotId(e.target.value)}>
              {slots.map((s) => (
                <MenuItem key={s.id} value={s.id}>
                  {s.date} ({s.assignedCount}/{s.capacity})
                </MenuItem>
              ))}
            </TextField>
            <Stack direction="row" spacing={2}>
              <TextField label="Latitude" value={lat} onChange={(e) => setLat(e.target.value)} />
              <TextField label="Longitude" value={lng} onChange={(e) => setLng(e.target.value)} />
            </Stack>
            <Button variant="outlined" component="label">
              Geotag photo
              <input
                hidden
                type="file"
                accept="image/*"
                onChange={(e) => setPhoto(e.target.files?.[0] ?? null)}
              />
            </Button>
            <Button
              variant="contained"
              disabled={!containerId || !terminalId || !photo}
              onClick={async () => {
                try {
                  await submit({
                    containerId,
                    terminalId,
                    slotId: slotId || undefined,
                    latitude: Number(lat),
                    longitude: Number(lng),
                    photo: photo!,
                  }).unwrap();
                  setMessage('Pre-advice submitted');
                  refetch();
                } catch (e: unknown) {
                  setError((e as { data?: { message?: string } })?.data?.message ?? 'Submit failed');
                }
              }}
            >
              Submit
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      <WorkflowSection title="Terminal queue" subtitle="Approve, reject, complete, or cancel requests based on the current workflow stage.">
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Container</TableCell>
              <TableCell>Terminal</TableCell>
              <TableCell>Trucker</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Package</TableCell>
              <TableCell>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {list.map((p) => (
              <TableRow key={p.id}>
                <TableCell>{p.containerNumber}</TableCell>
                <TableCell>{p.terminalName}</TableCell>
                <TableCell>{p.truckerName}</TableCell>
                <TableCell>{p.status}</TableCell>
                <TableCell>
                  {p.packagePdfPath && (
                    <Button size="small" href={`${API_BASE_URL}${p.packagePdfPath}`} target="_blank">
                      PDF
                    </Button>
                  )}
                  {p.qrCodePath && (
                    <Button size="small" href={`${API_BASE_URL}${p.qrCodePath}`} target="_blank">
                      QR
                    </Button>
                  )}
                </TableCell>
                <TableCell>
                  {isTerminal && p.status === 'Pending' && (
                    <>
                      <Button
                        size="small"
                        onClick={async () => {
                          await verify({ id: p.id, approve: true }).unwrap();
                          refetch();
                        }}
                      >
                        Approve
                      </Button>
                      <Button
                        size="small"
                        color="error"
                        onClick={async () => {
                          await verify({
                            id: p.id,
                            approve: false,
                            rejectionReason: 'Rejected by terminal',
                          }).unwrap();
                          refetch();
                        }}
                      >
                        Reject
                      </Button>
                    </>
                  )}
                  {isTerminal && p.status === 'Verified' && (
                    <Button
                      size="small"
                      variant="contained"
                      onClick={async () => {
                        await complete({ id: p.id }).unwrap();
                        setMessage('Package generated');
                        refetch();
                      }}
                    >
                      Complete / Print
                    </Button>
                  )}
                  {['Pending', 'Verified', 'Rejected'].includes(p.status) && (
                    <Button
                      size="small"
                      onClick={async () => {
                        await cancel(p.id).unwrap();
                        refetch();
                      }}
                    >
                      Cancel
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>
    </WorkflowPage>
  );
}
