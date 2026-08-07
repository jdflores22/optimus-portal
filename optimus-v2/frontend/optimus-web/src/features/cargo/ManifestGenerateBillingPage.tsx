import { FormEvent, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  MenuItem,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import AddOutlinedIcon from '@mui/icons-material/AddOutlined';
import CloseOutlinedIcon from '@mui/icons-material/CloseOutlined';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import { Link as RouterLink, Navigate, useNavigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGenerateBillingMutation,
  useGetExchangeRateQuery,
  useGetManifestQuery,
} from '../../app/api';
import { API_BASE_URL } from '../../shared/types';
import { formatWorkflowState } from '../../shared/formatWorkflowState';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

type ExtraCharge = { id: number; description: string; amount: string };

function money(amount: number, currency: 'USD' | 'PHP') {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

export function ManifestGenerateBillingPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const isAccounting = user?.role === 'Accounting' || user?.role === 'SystemAdmin';
  const { data, error, isLoading } = useGetManifestQuery(id, { skip: !id });
  const { data: fx, isFetching: fxLoading, isError: fxError } = useGetExchangeRateQuery();
  const [generateBilling] = useGenerateBillingMutation();

  const [currency, setCurrency] = useState<'USD' | 'PHP'>('USD');
  const [freight, setFreight] = useState('');
  const [thc, setThc] = useState('');
  const [extras, setExtras] = useState<ExtraCharge[]>([]);
  const [nextExtraId, setNextExtraId] = useState(1);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const exchangeRate = fx?.rate && fx.rate > 0 ? fx.rate : 57.5;
  const freightNum = Number(freight) || 0;
  const thcNum = Number(thc) || 0;
  const extraLines = useMemo(
    () =>
      extras
        .map((e) => ({
          description: e.description.trim(),
          amount: Number(e.amount) || 0,
        }))
        .filter((e) => e.description && e.amount > 0),
    [extras],
  );
  const additionalTotal = extraLines.reduce((sum, e) => sum + e.amount, 0);
  const total = freightNum + thcNum + additionalTotal;
  const toPhp = (amount: number) => (currency === 'USD' ? amount * exchangeRate : amount);

  if (!isAccounting) return <Navigate to={`/manifests/${id}`} replace />;
  if (error) return <Alert severity="error">Manifest not found.</Alert>;
  if (isLoading || !data) return <Typography>Loading...</Typography>;

  if (data.workflowState !== 'BlUploaded' || data.billingPdfPath) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setFormError(null);
    if (freightNum < 0 || thcNum < 0) {
      setFormError('Freight and THC cannot be negative.');
      return;
    }
    if (total <= 0) {
      setFormError('Enter at least one charge amount greater than zero.');
      return;
    }
    setConfirmOpen(true);
  };

  const onConfirm = async () => {
    setSubmitting(true);
    setFormError(null);
    try {
      const updated = await generateBilling({
        manifestId: id,
        freightCharges: freightNum,
        thcCharges: thcNum,
        additionalCharges: additionalTotal,
        currency,
        exchangeRate: currency === 'USD' ? exchangeRate : undefined,
        additionalChargeLines: extraLines,
      }).unwrap();
      setConfirmOpen(false);
      if (updated.billingPdfPath) {
        window.open(`${API_BASE_URL}${updated.billingPdfPath}`, '_blank', 'noopener,noreferrer');
      }
      navigate(`/manifests/${id}`, {
        replace: true,
        state: { flash: 'Billing statement generated successfully.' },
      });
    } catch (err: unknown) {
      setConfirmOpen(false);
      const msg =
        err && typeof err === 'object' && 'data' in err
          ? String((err as { data?: { error?: string } }).data?.error ?? 'Failed to generate billing.')
          : 'Failed to generate billing.';
      setFormError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const symbol = currency === 'USD' ? '$' : '₱';
  const fxUpdated = fx?.retrievedAtUtc
    ? new Date(fx.retrievedAtUtc).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
      })
    : null;

  return (
    <WorkflowPage
      eyebrow="Manifest workflow · Step 4"
      title="Generate Billing"
      subtitle="Enter freight, THC, and additional charges to issue the billing document after BL upload."
      chips={
        <>
          <Chip size="small" label={data.manifestNumber} variant="outlined" sx={{ fontWeight: 700 }} />
          <Chip size="small" label={formatWorkflowState(data.workflowState)} color="primary" />
          {data.blNumber && (
            <Chip size="small" label={`BL ${data.blNumber}`} variant="outlined" />
          )}
          {data.brokerName && <Chip size="small" label={data.brokerName} variant="outlined" />}
          {data.vesselName && <Chip size="small" label={data.vesselName} variant="outlined" />}
        </>
      }
      actions={
        <Button component={RouterLink} to={`/manifests/${id}`} startIcon={<ArrowBackOutlinedIcon />}>
          View Manifest
        </Button>
      }
    >
      {formError && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setFormError(null)}>
          {formError}
        </Alert>
      )}

      {(fxError || (!fxLoading && !fx?.rate)) && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Unable to fetch live exchange rates. Using fallback rate of ₱{exchangeRate.toFixed(2)}.
        </Alert>
      )}

      <Box
        component="form"
        onSubmit={onSubmit}
        sx={{
          display: 'grid',
          gap: 2.5,
          gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 1fr) minmax(0, 1fr)' },
          alignItems: 'start',
        }}
      >
        <WorkflowSection title="Billing Details" subtitle="Charges used on the billing statement PDF.">
          <Stack spacing={2.25}>
            <TextField
              select
              required
              label="Currency"
              value={currency}
              onChange={(e) => setCurrency(e.target.value as 'USD' | 'PHP')}
              helperText={
                fxLoading
                  ? 'Loading exchange rate…'
                  : `1 USD = ₱${exchangeRate.toFixed(4)}${fxError ? ' (Fallback)' : ''}`
              }
              fullWidth
            >
              <MenuItem value="PHP">Philippine Peso (₱)</MenuItem>
              <MenuItem value="USD">US Dollar ($)</MenuItem>
            </TextField>

            <TextField
              required
              type="number"
              label={`Freight Charges (${symbol})`}
              value={freight}
              onChange={(e) => setFreight(e.target.value)}
              inputProps={{ min: 0, step: '0.01' }}
              placeholder="0.00"
              fullWidth
            />

            <TextField
              required
              type="number"
              label={`Terminal Handling Charges (${symbol})`}
              value={thc}
              onChange={(e) => setThc(e.target.value)}
              inputProps={{ min: 0, step: '0.01' }}
              placeholder="0.00"
              fullWidth
            />

            <Box>
              <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1.25}>
                <Typography fontWeight={700} variant="body2">
                  Additional Charges
                </Typography>
                <Button
                  size="small"
                  startIcon={<AddOutlinedIcon />}
                  onClick={() => {
                    setExtras((rows) => [...rows, { id: nextExtraId, description: '', amount: '' }]);
                    setNextExtraId((n) => n + 1);
                  }}
                  sx={{ textTransform: 'none' }}
                >
                  Add Charge
                </Button>
              </Stack>

              {extras.length === 0 ? (
                <Typography variant="body2" color="text.secondary">
                  Optional line items (demurrage, documentation, etc.).
                </Typography>
              ) : (
                <Stack spacing={1.25}>
                  {extras.map((row) => (
                    <Stack key={row.id} direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems="stretch">
                      <TextField
                        size="small"
                        label="Description"
                        value={row.description}
                        onChange={(e) =>
                          setExtras((rows) =>
                            rows.map((r) =>
                              r.id === row.id ? { ...r, description: e.target.value } : r,
                            ),
                          )
                        }
                        fullWidth
                      />
                      <TextField
                        size="small"
                        type="number"
                        label="Amount"
                        value={row.amount}
                        onChange={(e) =>
                          setExtras((rows) =>
                            rows.map((r) =>
                              r.id === row.id ? { ...r, amount: e.target.value } : r,
                            ),
                          )
                        }
                        inputProps={{ min: 0, step: '0.01' }}
                        sx={{ width: { sm: 140 }, flexShrink: 0 }}
                      />
                      <IconButton
                        aria-label="Remove charge"
                        color="error"
                        onClick={() => setExtras((rows) => rows.filter((r) => r.id !== row.id))}
                        sx={{ alignSelf: { xs: 'flex-end', sm: 'center' } }}
                      >
                        <CloseOutlinedIcon fontSize="small" />
                      </IconButton>
                    </Stack>
                  ))}
                </Stack>
              )}
            </Box>

            <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="flex-end" spacing={1.25} pt={0.5}>
              <Button component={RouterLink} to={`/manifests/${id}`} disabled={submitting}>
                Cancel
              </Button>
              <Button
                type="submit"
                variant="contained"
                startIcon={<ReceiptLongOutlinedIcon />}
                disabled={submitting}
              >
                Generate Billing
              </Button>
            </Stack>
          </Stack>
        </WorkflowSection>

        <Paper
          elevation={0}
          sx={{
            p: { xs: 2, sm: 2.5 },
            border: 1,
            borderColor: 'divider',
            borderRadius: 2,
            position: { lg: 'sticky' },
            top: { lg: 88 },
          }}
        >
          <Typography variant="h6" fontWeight={700} mb={2}>
            Billing Preview
          </Typography>

          <Box
            sx={{
              p: 2,
              mb: 2.5,
              borderRadius: 2,
              bgcolor: 'action.hover',
              display: 'grid',
              gap: 1,
            }}
          >
            {[
              ['Manifest #', data.manifestNumber],
              ['BL Number', data.blNumber ?? '—'],
              ['Broker', data.brokerName ?? '—'],
              ['Consignee', data.consigneeName ?? '—'],
            ].map(([label, value]) => (
              <Stack key={label} direction="row" justifyContent="space-between" gap={2}>
                <Typography variant="body2" color="text.secondary">
                  {label}
                </Typography>
                <Typography variant="body2" fontWeight={700} textAlign="right">
                  {value}
                </Typography>
              </Stack>
            ))}
          </Box>

          <Stack spacing={1.5} divider={<Box sx={{ borderBottom: 1, borderColor: 'divider' }} />}>
            <PreviewRow
              label="Freight Charges"
              amount={money(freightNum, currency)}
              php={currency === 'USD' ? money(toPhp(freightNum), 'PHP') : null}
            />
            <PreviewRow
              label="THC Charges"
              amount={money(thcNum, currency)}
              php={currency === 'USD' ? money(toPhp(thcNum), 'PHP') : null}
            />
            {extraLines.map((line) => (
              <PreviewRow
                key={`${line.description}-${line.amount}`}
                label={line.description}
                amount={money(line.amount, currency)}
                php={currency === 'USD' ? money(toPhp(line.amount), 'PHP') : null}
              />
            ))}
          </Stack>

          <Stack
            direction="row"
            justifyContent="space-between"
            alignItems="flex-end"
            mt={2.5}
            pt={2}
            sx={{ borderTop: 2, borderColor: 'divider' }}
          >
            <Typography fontWeight={700} fontSize={18}>
              Total Amount
            </Typography>
            <Box textAlign="right">
              <Typography fontWeight={800} fontSize={26} color="primary.main" lineHeight={1.1}>
                {money(total, currency)}
              </Typography>
              {currency === 'USD' && (
                <Typography variant="body2" color="text.secondary" mt={0.5}>
                  {money(toPhp(total), 'PHP')}
                </Typography>
              )}
            </Box>
          </Stack>

          <Alert
            severity="info"
            icon={<InfoOutlinedIcon fontSize="inherit" />}
            sx={{ mt: 2.5, alignItems: 'flex-start' }}
          >
            <Typography variant="body2" fontWeight={700} mb={0.5}>
              Exchange Rate Information
            </Typography>
            <Typography variant="body2">
              {fxLoading
                ? 'Loading current rates…'
                : `Current rate: 1 USD = ₱${exchangeRate.toFixed(4)}${
                    fxUpdated ? ` (Updated: ${fxUpdated})` : ''
                  }`}
            </Typography>
            <Typography variant="caption" color="text.secondary" display="block" mt={0.5}>
              Rates are updated in real time when available.
            </Typography>
          </Alert>
        </Paper>
      </Box>

      <Dialog open={confirmOpen} onClose={() => (submitting ? null : setConfirmOpen(false))} fullWidth maxWidth="sm">
        <DialogTitle>Confirm billing generation</DialogTitle>
        <DialogContent dividers>
          <Alert severity="warning" variant="outlined" sx={{ mb: 2 }}>
            This creates the billing statement PDF and moves the manifest to Billing Generated.
          </Alert>
          <Stack spacing={1}>
            <Typography fontWeight={700}>
              Total {money(total, currency)}
              {currency === 'USD' ? ` · ${money(toPhp(total), 'PHP')}` : ''}
            </Typography>
            {currency === 'USD' && (
              <Typography variant="body2" color="text.secondary">
                Using rate 1 USD = ₱{exchangeRate.toFixed(4)}
              </Typography>
            )}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setConfirmOpen(false)} disabled={submitting}>
            Back
          </Button>
          <Button
            variant="contained"
            onClick={() => void onConfirm()}
            disabled={submitting}
            startIcon={<ReceiptLongOutlinedIcon />}
          >
            {submitting ? 'Generating…' : 'Confirm Generate'}
          </Button>
        </DialogActions>
      </Dialog>
    </WorkflowPage>
  );
}

function PreviewRow({
  label,
  amount,
  php,
}: {
  label: string;
  amount: string;
  php: string | null;
}) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="flex-start" gap={2} py={0.5}>
      <Typography color="text.secondary">{label}</Typography>
      <Box textAlign="right">
        <Typography fontWeight={700}>{amount}</Typography>
        {php && (
          <Typography variant="caption" color="text.secondary" display="block">
            {php}
          </Typography>
        )}
      </Box>
    </Stack>
  );
}
