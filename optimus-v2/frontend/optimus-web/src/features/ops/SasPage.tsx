import { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import SendOutlinedIcon from '@mui/icons-material/SendOutlined';
import { Link as RouterLink, Navigate } from 'react-router-dom';
import { useSelector } from 'react-redux';
import {
  useCompleteOnboardingStepMutation,
  useGetAccreditationsQuery,
  useGetActiveFormQuery,
  useGetFormsQuery,
  useGetRelationshipsQuery,
  useSubmitAccreditationMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import type { AccreditationDto, FormConfigurationDto, SasFormField } from '../../shared/types';
import {
  collectValidationErrors,
  formatFieldDisplayValue,
  getSubmissionSummary,
  isFileType,
  isLayoutField,
  parseFormFields,
} from '../../shared/formSchema';
import { DynamicFormFields, type DynamicFormValues } from './DynamicFormFields';
import { SasFormBuilder } from './SasFormBuilder';
import { SubmissionDetailsPreview } from './SubmissionDetailsPreview';
import { EvaluatorApplicationsPanel } from './EvaluatorApplicationsPanel';
import { TABLE_ACTIONS_HEADER, TableViewButton, TableViewLink } from '../shared/TableViewLink';

function errMsg(e: unknown, fallback: string): string {
  const data = (e as { data?: { message?: string; error?: string } })?.data;
  return data?.message || data?.error || fallback;
}

const RESUBMIT_STATUSES = new Set(['ComplianceRequired', 'Denied', 'Rejected']);
const LOCKED_STATUSES = new Set(['Pending', 'AwaitingFinalApproval', 'Approved']);

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

/** Compact human-readable summary for history table (labels, not raw field ids). */
function summarizeSubmitted(json: string, fields: SasFormField[]): string {
  const values = parseSubmittedValues(json);
  if (!fields.length) {
    return Object.entries(values)
      .slice(0, 3)
      .map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(', ') : String(v)}`)
      .join('\n');
  }

  const lines: string[] = [];
  for (const field of fields) {
    if (isLayoutField(field.type) || isFileType(field.type)) continue;
    if (['terms', 'checkbox', 'toggle', 'geolocation', 'address'].includes(field.type)) continue;
    const raw = values[field.id];
    if (raw == null || raw === '' || (Array.isArray(raw) && raw.length === 0)) continue;
    const display = formatFieldDisplayValue(field, raw);
    if (!display || display === '—') continue;
    lines.push(`${field.label}: ${display}`);
    if (lines.length >= 3) break;
  }

  const summary = getSubmissionSummary(fields, values);
  if (summary.documentCount > 0) {
    lines.push(
      `${summary.documentCount} document${summary.documentCount === 1 ? '' : 's'} attached`,
    );
  }

  return lines.join('\n') || 'No summary available';
}

export function SasPage() {
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const role = user?.role ?? '';
  const isAdmin = role === 'SystemAdmin';
  const isEvaluatorOnly = role === 'Evaluator';
  const isEvaluator = ['Evaluator', 'SystemAdmin'].includes(role);
  const isApplicant = role === 'Broker' || role === 'Consignee';
  const formType = role === 'Consignee' ? 'Consignee' : 'Broker';

  const { data: forms = [], refetch: refetchForms } = useGetFormsQuery(undefined, { skip: !isAdmin });
  const {
    data: activeForm,
    isLoading: formLoading,
    isError: formError,
    error: activeFormError,
    refetch: refetchActiveForm,
  } = useGetActiveFormQuery(formType, { skip: !isApplicant || !accessToken });
  const { data: submissions = [], refetch } = useGetAccreditationsQuery(undefined, {
    skip: !accessToken,
  });
  const { shippingLine, isLoading: lineLoading } = useDefaultShippingLine();

  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [viewing, setViewing] = useState<AccreditationDto | null>(null);

  const mySubmission = useMemo(
    () => (isApplicant ? submissions.find((s) => s.applicantId === user?.id) ?? null : null),
    [isApplicant, submissions, user?.id],
  );

  const formFieldsById = useMemo(() => {
    const map = new Map<string, SasFormField[]>();
    forms.forEach((f) => map.set(f.id, parseFormFields(f.fieldsJson)));
    if (activeForm) map.set(activeForm.id, parseFormFields(activeForm.fieldsJson));
    return map;
  }, [forms, activeForm]);

  const fieldsForSubmission = (s: AccreditationDto) =>
    formFieldsById.get(s.formConfigurationId) ??
    (activeForm ? parseFormFields(activeForm.fieldsJson) : []);

  const viewingFields = viewing ? fieldsForSubmission(viewing) : [];
  const viewingValues = viewing ? parseSubmittedValues(viewing.submittedDataJson) : {};

  if (isEvaluatorOnly) {
    return (
      <EvaluatorApplicationsPanel
        submissions={submissions}
        onRefresh={() => {
          refetch();
        }}
      />
    );
  }

  if (role === 'ShippingLinesAdmin') {
    return <Navigate to="/approvals" replace />;
  }

  return (
    <Stack spacing={3}>
      <Box>
        <Typography variant="h4" fontWeight={700}>
          SAS Accreditation
        </Typography>
        <Typography color="text.secondary">
          {isAdmin
            ? 'Build Broker and Consignee forms with templates, structure, properties, and live preview.'
            : isApplicant
              ? `Complete the active ${formType} accreditation form configured by your shipping line.`
              : 'Review accreditation submissions.'}
        </Typography>
      </Box>

      {message && (
        <Alert severity="success" onClose={() => setMessage(null)}>
          {message}
        </Alert>
      )}
      {error && (
        <Alert severity="error" onClose={() => setError(null)}>
          {error}
        </Alert>
      )}

      {isAdmin && (
        <SasFormBuilder
          forms={forms}
          onRefresh={refetchForms}
          onMessage={setMessage}
          onError={setError}
        />
      )}

      {isApplicant && (
        <ApplicantAccreditationPanel
          role={role as 'Broker' | 'Consignee'}
          formType={formType}
          activeForm={activeForm ?? null}
          formLoading={formLoading || lineLoading}
          formError={formError}
          formErrorStatus={
            activeFormError && 'status' in activeFormError
              ? activeFormError.status
              : undefined
          }
          shippingLineName={shippingLine?.brandName}
          mySubmission={mySubmission}
          onRetry={() => refetchActiveForm()}
          onMessage={setMessage}
          onError={setError}
          onSubmitted={() => {
            refetch();
            refetchActiveForm();
          }}
        />
      )}

      <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
        <Typography variant="h6" mb={2}>
          {isApplicant ? 'My submission history' : 'Submissions'}
        </Typography>
        <Table size="small">
          <TableHead>
            <TableRow>
              {!isApplicant && <TableCell>Applicant</TableCell>}
              <TableCell>Shipping line</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Submitted</TableCell>
              <TableCell>Summary</TableCell>
              <TableCell align="right">{TABLE_ACTIONS_HEADER}</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {submissions.length === 0 ? (
              <TableRow>
                <TableCell colSpan={isApplicant ? 5 : 6}>
                  <Typography variant="body2" color="text.secondary">
                    No submissions yet.
                  </Typography>
                </TableCell>
              </TableRow>
            ) : (
              submissions.map((s) => {
                const fields = fieldsForSubmission(s);
                return (
                  <TableRow key={s.id} hover>
                    {!isApplicant && (
                      <TableCell>
                        {s.applicantName}
                        <Typography variant="caption" color="text.secondary" display="block">
                          {s.applicantRole}
                        </Typography>
                      </TableCell>
                    )}
                    <TableCell>{s.shippingLineName}</TableCell>
                    <TableCell>
                      <Chip size="small" label={s.status} color={statusChipColor(s.status)} />
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {new Date(s.submittedAt).toLocaleDateString()}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {new Date(s.submittedAt).toLocaleTimeString()}
                      </Typography>
                    </TableCell>
                    <TableCell sx={{ maxWidth: 280 }}>
                      <Typography
                        variant="body2"
                        color="text.secondary"
                        sx={{ whiteSpace: 'pre-wrap', lineHeight: 1.45 }}
                      >
                        {summarizeSubmitted(s.submittedDataJson, fields)}
                      </Typography>
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={0.5} justifyContent="flex-end" flexWrap="wrap">
                        {(isEvaluator || isAdmin) && (
                          <TableViewLink to={`/evaluator/application/${s.id}`} />
                        )}
                        {isApplicant && <TableViewButton onClick={() => setViewing(s)} />}
                      </Stack>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>
      </Paper>

      <Dialog
        open={Boolean(viewing)}
        onClose={() => setViewing(null)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          Submission details
          {viewing && (
            <Typography variant="body2" color="text.secondary" fontWeight={400}>
              {viewing.applicantName} · {viewing.shippingLineName} · {viewing.status}
            </Typography>
          )}
        </DialogTitle>
        <DialogContent dividers>
          {viewing && (
            <Stack spacing={2}>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                <Chip size="small" label={viewing.status} color={statusChipColor(viewing.status)} />
                <Chip
                  size="small"
                  variant="outlined"
                  label={`Submitted ${new Date(viewing.submittedAt).toLocaleString()}`}
                />
              </Stack>
              {viewing.complianceNotes && (
                <Alert severity="warning">{viewing.complianceNotes}</Alert>
              )}
              {viewing.denialReason && <Alert severity="error">{viewing.denialReason}</Alert>}
              <SubmissionDetailsPreview fields={viewingFields} values={viewingValues} />
            </Stack>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setViewing(null)}>Close</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

function ApplicantAccreditationPanel({
  role,
  formType,
  activeForm,
  formLoading,
  formError,
  formErrorStatus,
  shippingLineName,
  mySubmission,
  onRetry,
  onMessage,
  onError,
  onSubmitted,
}: {
  role: 'Broker' | 'Consignee';
  formType: string;
  activeForm: FormConfigurationDto | null;
  formLoading: boolean;
  formError: boolean;
  formErrorStatus?: number | string;
  shippingLineName?: string;
  mySubmission: AccreditationDto | null;
  onRetry: () => void;
  onMessage: (m: string) => void;
  onError: (m: string) => void;
  onSubmitted: () => void;
}) {
  const fields = useMemo(() => parseFormFields(activeForm?.fieldsJson), [activeForm?.fieldsJson]);
  const [values, setValues] = useState<DynamicFormValues>({});
  const [validationErrors, setValidationErrors] = useState<string[]>([]);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submit, { isLoading }] = useSubmitAccreditationMutation();
  const [completeStep] = useCompleteOnboardingStepMutation();
  const { data: relationships = [] } = useGetRelationshipsQuery(undefined, {
    skip: role !== 'Consignee',
  });

  const hasActiveBroker = relationships.some((r) => /active/i.test(r.status));
  const canResubmit = !mySubmission || RESUBMIT_STATUSES.has(mySubmission.status);
  const isLocked = Boolean(mySubmission && LOCKED_STATUSES.has(mySubmission.status));
  const isResubmit = Boolean(mySubmission && RESUBMIT_STATUSES.has(mySubmission.status));
  const summary = useMemo(() => getSubmissionSummary(fields, values), [fields, values]);

  useEffect(() => {
    const initial: DynamicFormValues = {};
    fields.forEach((f) => {
      if (isLayoutField(f.type)) return;
      if (f.type === 'multi_select') initial[f.id] = [];
      else if (['checkbox', 'toggle', 'terms'].includes(f.type)) initial[f.id] = false;
      else initial[f.id] = '';
    });

    if (mySubmission?.submittedDataJson) {
      try {
        const prev = JSON.parse(mySubmission.submittedDataJson) as Record<string, unknown>;
        Object.entries(prev).forEach(([k, v]) => {
          if (Array.isArray(v)) initial[k] = v.map(String);
          else if (typeof v === 'boolean') initial[k] = v;
          else if (v != null && typeof v === 'object') initial[k] = JSON.stringify(v);
          else if (v != null) initial[k] = String(v);
        });
      } catch {
        /* ignore */
      }
    }

    setValues(initial);
  }, [activeForm?.id, fields, mySubmission?.id, mySubmission?.submittedDataJson]);

  const requestSubmit = () => {
    if (!activeForm) {
      onError(
        `No active ${formType} accreditation form is configured. Ask your shipping line admin to activate one.`,
      );
      return;
    }
    if (role === 'Consignee' && !hasActiveBroker) {
      onError('Link an approved broker before submitting SAS.');
      return;
    }

    const errors = collectValidationErrors(fields, values);
    if (errors.length > 0) {
      setValidationErrors(errors);
      setConfirmOpen(false);
      return;
    }

    setValidationErrors([]);
    setConfirmOpen(true);
  };

  const confirmSubmit = async () => {
    try {
      await submit({ submittedDataJson: JSON.stringify(values) }).unwrap();
      if (role === 'Consignee') {
        try {
          await completeStep({ stepId: 'submit_accreditation' }).unwrap();
        } catch {
          /* onboarding step is best-effort */
        }
      }
      setConfirmOpen(false);
      onMessage(isResubmit ? 'Accreditation resubmitted' : 'Accreditation submitted');
      onSubmitted();
    } catch (err) {
      setConfirmOpen(false);
      onError(errMsg(err, 'Submit failed'));
    }
  };

  if (formLoading) {
    return (
      <Paper elevation={0} sx={{ p: 4, border: 1, borderColor: 'divider', borderRadius: 2 }}>
        <Stack alignItems="center" spacing={1.5}>
          <CircularProgress size={28} />
          <Typography color="text.secondary">Loading active {formType} form…</Typography>
        </Stack>
      </Paper>
    );
  }

  if (formError) {
    const isAuth = formErrorStatus === 401;
    return (
      <Alert
        severity={isAuth ? 'warning' : 'error'}
        action={
          <Button color="inherit" size="small" onClick={onRetry}>
            Retry
          </Button>
        }
      >
        {isAuth
          ? 'Your session expired while loading the form. Retry, or log in again.'
          : `Could not load the active ${formType} form. Check that the API is running, then retry.`}
      </Alert>
    );
  }

  return (
    <Stack spacing={2}>
      {role === 'Consignee' && !hasActiveBroker && (
        <Alert
          severity="warning"
          action={
            <Button component={RouterLink} to="/brokers" color="inherit" size="small">
              Link broker
            </Button>
          }
        >
          Broker linkage required before you can submit accreditation.
        </Alert>
      )}

      {isLocked && mySubmission && (
        <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
          <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" spacing={1.5}>
            <Box>
              <Typography variant="h6" fontWeight={700}>
                Application status
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {activeForm
                  ? `${activeForm.name} v${activeForm.version}`
                  : `${formType} form`}
                {shippingLineName ? ` · ${shippingLineName}` : ''}
              </Typography>
            </Box>
            <Chip
              label={mySubmission.status}
              color={
                mySubmission.status === 'Approved'
                  ? 'success'
                  : mySubmission.status === 'Pending'
                    ? 'warning'
                    : 'info'
              }
            />
          </Stack>
          {mySubmission.complianceNotes && (
            <Alert severity="warning" sx={{ mt: 2 }}>
              {mySubmission.complianceNotes}
            </Alert>
          )}
          {mySubmission.denialReason && (
            <Alert severity="error" sx={{ mt: 2 }}>
              {mySubmission.denialReason}
            </Alert>
          )}
          <Typography variant="body2" color="text.secondary" mt={2}>
            Submitted {new Date(mySubmission.submittedAt).toLocaleString()}. You cannot edit while
            status is {mySubmission.status}.
          </Typography>
          {activeForm && fields.length > 0 && (
            <Box mt={2.5}>
              <SubmissionDetailsPreview
                fields={fields}
                values={values}
                title="Submitted details"
              />
            </Box>
          )}
        </Paper>
      )}

      {canResubmit && (
        <Paper
          component="form"
          onSubmit={(e) => {
            e.preventDefault();
            requestSubmit();
          }}
          elevation={0}
          sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 2 }}
        >
          <Typography variant="h6" mb={0.5}>
            {isResubmit ? 'Resubmit accreditation' : 'Submit accreditation'}
          </Typography>
          <Typography variant="body2" color="text.secondary" mb={2}>
            {activeForm
              ? `Active form: ${activeForm.name} v${activeForm.version} (${activeForm.type})`
              : `Waiting for admin to activate a ${formType} form.`}
            {shippingLineName ? ` · ${shippingLineName}` : ''}
          </Typography>

          {mySubmission?.status === 'ComplianceRequired' && mySubmission.complianceNotes && (
            <Alert severity="warning" sx={{ mb: 2 }}>
              {mySubmission.complianceNotes}
            </Alert>
          )}

          {!activeForm || fields.length === 0 ? (
            <Alert severity="info">
              No active {formType} form fields are available yet. Ask a System Admin or Shipping
              Lines Admin to build and <strong>Activate</strong> a {formType} form in SAS.
            </Alert>
          ) : (
            <Stack spacing={2}>
              <DynamicFormFields
                fields={fields}
                values={values}
                onChange={(id, value) => setValues((prev) => ({ ...prev, [id]: value }))}
                disabled={role === 'Consignee' && !hasActiveBroker}
              />
              <Button
                type="submit"
                variant="contained"
                color={isResubmit ? 'warning' : 'primary'}
                disabled={isLoading || (role === 'Consignee' && !hasActiveBroker)}
                sx={{ alignSelf: 'flex-start' }}
              >
                {isResubmit ? 'Resubmit' : 'Submit'}
              </Button>
            </Stack>
          )}
        </Paper>
      )}

      <Dialog
        open={validationErrors.length > 0}
        onClose={() => setValidationErrors([])}
        maxWidth="sm"
        fullWidth
      >
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <ErrorOutlineIcon color="error" />
          Please fix the following
        </DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" mb={1.5}>
            Some required fields are missing or invalid. Review the items below and try again.
          </Typography>
          <List dense disablePadding>
            {validationErrors.map((msg, i) => (
              <ListItem key={`${msg}-${i}`} disableGutters alignItems="flex-start">
                <ListItemIcon sx={{ minWidth: 28, mt: 0.5 }}>
                  <Box
                    sx={{
                      width: 6,
                      height: 6,
                      borderRadius: '50%',
                      bgcolor: 'error.main',
                    }}
                  />
                </ListItemIcon>
                <ListItemText primary={msg} primaryTypographyProps={{ variant: 'body2' }} />
              </ListItem>
            ))}
          </List>
        </DialogContent>
        <DialogActions>
          <Button variant="contained" onClick={() => setValidationErrors([])}>
            Review form
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={confirmOpen}
        onClose={() => !isLoading && setConfirmOpen(false)}
        maxWidth="md"
        fullWidth
        disableEscapeKeyDown={isLoading}
      >
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <SendOutlinedIcon color={isResubmit ? 'warning' : 'primary'} />
          {isResubmit ? 'Resubmit for review?' : 'Submit application?'}
        </DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={2}>
            {isResubmit
              ? `You are about to resubmit your compliance corrections${
                  shippingLineName ? ` for ${shippingLineName}` : ''
                }. Please review your details below before confirming.`
              : `You are about to submit your accreditation application${
                  shippingLineName ? ` for ${shippingLineName}` : ''
                }. Please review your details below before confirming.`}
          </Typography>

          <Paper variant="outlined" sx={{ p: 2, bgcolor: 'action.hover', mb: 2 }}>
            <Stack spacing={1}>
              <Stack direction="row" justifyContent="space-between">
                <Typography variant="body2" color="text.secondary">
                  Required fields completed
                </Typography>
                <Typography variant="body2" fontWeight={600}>
                  {summary.requiredFilled} / {summary.requiredTotal}
                </Typography>
              </Stack>
              <Stack direction="row" justifyContent="space-between">
                <Typography variant="body2" color="text.secondary">
                  Documents attached
                </Typography>
                <Typography variant="body2" fontWeight={600}>
                  {summary.documentCount}
                </Typography>
              </Stack>
            </Stack>
          </Paper>

          <Typography variant="subtitle2" fontWeight={700} mb={1}>
            Application preview
          </Typography>
          <SubmissionDetailsPreview
            fields={fields}
            values={values}
            maxHeight={{ xs: 320, sm: 420 }}
          />

          <Typography variant="caption" color="text.secondary" display="block" mt={2}>
            {isResubmit
              ? 'Your application will return to the evaluator queue for another review.'
              : "After submission, our evaluation team will review your application. You'll be notified by email."}
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setConfirmOpen(false)} disabled={isLoading}>
            Go back
          </Button>
          <Button
            variant="contained"
            color={isResubmit ? 'warning' : 'primary'}
            onClick={() => void confirmSubmit()}
            disabled={isLoading}
            startIcon={
              isLoading ? <CircularProgress size={16} color="inherit" /> : <SendOutlinedIcon />
            }
          >
            {isLoading
              ? isResubmit
                ? 'Resubmitting…'
                : 'Submitting…'
              : isResubmit
                ? 'Yes, resubmit'
                : 'Yes, submit'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
