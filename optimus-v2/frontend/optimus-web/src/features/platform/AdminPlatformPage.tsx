import { useState } from 'react';
import {
  Alert,
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
} from '@mui/material';
import {
  useGetDocumentTemplatesQuery,
  useGetMessageTemplatesQuery,
  useGetRateLimitsQuery,
  useGetScheduledReportsQuery,
  useGetSystemSettingsQuery,
  useProcessScheduledReportsMutation,
  useRunMaintenanceMutation,
  useUpsertDocumentTemplateMutation,
  useUpsertMessageTemplateMutation,
  useUpsertRateLimitMutation,
  useUpsertSystemSettingMutation,
} from '../../app/api';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function AdminPlatformPage() {
  const { data: settings = [], refetch: refetchSettings } = useGetSystemSettingsQuery();
  const { data: rates = [], refetch: refetchRates } = useGetRateLimitsQuery();
  const { data: msgTemplates = [], refetch: refetchMsg } = useGetMessageTemplatesQuery();
  const { data: docTemplates = [], refetch: refetchDocs } = useGetDocumentTemplatesQuery();
  const { data: reports = [], refetch: refetchReports } = useGetScheduledReportsQuery();

  const [upsertSetting] = useUpsertSystemSettingMutation();
  const [upsertRate] = useUpsertRateLimitMutation();
  const [upsertMsg] = useUpsertMessageTemplateMutation();
  const [upsertDoc] = useUpsertDocumentTemplateMutation();
  const [processReports] = useProcessScheduledReportsMutation();
  const [runMaintenance] = useRunMaintenanceMutation();

  const [settingKey, setSettingKey] = useState('session.idle_minutes');
  const [settingValue, setSettingValue] = useState('30');
  const [rateName, setRateName] = useState('Custom');
  const [ratePrefix, setRatePrefix] = useState('/api');
  const [rateLimit, setRateLimit] = useState(100);
  const [docType, setDocType] = useState('EDO');
  const [docBody, setDocBody] = useState('<h1>EDO</h1>');
  const [msgKey, setMsgKey] = useState('notify.custom');
  const [msgBody, setMsgBody] = useState('Hello {{name}}: {{message}}');
  const [message, setMessage] = useState<string | null>(null);

  return (
    <WorkflowPage
      eyebrow="Platform controls"
      title="Admin platform"
      subtitle="Manage portal-wide settings, templates, report jobs, and system maintenance from one oversight surface."
      chips={
        <>
          <Chip size="small" label={`${settings.length} settings`} color="primary" />
          <Chip size="small" label={`${reports.length} scheduled reports`} variant="outlined" />
        </>
      }
      actions={
        <>
          <Button
            variant="outlined"
            onClick={async () => {
              const r = await processReports().unwrap();
              setMessage(`Processed ${r.processed} scheduled report(s)`);
              refetchReports();
            }}
          >
            Process scheduled reports
          </Button>
          <Button
            variant="contained"
            color="warning"
            onClick={async () => {
              const r = await runMaintenance().unwrap();
              setMessage(
                `Maintenance: tokens ${r.refreshTokensRemoved}, notifs ${r.notificationsPurged}, deliveries ${r.deliveriesPurged}, files ${r.orphanFilesRemoved}`,
              );
            }}
          >
            Run maintenance
          </Button>
        </>
      }
      stats={[
        { label: 'Settings', value: settings.length, hint: 'System configuration keys', tone: 'primary' },
        { label: 'Rate limits', value: rates.length, hint: 'API protection rules', tone: 'warning' },
        { label: 'Message templates', value: msgTemplates.length, hint: 'Delivery content library', tone: 'info' },
        { label: 'Document templates', value: docTemplates.length, hint: 'Printable artifacts', tone: 'success' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      <WorkflowSection title="System settings" subtitle="Adjust global behavior such as timeouts, flags, and operational defaults.">
        <Stack direction="row" spacing={2} my={2}>
          <TextField size="small" label="Key" value={settingKey} onChange={(e) => setSettingKey(e.target.value)} />
          <TextField
            size="small"
            label="Value"
            value={settingValue}
            onChange={(e) => setSettingValue(e.target.value)}
          />
          <Button
            onClick={async () => {
              await upsertSetting({ key: settingKey, value: settingValue, description: null }).unwrap();
              setMessage('Setting saved');
              refetchSettings();
            }}
          >
            Upsert
          </Button>
        </Stack>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Key</TableCell>
              <TableCell>Value</TableCell>
              <TableCell>Description</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {settings.map((s) => (
              <TableRow key={s.id}>
                <TableCell>{s.key}</TableCell>
                <TableCell>{s.value}</TableCell>
                <TableCell>{s.description}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>

      <WorkflowSection title="Rate limits" subtitle="Protect platform APIs while keeping operational traffic moving.">
        <Stack direction="row" spacing={2} my={2} flexWrap="wrap">
          <TextField size="small" label="Name" value={rateName} onChange={(e) => setRateName(e.target.value)} />
          <TextField
            size="small"
            label="Path prefix"
            value={ratePrefix}
            onChange={(e) => setRatePrefix(e.target.value)}
          />
          <TextField
            size="small"
            type="number"
            label="Permit / min"
            value={rateLimit}
            onChange={(e) => setRateLimit(Number(e.target.value))}
            sx={{ width: 120 }}
          />
          <Button
            onClick={async () => {
              await upsertRate({
                name: rateName,
                pathPrefix: ratePrefix,
                role: null,
                permitLimit: rateLimit,
                windowSeconds: 60,
                isActive: true,
              }).unwrap();
              setMessage('Rate rule saved');
              refetchRates();
            }}
          >
            Add rule
          </Button>
        </Stack>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Name</TableCell>
              <TableCell>Path</TableCell>
              <TableCell>Limit</TableCell>
              <TableCell>Window</TableCell>
              <TableCell>Active</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {rates.map((r) => (
              <TableRow key={r.id}>
                <TableCell>{r.name}</TableCell>
                <TableCell>{r.pathPrefix}</TableCell>
                <TableCell>{r.permitLimit}</TableCell>
                <TableCell>{r.windowSeconds}s</TableCell>
                <TableCell>{r.isActive ? 'Yes' : 'No'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>

      <WorkflowSection title="Message templates" subtitle="Version notification content used by operational alerts and outbound communication.">
        <Stack direction="row" spacing={2} my={2}>
          <TextField size="small" label="Key" value={msgKey} onChange={(e) => setMsgKey(e.target.value)} />
          <TextField size="small" label="Body" value={msgBody} onChange={(e) => setMsgBody(e.target.value)} fullWidth />
          <Button
            onClick={async () => {
              await upsertMsg({
                key: msgKey,
                channel: 'email',
                name: msgKey,
                subject: msgKey,
                body: msgBody,
                isActive: true,
              }).unwrap();
              setMessage('Message template saved');
              refetchMsg();
            }}
          >
            Upsert
          </Button>
        </Stack>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Key</TableCell>
              <TableCell>Channel</TableCell>
              <TableCell>Name</TableCell>
              <TableCell>Active</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {msgTemplates.map((t) => (
              <TableRow key={t.id}>
                <TableCell>{t.key}</TableCell>
                <TableCell>{t.channel}</TableCell>
                <TableCell>{t.name}</TableCell>
                <TableCell>{t.isActive ? 'Yes' : 'No'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>

      <WorkflowSection title="Document templates" subtitle="Maintain the HTML sources for generated documents across workflows.">
        <Stack direction="row" spacing={2} my={2}>
          <TextField select size="small" label="Type" value={docType} onChange={(e) => setDocType(e.target.value)}>
            {['NOA', 'EDO', 'BL', 'Billing', 'OR', 'Certificate'].map((t) => (
              <MenuItem key={t} value={t}>
                {t}
              </MenuItem>
            ))}
          </TextField>
          <TextField size="small" label="HTML" value={docBody} onChange={(e) => setDocBody(e.target.value)} fullWidth />
          <Button
            onClick={async () => {
              await upsertDoc({
                documentType: docType,
                name: `${docType} custom`,
                bodyHtml: docBody,
                isActive: true,
              }).unwrap();
              setMessage('Document template versioned');
              refetchDocs();
            }}
          >
            Publish version
          </Button>
        </Stack>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Type</TableCell>
              <TableCell>Name</TableCell>
              <TableCell>Version</TableCell>
              <TableCell>Active</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {docTemplates.map((t) => (
              <TableRow key={t.id}>
                <TableCell>{t.documentType}</TableCell>
                <TableCell>{t.name}</TableCell>
                <TableCell>{t.version}</TableCell>
                <TableCell>{t.isActive ? 'Yes' : 'No'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>

      <WorkflowSection title="Scheduled reports" subtitle="Monitor the schedule, last execution, and output path for recurring reports.">
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Type</TableCell>
              <TableCell>Cron</TableCell>
              <TableCell>Last run</TableCell>
              <TableCell>Result</TableCell>
              <TableCell>Error</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {reports.map((r) => (
              <TableRow key={r.id}>
                <TableCell>{r.reportType}</TableCell>
                <TableCell>{r.cronExpression}</TableCell>
                <TableCell>{r.lastRunAt ? new Date(r.lastRunAt).toLocaleString() : '-'}</TableCell>
                <TableCell>{r.lastResultPath ?? '-'}</TableCell>
                <TableCell>{r.lastError ?? '-'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>
    </WorkflowPage>
  );
}
