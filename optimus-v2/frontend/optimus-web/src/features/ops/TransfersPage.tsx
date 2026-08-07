import { useState } from 'react';
import {
  Alert,
  Box,
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
  useCreateTransferMutation,
  useGetHierarchyUsersQuery,
  useGetManifestsQuery,
  useGetTransfersQuery,
  useReviewTransferMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function TransfersPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const isConsignee = ['Consignee', 'SystemAdmin'].includes(user?.role ?? '');
  const isStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');
  const { data: transfers = [], refetch } = useGetTransfersQuery();
  const { data: manifests = [] } = useGetManifestsQuery();
  const { data: users = [] } = useGetHierarchyUsersQuery();
  const brokers = users.filter((u) => u.role === 'Broker');
  const [createTransfer] = useCreateTransferMutation();
  const [review] = useReviewTransferMutation();
  const [manifestId, setManifestId] = useState('');
  const [newBrokerId, setNewBrokerId] = useState('');
  const [reason, setReason] = useState('Broker suspended / transfer needed');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const pending = transfers.filter((t) => t.status === 'Pending');
  const approved = transfers.filter((t) => t.status === 'Approved');
  const rejected = transfers.filter((t) => t.status === 'Rejected');

  return (
    <WorkflowPage
      eyebrow="Transfer Workflow"
      title="Broker Transfers"
      subtitle="Request broker reassignment and review transfer approvals across affected manifests."
      chips={
        <>
          <Chip size="small" color="warning" label={`${pending.length} pending`} />
          <Chip size="small" color="success" label={`${approved.length} approved`} />
          <Chip size="small" color="error" label={`${rejected.length} rejected`} />
        </>
      }
      stats={[
        { label: 'All Requests', value: transfers.length, hint: 'Transfer records', tone: 'primary' },
        { label: 'Pending', value: pending.length, hint: 'Needs review', tone: 'warning' },
        { label: 'Approved', value: approved.length, hint: 'Broker changed', tone: 'success' },
        { label: 'Rejected', value: rejected.length, hint: 'Needs follow-up', tone: 'error' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      {isConsignee && (
        <WorkflowSection
          title="Request Transfer"
          subtitle="Move a manifest to another broker when the current assignment is blocked or needs replacement."
        >
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <TextField
              select
              label="Manifest"
              value={manifestId}
              onChange={(e) => setManifestId(e.target.value)}
              sx={{ minWidth: 200 }}
            >
              {manifests.map((m) => (
                <MenuItem key={m.id} value={m.id}>
                  {m.manifestNumber}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="New broker"
              value={newBrokerId}
              onChange={(e) => setNewBrokerId(e.target.value)}
              sx={{ minWidth: 180 }}
            >
              {brokers.map((b) => (
                <MenuItem key={b.id} value={b.id}>
                  {b.fullName}
                </MenuItem>
              ))}
            </TextField>
            <TextField label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} fullWidth />
            <Button
              variant="contained"
              disabled={!manifestId || !newBrokerId}
              onClick={async () => {
                try {
                  await createTransfer({ manifestId, newBrokerId, reason }).unwrap();
                  setMessage('Transfer requested');
                  refetch();
                } catch (e: unknown) {
                  setError((e as { data?: { message?: string } })?.data?.message ?? 'Failed');
                }
              }}
            >
              Request
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      <WorkflowSection
        title="Transfer Queue"
        subtitle="Review open broker transfer requests and their outcomes."
      >
        <Box sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Manifest</TableCell>
              <TableCell>From → To</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {transfers.length === 0 && (
              <TableRow>
                <TableCell colSpan={4}>
                  <Typography variant="body2" color="text.secondary" py={2} textAlign="center">
                    No transfer requests yet.
                  </Typography>
                </TableCell>
              </TableRow>
            )}
            {transfers.map((t) => (
              <TableRow key={t.id}>
                <TableCell>{t.manifestNumber}</TableCell>
                <TableCell>
                  {t.oldBrokerName} → {t.newBrokerName}
                </TableCell>
                <TableCell>
                  <Chip size="small" label={t.status} color={t.status === 'Approved' ? 'success' : t.status === 'Rejected' ? 'error' : 'warning'} />
                </TableCell>
                <TableCell>
                  {isStaff && t.status === 'Pending' && (
                    <>
                      <Button
                        size="small"
                        onClick={async () => {
                          await review({ id: t.id, approve: true }).unwrap();
                          refetch();
                        }}
                      >
                        Approve
                      </Button>
                      <Button
                        size="small"
                        color="error"
                        onClick={async () => {
                          await review({ id: t.id, approve: false, notes: 'Rejected' }).unwrap();
                          refetch();
                        }}
                      >
                        Reject
                      </Button>
                    </>
                  )}
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
