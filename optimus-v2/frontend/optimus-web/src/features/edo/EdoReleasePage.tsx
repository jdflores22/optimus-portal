import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Paper,
  Stack,
  Tab,
  Tabs,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import SendOutlinedIcon from '@mui/icons-material/SendOutlined';
import { useSelector } from 'react-redux';
import { Link as RouterLink } from 'react-router-dom';
import { useGetEdoReleaseQueueQuery, useGetEdoReleaseRecordsQuery, useReleaseEdoMutation } from '../../app/api';
import type { RootState } from '../../app/store';
import type { EdoReleaseQueueItemDto } from '../../shared/types';
import { formatEdoStatus } from '../../shared/formatEdoStatus';
import { TABLE_ACTIONS_HEADER, TableActionButton, TableViewLink } from '../shared/TableViewLink';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

type TabKey = 'verified' | 'all' | 'awaiting' | 'record';

function money(amount: number, currency: string) {
  const symbol = currency === 'USD' ? '$' : '₱';
  return `${symbol}${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function paymentBadge(item: EdoReleaseQueueItemDto) {
  if (!item.paymentId) {
    return <Chip size="small" label="Awaiting payment" variant="outlined" />;
  }
  if (/verified/i.test(item.paymentStatus ?? '')) {
    return <Chip size="small" label="Payment verified" color="success" />;
  }
  if (/pendingvalidation/i.test(item.paymentStatus ?? '')) {
    return <Chip size="small" label="Pending admin review" color="warning" />;
  }
  if (/reject/i.test(item.paymentStatus ?? '')) {
    return <Chip size="small" label="Payment rejected" color="error" />;
  }
  return <Chip size="small" label={item.paymentStatus ?? '—'} />;
}

function filterByTab(items: EdoReleaseQueueItemDto[], tab: TabKey) {
  switch (tab) {
    case 'verified':
      return items.filter((x) => x.status === 'PendingRelease');
    case 'awaiting':
      return items.filter((x) => !x.paymentId || /pendingvalidation/i.test(x.paymentStatus ?? ''));
    default:
      return items;
  }
}

/** Shipping line staff release eDO/CRO; platform admin can view queue and release record (read-only). */
export function EdoReleasePage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const role = user?.role ?? '';
  const canView = ['SlStaff', 'ShippingLinesAdmin', 'TerminalTeam', 'SystemAdmin'].includes(role);
  const canPerformRelease = ['SlStaff', 'ShippingLinesAdmin', 'TerminalTeam'].includes(role);
  const isProviderView = role === 'SystemAdmin';

  const { data, refetch } = useGetEdoReleaseQueueQuery(undefined, { skip: !canView });
  const { data: records = [], refetch: refetchRecords } = useGetEdoReleaseRecordsQuery(undefined, {
    skip: !canView,
  });
  const [releaseEdo] = useReleaseEdoMutation();

  const [tab, setTab] = useState<TabKey>(isProviderView ? 'record' : 'verified');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const items = data?.items ?? [];
  const filtered = useMemo(() => filterByTab(items, tab), [items, tab]);

  if (!canView) {
    return (
      <Alert severity="info">
        eDO release is handled by shipping line staff. Platform admins validate payments under{' '}
        <strong>eDO Payment Validation</strong>.
      </Alert>
    );
  }

  return (
    <WorkflowPage
      eyebrow={isProviderView ? 'Platform admin' : 'Shipping line operations'}
      title="Release eDO / CRO"
      subtitle={
        isProviderView
          ? 'Read-only view of the release queue and released documents. Shipping line staff perform the release action.'
          : 'Release electronic delivery orders after the platform admin has verified broker payment.'
      }
      chips={
        <>
          <Chip size="small" color="success" label={`${data?.readyToRelease ?? 0} ready to release`} />
          {(data?.pendingValidation ?? 0) > 0 && (
            <Chip
              size="small"
              color="warning"
              variant="outlined"
              label={`${data?.pendingValidation} awaiting admin validation`}
            />
          )}
        </>
      }
      stats={[
        {
          label: 'Ready to release',
          value: data?.readyToRelease ?? 0,
          hint: 'Payment verified',
          tone: 'success',
        },
        {
          label: 'Awaiting admin',
          value: data?.pendingValidation ?? 0,
          hint: 'Payment not verified yet',
          tone: 'warning',
        },
        {
          label: 'Awaiting payment',
          value: data?.awaitingPayment ?? 0,
          hint: 'Broker has not paid',
          tone: 'info',
        },
        { label: 'Total in queue', value: data?.total ?? 0, hint: 'All open eDOs', tone: 'primary' },
        { label: 'Released record', value: records.length, hint: 'Documents released', tone: 'info' },
      ]}
    >
      {message && (
        <Alert severity="success" sx={{ mb: 2 }} onClose={() => setMessage(null)}>
          {message}
        </Alert>
      )}
      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}
      {isProviderView && (
        <Alert severity="info" sx={{ mb: 2 }}>
          You are viewing as platform admin. To validate broker payments, use{' '}
          <strong>eDO Payment Validation</strong>. Only SL Staff can click Release eDO.
        </Alert>
      )}

      <WorkflowSection
        title="Release queue"
        subtitle={
          isProviderView
            ? 'Monitor what is waiting for release or already released.'
            : 'Only verified payments can be released.'
        }
      >
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: 'hidden' }}>
          <Tabs
            value={tab}
            onChange={(_, value: TabKey) => setTab(value)}
            variant="scrollable"
            scrollButtons="auto"
            sx={{ px: 1, borderBottom: 1, borderColor: 'divider' }}
          >
            <Tab
              value="verified"
              label={`Ready to release (${data?.readyToRelease ?? 0})`}
              sx={{ textTransform: 'none' }}
            />
            <Tab
              value="awaiting"
              label={`Awaiting validation (${data?.pendingValidation ?? 0})`}
              sx={{ textTransform: 'none' }}
            />
            <Tab value="all" label={`All (${data?.total ?? 0})`} sx={{ textTransform: 'none' }} />
            <Tab value="record" label={`Released (${records.length})`} sx={{ textTransform: 'none' }} />
          </Tabs>

          <Box sx={{ overflowX: 'auto' }}>
            {tab === 'record' ? (
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Manifest / eDO</TableCell>
                    <TableCell>Container</TableCell>
                    <TableCell>Payment</TableCell>
                    <TableCell>Admin validated</TableCell>
                    <TableCell>Released</TableCell>
                    <TableCell>Released by</TableCell>
                    <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {records.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={7}>
                        <Typography variant="body2" color="text.secondary" py={4} textAlign="center">
                          No released eDO/CRO documents yet.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                  {records.map((item) => (
                    <TableRow key={item.edoId} hover>
                      <TableCell>
                        <Button
                          component={RouterLink}
                          to={`/manifests/${item.manifestId}?tab=documents`}
                          size="small"
                          sx={{ display: 'block', textAlign: 'left', fontFamily: 'monospace', p: 0 }}
                        >
                          {item.manifestNumber}
                        </Button>
                        <Typography variant="caption" color="text.secondary" fontFamily="monospace">
                          {item.edoNumber}
                        </Typography>
                      </TableCell>
                      <TableCell sx={{ fontFamily: 'monospace' }}>{item.containerNumber ?? '—'}</TableCell>
                      <TableCell>
                        {item.paymentAmount != null && item.paymentCurrency
                          ? money(item.paymentAmount, item.paymentCurrency)
                          : '—'}
                      </TableCell>
                      <TableCell>
                        {item.paymentValidatedAt ? (
                          <>
                            <Typography variant="caption" display="block">
                              {new Date(item.paymentValidatedAt).toLocaleString()}
                            </Typography>
                            <Typography variant="caption" color="text.secondary">
                              {item.paymentValidatedByName ?? 'Provider'}
                            </Typography>
                          </>
                        ) : (
                          '—'
                        )}
                      </TableCell>
                      <TableCell>
                        {item.releasedAt ? new Date(item.releasedAt).toLocaleString() : '—'}
                      </TableCell>
                      <TableCell>{item.releasedByName ?? '—'}</TableCell>
                      <TableCell align="right">
                        <TableViewLink to={`/edo/${item.edoId}?from=release`} />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            ) : (
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Manifest / eDO</TableCell>
                  <TableCell>Container</TableCell>
                  <TableCell>Payment</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {filtered.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={5}>
                      <Typography variant="body2" color="text.secondary" py={4} textAlign="center">
                        No eDOs in this view.
                      </Typography>
                    </TableCell>
                  </TableRow>
                )}
                {filtered.map((item) => {
                  const readyToRelease = item.status === 'PendingRelease';
                  return (
                    <TableRow key={item.edoId} hover>
                      <TableCell>
                        <Button
                          component={RouterLink}
                          to={`/manifests/${item.manifestId}?tab=documents`}
                          size="small"
                          sx={{ display: 'block', textAlign: 'left', fontFamily: 'monospace', p: 0 }}
                        >
                          {item.manifestNumber}
                        </Button>
                        <Typography variant="caption" color="text.secondary" fontFamily="monospace">
                          {item.edoNumber}
                        </Typography>
                      </TableCell>
                      <TableCell sx={{ fontFamily: 'monospace' }}>{item.containerNumber ?? '—'}</TableCell>
                      <TableCell>
                        <Stack spacing={0.75}>
                          {paymentBadge(item)}
                          {item.paymentAmount != null && item.paymentCurrency && (
                            <Typography variant="caption" fontWeight={700}>
                              {money(item.paymentAmount, item.paymentCurrency)}
                            </Typography>
                          )}
                        </Stack>
                      </TableCell>
                      <TableCell>
                        <Chip
                          size="small"
                          label={formatEdoStatus(item.status, item.paymentStatus)}
                          color={readyToRelease ? 'success' : 'warning'}
                          variant="outlined"
                        />
                      </TableCell>
                      <TableCell align="right">
                        <Stack direction="row" spacing={0.5} justifyContent="flex-end" alignItems="center">
                          <TableViewLink to={`/edo/${item.edoId}?from=release`} />
                          {readyToRelease && canPerformRelease ? (
                            <TableActionButton
                              label="Release"
                              color="success"
                              icon={<SendOutlinedIcon sx={{ fontSize: 16 }} />}
                              onClick={async () => {
                                try {
                                  await releaseEdo({ id: item.edoId, approve: true }).unwrap();
                                  setMessage(`Released ${item.edoNumber}`);
                                  refetch();
                                  refetchRecords();
                                } catch (e: unknown) {
                                  setError(
                                    (e as { data?: { message?: string } })?.data?.message ??
                                      'Release failed',
                                  );
                                }
                              }}
                            />
                          ) : null}
                        </Stack>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
            )}
          </Box>
        </Paper>
      </WorkflowSection>
    </WorkflowPage>
  );
}
