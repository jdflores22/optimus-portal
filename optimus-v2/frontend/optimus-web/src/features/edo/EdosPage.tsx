import { useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import CreditCardOutlinedIcon from '@mui/icons-material/CreditCardOutlined';
import LockOpenOutlinedIcon from '@mui/icons-material/LockOpenOutlined';
import RefreshOutlinedIcon from '@mui/icons-material/RefreshOutlined';
import { useSelector } from 'react-redux';
import { Link as RouterLink } from 'react-router-dom';
import {
  useCreateEdoRenewalMutation,
  useGetActivePaymentFeeQuery,
  useGetEdosQuery,
  useUnlockEdoMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { openEdoFile } from '../../shared/edoDownload';
import { edoCanDownload, edoNeedsPayment, formatEdoStatus } from '../../shared/formatEdoStatus';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { EdoGenerationPage } from './EdoGenerationPage';

function edoTone(
  status: string,
  paymentStatus?: string | null,
): 'default' | 'success' | 'warning' | 'error' | 'info' {
  if (/released|active/i.test(status)) return 'success';
  if (/pendingvalidation/i.test(status) && /pendingvalidation/i.test(paymentStatus ?? '')) return 'info';
  if (/pending/i.test(status)) return 'warning';
  if (/expired|locked|rejected/i.test(status)) return 'error';
  return 'info';
}

export function EdosPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const isGenerationStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');

  if (isGenerationStaff) {
    return <EdoGenerationPage />;
  }

  return <EdoDocumentsPage />;
}

function EdoDocumentsPage() {
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const { data: edos = [], refetch } = useGetEdosQuery();
  const [createRenewal] = useCreateEdoRenewalMutation();
  const [unlockEdo] = useUnlockEdoMutation();

  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const isBroker = ['Broker', 'Consignee', 'SystemAdmin'].includes(user?.role ?? '');
  const isAdmin = ['SystemAdmin', 'ShippingLinesAdmin'].includes(user?.role ?? '');
  const isTerminal = user?.role === 'TerminalTeam' || user?.role === 'SystemAdmin';

  const openEdoDocument = async (edoId: string, kind: 'download' | 'qr') => {
    if (!accessToken) return;
    try {
      await openEdoFile(edoId, kind, accessToken);
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Could not open eDO file.');
    }
  };

  const { data: edoFee } = useGetActivePaymentFeeQuery('edo');
  const pendingPayment = edos.filter((e) => ['PendingValidation', 'PendingRelease'].includes(e.status));
  const released = edos.filter((e) => e.status === 'Released');
  const expired = edos.filter((e) => ['Expired', 'Locked'].includes(e.status));

  return (
    <WorkflowPage
      eyebrow="Document Workflow"
      title="eDO / CRO"
      subtitle="Manage generated eDOs, payment state, renewals, and release documents from one queue."
      chips={
        <>
          <Chip size="small" color="warning" label={`${pendingPayment.length} pending`} />
          <Chip size="small" color="success" label={`${released.length} released`} />
          <Chip size="small" color="error" label={`${expired.length} expired/locked`} />
        </>
      }
      actions={
        <>
          <Button
            component={RouterLink}
            to="/edo/renewals"
            variant="outlined"
            startIcon={<RefreshOutlinedIcon />}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            Renewals
          </Button>
        </>
      }
      stats={[
        { label: 'Total eDOs', value: edos.length, hint: 'All documents', tone: 'primary' },
        { label: 'Pending Payment', value: pendingPayment.length, hint: 'Awaiting broker action', tone: 'warning' },
        { label: 'Released', value: released.length, hint: 'Ready for release flow', tone: 'success' },
        { label: 'Fee', value: `${edoFee?.amount ?? '-'} PHP`, hint: 'Current eDO fee', tone: 'info' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      <WorkflowSection
        title="Documents Queue"
        subtitle="Status-aware list of eDOs with payment, download, renewal, and unlock actions."
      >
        <Box sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Number</TableCell>
              <TableCell>Manifest</TableCell>
              <TableCell>Container</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Expires</TableCell>
              <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {edos.length === 0 && (
              <TableRow>
                <TableCell colSpan={6}>
                  <Typography variant="body2" color="text.secondary" py={2} textAlign="center">
                    No eDOs generated yet.
                  </Typography>
                </TableCell>
              </TableRow>
            )}
            {edos.map((edo) => (
              <TableRow key={edo.id}>
                <TableCell>{edo.edoNumber}</TableCell>
                <TableCell>
                  <Button component={RouterLink} to={`/manifests/${edo.manifestId}`} size="small">
                    {edo.manifestNumber}
                  </Button>
                </TableCell>
                <TableCell>{edo.containerNumber}</TableCell>
                <TableCell>
                  <Chip
                    size="small"
                    label={formatEdoStatus(edo.status, edo.currentPaymentStatus)}
                    color={edoTone(edo.status, edo.currentPaymentStatus)}
                  />
                </TableCell>
                <TableCell>
                  {edo.expiresAt ? new Date(edo.expiresAt).toLocaleDateString() : '-'}
                </TableCell>
                <TableCell>
                  <Stack direction="row" spacing={0.5} flexWrap="wrap">
                    <TableViewLink to={`/edo/${edo.id}`} />
                    {edoCanDownload(edo.status, user?.role) && (
                      <Button
                        size="small"
                        onClick={() => void openEdoDocument(edo.id, 'download')}
                      >
                        PDF
                      </Button>
                    )}
                    {edo.qrImagePath && edoCanDownload(edo.status, user?.role) && (
                      <Button
                        size="small"
                        onClick={() => void openEdoDocument(edo.id, 'qr')}
                      >
                        QR
                      </Button>
                    )}
                    {isBroker && edoNeedsPayment(edo.status, edo.currentPaymentStatus) && (
                      <Button
                        size="small"
                        component={RouterLink}
                        to={`/manifests/${edo.manifestId}/edo-payment/${edo.id}`}
                        startIcon={<CreditCardOutlinedIcon />}
                        sx={{ textTransform: 'none' }}
                      >
                        Pay
                      </Button>
                    )}
                    {isBroker &&
                      ['Expired', 'Locked', 'Released', 'Active'].includes(edo.status) && (
                        <Button
                          size="small"
                          onClick={async () => {
                            try {
                              const r = await createRenewal({
                                expiredEdoId: edo.id,
                                emptyContainerReturnDate: new Date().toISOString(),
                              }).unwrap();
                              setMessage(
                                `Renewal ${r.status} detention=${r.detentionChargeAmount}`,
                              );
                            } catch (e: unknown) {
                              setError(
                                (e as { data?: { message?: string } })?.data?.message ??
                                  'Renewal failed',
                              );
                            }
                          }}
                        >
                          Renew
                        </Button>
                      )}
                    {isAdmin && ['Expired', 'Locked'].includes(edo.status) && (
                      <Button
                        size="small"
                        startIcon={<LockOpenOutlinedIcon />}
                        onClick={async () => {
                          try {
                            await unlockEdo({
                              id: edo.id,
                              notes: 'Admin unlock',
                            }).unwrap();
                            setMessage(`Unlocked ${edo.edoNumber}`);
                            refetch();
                          } catch (e: unknown) {
                            setError(
                              (e as { data?: { message?: string } })?.data?.message ??
                                'Unlock failed',
                            );
                          }
                        }}
                      >
                        Unlock
                      </Button>
                    )}
                    {isTerminal && edoCanDownload(edo.status, user?.role) && (
                      <Button
                        size="small"
                        onClick={() => void openEdoDocument(edo.id, 'download')}
                      >
                        Package
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
