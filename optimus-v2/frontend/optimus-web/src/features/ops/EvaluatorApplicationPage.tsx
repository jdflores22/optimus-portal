import { FormEvent, useMemo, useState, type ReactNode } from 'react';
import { useTheme } from '@mui/material/styles';
import { pageHeroGradient } from '../../shared/theme';
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormControlLabel,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import GavelOutlinedIcon from '@mui/icons-material/GavelOutlined';
import PersonOutlinedIcon from '@mui/icons-material/PersonOutlined';
import CheckCircleOutlinedIcon from '@mui/icons-material/CheckCircleOutlined';
import { Link as RouterLink, useNavigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import {
  useEvaluatorAccreditationMutation,
  useFinalAccreditationMutation,
  useGetAccreditationQuery,
  useGetFormsQuery,
} from '../../app/api';
import {
  buildSubmissionPreview,
  getSubmissionSummary,
  parseFormFields,
} from '../../shared/formSchema';
import { SubmissionDetailsPreview } from './SubmissionDetailsPreview';
import type { DynamicFormValues } from './DynamicFormFields';
import type { RootState } from '../../app/store';

function statusChipColor(
  status: string,
): 'default' | 'success' | 'warning' | 'error' | 'info' | 'primary' {
  switch (status) {
    case 'Approved':
      return 'success';
    case 'Pending':
    case 'AwaitingFinalApproval':
      return 'warning';
    case 'ComplianceRequired':
      return 'info';
    case 'Denied':
    case 'Rejected':
      return 'error';
    default:
      return 'default';
  }
}

function decisionLabel(decision: string): string {
  switch (decision) {
    case 'approve':
      return 'Approve — Forward to Admin';
    case 'deny':
      return 'Deny — Reject application';
    case 'reject':
      return 'Reject — Invalid submission';
    case 'compliance':
      return 'Compliance Required — Request info';
    default:
      return decision;
  }
}

function parseSubmittedValues(json: string): DynamicFormValues {
  const initial: DynamicFormValues = {};
  try {
    const prev = JSON.parse(json) as Record<string, unknown>;
    Object.entries(prev).forEach(([k, v]) => {
      if (Array.isArray(v)) initial[k] = v.map(String);
      else if (typeof v === 'boolean') initial[k] = v;
      else if (v != null && typeof v === 'object') initial[k] = JSON.stringify(v);
      else if (v != null) initial[k] = String(v);
    });
  } catch {
    /* ignore */
  }
  return initial;
}

function parseComplianceFieldIds(json?: string | null): string[] {
  if (!json) return [];
  try {
    const parsed = JSON.parse(json) as unknown;
    if (Array.isArray(parsed)) {
      return parsed
        .map((item) => (typeof item === 'string' ? item : (item as { id?: string })?.id))
        .filter((id): id is string => Boolean(id));
    }
  } catch {
    /* ignore */
  }
  return [];
}

function stepperIndex(status: string): number {
  // Past last step (4) so every chip — including the terminal one — renders as completed.
  if (status === 'Approved' || status === 'Denied' || status === 'Rejected') return 5;
  if (status === 'AwaitingFinalApproval') return 3;
  if (status === 'Pending' || status === 'ComplianceRequired') return 2;
  return 1;
}

export function EvaluatorApplicationPage() {
  const theme = useTheme();
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const role = useSelector((state: RootState) => state.auth.user?.role ?? '');
  const listPath = role === 'ShippingLinesAdmin' ? '/approvals' : '/sas';
  const { data: submission, isLoading, isError, refetch } = useGetAccreditationQuery(id, {
    skip: !id,
  });
  const { data: forms = [] } = useGetFormsQuery();
  const [evaluator, { isLoading: submitting }] = useEvaluatorAccreditationMutation();
  const [finalDecision, { isLoading: finalizing }] = useFinalAccreditationMutation();

  const [decision, setDecision] = useState('');
  const [notes, setNotes] = useState('');
  const [flaggedFields, setFlaggedFields] = useState<Record<string, boolean>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reviewOpen, setReviewOpen] = useState(false);
  const [finalConfirm, setFinalConfirm] = useState<'approve' | 'deny' | null>(null);

  const formConfig = useMemo(
    () => forms.find((f) => f.id === submission?.formConfigurationId) ?? null,
    [forms, submission?.formConfigurationId],
  );
  const fields = useMemo(
    () => (formConfig ? parseFormFields(formConfig.fieldsJson) : []),
    [formConfig],
  );
  const values = useMemo(
    () => (submission ? parseSubmittedValues(submission.submittedDataJson) : {}),
    [submission],
  );
  const summary = useMemo(() => getSubmissionSummary(fields, values), [fields, values]);
  const previewRows = useMemo(() => buildSubmissionPreview(fields, values), [fields, values]);
  const fieldRows = previewRows.filter((row) => row.kind === 'field');
  const existingFlagged = useMemo(
    () => parseComplianceFieldIds(submission?.complianceFieldIdsJson),
    [submission?.complianceFieldIdsJson],
  );

  const canEvaluate =
    submission != null && ['Pending', 'ComplianceRequired'].includes(submission.status);
  const canFinalApprove =
    submission != null &&
    submission.status === 'AwaitingFinalApproval' &&
    role === 'ShippingLinesAdmin';
  const showCompliancePicker = decision === 'compliance';
  const currentStep = submission ? stepperIndex(submission.status) : 2;

  const toggleFlag = (fieldId: string) => {
    setFlaggedFields((current) => ({ ...current, [fieldId]: !current[fieldId] }));
  };

  const selectedFieldIds = useMemo(
    () =>
      Object.entries(flaggedFields)
        .filter(([, flagged]) => flagged)
        .map(([fieldId]) => fieldId),
    [flaggedFields],
  );

  const flaggedFieldRows = useMemo(
    () => fieldRows.filter((row) => row.kind === 'field' && selectedFieldIds.includes(row.id)),
    [fieldRows, selectedFieldIds],
  );

  const validateEvaluation = (): string | null => {
    if (!submission || !decision) return 'Select a decision before submitting.';
    if ((decision === 'deny' || decision === 'reject') && !notes.trim()) {
      return 'Comments are required for deny and reject decisions.';
    }
    if (decision === 'compliance' && selectedFieldIds.length === 0) {
      return 'Mark at least one field that requires correction for compliance.';
    }
    return null;
  };

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setMessage(null);
    const validationError = validateEvaluation();
    if (validationError) {
      setError(validationError);
      return;
    }
    setError(null);
    setReviewOpen(true);
  };

  const confirmEvaluation = async () => {
    setMessage(null);
    setError(null);
    const validationError = validateEvaluation();
    if (validationError || !submission) {
      setError(validationError ?? 'Application not found.');
      setReviewOpen(false);
      return;
    }

    try {
      await evaluator({
        id: submission.id,
        action: decision,
        notes: notes.trim() || undefined,
        complianceFieldIdsJson:
          decision === 'compliance' ? JSON.stringify(selectedFieldIds) : undefined,
      }).unwrap();
      setReviewOpen(false);
      setMessage('Evaluation submitted.');
      setDecision('');
      setNotes('');
      setFlaggedFields({});
      await refetch();
      navigate(listPath);
    } catch {
      setError('Failed to submit evaluation.');
      setReviewOpen(false);
    }
  };

  if (isLoading) {
    return (
      <Stack alignItems="center" py={8}>
        <CircularProgress />
      </Stack>
    );
  }

  if (isError || !submission) {
    return (
      <Stack spacing={2}>
        <Alert severity="error">Application not found.</Alert>
        <Button component={RouterLink} to={listPath} startIcon={<ArrowBackOutlinedIcon />}>
          Back to applications
        </Button>
      </Stack>
    );
  }

  const steps = [
    { label: 'Application', index: 1 },
    { label: 'Evaluator Review', index: 2 },
    { label: 'Final Approval', index: 3 },
    {
      label:
        submission.status === 'Approved'
          ? 'Approved'
          : submission.status === 'Denied' || submission.status === 'Rejected'
            ? 'Decision'
            : 'Complete',
      index: 4,
    },
  ];

  return (
    <Stack spacing={3}>
      <Paper
        elevation={0}
        sx={{
          p: { xs: 2.5, sm: 3 },
          border: 1,
          borderColor: 'divider',
          borderRadius: 3,
          background: pageHeroGradient(theme.palette.mode),
        }}
      >
        <Stack
          direction={{ xs: 'column', md: 'row' }}
          justifyContent="space-between"
          spacing={2}
          alignItems={{ md: 'flex-start' }}
        >
          <Stack direction="row" spacing={1.5} alignItems="flex-start">
            <Button
              component={RouterLink}
              to={listPath}
              size="small"
              sx={{ minWidth: 36, px: 1, mt: 0.25 }}
            >
              <ArrowBackOutlinedIcon fontSize="small" />
            </Button>
            <Box>
              <Typography variant="caption" color="text.secondary">
                Dashboard / Applications / #{submission.id.slice(0, 8)}
              </Typography>
              <Typography variant="h4" fontWeight={800} mt={0.5}>
                Application Review
              </Typography>
              <Typography color="text.secondary" mt={0.75}>
                Submitted {new Date(submission.submittedAt).toLocaleString()} ·{' '}
                {submission.shippingLineName}
              </Typography>
            </Box>
          </Stack>
          <Stack direction="row" spacing={1} alignItems="center">
            <Chip
              size="small"
              label={submission.status.replace(/([a-z])([A-Z])/g, '$1 $2')}
              color={statusChipColor(submission.status)}
            />
          </Stack>
        </Stack>
      </Paper>

      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      {canEvaluate && submission.status === 'Pending' && (
        <Alert severity="warning" icon={<GavelOutlinedIcon />}>
          <Typography fontWeight={700}>Pending Your Evaluation</Typography>
          <Typography variant="body2">
            Review the applicant information below. When requesting compliance, mark the specific
            fields that need correction directly on each field card.
          </Typography>
        </Alert>
      )}

      <Box
        sx={{
          display: 'grid',
          gap: 3,
          gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 1fr) 320px' },
          alignItems: 'start',
        }}
      >
        <Stack spacing={3}>
          <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Box
              sx={{
                display: 'grid',
                gap: 1,
                gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
              }}
            >
              {steps.map((step) => {
                const done = currentStep > step.index;
                const active = currentStep === step.index;
                return (
                  <Paper
                    key={step.label}
                    elevation={0}
                    sx={{
                      p: 1.5,
                      border: 1,
                      borderColor: active ? 'primary.main' : done ? 'success.light' : 'divider',
                      borderRadius: 2,
                      bgcolor: active
                        ? 'action.selected'
                        : done
                          ? (t) =>
                              t.palette.mode === 'dark'
                                ? 'rgba(102,187,106,0.12)'
                                : 'rgba(46,125,50,0.06)'
                          : 'background.paper',
                    }}
                  >
                    <Stack direction="row" spacing={1} alignItems="center">
                      <Box
                        sx={{
                          width: 24,
                          height: 24,
                          borderRadius: '50%',
                          display: 'grid',
                          placeItems: 'center',
                          bgcolor: done ? 'success.main' : active ? 'primary.main' : 'action.hover',
                          color: done || active ? 'common.white' : 'text.secondary',
                          fontSize: 12,
                          fontWeight: 700,
                        }}
                      >
                        {done ? <CheckCircleOutlinedIcon sx={{ fontSize: 16 }} /> : step.index}
                      </Box>
                      <Typography fontWeight={700} fontSize="0.85rem">
                        {step.label}
                      </Typography>
                    </Stack>
                  </Paper>
                );
              })}
            </Box>
          </Paper>

          <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Stack direction="row" spacing={1} alignItems="center" mb={2}>
              <PersonOutlinedIcon color="primary" fontSize="small" />
              <Typography variant="h6" fontWeight={700}>
                Applicant Information
              </Typography>
            </Stack>
            <Box
              sx={{
                display: 'grid',
                gap: 1.5,
                gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)' },
              }}
            >
              <InfoTile label="Applicant" value={submission.applicantName} />
              <InfoTile
                label="Role"
                value={
                  <Chip size="small" color="primary" variant="outlined" label={submission.applicantRole} />
                }
              />
              <InfoTile label="Shipping line" value={submission.shippingLineName} />
              <InfoTile
                label="Account status"
                value={
                  <Chip
                    size="small"
                    label={submission.status.replace(/([a-z])([A-Z])/g, '$1 $2')}
                    color={statusChipColor(submission.status)}
                  />
                }
              />
              <InfoTile
                label="Submitted"
                value={new Date(submission.submittedAt).toLocaleString()}
              />
              <InfoTile
                label="Form version"
                value={formConfig ? `v${formConfig.version}` : 'Unavailable'}
              />
            </Box>
          </Paper>

          <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <Typography variant="h6" fontWeight={700} mb={0.5}>
              Submitted Application
            </Typography>
            <Typography variant="body2" color="text.secondary" mb={2}>
              Review each submitted field. For compliance requests, mark fields that need
              correction.
            </Typography>

            {showCompliancePicker && fieldRows.length > 0 ? (
              <Stack spacing={1.25}>
                {fieldRows.map((row) => {
                  if (row.kind !== 'field') return null;
                  const flagged = Boolean(flaggedFields[row.id]);
                  return (
                    <Paper
                      key={row.id}
                      elevation={0}
                      sx={{
                        p: 1.75,
                        border: 1,
                        borderColor: flagged ? 'warning.main' : 'divider',
                        borderRadius: 2,
                        bgcolor: flagged ? 'rgba(239,108,0,0.04)' : 'background.paper',
                      }}
                    >
                      <Stack
                        direction={{ xs: 'column', sm: 'row' }}
                        justifyContent="space-between"
                        spacing={1.5}
                      >
                        <Box minWidth={0}>
                          <Typography variant="caption" color="text.secondary" fontWeight={700}>
                            {row.label}
                          </Typography>
                          <Typography fontWeight={600} sx={{ wordBreak: 'break-word' }}>
                            {row.value}
                          </Typography>
                        </Box>
                        <FormControlLabel
                          control={
                            <Checkbox
                              checked={flagged}
                              onChange={() => toggleFlag(row.id)}
                              color="warning"
                            />
                          }
                          label="Requires correction"
                          sx={{ flexShrink: 0 }}
                        />
                      </Stack>
                    </Paper>
                  );
                })}
              </Stack>
            ) : (
              <SubmissionDetailsPreview fields={fields} values={values} />
            )}

            {existingFlagged.length > 0 && !showCompliancePicker && (
              <Alert severity="info" sx={{ mt: 2 }}>
                Previously flagged fields: {existingFlagged.length}
              </Alert>
            )}
          </Paper>
        </Stack>

        <Paper
          elevation={0}
          sx={{
            p: 2.5,
            border: 1,
            borderColor: 'divider',
            borderRadius: 2,
            position: { lg: 'sticky' },
            top: { lg: 88 },
          }}
        >
          {canEvaluate ? (
            <Stack component="form" onSubmit={onSubmit} spacing={2.5}>
              <Box>
                <Stack direction="row" spacing={1} alignItems="center">
                  <GavelOutlinedIcon color="warning" fontSize="small" />
                  <Typography variant="h6" fontWeight={700}>
                    Evaluate Application
                  </Typography>
                </Stack>
                <Typography variant="caption" color="text.secondary">
                  Application #{submission.id.slice(0, 8)}
                </Typography>
              </Box>

              <Paper elevation={0} sx={{ p: 1.75, bgcolor: 'background.default', borderRadius: 2 }}>
                <Stack spacing={1}>
                  <Stack direction="row" justifyContent="space-between">
                    <Typography variant="body2" color="text.secondary">
                      Documents
                    </Typography>
                    <Typography fontWeight={700}>{summary.documentCount}</Typography>
                  </Stack>
                  <Stack direction="row" justifyContent="space-between">
                    <Typography variant="body2" color="text.secondary">
                      Form version
                    </Typography>
                    <Typography fontWeight={700}>
                      {formConfig ? `v${formConfig.version}` : '—'}
                    </Typography>
                  </Stack>
                </Stack>
              </Paper>

              <FormControl fullWidth required>
                <InputLabel id="decision-label">Decision</InputLabel>
                <Select
                  labelId="decision-label"
                  label="Decision"
                  value={decision}
                  onChange={(e) => setDecision(e.target.value)}
                >
                  <MenuItem value="">
                    <em>Select a decision...</em>
                  </MenuItem>
                  <MenuItem value="approve">Approve — Forward to Admin</MenuItem>
                  <MenuItem value="deny">Deny — Reject application</MenuItem>
                  <MenuItem value="reject">Reject — Invalid submission</MenuItem>
                  <MenuItem value="compliance">Compliance Required — Request info</MenuItem>
                </Select>
              </FormControl>

              {showCompliancePicker && (
                <Alert severity="warning" variant="outlined">
                  Mark <strong>Requires correction</strong> on each field in the submitted
                  application, then add optional overall comments.
                </Alert>
              )}

              <TextField
                label="General comments"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                multiline
                minRows={3}
                fullWidth
                required={decision === 'deny' || decision === 'reject'}
                helperText="Required for denials and rejections. Optional for compliance if field notes are provided."
                placeholder="Optional overall instructions for the applicant..."
              />

              <Button type="submit" variant="contained" fullWidth disabled={submitting || !decision}>
                {submitting ? 'Submitting...' : 'Submit Evaluation'}
              </Button>
              <Button component={RouterLink} to={listPath} fullWidth>
                Cancel
              </Button>
            </Stack>
          ) : canFinalApprove ? (
            <Stack spacing={2.5}>
              <Box>
                <Stack direction="row" spacing={1} alignItems="center">
                  <GavelOutlinedIcon color="warning" fontSize="small" />
                  <Typography variant="h6" fontWeight={700}>
                    Final approval
                  </Typography>
                </Stack>
                <Typography variant="caption" color="text.secondary">
                  Evaluator forwarded this application for your decision.
                </Typography>
              </Box>
              <TextField
                label="Notes (required to deny)"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                multiline
                minRows={3}
                fullWidth
                disabled={finalizing}
              />
              <Button
                variant="contained"
                color="success"
                fullWidth
                disabled={finalizing}
                onClick={() => {
                  setError(null);
                  setFinalConfirm('approve');
                }}
              >
                Approve
              </Button>
              <Button
                variant="outlined"
                color="error"
                fullWidth
                disabled={finalizing || !notes.trim()}
                onClick={() => {
                  setError(null);
                  setFinalConfirm('deny');
                }}
              >
                Deny
              </Button>
              <Button component={RouterLink} to={listPath} fullWidth disabled={finalizing}>
                Back to queue
              </Button>
            </Stack>
          ) : (
            <Stack spacing={2.5}>
              <Typography variant="h6" fontWeight={700}>
                Evaluation Status
              </Typography>
              <Paper
                elevation={0}
                sx={{
                  p: 3,
                  textAlign: 'center',
                  bgcolor: 'background.default',
                  borderRadius: 2,
                }}
              >
                <Chip
                  label={submission.status.replace(/([a-z])([A-Z])/g, '$1 $2')}
                  color={statusChipColor(submission.status)}
                  sx={{ mb: 1.5 }}
                />
                <Typography fontWeight={700}>{submission.status}</Typography>
              </Paper>

              <Stack spacing={1.25}>
                <TimelineItem
                  label="Submitted"
                  detail={new Date(submission.submittedAt).toLocaleString()}
                />
                {submission.evaluatedAt && (
                  <TimelineItem
                    label="Evaluated"
                    detail={new Date(submission.evaluatedAt).toLocaleString()}
                  />
                )}
                {submission.approvedAt && (
                  <TimelineItem
                    label="Approved"
                    detail={new Date(submission.approvedAt).toLocaleString()}
                  />
                )}
              </Stack>

              {submission.complianceNotes && (
                <Alert severity="warning">{submission.complianceNotes}</Alert>
              )}
              {submission.denialReason && <Alert severity="error">{submission.denialReason}</Alert>}

              <Button
                component={RouterLink}
                to={listPath}
                startIcon={<ArrowBackOutlinedIcon />}
                fullWidth
              >
                Back to Applications
              </Button>
            </Stack>
          )}
        </Paper>
      </Box>

      <Dialog
        open={Boolean(finalConfirm)}
        onClose={() => {
          if (!finalizing) setFinalConfirm(null);
        }}
        fullWidth
        maxWidth="sm"
      >
        <DialogTitle>
          {finalConfirm === 'approve' ? 'Confirm final approval' : 'Confirm denial'}
        </DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2}>
            <Alert
              severity={finalConfirm === 'approve' ? 'success' : 'warning'}
              variant="outlined"
            >
              {finalConfirm === 'approve'
                ? 'This will approve the accreditation and activate the applicant account.'
                : 'This will deny the accreditation. The applicant will be notified.'}
            </Alert>
            <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
              <Typography variant="overline" color="text.secondary">
                Applicant
              </Typography>
              <Typography fontWeight={700}>{submission.applicantName}</Typography>
              <Typography variant="body2" color="text.secondary">
                {submission.applicantRole} · {submission.shippingLineName}
              </Typography>
            </Paper>
            {finalConfirm === 'deny' && notes.trim() && (
              <Box>
                <Typography variant="overline" color="text.secondary">
                  Denial reason
                </Typography>
                <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                  {notes.trim()}
                </Typography>
              </Box>
            )}
            {finalizing && (
              <Stack direction="row" spacing={1.5} alignItems="center" justifyContent="center" py={1}>
                <CircularProgress size={22} />
                <Typography variant="body2" color="text.secondary">
                  {finalConfirm === 'approve' ? 'Recording approval...' : 'Recording denial...'}
                </Typography>
              </Stack>
            )}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setFinalConfirm(null)} disabled={finalizing}>
            Cancel
          </Button>
          <Button
            variant="contained"
            color={finalConfirm === 'approve' ? 'success' : 'error'}
            disabled={finalizing || (finalConfirm === 'deny' && !notes.trim())}
            startIcon={finalizing ? <CircularProgress size={16} color="inherit" /> : undefined}
            onClick={async () => {
              if (!finalConfirm) return;
              try {
                setError(null);
                await finalDecision({
                  id: submission.id,
                  approve: finalConfirm === 'approve',
                  notes: finalConfirm === 'deny' ? notes.trim() : undefined,
                }).unwrap();
                setMessage(
                  finalConfirm === 'approve'
                    ? 'Final approval recorded.'
                    : 'Application denied.',
                );
                setFinalConfirm(null);
                await refetch();
                navigate(listPath);
              } catch (e) {
                setError(e instanceof Error ? e.message : 'Final decision failed.');
                setFinalConfirm(null);
              }
            }}
          >
            {finalizing
              ? 'Please wait...'
              : finalConfirm === 'approve'
                ? 'Confirm approve'
                : 'Confirm deny'}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={reviewOpen}
        onClose={() => (submitting ? null : setReviewOpen(false))}
        fullWidth
        maxWidth="md"
      >
        <DialogTitle>Review evaluation details</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            <Alert severity="info" variant="outlined">
              Validate the decision and flagged fields below before confirming this evaluation.
            </Alert>

            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
              <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
                <Typography variant="overline" color="text.secondary">
                  Applicant
                </Typography>
                <Typography fontWeight={700}>{submission.applicantName}</Typography>
                <Typography variant="body2" color="text.secondary">
                  {submission.applicantRole} · {submission.shippingLineName}
                </Typography>
              </Paper>
              <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
                <Typography variant="overline" color="text.secondary">
                  Decision
                </Typography>
                <Typography fontWeight={700}>{decisionLabel(decision)}</Typography>
                <Typography variant="body2" color="text.secondary">
                  Application #{submission.id.slice(0, 8)}
                </Typography>
              </Paper>
            </Box>

            <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
              <Typography variant="overline" color="text.secondary">
                General comments
              </Typography>
              <Typography fontWeight={600} sx={{ whiteSpace: 'pre-wrap' }}>
                {notes.trim() || 'No comments provided'}
              </Typography>
            </Paper>

            {decision === 'compliance' && (
              <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
                <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1.5}>
                  <Typography variant="overline" color="text.secondary">
                    Fields requiring correction
                  </Typography>
                  <Chip
                    size="small"
                    color="warning"
                    label={`${flaggedFieldRows.length} field${flaggedFieldRows.length === 1 ? '' : 's'}`}
                  />
                </Stack>
                <Stack spacing={1}>
                  {flaggedFieldRows.map((row) =>
                    row.kind === 'field' ? (
                      <Paper
                        key={row.id}
                        elevation={0}
                        sx={{
                          p: 1.5,
                          border: 1,
                          borderColor: 'warning.main',
                          borderRadius: 2,
                          bgcolor: 'rgba(239,108,0,0.04)',
                        }}
                      >
                        <Typography variant="caption" color="text.secondary" fontWeight={700}>
                          {row.label}
                        </Typography>
                        <Typography fontWeight={600}>{row.value}</Typography>
                      </Paper>
                    ) : null,
                  )}
                </Stack>
              </Paper>
            )}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setReviewOpen(false)} disabled={submitting}>
            Back to edit
          </Button>
          <Button onClick={confirmEvaluation} variant="contained" disabled={submitting}>
            {submitting ? 'Submitting...' : 'Confirm Submit Evaluation'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

function InfoTile({ label, value }: { label: string; value: ReactNode }) {
  return (
    <Paper elevation={0} sx={{ p: 1.75, bgcolor: 'background.default', borderRadius: 2 }}>
      <Typography variant="caption" color="text.secondary" fontWeight={700} display="block" mb={0.5}>
        {label}
      </Typography>
      {typeof value === 'string' ? <Typography fontWeight={700}>{value}</Typography> : value}
    </Paper>
  );
}

function TimelineItem({ label, detail }: { label: string; detail: string }) {
  return (
    <Paper elevation={0} sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
      <Typography fontWeight={700}>{label}</Typography>
      <Typography variant="caption" color="text.secondary">
        {detail}
      </Typography>
    </Paper>
  );
}
