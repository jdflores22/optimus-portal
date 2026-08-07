import { useMemo, type ReactNode } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import PlayArrowOutlinedIcon from '@mui/icons-material/PlayArrowOutlined';
import RefreshOutlinedIcon from '@mui/icons-material/RefreshOutlined';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import ScheduleOutlinedIcon from '@mui/icons-material/ScheduleOutlined';
import CheckCircleOutlinedIcon from '@mui/icons-material/CheckCircleOutlined';
import CancelOutlinedIcon from '@mui/icons-material/CancelOutlined';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import ReplayOutlinedIcon from '@mui/icons-material/ReplayOutlined';
import { Link as RouterLink } from 'react-router-dom';
import type { AccreditationDto } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

function statusChipColor(
  status: string,
): 'default' | 'success' | 'warning' | 'error' | 'info' | 'primary' {
  switch (status) {
    case 'Approved':
      return 'success';
    case 'Pending':
      return 'warning';
    case 'AwaitingFinalApproval':
      return 'info';
    case 'ComplianceRequired':
      return 'warning';
    case 'Denied':
    case 'Rejected':
      return 'error';
    default:
      return 'default';
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'Pending':
      return 'Pending Review';
    case 'AwaitingFinalApproval':
      return 'Awaiting Final Approval';
    case 'ComplianceRequired':
      return 'Compliance Required';
    default:
      return status.replace(/([a-z])([A-Z])/g, '$1 $2');
  }
}

function daysWaiting(submittedAt: string): number {
  const start = new Date(submittedAt).getTime();
  const now = Date.now();
  return Math.max(Math.floor((now - start) / (1000 * 60 * 60 * 24)), 0);
}

function shortId(id: string): string {
  return id.replace(/-/g, '').slice(0, 8).toUpperCase();
}

type Props = {
  submissions: AccreditationDto[];
  onRefresh: () => void;
};

export function EvaluatorApplicationsPanel({ submissions, onRefresh }: Props) {
  const pending = useMemo(
    () => submissions.filter((s) => s.status === 'Pending'),
    [submissions],
  );
  const awaitingCompliance = useMemo(
    () => submissions.filter((s) => s.status === 'ComplianceRequired'),
    [submissions],
  );
  const forwarded = useMemo(
    () =>
      submissions.filter((s) =>
        ['AwaitingFinalApproval', 'Approved'].includes(s.status),
      ),
    [submissions],
  );
  const denied = useMemo(
    () => submissions.filter((s) => ['Denied', 'Rejected'].includes(s.status)),
    [submissions],
  );
  const recentlyEvaluated = useMemo(
    () =>
      [...submissions]
        .filter((s) => s.evaluatedAt)
        .sort(
          (a, b) =>
            new Date(b.evaluatedAt ?? 0).getTime() - new Date(a.evaluatedAt ?? 0).getTime(),
        )
        .slice(0, 5),
    [submissions],
  );
  const resubmitted = useMemo(
    () =>
      submissions.filter(
        (s) =>
          s.status === 'Pending' &&
          Boolean(s.complianceNotes || s.denialReason || s.evaluatedAt),
      ),
    [submissions],
  );

  const firstPending = pending[0] ?? resubmitted[0] ?? null;
  const approvalRate =
    forwarded.length + denied.length === 0
      ? 100
      : Math.round((forwarded.length / (forwarded.length + denied.length)) * 100);

  return (
    <WorkflowPage
      eyebrow="Evaluator Console"
      title="Accreditation Applications"
      subtitle="Review submissions, issue compliance requests, and move qualified applications to final approval."
      chips={
        <>
          <Chip size="small" color="warning" label={`${pending.length} pending`} />
          <Chip size="small" color="success" label={`${approvalRate}% approval rate`} />
        </>
      }
      actions={
        <>
          <Button
            variant="contained"
            startIcon={<PlayArrowOutlinedIcon />}
            component={RouterLink}
            to={firstPending ? `/evaluator/application/${firstPending.id}` : '/sas'}
            disabled={!firstPending}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            Start Reviewing
          </Button>
          <Button
            variant="outlined"
            startIcon={<RefreshOutlinedIcon />}
            onClick={onRefresh}
            sx={{ textTransform: 'none', fontWeight: 600 }}
          >
            Refresh
          </Button>
        </>
      }
    >
      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' },
        }}
      >
        <FocusCard
          icon={<ScheduleOutlinedIcon />}
          tone="warning"
          title={`${pending.length} Pending review`}
          hint="Ready for evaluator action"
        />
        <FocusCard
          icon={<InfoOutlinedIcon />}
          tone="info"
          title={`${awaitingCompliance.length} Awaiting compliance`}
          hint="Waiting on applicant updates"
        />
        <FocusCard
          icon={<ReplayOutlinedIcon />}
          tone="success"
          title={`${resubmitted.length} Resubmitted — review first`}
          hint="Returned applications back in queue"
        />
      </Box>

      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr 1fr', md: 'repeat(4, 1fr)' },
        }}
      >
        <MetricCard
          icon={<DescriptionOutlinedIcon />}
          label="Total Applications"
          value={submissions.length}
          hint="All applications in system"
          tone="primary"
        />
        <MetricCard
          icon={<ScheduleOutlinedIcon />}
          label="Pending Review"
          value={pending.length}
          hint="Awaiting evaluation"
          tone="warning"
        />
        <MetricCard
          icon={<CheckCircleOutlinedIcon />}
          label="Forwarded"
          value={forwarded.length}
          hint="Approved and sent for final sign-off"
          tone="success"
        />
        <MetricCard
          icon={<CancelOutlinedIcon />}
          label="Denied/Rejected"
          value={denied.length}
          hint="Not approved"
          tone="error"
        />
      </Box>

      <WorkflowSection
        title="Review Queue"
        subtitle="Pending applications that need evaluator attention first."
        actions={<Chip size="small" label={`${pending.length} application${pending.length === 1 ? '' : 's'}`} />}
      >
        {pending.length === 0 ? (
          <Alert severity="success" variant="outlined">
            No pending applications right now. All clear.
          </Alert>
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Status</TableCell>
                <TableCell>Applicant</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Company / Consignee</TableCell>
                <TableCell>Submitted</TableCell>
                <TableCell>Waiting</TableCell>
                <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {pending.map((s) => {
                const waiting = daysWaiting(s.submittedAt);
                return (
                  <TableRow key={s.id} hover>
                    <TableCell>
                      <Chip size="small" color="warning" label="Pending" />
                    </TableCell>
                    <TableCell>
                      <Typography fontWeight={700}>{s.applicantName}</Typography>
                      <Typography variant="caption" color="text.secondary" display="block">
                        {s.shippingLineName}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Chip size="small" variant="outlined" label={s.applicantRole} />
                    </TableCell>
                    <TableCell>{s.applicantName}</TableCell>
                    <TableCell>
                      {new Date(s.submittedAt).toLocaleDateString(undefined, {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                      })}
                    </TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        color={waiting >= 7 ? 'error' : 'default'}
                        label={`${waiting} day${waiting === 1 ? '' : 's'}`}
                      />
                    </TableCell>
                    <TableCell align="right">
                      <TableViewLink to={`/evaluator/application/${s.id}`} />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        )}
      </WorkflowSection>

      <WorkflowSection
        title="All Applications"
        subtitle="Review and evaluate accreditation applications across every status."
        actions={<Chip size="small" label={`${submissions.length}`} />}
      >
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Application</TableCell>
              <TableCell>Applicant</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Evaluator</TableCell>
              <TableCell>Submitted</TableCell>
              <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {submissions.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6}>
                  <Typography variant="body2" color="text.secondary">
                    No applications yet.
                  </Typography>
                </TableCell>
              </TableRow>
            ) : (
              submissions.map((s) => (
                  <TableRow key={s.id} hover>
                    <TableCell>
                      <Stack direction="row" spacing={1.25} alignItems="center">
                        <Box
                          sx={{
                            width: 36,
                            height: 36,
                            borderRadius: '50%',
                            bgcolor: 'rgba(11,61,92,0.08)',
                            color: 'primary.main',
                            display: 'grid',
                            placeItems: 'center',
                            flexShrink: 0,
                          }}
                        >
                          <DescriptionOutlinedIcon fontSize="small" />
                        </Box>
                        <Box>
                          <Typography fontWeight={700}>Application #{shortId(s.id)}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {s.applicantRole} Application
                          </Typography>
                        </Box>
                      </Stack>
                    </TableCell>
                    <TableCell>
                      <Typography fontWeight={600}>{s.applicantName}</Typography>
                      <Typography variant="caption" color="text.secondary" display="block">
                        {s.shippingLineName}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        label={statusLabel(s.status)}
                        color={statusChipColor(s.status)}
                      />
                    </TableCell>
                    <TableCell>
                      {s.evaluatedAt ? (
                        <>
                          <Typography fontWeight={600}>Evaluated</Typography>
                          <Typography variant="caption" color="text.secondary" display="block">
                            {new Date(s.evaluatedAt).toLocaleString()}
                          </Typography>
                        </>
                      ) : (
                        <Typography variant="body2" color="text.secondary" fontStyle="italic">
                          Not evaluated
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {new Date(s.submittedAt).toLocaleDateString(undefined, {
                          month: 'short',
                          day: 'numeric',
                          year: 'numeric',
                        })}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {new Date(s.submittedAt).toLocaleTimeString(undefined, {
                          hour: 'numeric',
                          minute: '2-digit',
                        })}
                      </Typography>
                    </TableCell>
                    <TableCell align="right">
                      <TableViewLink to={`/evaluator/application/${s.id}`} />
                    </TableCell>
                  </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </WorkflowSection>

      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' },
        }}
      >
        <WorkflowSection
          title="Awaiting Applicant Compliance"
          subtitle="Applications sent back for corrections or additional information."
        >
          {awaitingCompliance.length === 0 ? (
            <Alert severity="info" variant="outlined">
              No applications are waiting on applicant compliance.
            </Alert>
          ) : (
            <Stack spacing={1.25}>
              {awaitingCompliance.slice(0, 5).map((s) => (
                <Paper
                  key={s.id}
                  elevation={0}
                  sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2 }}
                >
                  <Stack direction="row" justifyContent="space-between" spacing={1.5} alignItems="center">
                    <Box minWidth={0}>
                      <Typography fontWeight={700}>{s.applicantName}</Typography>
                      <Typography variant="body2" color="text.secondary">
                        {s.complianceNotes || 'Compliance required'}
                      </Typography>
                    </Box>
                    <TableViewLink to={`/evaluator/application/${s.id}`} />
                  </Stack>
                </Paper>
              ))}
            </Stack>
          )}
        </WorkflowSection>

        <WorkflowSection
          title="Recently Evaluated"
          subtitle="Latest decisions already completed by the evaluator desk."
        >
          {recentlyEvaluated.length === 0 ? (
            <Alert severity="info" variant="outlined">
              No evaluated applications yet.
            </Alert>
          ) : (
            <Stack spacing={1.25}>
              {recentlyEvaluated.map((s) => (
                <Paper
                  key={s.id}
                  elevation={0}
                  sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2 }}
                >
                  <Stack direction="row" justifyContent="space-between" spacing={1.5} alignItems="center">
                    <Box minWidth={0}>
                      <Typography fontWeight={700}>{s.applicantName}</Typography>
                      <Stack direction="row" spacing={1} alignItems="center" mt={0.5}>
                        <Chip
                          size="small"
                          label={statusLabel(s.status)}
                          color={statusChipColor(s.status)}
                        />
                        <Typography variant="caption" color="text.secondary">
                          {s.evaluatedAt ? new Date(s.evaluatedAt).toLocaleString() : ''}
                        </Typography>
                      </Stack>
                    </Box>
                    <TableViewLink to={`/evaluator/application/${s.id}`} />
                  </Stack>
                </Paper>
              ))}
            </Stack>
          )}
        </WorkflowSection>
      </Box>
    </WorkflowPage>
  );
}

function FocusCard({
  icon,
  title,
  hint,
  tone,
}: {
  icon: ReactNode;
  title: string;
  hint: string;
  tone: 'warning' | 'info' | 'success';
}) {
  const color =
    tone === 'warning' ? '#EF6C00' : tone === 'info' ? '#0277BD' : '#2E7D32';
  const bg =
    tone === 'warning'
      ? 'rgba(239,108,0,0.08)'
      : tone === 'info'
        ? 'rgba(2,119,189,0.08)'
        : 'rgba(46,125,50,0.08)';

  return (
    <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
      <Stack direction="row" spacing={1.5} alignItems="flex-start">
        <Box
          sx={{
            width: 40,
            height: 40,
            borderRadius: 2,
            bgcolor: bg,
            color,
            display: 'grid',
            placeItems: 'center',
            flexShrink: 0,
          }}
        >
          {icon}
        </Box>
        <Box>
          <Typography fontWeight={800}>{title}</Typography>
          <Typography variant="body2" color="text.secondary">
            {hint}
          </Typography>
        </Box>
      </Stack>
    </Paper>
  );
}

function MetricCard({
  icon,
  label,
  value,
  hint,
  tone,
}: {
  icon: ReactNode;
  label: string;
  value: number;
  hint: string;
  tone: 'primary' | 'warning' | 'success' | 'error';
}) {
  const color =
    tone === 'primary'
      ? '#0B3D5C'
      : tone === 'warning'
        ? '#EF6C00'
        : tone === 'success'
          ? '#2E7D32'
          : '#C62828';
  const bg =
    tone === 'primary'
      ? 'rgba(11,61,92,0.08)'
      : tone === 'warning'
        ? 'rgba(239,108,0,0.08)'
        : tone === 'success'
          ? 'rgba(46,125,50,0.08)'
          : 'rgba(198,40,40,0.08)';

  return (
    <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
      <Box
        sx={{
          width: 40,
          height: 40,
          borderRadius: 2,
          bgcolor: bg,
          color,
          display: 'grid',
          placeItems: 'center',
          mb: 1.25,
        }}
      >
        {icon}
      </Box>
      <Typography variant="caption" color="text.secondary" fontWeight={600}>
        {label}
      </Typography>
      <Typography variant="h4" fontWeight={800} sx={{ color, mt: 0.5 }}>
        {value}
      </Typography>
      <Typography variant="caption" color="text.secondary">
        {hint}
      </Typography>
    </Paper>
  );
}
