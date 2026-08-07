import { useState } from 'react';
import {
  Alert,
  Button,
  Chip,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import {
  useGetDwellConfigQuery,
  useGetDwellMonitorQuery,
  usePauseDwellMutation,
  useProcessDwellMutation,
  useRecordArrivalMutation,
  useResumeDwellMutation,
  useUpsertDwellConfigMutation,
} from '../../app/api';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function DwellPage() {
  const { data: config, refetch: refetchConfig } = useGetDwellConfigQuery();
  const { data: monitor = [], refetch } = useGetDwellMonitorQuery();
  const [upsertConfig] = useUpsertDwellConfigMutation();
  const [recordArrival] = useRecordArrivalMutation();
  const [pause] = usePauseDwellMutation();
  const [resume] = useResumeDwellMutation();
  const [process] = useProcessDwellMutation();
  const [notifyDays, setNotifyDays] = useState(60);
  const [returnDays, setReturnDays] = useState(90);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const pausedCount = monitor.filter((c) => Boolean(c.dwellPausedAt)).length;
  const overdueCount = monitor.filter(
    (c) => c.currentDwellDays >= (config?.automaticReturnThresholdDays ?? returnDays),
  ).length;

  return (
    <WorkflowPage
      eyebrow="Terminal watch"
      title="Dwell monitoring"
      subtitle="Track arrival aging, hold periods, and automated return thresholds across the yard."
      chips={
        <>
          <Chip size="small" label={`${config?.notificationThresholdDays ?? notifyDays}d notify`} color="warning" />
          <Chip size="small" label={`${config?.automaticReturnThresholdDays ?? returnDays}d auto-return`} variant="outlined" />
        </>
      }
      actions={
        <Button
          variant="contained"
          onClick={async () => {
            try {
              const r = await process().unwrap();
              setMessage(`Processed actions=${r.actions}`);
              refetch();
            } catch (e: unknown) {
              setError((e as { data?: { message?: string } })?.data?.message ?? 'Process failed');
            }
          }}
        >
          Run job now
        </Button>
      }
      stats={[
        { label: 'Containers watched', value: monitor.length, hint: 'Current dwell monitor scope', tone: 'primary' },
        { label: 'Paused', value: pausedCount, hint: 'Manually paused dwell timers', tone: 'info' },
        { label: 'Over threshold', value: overdueCount, hint: 'At or past auto-return limit', tone: overdueCount ? 'error' : 'success' },
        { label: 'Notifications', value: config?.enableNotifications ? 'On' : 'Off', hint: 'Current config state', tone: config?.enableNotifications ? 'success' : 'warning' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      <WorkflowSection title="Dwell configuration" subtitle="Set the timing rules that drive alerts and automatic returns.">
        <Typography variant="body2" mb={2}>
          Notify @ {config?.notificationThresholdDays ?? '-'}d | Auto-return @{' '}
          {config?.automaticReturnThresholdDays ?? '-'}d | Auto={' '}
          {config?.enableAutomaticReturns ? 'on' : 'off'}
        </Typography>
        <Stack direction="row" spacing={2}>
          <TextField
            type="number"
            label="Notify days"
            value={notifyDays}
            onChange={(e) => setNotifyDays(Number(e.target.value))}
          />
          <TextField
            type="number"
            label="Return days"
            value={returnDays}
            onChange={(e) => setReturnDays(Number(e.target.value))}
          />
          <Button
            variant="outlined"
            onClick={async () => {
              try {
                await upsertConfig({
                  notificationThresholdDays: notifyDays,
                  automaticReturnThresholdDays: returnDays,
                  timezone: 'Asia/Manila',
                  enableAutomaticReturns: true,
                  enableNotifications: true,
                }).unwrap();
                setMessage('Dwell config saved');
                refetchConfig();
              } catch (e: unknown) {
                setError((e as { data?: { message?: string } })?.data?.message ?? 'Config failed');
              }
            }}
          >
            Save config
          </Button>
        </Stack>
      </WorkflowSection>

      <WorkflowSection title="Dwell queue" subtitle="Operators can record arrivals and pause or resume monitoring from one list.">
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Container</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Dwell</TableCell>
              <TableCell>Arrival</TableCell>
              <TableCell>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {monitor.map((c) => (
              <TableRow key={c.id}>
                <TableCell>{c.containerNumber}</TableCell>
                <TableCell>{c.status}</TableCell>
                <TableCell>{c.currentDwellDays}d</TableCell>
                <TableCell>
                  {c.terminalArrivalDate
                    ? new Date(c.terminalArrivalDate).toLocaleDateString()
                    : '-'}
                </TableCell>
                <TableCell>
                  {!c.terminalArrivalDate && (
                    <Button
                      size="small"
                      onClick={async () => {
                        await recordArrival(c.id).unwrap();
                        refetch();
                      }}
                    >
                      Arrival
                    </Button>
                  )}
                  {c.dwellPausedAt ? (
                    <Button
                      size="small"
                      onClick={async () => {
                        await resume({ id: c.id, reason: 'UI resume' }).unwrap();
                        refetch();
                      }}
                    >
                      Resume
                    </Button>
                  ) : (
                    <Button
                      size="small"
                      onClick={async () => {
                        await pause({ id: c.id, reason: 'UI pause' }).unwrap();
                        refetch();
                      }}
                    >
                      Pause
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
