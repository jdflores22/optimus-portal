import { useMemo, useState, type ReactNode } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
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
import RefreshOutlinedIcon from '@mui/icons-material/RefreshOutlined';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import CheckCircleOutlinedIcon from '@mui/icons-material/CheckCircleOutlined';
import CancelOutlinedIcon from '@mui/icons-material/CancelOutlined';
import ScheduleOutlinedIcon from '@mui/icons-material/ScheduleOutlined';
import AssignmentOutlinedIcon from '@mui/icons-material/AssignmentOutlined';
import { Link as RouterLink } from 'react-router-dom';
import type { AccreditationDto } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';
import { TABLE_ACTIONS_HEADER, TableViewLink } from '../shared/TableViewLink';

type ListTab = 'queue' | 'all';

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
    case 'AwaitingFinalApproval':
      return 'Awaiting final approval';
    case 'ComplianceRequired':
      return 'Compliance required';
    default:
      return status;
  }
}

function daysWaiting(submittedAt: string): number {
  const ms = Date.now() - new Date(submittedAt).getTime();
  return Math.max(0, Math.floor(ms / (1000 * 60 * 60 * 24)));
}

type ShippingAdminAccreditationsPanelProps = {
  submissions: AccreditationDto[];
  onRefresh: () => void;
  formBuilder?: ReactNode;
};

export function ShippingAdminAccreditationsPanel({
  submissions,
  onRefresh,
  formBuilder,
}: ShippingAdminAccreditationsPanelProps) {
  const awaitingFinal = useMemo(
    () => submissions.filter((s) => s.status === 'AwaitingFinalApproval'),
    [submissions],
  );
  const approved = useMemo(() => submissions.filter((s) => s.status === 'Approved'), [submissions]);
  const denied = useMemo(
    () => submissions.filter((s) => s.status === 'Denied' || s.status === 'Rejected'),
    [submissions],
  );
  const inPipeline = useMemo(
    () =>
      submissions.filter((s) =>
        ['Pending', 'ComplianceRequired', 'AwaitingFinalApproval'].includes(s.status),
      ),
    [submissions],
  );

  const [tab, setTab] = useState<ListTab>(() => (awaitingFinal.length > 0 ? 'queue' : 'all'));

  return (
    <WorkflowPage
      eyebrow="Shipping Lines Admin"
      title="Accreditations"
      subtitle="Open an application to review the submitted details, then approve or deny from the detail page."
      chips={
        <>
          <Chip size="small" color="warning" label={`${awaitingFinal.length} awaiting final`} />
          <Chip size="small" color="success" label={`${approved.length} approved`} />
        </>
      }
      actions={
        <Button
          variant="outlined"
          startIcon={<RefreshOutlinedIcon />}
          onClick={onRefresh}
          sx={{ textTransform: 'none', fontWeight: 600 }}
        >
          Refresh
        </Button>
      }
    >
      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', md: 'repeat(4, 1fr)' },
        }}
      >
        <MetricCard
          icon={<ScheduleOutlinedIcon />}
          label="Awaiting final"
          value={awaitingFinal.length}
          hint="Forwarded by evaluator"
          tone="warning"
        />
        <MetricCard
          icon={<AssignmentOutlinedIcon />}
          label="In pipeline"
          value={inPipeline.length}
          hint="Pending + compliance + final"
          tone="info"
        />
        <MetricCard
          icon={<CheckCircleOutlinedIcon />}
          label="Approved"
          value={approved.length}
          hint="Final sign-off complete"
          tone="success"
        />
        <MetricCard
          icon={<CancelOutlinedIcon />}
          label="Denied / rejected"
          value={denied.length}
          hint="Not approved"
          tone="error"
        />
      </Box>

      <WorkflowSection
        title="Applications"
        subtitle={
          tab === 'queue'
            ? 'Open View to review the application before approving or denying.'
            : 'Full accreditation history for your shipping line.'
        }
      >
        <Tabs
          value={tab}
          onChange={(_, value: ListTab) => setTab(value)}
          sx={{
            mb: 2,
            minHeight: 40,
            borderBottom: 1,
            borderColor: 'divider',
            '& .MuiTab-root': {
              textTransform: 'none',
              fontWeight: 600,
              minHeight: 40,
              py: 1,
            },
          }}
        >
          <Tab
            value="queue"
            label={
              <Stack direction="row" spacing={1} alignItems="center">
                <span>Final approval queue</span>
                <Chip
                  size="small"
                  color={awaitingFinal.length ? 'warning' : 'default'}
                  label={awaitingFinal.length}
                  sx={{ height: 20, fontWeight: 700, fontSize: 11 }}
                />
              </Stack>
            }
          />
          <Tab
            value="all"
            label={
              <Stack direction="row" spacing={1} alignItems="center">
                <span>All applications</span>
                <Chip
                  size="small"
                  variant="outlined"
                  label={submissions.length}
                  sx={{ height: 20, fontWeight: 700, fontSize: 11 }}
                />
              </Stack>
            }
          />
        </Tabs>

        {tab === 'queue' ? (
          awaitingFinal.length === 0 ? (
            <Alert severity="success" variant="outlined">
              No applications waiting for final approval.
            </Alert>
          ) : (
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Applicant</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell>Shipping line</TableCell>
                  <TableCell>Submitted</TableCell>
                  <TableCell>Waiting</TableCell>
                  <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {awaitingFinal.map((s) => {
                  const waiting = daysWaiting(s.submittedAt);
                  return (
                    <TableRow key={s.id} hover>
                      <TableCell>
                        <Typography fontWeight={700}>{s.applicantName}</Typography>
                      </TableCell>
                      <TableCell>
                        <Chip size="small" variant="outlined" label={s.applicantRole} />
                      </TableCell>
                      <TableCell>{s.shippingLineName}</TableCell>
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
          )
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Applicant</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Submitted</TableCell>
                <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {submissions.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={4}>
                    <Typography variant="body2" color="text.secondary">
                      No submissions yet.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                submissions.map((s) => (
                  <TableRow key={s.id} hover>
                    <TableCell>
                      <Typography fontWeight={600}>{s.applicantName}</Typography>
                      <Typography variant="caption" color="text.secondary" display="block">
                        {s.applicantRole} · {s.shippingLineName}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Chip size="small" label={statusLabel(s.status)} color={statusChipColor(s.status)} />
                    </TableCell>
                    <TableCell>{new Date(s.submittedAt).toLocaleDateString()}</TableCell>
                    <TableCell align="right">
                      <TableViewLink to={`/evaluator/application/${s.id}`} />
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        )}
      </WorkflowSection>

      {formBuilder}
    </WorkflowPage>
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
  tone: 'warning' | 'info' | 'success' | 'error';
}) {
  const color =
    tone === 'warning'
      ? 'warning.main'
      : tone === 'info'
        ? 'info.main'
        : tone === 'success'
          ? 'success.main'
          : 'error.main';

  return (
    <Box
      sx={{
        p: 2,
        border: 1,
        borderColor: 'divider',
        borderRadius: 2,
        bgcolor: 'background.paper',
      }}
    >
      <Stack direction="row" spacing={1.25} alignItems="flex-start">
        <Box sx={{ color, mt: 0.25 }}>{icon}</Box>
        <Box minWidth={0}>
          <Typography variant="caption" color="text.secondary" fontWeight={600}>
            {label}
          </Typography>
          <Typography variant="h5" fontWeight={700} lineHeight={1.2}>
            {value}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {hint}
          </Typography>
        </Box>
      </Stack>
    </Box>
  );
}
