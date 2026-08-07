import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  FormControlLabel,
  Paper,
  Stack,
  TableContainer,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { useNavigate } from 'react-router-dom';
import { useSelector } from 'react-redux';
import {
  useGetNotificationMetricsQuery,
  useGetNotificationPreferencesQuery,
  useGetNotificationsQuery,
  useMarkNotificationsReadMutation,
  useSubscribePushMutation,
  useUpsertNotificationPreferencesMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { parseApiDate } from '../../shared/dateTime';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function NotificationsPage() {
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const isAdmin = user?.role === 'SystemAdmin' || user?.role === 'ShippingLinesAdmin';
  const { data: items = [], refetch } = useGetNotificationsQuery();
  const { data: prefs } = useGetNotificationPreferencesQuery();
  const { data: metrics } = useGetNotificationMetricsQuery(undefined, { skip: !isAdmin });
  const [markRead] = useMarkNotificationsReadMutation();
  const [upsertPrefs] = useUpsertNotificationPreferencesMutation();
  const [subscribePush] = useSubscribePushMutation();
  const [muted, setMuted] = useState('[]');
  const [message, setMessage] = useState<string | null>(null);

  const unread = useMemo(() => items.filter((x) => !x.isRead).length, [items]);
  const critical = useMemo(
    () => items.filter((x) => ['appeal', 'suspension', 'pre_advice'].includes((x.category ?? '').toLowerCase())).length,
    [items],
  );

  return (
    <WorkflowPage
      eyebrow="Operations inbox"
      title="Alerts & notifications"
      subtitle="Track queue changes, escalations, and delivery notices across the shipping portal."
      chips={
        <>
          <Chip label={`${unread} unread`} color={unread ? 'warning' : 'success'} size="small" />
          <Chip label={`${critical} priority`} color={critical ? 'error' : 'default'} size="small" />
          <Chip label={user?.role ?? 'User'} size="small" variant="outlined" />
        </>
      }
      actions={
        <>
          <Button variant="outlined" onClick={() => refetch()}>
            Refresh
          </Button>
          <Button
            variant="contained"
            onClick={async () => {
              await markRead({}).unwrap();
              setMessage('All alerts marked as read');
              refetch();
            }}
          >
            Mark all read
          </Button>
        </>
      }
      stats={[
        { label: 'Unread', value: unread, hint: 'Still needs review', tone: unread ? 'warning' : 'success' },
        { label: 'Priority', value: critical, hint: 'Escalation-style categories', tone: critical ? 'error' : 'default' },
        { label: 'Total alerts', value: items.length, hint: 'Full inbox history', tone: 'primary' },
        {
          label: 'Delivery sent',
          value: metrics?.sent ?? '—',
          hint: isAdmin ? 'Admin delivery visibility' : 'Admin-only metric',
          tone: 'info',
        },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}

      <Box
        sx={{
          display: 'grid',
          gap: 3,
          gridTemplateColumns: { xs: '1fr', xl: 'minmax(0, 2fr) minmax(280px, 360px)' },
        }}
      >
        <WorkflowSection title="Alert queue" subtitle="Unread items stay visually emphasized so urgent workflow changes are easy to spot.">
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Alert</TableCell>
                  <TableCell>Category</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>When</TableCell>
                  <TableCell />
                </TableRow>
              </TableHead>
              <TableBody>
                {items.map((n) => (
                  <TableRow
                    key={n.id}
                    hover
                    sx={{ cursor: 'pointer', bgcolor: n.isRead ? 'transparent' : 'rgba(11,61,92,0.04)' }}
                    onClick={() => navigate(`/notifications/${n.id}`)}
                  >
                    <TableCell>
                      <Typography fontWeight={n.isRead ? 500 : 700}>{n.title}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {n.message}
                      </Typography>
                    </TableCell>
                    <TableCell>{n.category}</TableCell>
                    <TableCell>{n.isRead ? 'Read' : 'Unread'}</TableCell>
                    <TableCell>
                      {(() => {
                        const d = parseApiDate(n.createdAt);
                        return d ? d.toLocaleString() : '—';
                      })()}
                    </TableCell>
                    <TableCell>
                      {!n.isRead && (
                        <Button
                          size="small"
                          onClick={async (e) => {
                            e.stopPropagation();
                            await markRead({ notificationId: n.id }).unwrap();
                            refetch();
                          }}
                        >
                          Mark read
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </WorkflowSection>

        <Stack spacing={3}>
          {prefs && (
            <WorkflowSection title="Delivery preferences" subtitle="Choose which channels stay active for your operational alerts.">
              <Stack direction="row" flexWrap="wrap" gap={2} mb={2}>
                <FormControlLabel
                  control={
                    <Switch
                      checked={prefs.inAppEnabled}
                      onChange={async (_, v) => {
                        await upsertPrefs({
                          inAppEnabled: v,
                          emailEnabled: prefs.emailEnabled,
                          smsEnabled: prefs.smsEnabled,
                          pushEnabled: prefs.pushEnabled,
                          mutedCategoriesJson: prefs.mutedCategoriesJson,
                        }).unwrap();
                      }}
                    />
                  }
                  label="In-app"
                />
                <FormControlLabel
                  control={
                    <Switch
                      checked={prefs.emailEnabled}
                      onChange={async (_, v) => {
                        await upsertPrefs({
                          inAppEnabled: prefs.inAppEnabled,
                          emailEnabled: v,
                          smsEnabled: prefs.smsEnabled,
                          pushEnabled: prefs.pushEnabled,
                          mutedCategoriesJson: prefs.mutedCategoriesJson,
                        }).unwrap();
                      }}
                    />
                  }
                  label="Email"
                />
                <FormControlLabel
                  control={
                    <Switch
                      checked={prefs.smsEnabled}
                      onChange={async (_, v) => {
                        await upsertPrefs({
                          inAppEnabled: prefs.inAppEnabled,
                          emailEnabled: prefs.emailEnabled,
                          smsEnabled: v,
                          pushEnabled: prefs.pushEnabled,
                          mutedCategoriesJson: prefs.mutedCategoriesJson,
                        }).unwrap();
                      }}
                    />
                  }
                  label="SMS"
                />
                <FormControlLabel
                  control={
                    <Switch
                      checked={prefs.pushEnabled}
                      onChange={async (_, v) => {
                        await upsertPrefs({
                          inAppEnabled: prefs.inAppEnabled,
                          emailEnabled: prefs.emailEnabled,
                          smsEnabled: prefs.smsEnabled,
                          pushEnabled: v,
                          mutedCategoriesJson: prefs.mutedCategoriesJson,
                        }).unwrap();
                      }}
                    />
                  }
                  label="Push"
                />
              </Stack>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                <TextField
                  size="small"
                  label="Muted categories JSON"
                  defaultValue={prefs.mutedCategoriesJson}
                  onChange={(e) => setMuted(e.target.value)}
                  fullWidth
                />
                <Button
                  onClick={async () => {
                    await upsertPrefs({
                      inAppEnabled: prefs.inAppEnabled,
                      emailEnabled: prefs.emailEnabled,
                      smsEnabled: prefs.smsEnabled,
                      pushEnabled: prefs.pushEnabled,
                      mutedCategoriesJson: muted || prefs.mutedCategoriesJson,
                    }).unwrap();
                    setMessage('Preferences saved');
                  }}
                >
                  Save
                </Button>
              </Stack>
            </WorkflowSection>
          )}

          <WorkflowSection title="Push enrollment" subtitle="Keep a test subscription handy while delivery integrations are still being refined.">
            <Stack spacing={1.5}>
              <Typography variant="body2" color="text.secondary">
                This currently stores a demo push subscription payload for local testing and notification UX validation.
              </Typography>
              <Button
                variant="outlined"
                onClick={async () => {
                  await subscribePush({
                    endpoint: `https://push.local/demo/${user?.id ?? 'anon'}`,
                    p256dh: 'demo-p256dh',
                    auth: 'demo-auth',
                    userAgent: navigator.userAgent,
                  }).unwrap();
                  setMessage('Demo push subscription saved');
                }}
              >
                Save demo push subscription
              </Button>
              {metrics && (
                <Paper elevation={0} sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
                  <Typography variant="caption" color="text.secondary" display="block">
                    Delivery metrics
                  </Typography>
                  <Typography variant="body2">
                    Sent {metrics.sent} · Failed {metrics.failed} · Skipped {metrics.skipped} · Unread {metrics.inAppUnread}
                  </Typography>
                </Paper>
              )}
            </Stack>
          </WorkflowSection>
        </Stack>
      </Box>
    </WorkflowPage>
  );
}
