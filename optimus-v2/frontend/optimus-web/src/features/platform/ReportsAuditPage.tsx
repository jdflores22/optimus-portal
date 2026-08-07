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
  Typography,
} from '@mui/material';
import {
  useExportEdoReleaseMetricsMutation,
  useGetActivityLogsQuery,
  useGetEdoReleaseMetricsQuery,
  useGetManifestAuditQuery,
  useGetEdoAuditQuery,
} from '../../app/api';
import { API_BASE_URL } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function ReportsAuditPage() {
  const { data: metrics, refetch } = useGetEdoReleaseMetricsQuery();
  const [exportMetrics] = useExportEdoReleaseMetricsMutation();
  const [entityType, setEntityType] = useState('');
  const [manifestId, setManifestId] = useState('');
  const [edoId, setEdoId] = useState('');
  const { data: activity = [] } = useGetActivityLogsQuery({ entityType: entityType || undefined });
  const { data: manifestAudit = [] } = useGetManifestAuditQuery(manifestId, { skip: !manifestId });
  const { data: edoAudit = [] } = useGetEdoAuditQuery(edoId, { skip: !edoId });
  const [csv, setCsv] = useState<string | null>(null);
  const [pdf, setPdf] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  return (
    <WorkflowPage
      eyebrow="Oversight and traceability"
      title="Reports and audit"
      subtitle="Review release metrics, activity history, and manifest or eDO audit trails from one governance surface."
      chips={
        <>
          <Chip size="small" label={entityType || 'All activity'} color="primary" />
          {pdf && <Chip size="small" label="Export ready" color="success" />}
        </>
      }
      actions={
        <>
          <Button onClick={() => refetch()}>Refresh</Button>
          <Button
            variant="contained"
            onClick={async () => {
              const r = await exportMetrics().unwrap();
              setCsv(r.csv);
              setPdf(r.pdfPath);
              setMessage('Export ready');
            }}
          >
            Export
          </Button>
          {pdf && (
            <Button href={`${API_BASE_URL}${pdf}`} target="_blank">
              Open PDF
            </Button>
          )}
        </>
      }
      stats={[
        { label: 'Generated', value: metrics?.totalGenerated ?? '—', hint: 'Total eDO documents', tone: 'primary' },
        { label: 'Released', value: metrics?.totalReleased ?? '—', hint: 'Successful handoffs', tone: 'success' },
        { label: 'Rejected', value: metrics?.totalRejected ?? '—', hint: 'Requires follow-up', tone: 'warning' },
        { label: 'Expired', value: metrics?.totalExpired ?? '—', hint: 'Aged release documents', tone: 'error' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}

      <WorkflowSection title="eDO release metrics" subtitle="High-level performance view of the release pipeline.">
        {metrics && (
          <Typography variant="body2">
            Generated {metrics.totalGenerated} · Released {metrics.totalReleased} · Rejected{' '}
            {metrics.totalRejected} · Expired {metrics.totalExpired} · 7d {metrics.releasedLast7Days}{' '}
            · 30d {metrics.releasedLast30Days} · Avg hrs {metrics.avgHoursToRelease}
          </Typography>
        )}
        {csv && (
          <Typography variant="caption" component="pre" display="block" mt={1}>
            {csv}
          </Typography>
        )}
      </WorkflowSection>

      <WorkflowSection title="Activity log" subtitle="Filter platform events by entity family to trace operator activity.">
        <TextField
          select
          size="small"
          label="Entity type"
          value={entityType}
          onChange={(e) => setEntityType(e.target.value)}
          sx={{ minWidth: 200, mb: 2 }}
        >
          <MenuItem value="">All</MenuItem>
          <MenuItem value="Manifest">Manifest</MenuItem>
          <MenuItem value="ElectronicDeliveryOrder">eDO</MenuItem>
          <MenuItem value="Terminal">Terminal</MenuItem>
        </TextField>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Action</TableCell>
              <TableCell>Entity</TableCell>
              <TableCell>Actor</TableCell>
              <TableCell>When</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {activity.slice(0, 40).map((a) => (
              <TableRow key={a.id}>
                <TableCell>{a.action}</TableCell>
                <TableCell>
                  {a.entityType} {a.entityId?.slice(0, 8)}
                </TableCell>
                <TableCell>{a.actorName}</TableCell>
                <TableCell>{new Date(a.createdAt).toLocaleString()}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>

      <WorkflowSection title="Manifest and eDO audit" subtitle="Inspect state-change history for a specific manifest or eDO identifier.">
        <Stack direction="row" spacing={2} mb={2}>
          <TextField
            size="small"
            label="Manifest Id"
            value={manifestId}
            onChange={(e) => setManifestId(e.target.value)}
            fullWidth
          />
          <TextField
            size="small"
            label="eDO Id"
            value={edoId}
            onChange={(e) => setEdoId(e.target.value)}
            fullWidth
          />
        </Stack>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Source</TableCell>
              <TableCell>Event</TableCell>
              <TableCell>From → To</TableCell>
              <TableCell>Actor</TableCell>
              <TableCell>When</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {[...manifestAudit, ...edoAudit].map((a, i) => (
              <TableRow key={`${a.source}-${a.at}-${i}`}>
                <TableCell>{a.source}</TableCell>
                <TableCell>{a.event}</TableCell>
                <TableCell>
                  {a.from ?? '-'} → {a.to ?? '-'}
                </TableCell>
                <TableCell>{a.actor}</TableCell>
                <TableCell>{new Date(a.at).toLocaleString()}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>
    </WorkflowPage>
  );
}
