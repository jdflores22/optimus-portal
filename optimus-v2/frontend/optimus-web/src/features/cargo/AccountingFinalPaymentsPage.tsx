import { useMemo } from 'react';
import {
  Box,
  Button,
  Chip,
  MenuItem,
  Paper,
  Stack,
  Tab,
  Tabs,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import PlayArrowOutlinedIcon from '@mui/icons-material/PlayArrowOutlined';
import WarningAmberOutlinedIcon from '@mui/icons-material/WarningAmberOutlined';
import { useSearchParams } from 'react-router-dom';
import { useGetFinalPaymentsQuery } from '../../app/api';
import type { FinalPaymentListItemDto } from '../../shared/types';
import { WorkflowPage } from '../shared/WorkflowPage';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

type StatusFilter = 'pending_validation' | 'verified' | 'rejected' | 'all';

const STATUS_TABS: { key: StatusFilter; label: string; tone: 'warning' | 'success' | 'error' | 'primary' }[] = [
  { key: 'pending_validation', label: 'Pending', tone: 'warning' },
  { key: 'verified', label: 'Approved', tone: 'success' },
  { key: 'rejected', label: 'Rejected', tone: 'error' },
  { key: 'all', label: 'All', tone: 'primary' },
];

function money(amount: number, currency: string) {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function statusLabel(status: string) {
  if (/pending/i.test(status)) return 'Pending';
  if (/verified/i.test(status)) return 'Approved';
  if (/reject/i.test(status)) return 'Rejected';
  return status;
}

function statusColor(status: string): 'warning' | 'success' | 'error' | 'default' {
  if (/pending/i.test(status)) return 'warning';
  if (/verified/i.test(status)) return 'success';
  if (/reject/i.test(status)) return 'error';
  return 'default';
}

function sectionTitle(filter: StatusFilter) {
  switch (filter) {
    case 'all':
      return 'All Final Payments';
    case 'verified':
      return 'Approved Payments';
    case 'rejected':
      return 'Rejected Payments';
    default:
      return 'Pending Validation';
  }
}

function rowBg(status: string) {
  if (/pending/i.test(status)) return 'warning.50';
  if (/verified/i.test(status)) return 'success.50';
  if (/reject/i.test(status)) return 'error.50';
  return undefined;
}

function discrepancy(payment: FinalPaymentListItemDto) {
  if (payment.billingAmount == null) return null;
  return Math.abs(payment.amount - payment.billingAmount);
}

export function AccountingFinalPaymentsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const status = (searchParams.get('status') ?? 'pending_validation') as StatusFilter;
  const page = Math.max(1, Number(searchParams.get('page') ?? 1));
  const limit = [10, 20, 50].includes(Number(searchParams.get('limit')))
    ? Number(searchParams.get('limit'))
    : 20;

  const { data, isLoading, isFetching } = useGetFinalPaymentsQuery({ status, page, limit });

  const stats = data?.stats;
  const items = data?.items ?? [];

  const tabCounts = useMemo(
    () => ({
      pending_validation: stats?.pending ?? 0,
      verified: stats?.approved ?? 0,
      rejected: stats?.rejected ?? 0,
      all: stats?.total ?? 0,
    }),
    [stats],
  );

  const setFilter = (nextStatus: StatusFilter) => {
    const next = new URLSearchParams(searchParams);
    next.set('status', nextStatus);
    next.set('page', '1');
    if (!next.get('limit')) next.set('limit', String(limit));
    setSearchParams(next);
  };

  const setPage = (nextPage: number) => {
    const next = new URLSearchParams(searchParams);
    next.set('page', String(nextPage));
    setSearchParams(next);
  };

  const setLimit = (nextLimit: number) => {
    const next = new URLSearchParams(searchParams);
    next.set('limit', String(nextLimit));
    next.set('page', '1');
    setSearchParams(next);
  };

  return (
    <WorkflowPage
      eyebrow="Accounting"
      title="Final Payments"
      subtitle="Review broker final payments against billing amounts and validate receipts."
      chips={
        stats && stats.pending > 0 ? (
          <Chip size="small" color="warning" label={`${stats.pending} pending review`} />
        ) : undefined
      }
      actions={
        stats && stats.pending > 0 ? (
          <Button
            variant="contained"
            startIcon={<PlayArrowOutlinedIcon />}
            onClick={() => setFilter('pending_validation')}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            Review Pending ({stats.pending})
          </Button>
        ) : undefined
      }
    >
      <Paper elevation={0} sx={{ border: 1, borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
        <Tabs
          value={status}
          onChange={(_, value) => setFilter(value as StatusFilter)}
          variant="scrollable"
          scrollButtons="auto"
          allowScrollButtonsMobile
          sx={{
            px: { xs: 0.5, sm: 1 },
            bgcolor: 'background.paper',
            borderBottom: 1,
            borderColor: 'divider',
            minHeight: 48,
            '& .MuiTab-root': {
              textTransform: 'none',
              fontWeight: 600,
              minHeight: 48,
            },
          }}
        >
          {STATUS_TABS.map((tab) => (
            <Tab
              key={tab.key}
              value={tab.key}
              label={
                <Stack direction="row" spacing={1} alignItems="center">
                  <span>{tab.label}</span>
                  <Chip
                    size="small"
                    label={tabCounts[tab.key]}
                    color={status === tab.key ? tab.tone : 'default'}
                    variant={status === tab.key ? 'filled' : 'outlined'}
                    sx={{ height: 22, fontWeight: 700, minWidth: 28 }}
                  />
                </Stack>
              }
            />
          ))}
        </Tabs>

        <Box sx={{ px: { xs: 1.5, sm: 2.5 }, py: 2 }}>
          <Stack
            direction={{ xs: 'column', sm: 'row' }}
            justifyContent="space-between"
            alignItems={{ sm: 'center' }}
            spacing={1}
            mb={2}
          >
            <Box>
              <Typography variant="h6" fontWeight={700}>
                {sectionTitle(status)}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {data
                  ? `Showing ${data.start}–${data.end} of ${data.total} record${data.total === 1 ? '' : 's'} · Page ${data.page} of ${data.pages}`
                  : 'Loading payments…'}
              </Typography>
            </Box>
            {stats && stats.discrepancies > 0 && (status === 'pending_validation' || status === 'all') && (
              <Chip
                size="small"
                color="warning"
                icon={<WarningAmberOutlinedIcon />}
                label={`${stats.discrepancies} amount mismatch${stats.discrepancies === 1 ? '' : 'es'}`}
              />
            )}
          </Stack>

        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Payment</TableCell>
                <TableCell>Manifest</TableCell>
                <TableCell>Submitter</TableCell>
                <TableCell>Billing</TableCell>
                <TableCell>Paid</TableCell>
                <TableCell>Match</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Submitted</TableCell>
                <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {isLoading && (
                <TableRow>
                  <TableCell colSpan={9}>
                    <Typography py={3} textAlign="center" color="text.secondary">
                      Loading…
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
              {!isLoading && items.length === 0 && (
                <TableRow>
                  <TableCell colSpan={9}>
                    <Stack py={4} alignItems="center" spacing={1}>
                      <Typography fontWeight={600}>No payments found</Typography>
                      <Typography variant="body2" color="text.secondary">
                        {status === 'pending_validation'
                          ? 'All caught up — no payments waiting for validation.'
                          : 'Try a different status filter.'}
                      </Typography>
                      {status !== 'pending_validation' && (
                        <Button size="small" onClick={() => setFilter('pending_validation')}>
                          View pending
                        </Button>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              )}
              {items.map((p) => {
                const diff = discrepancy(p);
                const matches = diff == null || diff <= 0.01;
                const billingCur = p.billingCurrency ?? p.currency;
                return (
                  <TableRow key={p.id} sx={{ bgcolor: rowBg(p.status) }}>
                    <TableCell>
                      <Typography variant="body2" fontFamily="monospace" fontWeight={600}>
                        #{p.id.slice(0, 8)}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {p.currency}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Typography
                        component={RouterLink}
                        to={`/manifests/${p.manifestId}`}
                        variant="body2"
                        fontWeight={600}
                        sx={{ color: 'primary.main', textDecoration: 'none', '&:hover': { underline: 'none' } }}
                      >
                        {p.manifestNumber}
                      </Typography>
                      {p.consigneeName && (
                        <Typography variant="caption" color="text.secondary" display="block" noWrap sx={{ maxWidth: 140 }}>
                          {p.consigneeName}
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{p.submittedByName}</Typography>
                      {p.submittedByEmail && (
                        <Typography variant="caption" color="text.secondary" display="block" noWrap sx={{ maxWidth: 160 }}>
                          {p.submittedByEmail}
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell>
                      {p.billingAmount != null ? (
                        <Typography variant="body2" fontWeight={700}>
                          {money(p.billingAmount, billingCur)}
                        </Typography>
                      ) : (
                        <Typography variant="body2" color="text.disabled">
                          —
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" fontWeight={700}>
                        {money(p.amount, p.currency)}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      {p.billingAmount == null ? (
                        <Typography variant="caption" color="text.disabled">
                          —
                        </Typography>
                      ) : matches ? (
                        <Chip size="small" color="success" label="Match" />
                      ) : (
                        <Chip
                          size="small"
                          color="warning"
                          icon={<WarningAmberOutlinedIcon />}
                          label={money(diff!, p.currency)}
                        />
                      )}
                    </TableCell>
                    <TableCell>
                      <Chip size="small" color={statusColor(p.status)} label={statusLabel(p.status)} />
                      {p.validatedByName && (
                        <Typography variant="caption" color="text.secondary" display="block" mt={0.5}>
                          {p.validatedByName}
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" color="text.secondary">
                        {new Date(p.createdAt).toLocaleDateString(undefined, {
                          month: 'short',
                          day: 'numeric',
                          year: 'numeric',
                        })}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {new Date(p.createdAt).toLocaleTimeString(undefined, {
                          hour: 'numeric',
                          minute: '2-digit',
                        })}
                      </Typography>
                    </TableCell>
                    <TableCell align="right">
                      <TableViewLink to={`/payments/final/${p.id}`} />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Box>

        {data && data.total > 0 && (
          <Stack
            direction={{ xs: 'column', sm: 'row' }}
            justifyContent="space-between"
            alignItems={{ sm: 'center' }}
            spacing={2}
            mt={2}
            pt={2}
            borderTop={1}
            borderColor="divider"
          >
            <Typography variant="body2" color="text.secondary">
              Showing <strong>{data.start}</strong>–<strong>{data.end}</strong> of{' '}
              <strong>{data.total}</strong>
              {isFetching ? ' · Updating…' : ''}
            </Typography>
            <Stack direction="row" spacing={2} alignItems="center">
              <TextField
                select
                size="small"
                label="Rows"
                value={limit}
                onChange={(e) => setLimit(Number(e.target.value))}
                sx={{ width: 88 }}
              >
                {[10, 20, 50].map((n) => (
                  <MenuItem key={n} value={n}>
                    {n}
                  </MenuItem>
                ))}
              </TextField>
              <Stack direction="row" spacing={1}>
                <Button size="small" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                  Prev
                </Button>
                <Chip size="small" label={`${page} / ${data.pages}`} />
                <Button size="small" disabled={page >= data.pages} onClick={() => setPage(page + 1)}>
                  Next
                </Button>
              </Stack>
            </Stack>
          </Stack>
        )}
        </Box>
      </Paper>
    </WorkflowPage>
  );
}
