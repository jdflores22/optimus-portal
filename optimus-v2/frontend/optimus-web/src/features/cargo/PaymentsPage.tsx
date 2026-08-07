import { useState } from 'react';
import {
  Alert,
  Button,
  Chip,
  MenuItem,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import { useSelector } from 'react-redux';
import {
  useGetManifestsQuery,
  useGetActivePaymentFeeQuery,
  useSubmitPaymentMutation,
  useUpsertPaymentFeeMutation,
  useGetExchangeRateQuery,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { AccountingFinalPaymentsPage } from './AccountingFinalPaymentsPage';

export function PaymentsPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const isBroker = ['Broker', 'Consignee', 'SystemAdmin'].includes(user?.role ?? '');
  const isAccounting = user?.role === 'Accounting';
  const isSystemAdmin = user?.role === 'SystemAdmin';

  const { data: manifests = [], refetch: refetchManifests } = useGetManifestsQuery(undefined, {
    skip: isAccounting,
  });
  const { data: accessFee } = useGetActivePaymentFeeQuery('manifest_access', { skip: isAccounting });
  const { data: fx } = useGetExchangeRateQuery(undefined, { skip: isAccounting });
  const [submitPayment] = useSubmitPaymentMutation();
  const [upsertFee] = useUpsertPaymentFeeMutation();

  const [submit, setSubmit] = useState({
    manifestId: '',
    paymentType: 'FinalPayment',
    amount: 1250,
    currency: 'USD',
  });
  const [feeAmount, setFeeAmount] = useState(500);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (isAccounting) {
    return <AccountingFinalPaymentsPage />;
  }

  const payableManifests = manifests.filter((m) => /billing|payment|generated/i.test(m.workflowState));

  if (isSystemAdmin) {
    return (
      <Stack spacing={3}>
        <AccountingFinalPaymentsPage />
        <WorkflowPage
          eyebrow="Administration"
          title="Fee Configuration"
          subtitle="Maintain the manifest access fee used by the payment workflow."
        >
          {message && <Alert severity="success">{message}</Alert>}
          <WorkflowSection title="Manifest access fee" subtitle="Used for manifest access payments.">
            <Stack direction="row" spacing={2}>
              <TextField
                label="Manifest access fee"
                type="number"
                value={feeAmount}
                onChange={(e) => setFeeAmount(Number(e.target.value))}
              />
              <Button
                variant="contained"
                onClick={async () => {
                  await upsertFee({ feeType: 'manifest_access', amount: feeAmount }).unwrap();
                  setMessage('Fee updated.');
                }}
              >
                Save fee
              </Button>
            </Stack>
          </WorkflowSection>
        </WorkflowPage>
      </Stack>
    );
  }

  return (
    <WorkflowPage
      eyebrow="Payment Workflow"
      title="Payments"
      subtitle="Submit receipts for manifest access or final payment."
      chips={
        <>
          <Chip size="small" color="info" label={`FX ${fx?.rate ?? '-'}`} />
          <Chip size="small" variant="outlined" label={`Access fee ${accessFee?.amount ?? '-'} PHP`} />
        </>
      }
      actions={
        <Button variant="outlined" startIcon={<ReceiptLongOutlinedIcon />} sx={{ textTransform: 'none', fontWeight: 600 }}>
          Submit payment below
        </Button>
      }
      stats={[
        { label: 'Payable Manifests', value: payableManifests.length, hint: 'Relevant workflow states', tone: 'info' },
        { label: 'Access Fee', value: `${accessFee?.amount ?? '-'} PHP`, hint: 'Manifest access', tone: 'primary' },
        { label: 'FX Rate', value: fx?.rate ?? '-', hint: fx?.fromCache ? 'Cached' : 'Live', tone: 'success' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      {isBroker && (
        <WorkflowSection
          title="Submit Payment"
          subtitle="Upload a receipt for manifest access or final payment."
        >
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <TextField
              select
              label="Manifest"
              value={submit.manifestId}
              onChange={(e) => setSubmit({ ...submit, manifestId: e.target.value })}
              sx={{ minWidth: 180 }}
            >
              {manifests.map((m) => (
                <MenuItem key={m.id} value={m.id}>
                  {m.manifestNumber} ({m.workflowState})
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="Type"
              value={submit.paymentType}
              onChange={(e) => setSubmit({ ...submit, paymentType: e.target.value })}
            >
              <MenuItem value="ManifestAccess">ManifestAccess</MenuItem>
              <MenuItem value="FinalPayment">FinalPayment</MenuItem>
            </TextField>
            <TextField
              label="Amount"
              type="number"
              value={submit.amount}
              onChange={(e) => setSubmit({ ...submit, amount: Number(e.target.value) })}
            />
            <Button variant="contained" component="label">
              Submit + receipt
              <input
                hidden
                type="file"
                onChange={async (e) => {
                  const file = e.target.files?.[0];
                  setError(null);
                  try {
                    await submitPayment({
                      manifestId: submit.manifestId,
                      paymentType: submit.paymentType,
                      amount: submit.amount,
                      currency: submit.currency,
                      receipt: file ?? undefined,
                    }).unwrap();
                    setMessage('Payment submitted.');
                    refetchManifests();
                  } catch {
                    setError('Submit failed. Final payment needs BillingGenerated state.');
                  }
                }}
              />
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      {!isBroker && (
        <Typography color="text.secondary">No payment actions are available for your role.</Typography>
      )}
    </WorkflowPage>
  );
}
