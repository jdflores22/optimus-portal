import { useMemo } from 'react';
import {
  Alert,
  Button,
  Chip,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import AddOutlinedIcon from '@mui/icons-material/AddOutlined';
import { Link as RouterLink } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { useGetRepositioningQuery } from '../../app/api';
import type { RootState } from '../../app/store';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

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

export function RepositioningPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const isStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');
  const isShippingAdmin = user?.role === 'ShippingLinesAdmin';
  const { data: list = [], error, isFetching } = useGetRepositioningQuery();

  const pendingCount = useMemo(() => list.filter((r) => r.status === 'Pending').length, [list]);
  const inTransitCount = useMemo(() => list.filter((r) => r.status === 'InTransit').length, [list]);
  const completedCount = useMemo(() => list.filter((r) => r.status === 'Completed').length, [list]);

  return (
    <WorkflowPage
      eyebrow="Outbound movement"
      title={isShippingAdmin ? 'Outbound / Export Requests' : 'Repositioning / Export Requests'}
      subtitle="CY → port outbound moves for export and repositioning, with staff review and terminal completion."
      chips={
        <>
          <Chip size="small" label={user?.role ?? 'User'} color="primary" />
          {isFetching && <Chip size="small" label="Refreshing…" variant="outlined" />}
        </>
      }
      actions={
        isStaff ? (
          <Button component={RouterLink} to="/repositioning/new" variant="contained" startIcon={<AddOutlinedIcon />}>
            New Outbound Request
          </Button>
        ) : undefined
      }
      stats={[
        { label: 'Requests', value: list.length, hint: 'Visible outbound records', tone: 'primary' },
        { label: 'Pending', value: pendingCount, hint: 'Awaiting review', tone: 'warning' },
        { label: 'In transit', value: inTransitCount, hint: 'Released to port', tone: 'info' },
        { label: 'Completed', value: completedCount, hint: 'Arrived at port', tone: 'success' },
      ]}
    >
      {error && (
        <Alert severity="error" sx={{ mb: 2 }}>
          Unable to load repositioning requests.
        </Alert>
      )}

      {pendingCount > 0 && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          {pendingCount} request(s) pending review.
        </Alert>
      )}

      <WorkflowSection title="Request queue" subtitle="Open a request to review route, purpose, containers, and workflow actions.">
        {list.length === 0 ? (
          <Alert severity="info" variant="outlined">
            No outbound requests yet.
          </Alert>
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Request #</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>From CY</TableCell>
                <TableCell>To Port</TableCell>
                <TableCell>Containers</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Requested</TableCell>
                <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {list.map((r) => (
                <TableRow key={r.id} hover>
                  <TableCell>
                    <Typography
                      component={RouterLink}
                      to={`/repositioning/${r.id}`}
                      fontFamily="ui-monospace, monospace"
                      fontWeight={700}
                      sx={{ color: 'primary.main', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}
                    >
                      {r.requestNumber}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Chip size="small" label={r.requestType} variant="outlined" />
                  </TableCell>
                  <TableCell>{r.sourceTerminalName}</TableCell>
                  <TableCell>{r.destinationTerminalName}</TableCell>
                  <TableCell>{r.containerCount}</TableCell>
                  <TableCell>
                    <Chip size="small" label={statusLabel(r.status)} color={statusTone(r.status)} />
                  </TableCell>
                  <TableCell>
                    {new Date(r.requestedAt).toLocaleString(undefined, {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                      hour: 'numeric',
                      minute: '2-digit',
                    })}
                  </TableCell>
                  <TableCell align="right">
                    <TableViewLink to={`/repositioning/${r.id}`} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </WorkflowSection>
    </WorkflowPage>
  );
}
