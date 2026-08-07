import { useState } from 'react';
import {
  Alert,
  Chip,
  Paper,
  Tab,
  Tabs,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import { useGetPendingEdoPaymentsQuery, useGetReviewedEdoPaymentsQuery } from '../../app/api';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function money(amount: number, currency: string) {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function statusChip(status: string) {
  if (/verified/i.test(status)) {
    return <Chip size="small" label="Approved" color="success" />;
  }
  if (/reject/i.test(status)) {
    return <Chip size="small" label="Rejected" color="error" />;
  }
  return <Chip size="small" label={status} />;
}

type TabKey = 'pending' | 'record';

/** Platform admin (provider) — validate broker eDO access payments before shipping line release. */
export function EdoPaymentValidationPage() {
  const [tab, setTab] = useState<TabKey>('pending');
  const { data: payments = [], isLoading: pendingLoading } = useGetPendingEdoPaymentsQuery(undefined, {
    skip: tab !== 'pending',
  });
  const { data: reviewed = [], isLoading: reviewedLoading } = useGetReviewedEdoPaymentsQuery(undefined, {
    skip: tab !== 'record',
  });

  const isLoading = tab === 'pending' ? pendingLoading : reviewedLoading;

  return (
    <WorkflowPage
      eyebrow="Platform admin"
      title="eDO payment validation"
      subtitle="Review broker payment receipts and confirm eDO access fees. After approval, shipping line staff can release the eDO."
      chips={
        <Chip
          size="small"
          color={payments.length ? 'warning' : 'success'}
          label={`${payments.length} awaiting review`}
        />
      }
      stats={[
        { label: 'Pending review', value: payments.length, hint: 'Submitted receipts', tone: 'warning' },
        { label: 'Validated record', value: reviewed.length, hint: 'Approved or rejected', tone: 'info' },
        { label: 'Your role', value: 'Provider', hint: 'Platform admin', tone: 'primary' },
      ]}
    >
      <Alert severity="info" sx={{ mb: 2 }}>
        You validate payments here. <strong>SL Staff</strong> releases eDO/CRO documents after payment is
        verified under <strong>Release eDO / CRO</strong>.
      </Alert>

      <WorkflowSection
        title={tab === 'pending' ? 'Submitted payments' : 'Validation record'}
        subtitle={
          tab === 'pending'
            ? 'Open each receipt, confirm the fee, then approve or reject.'
            : 'Payments you have already approved or rejected.'
        }
      >
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: 'hidden', mb: 0 }}>
          <Tabs
            value={tab}
            onChange={(_, value: TabKey) => setTab(value)}
            sx={{ px: 1, borderBottom: 1, borderColor: 'divider' }}
          >
            <Tab value="pending" label={`Pending (${payments.length})`} sx={{ textTransform: 'none' }} />
            <Tab value="record" label={`Record (${reviewed.length})`} sx={{ textTransform: 'none' }} />
          </Tabs>
        </Paper>

        {isLoading ? (
          <Typography py={3}>Loading...</Typography>
        ) : tab === 'pending' ? (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>eDO</TableCell>
                <TableCell>Manifest</TableCell>
                <TableCell>Container</TableCell>
                <TableCell>Amount</TableCell>
                <TableCell>Submitted by</TableCell>
                <TableCell>Submitted</TableCell>
                <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {payments.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7}>
                    <Typography variant="body2" color="text.secondary" py={3} textAlign="center">
                      No eDO payments waiting for validation.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
              {payments.map((p) => (
                <TableRow key={p.id} hover>
                  <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600 }}>
                    {p.edoNumber ?? p.edoId}
                  </TableCell>
                  <TableCell>{p.manifestNumber ?? '—'}</TableCell>
                  <TableCell sx={{ fontFamily: 'monospace' }}>{p.containerNumber ?? '—'}</TableCell>
                  <TableCell>{money(p.amount, p.currency)}</TableCell>
                  <TableCell>{p.submittedByName ?? '—'}</TableCell>
                  <TableCell>{new Date(p.createdAt).toLocaleString()}</TableCell>
                  <TableCell align="right">
                    <TableViewLink to={`/edo/payment-validation/${p.id}?from=validation`} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>eDO</TableCell>
                <TableCell>Manifest</TableCell>
                <TableCell>Container</TableCell>
                <TableCell>Amount</TableCell>
                <TableCell>Outcome</TableCell>
                <TableCell>Validated</TableCell>
                <TableCell>Validated by</TableCell>
                <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {reviewed.length === 0 && (
                <TableRow>
                  <TableCell colSpan={8}>
                    <Typography variant="body2" color="text.secondary" py={3} textAlign="center">
                      No validated payments yet.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
              {reviewed.map((p) => (
                <TableRow key={p.id} hover>
                  <TableCell sx={{ fontFamily: 'monospace', fontWeight: 600 }}>
                    {p.edoNumber ?? p.edoId}
                  </TableCell>
                  <TableCell>{p.manifestNumber ?? '—'}</TableCell>
                  <TableCell sx={{ fontFamily: 'monospace' }}>{p.containerNumber ?? '—'}</TableCell>
                  <TableCell>{money(p.amount, p.currency)}</TableCell>
                  <TableCell>{statusChip(p.status)}</TableCell>
                  <TableCell>{p.validatedAt ? new Date(p.validatedAt).toLocaleString() : '—'}</TableCell>
                  <TableCell>{p.validatedByName ?? '—'}</TableCell>
                  <TableCell align="right">
                    <TableViewLink to={`/edo/payment-validation/${p.id}?from=validation`} />
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
