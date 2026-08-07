import { useState } from 'react';
import {
  Alert,
  Box,
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
import { useSelector } from 'react-redux';
import {
  useApplyReferralMutation,
  useCompleteOnboardingStepMutation,
  useDeactivateReferralMutation,
  useGenerateReferralMutation,
  useGetAppealsQuery,
  useGetHierarchyUsersQuery,
  useGetReferralsQuery,
  useGetWelcomeQuery,
  useReviewAppealMutation,
  useSubmitAppealMutation,
  useSuspendBrokerMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function AppealsReferralsPage() {
  const { user } = useSelector((state: RootState) => state.auth);
  const role = user?.role ?? '';
  const isAdmin = role === 'SystemAdmin' || role === 'ShippingLinesAdmin';
  const isBroker = role === 'Broker' || role === 'SystemAdmin';
  const isConsignee = role === 'Consignee' || role === 'SystemAdmin';

  const { data: appeals = [], refetch: refetchAppeals } = useGetAppealsQuery();
  const { data: referrals = [], refetch: refetchRefs } = useGetReferralsQuery(undefined, {
    skip: !isConsignee,
  });
  const { data: welcome } = useGetWelcomeQuery();
  const { data: users = [] } = useGetHierarchyUsersQuery(undefined, { skip: !isAdmin });
  const brokers = users.filter((u) => u.role === 'Broker');

  const [suspend] = useSuspendBrokerMutation();
  const [submitAppeal] = useSubmitAppealMutation();
  const [reviewAppeal] = useReviewAppealMutation();
  const [generate] = useGenerateReferralMutation();
  const [apply] = useApplyReferralMutation();
  const [deactivate] = useDeactivateReferralMutation();
  const [completeStep] = useCompleteOnboardingStepMutation();

  const [brokerId, setBrokerId] = useState('');
  const [letter, setLetter] = useState('Please reconsider my suspension.');
  const [code, setCode] = useState('DEMOREF01');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const pendingAppeals = appeals.filter((a) => a.status === 'Pending');

  return (
    <WorkflowPage
      eyebrow="Appeals And Referrals"
      title="Appeals, Referrals & Onboarding"
      subtitle="Manage broker appeals, consignee referral codes, and onboarding guidance in one operational surface."
      chips={
        <>
          <Chip size="small" color="warning" label={`${pendingAppeals.length} pending appeals`} />
          {isConsignee && <Chip size="small" color="info" label={`${referrals.length} referral codes`} />}
        </>
      }
      stats={[
        { label: 'Appeals', value: appeals.length, hint: 'All submissions', tone: 'primary' },
        { label: 'Pending Appeals', value: pendingAppeals.length, hint: 'Awaiting admin review', tone: 'warning' },
        { label: 'Referral Codes', value: referrals.length, hint: 'Consignee side', tone: 'info' },
        { label: 'Broker Accounts', value: brokers.length, hint: 'Admin actions', tone: 'success' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      {welcome && (
        <WorkflowSection
          title="Onboarding"
          subtitle="Contextual guidance and completion tracking for first-time setup."
        >
          <Typography variant="h6">{welcome.title}</Typography>
          <Typography variant="body2" mb={1}>
            {welcome.bodyMarkdown}
          </Typography>
          <Typography variant="caption">
            Completed: {(welcome.completedSteps ?? []).join(', ') || 'none'}
          </Typography>
          {isConsignee && (
            <Button
              size="small"
              sx={{ ml: 2 }}
              onClick={async () => {
                await completeStep({ stepId: 'generate_referral_code' }).unwrap();
                setMessage('Onboarding step marked');
              }}
            >
              Complete sample step
            </Button>
          )}
        </WorkflowSection>
      )}

      {isAdmin && (
        <WorkflowSection
          title="Broker Suspension"
          subtitle="Administrative suspension action before any appeal review happens."
        >
          <Stack direction="row" spacing={2}>
            <TextField
              select
              label="Broker"
              value={brokerId}
              onChange={(e) => setBrokerId(e.target.value)}
              sx={{ minWidth: 200 }}
            >
              {brokers.map((b) => (
                <MenuItem key={b.id} value={b.id}>
                  {b.fullName}
                </MenuItem>
              ))}
            </TextField>
            <Button
              color="error"
              variant="contained"
              disabled={!brokerId}
              onClick={async () => {
                try {
                  await suspend({ brokerId, reason: 'Compliance hold' }).unwrap();
                  setMessage('Broker suspended');
                } catch (e: unknown) {
                  setError((e as { data?: { message?: string } })?.data?.message ?? 'Failed');
                }
              }}
            >
              Suspend
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      {isBroker && (
        <WorkflowSection
          title="Broker Appeal And Referral"
          subtitle="Submit an appeal if suspended, or link to a consignee with a referral code."
        >
          <Stack spacing={2}>
            <TextField
              label="Appeal letter"
              value={letter}
              onChange={(e) => setLetter(e.target.value)}
              multiline
              minRows={2}
            />
            <Button
              variant="outlined"
              onClick={async () => {
                try {
                  await submitAppeal({ appealLetter: letter }).unwrap();
                  setMessage('Appeal submitted');
                  refetchAppeals();
                } catch (e: unknown) {
                  setError((e as { data?: { message?: string } })?.data?.message ?? 'Failed');
                }
              }}
            >
              Submit appeal
            </Button>
            <Stack direction="row" spacing={2}>
              <TextField label="Referral code" value={code} onChange={(e) => setCode(e.target.value)} />
              <Button
                variant="contained"
                onClick={async () => {
                  try {
                    await apply({ code }).unwrap();
                    setMessage('Referral applied');
                  } catch (e: unknown) {
                    setError((e as { data?: { message?: string } })?.data?.message ?? 'Failed');
                  }
                }}
              >
                Apply code
              </Button>
            </Stack>
          </Stack>
        </WorkflowSection>
      )}

      {isConsignee && (
        <WorkflowSection
          title="Referral Codes"
          subtitle="Generate, monitor, and deactivate broker invitation codes."
        >
          <Button
            variant="contained"
            sx={{ mb: 2 }}
            onClick={async () => {
              await generate({ maxUses: 25 }).unwrap();
              refetchRefs();
              setMessage('Code generated');
            }}
          >
            Generate
          </Button>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Code</TableCell>
                <TableCell>Uses</TableCell>
                <TableCell>Active</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {referrals.map((r) => (
                <TableRow key={r.id}>
                  <TableCell>{r.code}</TableCell>
                  <TableCell>
                    {r.currentUses}/{r.maxUses ?? '∞'}
                  </TableCell>
                  <TableCell>{r.isActive ? 'Yes' : 'No'}</TableCell>
                  <TableCell>
                    {r.isActive && (
                      <Button size="small" onClick={async () => {
                        await deactivate(r.id).unwrap();
                        refetchRefs();
                      }}>
                        Deactivate
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </WorkflowSection>
      )}

      <WorkflowSection
        title="Appeals Queue"
        subtitle="Review appeal status and process pending broker appeals."
      >
        <Box sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>User</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Letter</TableCell>
              <TableCell>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {appeals.length === 0 && (
              <TableRow>
                <TableCell colSpan={4}>
                  <Typography variant="body2" color="text.secondary" py={2} textAlign="center">
                    No appeals submitted yet.
                  </Typography>
                </TableCell>
              </TableRow>
            )}
            {appeals.map((a) => (
              <TableRow key={a.id}>
                <TableCell>{a.userName}</TableCell>
                <TableCell>
                  <Chip size="small" label={a.status} color={a.status === 'Approved' ? 'success' : a.status === 'Rejected' ? 'error' : 'warning'} />
                </TableCell>
                <TableCell>{a.appealLetter.slice(0, 60)}</TableCell>
                <TableCell>
                  {role === 'SystemAdmin' && a.status === 'Pending' && (
                    <Button
                      size="small"
                      onClick={async () => {
                        await reviewAppeal({ id: a.id, approve: true }).unwrap();
                        refetchAppeals();
                      }}
                    >
                      Approve
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        </Box>
      </WorkflowSection>
    </WorkflowPage>
  );
}
