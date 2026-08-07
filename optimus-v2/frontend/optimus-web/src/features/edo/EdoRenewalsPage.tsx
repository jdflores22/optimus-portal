import { useState } from 'react';
import {
  Alert,
  Button,
  Chip,
  Box,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import { useSelector } from 'react-redux';
import {
  useGenerateRenewedEdoMutation,
  useGetEdoRenewalsQuery,
  useReviewEdoRenewalMutation,
  useVerifyEdoRenewalPaymentMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function EdoRenewalsPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const { data: renewals = [], refetch } = useGetEdoRenewalsQuery();
  const [review] = useReviewEdoRenewalMutation();
  const [verifyPayment] = useVerifyEdoRenewalPaymentMutation();
  const [generate] = useGenerateRenewedEdoMutation();
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const isStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');
  const isAccounting = ['Accounting', 'SystemAdmin'].includes(user?.role ?? '');
  const pending = renewals.filter((r) => r.status === 'PendingReview');
  const awaitingPayment = renewals.filter((r) => r.status === 'AwaitingPayment');
  const ready = renewals.filter((r) => r.status === 'ReadyForGeneration');
  const completed = renewals.filter((r) => r.status === 'Completed');

  return (
    <WorkflowPage
      eyebrow="Renewal Queue"
      title="eDO Renewals"
      subtitle="Review expired eDO renewals, verify detention payment, and generate renewed documents."
      chips={
        <>
          <Chip size="small" color="warning" label={`${pending.length} pending`} />
          <Chip size="small" color="info" label={`${awaitingPayment.length} awaiting payment`} />
          <Chip size="small" color="success" label={`${completed.length} completed`} />
        </>
      }
      stats={[
        { label: 'All Requests', value: renewals.length, hint: 'Renewal queue', tone: 'primary' },
        { label: 'Pending Review', value: pending.length, hint: 'Staff decision required', tone: 'warning' },
        { label: 'Ready For Generation', value: ready.length, hint: 'Can be generated', tone: 'success' },
        { label: 'Completed', value: completed.length, hint: 'Renewed eDO issued', tone: 'info' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      <WorkflowSection
        title="Renewal Requests"
        subtitle="Status-aware list of expired eDO renewal requests."
      >
        <Box sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Expired eDO</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Overdue</TableCell>
              <TableCell>Detention</TableCell>
              <TableCell>Paid</TableCell>
              <TableCell>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {renewals.length === 0 && (
              <TableRow>
                <TableCell colSpan={6}>
                  <Typography variant="body2" color="text.secondary" py={2} textAlign="center">
                    No renewal requests yet.
                  </Typography>
                </TableCell>
              </TableRow>
            )}
            {renewals.map((r) => (
              <TableRow key={r.id}>
                <TableCell>{r.expiredEdoNumber}</TableCell>
                <TableCell>
                  <Chip size="small" label={r.status} color={r.status === 'Completed' ? 'success' : r.status === 'ReadyForGeneration' ? 'info' : r.status === 'AwaitingPayment' ? 'warning' : 'default'} />
                </TableCell>
                <TableCell>{r.overdueDays}d</TableCell>
                <TableCell>{r.detentionChargeAmount} PHP</TableCell>
                <TableCell>{r.paymentVerified ? 'Yes' : 'No'}</TableCell>
                <TableCell>
                  <Stack direction="row" spacing={1}>
                    {isStaff && r.status === 'PendingReview' && (
                      <Button
                        size="small"
                        onClick={async () => {
                          try {
                            await review({ id: r.id, approve: true }).unwrap();
                            setMessage('Renewal approved');
                            refetch();
                          } catch (e: unknown) {
                            setError(
                              (e as { data?: { message?: string } })?.data?.message ??
                                'Review failed',
                            );
                          }
                        }}
                      >
                        Approve
                      </Button>
                    )}
                    {isAccounting && r.status === 'AwaitingPayment' && (
                      <Button
                        size="small"
                        onClick={async () => {
                          try {
                            await verifyPayment(r.id).unwrap();
                            setMessage('Detention payment verified');
                            refetch();
                          } catch (e: unknown) {
                            setError(
                              (e as { data?: { message?: string } })?.data?.message ??
                                'Verify failed',
                            );
                          }
                        }}
                      >
                        Verify pay
                      </Button>
                    )}
                    {isStaff && r.status === 'ReadyForGeneration' && (
                      <Button
                        size="small"
                        variant="contained"
                        onClick={async () => {
                          try {
                            const edo = await generate(r.id).unwrap();
                            setMessage(`Renewed as ${edo.edoNumber}`);
                            refetch();
                          } catch (e: unknown) {
                            setError(
                              (e as { data?: { message?: string } })?.data?.message ??
                                'Generate failed',
                            );
                          }
                        }}
                      >
                        Generate
                      </Button>
                    )}
                  </Stack>
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
