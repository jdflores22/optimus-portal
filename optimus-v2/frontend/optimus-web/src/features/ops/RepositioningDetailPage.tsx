import { useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Divider,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import {
  useCancelRepositioningMutation,
  useCompleteRepositioningMutation,
  useGetRepositioningByIdQuery,
  useReviewRepositioningMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function statusTone(status: string): 'warning' | 'info' | 'success' | 'error' | 'default' {
  if (status === 'Pending') return 'warning';
  if (status === 'InTransit' || status === 'Approved') return 'info';
  if (status === 'Completed') return 'success';
  if (status === 'Rejected' || status === 'Cancelled') return 'error';
  return 'default';
}

function statusLabel(status: string): string {
  if (status === 'InTransit') return 'In Transit to Port';
  if (status === 'Pending') return 'Pending Review';
  return status;
}

function dwellTone(days: number): 'default' | 'warning' | 'error' {
  if (days >= 90) return 'error';
  if (days >= 60) return 'warning';
  return 'default';
}

function MetaRow({ label, value }: { label: string; value: string }) {
  return (
    <Stack direction="row" justifyContent="space-between" spacing={2}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600} textAlign="right">
        {value}
      </Typography>
    </Stack>
  );
}

export function RepositioningDetailPage() {
  const { id = '' } = useParams();
  const { user } = useSelector((state: RootState) => state.auth);
  const canReview = ['SlStaff', 'SystemAdmin'].includes(user?.role ?? '');
  const canComplete = ['SlStaff', 'TerminalTeam', 'SystemAdmin'].includes(user?.role ?? '');
  const canCancel = ['ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');

  const { data, error, isLoading, refetch } = useGetRepositioningByIdQuery(id, { skip: !id });
  const [review] = useReviewRepositioningMutation();
  const [complete] = useCompleteRepositioningMutation();
  const [cancel] = useCancelRepositioningMutation();

  const [rejectNotes, setRejectNotes] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (error) {
    return (
      <Alert severity="error">
        Request not found or you do not have access to view this request.
      </Alert>
    );
  }
  if (isLoading || !data) return <Typography>Loading...</Typography>;

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    try {
      setBusy(true);
      setActionError(null);
      await fn();
      setMessage(ok);
      refetch();
    } catch (e: unknown) {
      setActionError((e as { data?: { message?: string } })?.data?.message ?? 'Action failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <WorkflowPage
      eyebrow="Outbound request"
      title={data.requestNumber}
      subtitle={`${data.requestType} · ${data.containerCount} container(s)`}
      chips={<Chip size="small" label={statusLabel(data.status)} color={statusTone(data.status)} />}
      actions={
        <Button component={RouterLink} to="/repositioning" variant="outlined">
          All requests
        </Button>
      }
    >
      {message && <Alert severity="success" sx={{ mb: 2 }}>{message}</Alert>}
      {actionError && <Alert severity="error" sx={{ mb: 2 }}>{actionError}</Alert>}

      <Box
        sx={{
          display: 'grid',
          gap: 3,
          gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' },
        }}
      >
        <WorkflowSection title="Route">
          <Stack spacing={1.5} divider={<Divider flexItem />}>
            <MetaRow label="From CY" value={data.sourceTerminalName} />
            <MetaRow
              label="To Port"
              value={`${data.destinationTerminalName}${data.destinationTerminalCode ? ` (${data.destinationTerminalCode})` : ''}`}
            />
            <MetaRow
              label="Requested"
              value={new Date(data.requestedAt).toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
              })}
            />
            <MetaRow label="By" value={data.requestedByEmail ?? '—'} />
            {data.reviewedAt && (
              <MetaRow
                label="Reviewed"
                value={new Date(data.reviewedAt).toLocaleString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
                })}
              />
            )}
            {data.completedAt && (
              <MetaRow
                label="Completed"
                value={new Date(data.completedAt).toLocaleString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
                })}
              />
            )}
          </Stack>
        </WorkflowSection>

        <WorkflowSection title="Purpose">
          <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
            {data.purpose}
          </Typography>
          {data.requestLetterPath && (
            <Button
              href={`${import.meta.env.VITE_API_BASE_URL}${data.requestLetterPath}`}
              target="_blank"
              variant="outlined"
              size="small"
              sx={{ mt: 2 }}
            >
              View Request Letter
            </Button>
          )}
          {data.reviewNotes && (
            <Alert severity="error" variant="outlined" sx={{ mt: 2 }}>
              <strong>Review notes:</strong> {data.reviewNotes}
            </Alert>
          )}
        </WorkflowSection>
      </Box>

      <Box mt={3}>
        <WorkflowSection
          title="Containers (by dwell time at request)"
          subtitle="CAO 8-2019 — dwell counted from vessel discharge until reload for export/repositioning."
        >
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>#</TableCell>
                <TableCell>Container</TableCell>
                <TableCell>Dwell at request</TableCell>
                <TableCell>Discharge date</TableCell>
                <TableCell>Current status</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(data.items ?? []).map((item, index) => (
                <TableRow key={item.containerId} hover>
                  <TableCell>{index + 1}</TableCell>
                  <TableCell>
                    <Typography
                      component={RouterLink}
                      to={`/container/${encodeURIComponent(item.containerNumber)}/details`}
                      fontFamily="ui-monospace, monospace"
                      fontWeight={700}
                      sx={{ color: 'primary.main', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}
                    >
                      {item.containerNumber}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Chip size="small" label={`${item.dwellTimeDays} days`} color={dwellTone(item.dwellTimeDays)} />
                  </TableCell>
                  <TableCell>
                    {item.dischargeDate
                      ? new Date(item.dischargeDate).toLocaleDateString(undefined, {
                          month: 'short',
                          day: 'numeric',
                          year: 'numeric',
                        })
                      : '—'}
                  </TableCell>
                  <TableCell>{item.currentStatus}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </WorkflowSection>
      </Box>

      {(canReview || canComplete || canCancel) && (
        <Box mt={3}>
          <WorkflowSection title="Actions" subtitle="Review pending moves, complete in-transit arrivals, or cancel drafts.">
            <Stack spacing={2}>
              {canReview && data.status === 'Pending' && (
                <>
                  <Button
                    variant="contained"
                    color="success"
                    disabled={busy}
                    onClick={() => {
                      if (!window.confirm('Approve and release containers to port?')) return;
                      run(() => review({ id: data.id, approve: true }).unwrap(), 'Approved — released to port');
                    }}
                  >
                    Approve — Release to Port
                  </Button>
                  <TextField
                    label="Rejection notes"
                    placeholder="Rejection reason…"
                    value={rejectNotes}
                    onChange={(e) => setRejectNotes(e.target.value)}
                    multiline
                    minRows={2}
                    fullWidth
                    required
                  />
                  <Button
                    color="error"
                    variant="outlined"
                    disabled={busy || !rejectNotes.trim()}
                    onClick={() =>
                      run(
                        () => review({ id: data.id, approve: false, notes: rejectNotes.trim() }).unwrap(),
                        'Request rejected',
                      )
                    }
                  >
                    Reject
                  </Button>
                </>
              )}

              {canComplete && data.status === 'InTransit' && (
                <Button
                  variant="contained"
                  disabled={busy}
                  onClick={() => {
                    if (!window.confirm('Mark containers as arrived at destination port?')) return;
                    run(() => complete(data.id).unwrap(), 'Marked arrived at port');
                  }}
                >
                  Mark Arrived at Port
                </Button>
              )}

              {canCancel && data.status === 'Pending' && (
                <Button
                  color="error"
                  variant="text"
                  disabled={busy}
                  onClick={() => {
                    if (!window.confirm('Cancel this request?')) return;
                    run(() => cancel(data.id).unwrap(), 'Request cancelled');
                  }}
                >
                  Cancel Request
                </Button>
              )}
            </Stack>
          </WorkflowSection>
        </Box>
      )}
    </WorkflowPage>
  );
}
