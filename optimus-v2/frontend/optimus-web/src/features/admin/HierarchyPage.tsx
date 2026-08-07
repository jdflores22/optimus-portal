import {
  Alert,
  Button,
  Chip,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { useMemo, useState } from 'react';
import { useSelector } from 'react-redux';
import {
  useGetHierarchyUsersQuery,
  useInviteUserMutation,
  useUnlockUserMutation,
} from '../../app/api';
import type { RootState } from '../../app/store';
import { formatRoleLabel } from '../../shared/roleLabels';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

const SYSTEM_ROLES = [
  'SystemAdmin',
  'ShippingLinesAdmin',
  'SlStaff',
  'Evaluator',
  'Accounting',
  'TerminalTeam',
  'Broker',
  'Consignee',
  'Trucker',
];

const TEAM_INVITE_ROLES = ['SlStaff', 'Evaluator', 'Accounting', 'TerminalTeam'];

export function HierarchyPage() {
  const role = useSelector((state: RootState) => state.auth.user?.role ?? '');
  const isShippingAdmin = role === 'ShippingLinesAdmin';
  const isSystemAdmin = role === 'SystemAdmin';
  const canInvite = isShippingAdmin || isSystemAdmin;
  const inviteRoles = isShippingAdmin ? TEAM_INVITE_ROLES : SYSTEM_ROLES;

  const { data = [], refetch } = useGetHierarchyUsersQuery();
  const [inviteUser] = useInviteUserMutation();
  const [unlockUser] = useUnlockUserMutation();
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [form, setForm] = useState({
    email: '',
    firstName: '',
    lastName: '',
    role: 'SlStaff',
  });
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return data.filter((user) => {
      if (roleFilter && user.role !== roleFilter) return false;
      if (!q) return true;
      return (
        user.email.toLowerCase().includes(q) ||
        user.fullName.toLowerCase().includes(q)
      );
    });
  }, [data, roleFilter, search]);

  const lockedCount = data.filter((user) => user.status === 'Locked').length;

  const onInvite = async () => {
    setError(null);
    setMessage(null);
    try {
      const pending = await inviteUser(form).unwrap();
      setMessage(`Invited ${pending.email}. Acceptance token issued.`);
      setForm({ email: '', firstName: '', lastName: '', role: 'SlStaff' });
      refetch();
    } catch {
      setError('Invite failed.');
    }
  };

  return (
    <WorkflowPage
      eyebrow={isShippingAdmin ? 'Shipping Lines Admin' : 'Governance'}
      title={isShippingAdmin ? 'My Team' : 'User hierarchy'}
      subtitle={
        isShippingAdmin
          ? 'Invite and manage operators under your shipping line: staff, evaluators, accounting, and terminal team.'
          : 'Invite internal operators, review account status, and restore access for locked users.'
      }
      chips={
        <>
          <Chip size="small" label={`${filtered.length} users`} color="primary" />
          <Chip size="small" label={`${lockedCount} locked`} color={lockedCount ? 'warning' : 'success'} />
        </>
      }
      stats={[
        { label: 'Team', value: data.length, hint: isShippingAdmin ? 'Your hierarchy' : 'Visible records', tone: 'primary' },
        { label: 'Locked', value: lockedCount, hint: 'Requires unlock', tone: lockedCount ? 'warning' : 'success' },
        { label: 'Invite roles', value: inviteRoles.length, hint: 'Assignable set', tone: 'info' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      {canInvite && (
        <WorkflowSection
          title={isShippingAdmin ? 'Invite teammate' : 'Invite user'}
          subtitle={
            isShippingAdmin
              ? 'New operators report to you and inherit your shipping-line scope.'
              : 'Provision the next internal operator into the shipping portal.'
          }
        >
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <TextField
              label="Email"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
            />
            <TextField
              label="First name"
              value={form.firstName}
              onChange={(e) => setForm({ ...form, firstName: e.target.value })}
            />
            <TextField
              label="Last name"
              value={form.lastName}
              onChange={(e) => setForm({ ...form, lastName: e.target.value })}
            />
            <TextField
              select
              label="Role"
              value={form.role}
              onChange={(e) => setForm({ ...form, role: e.target.value })}
              SelectProps={{ native: true }}
              sx={{ minWidth: 160 }}
            >
              {inviteRoles.map((r) => (
                <option key={r} value={r}>
                  {formatRoleLabel(r)}
                </option>
              ))}
            </TextField>
            <Button variant="contained" onClick={onInvite}>
              Invite
            </Button>
          </Stack>
        </WorkflowSection>
      )}

      <WorkflowSection
        title={isShippingAdmin ? 'Team register' : 'Access register'}
        subtitle="Monitor status and role placement across your organization."
        actions={
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
            <TextField
              size="small"
              label="Search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <TextField
              select
              size="small"
              label="Role"
              value={roleFilter}
              onChange={(e) => setRoleFilter(e.target.value)}
              SelectProps={{ native: true }}
              sx={{ minWidth: 140 }}
            >
              <option value="">All roles</option>
              {(isShippingAdmin ? TEAM_INVITE_ROLES.concat('ShippingLinesAdmin') : SYSTEM_ROLES).map((r) => (
                <option key={r} value={r}>
                  {formatRoleLabel(r)}
                </option>
              ))}
            </TextField>
          </Stack>
        }
      >
        {filtered.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            No users match the current filters.
          </Typography>
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Name</TableCell>
                <TableCell>Email</TableCell>
                <TableCell>Role</TableCell>
                <TableCell>Status</TableCell>
                {isSystemAdmin && <TableCell align="right">Actions</TableCell>}
              </TableRow>
            </TableHead>
            <TableBody>
              {filtered.map((user) => (
                <TableRow key={user.id}>
                  <TableCell>{user.fullName}</TableCell>
                  <TableCell>{user.email}</TableCell>
                  <TableCell>
                    <Chip size="small" label={formatRoleLabel(user.role)} variant="outlined" />
                  </TableCell>
                  <TableCell>{user.status}</TableCell>
                  {isSystemAdmin && (
                    <TableCell align="right">
                      {user.status === 'Locked' && (
                        <Button
                          size="small"
                          onClick={async () => {
                            await unlockUser(user.id);
                            refetch();
                          }}
                        >
                          Unlock
                        </Button>
                      )}
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </WorkflowSection>
    </WorkflowPage>
  );
}
