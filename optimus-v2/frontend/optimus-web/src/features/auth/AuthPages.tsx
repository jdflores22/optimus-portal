import { FormEvent, useState } from 'react';
import { Alert, Button, Link, Stack, TextField } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import {
  useRegisterBrokerMutation,
  useRegisterConsigneeMutation,
  useRequestOtpMutation,
  useResetPasswordMutation,
  useVerifyEmailMutation,
  useGetInvitationQuery,
  useAcceptInvitationMutation,
} from '../../app/api';
import { useDispatch } from 'react-redux';
import { setCredentials } from '../../app/authSlice';
import { useNavigate, useParams } from 'react-router-dom';
import { AuthSplitLayout } from './AuthSplitLayout';
import { postAuthHomePath } from '../../shared/postAuthHomePath';

const FIELD_LABELS: Record<string, string> = {
  email: 'Email address',
  password: 'Password',
  firstName: 'First name',
  lastName: 'Last name',
  businessAddress: 'Business address',
  businessName: 'Business name',
  referralCode: 'Referral code',
};

export function RegisterBrokerPage() {
  const [register, { isLoading }] = useRegisterBrokerMutation();
  const [form, setForm] = useState({
    email: '',
    password: '',
    firstName: '',
    lastName: '',
    businessAddress: '',
    referralCode: 'DEMOREF01',
  });
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    try {
      const res = await register(form).unwrap();
      setMessage(res.message);
    } catch {
      setError('Registration failed.');
    }
  };

  return (
    <AuthSplitLayout
      title="Sign up"
      maxWidth={440}
      subtitle={
        <>
          Register as a broker.{' '}
          <Link component={RouterLink} to="/login" fontWeight={600}>
            Sign in
          </Link>{' '}
          if you already have an account.
        </>
      }
    >
      <Stack spacing={2} component="form" onSubmit={onSubmit}>
        {message && <Alert severity="success">{message}</Alert>}
        {error && <Alert severity="error">{error}</Alert>}
        {Object.entries(form).map(([key, value]) => (
          <TextField
            key={key}
            label={FIELD_LABELS[key] ?? key}
            type={key === 'password' ? 'password' : key === 'email' ? 'email' : 'text'}
            value={value}
            onChange={(e) => setForm({ ...form, [key]: e.target.value })}
            required={key !== 'businessAddress' && key !== 'referralCode'}
            fullWidth
          />
        ))}
        <Button
          type="submit"
          variant="contained"
          size="large"
          fullWidth
          disabled={isLoading}
          sx={{ py: 1.35, fontWeight: 600, textTransform: 'none', fontSize: '1rem' }}
        >
          Create broker account
        </Button>
        <Link component={RouterLink} to="/register/consignee" variant="body2" textAlign="center">
          Register as Consignee instead
        </Link>
      </Stack>
    </AuthSplitLayout>
  );
}

export function RegisterConsigneePage() {
  const [register, { isLoading }] = useRegisterConsigneeMutation();
  const [form, setForm] = useState({
    email: '',
    password: '',
    firstName: '',
    lastName: '',
    businessName: '',
  });
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    try {
      const res = await register(form).unwrap();
      setMessage(res.message);
    } catch {
      setError('Registration failed.');
    }
  };

  return (
    <AuthSplitLayout
      title="Sign up"
      maxWidth={440}
      subtitle={
        <>
          Register as a consignee.{' '}
          <Link component={RouterLink} to="/login" fontWeight={600}>
            Sign in
          </Link>{' '}
          if you already have an account.
        </>
      }
    >
      <Stack spacing={2} component="form" onSubmit={onSubmit}>
        {message && <Alert severity="success">{message}</Alert>}
        {error && <Alert severity="error">{error}</Alert>}
        {Object.entries(form).map(([key, value]) => (
          <TextField
            key={key}
            label={FIELD_LABELS[key] ?? key}
            type={key === 'password' ? 'password' : key === 'email' ? 'email' : 'text'}
            value={value}
            onChange={(e) => setForm({ ...form, [key]: e.target.value })}
            required
            fullWidth
          />
        ))}
        <Button
          type="submit"
          variant="contained"
          size="large"
          fullWidth
          disabled={isLoading}
          sx={{ py: 1.35, fontWeight: 600, textTransform: 'none', fontSize: '1rem' }}
        >
          Create consignee account
        </Button>
        <Link component={RouterLink} to="/register/broker" variant="body2" textAlign="center">
          Register as Broker instead
        </Link>
      </Stack>
    </AuthSplitLayout>
  );
}

export function ForgotPasswordPage() {
  const [requestOtp] = useRequestOtpMutation();
  const [resetPassword] = useResetPasswordMutation();
  const [email, setEmail] = useState('');
  const [otp, setOtp] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  return (
    <AuthSplitLayout
      title="Forgot password"
      subtitle="Request an OTP, then set a new password for your account."
    >
      <Stack spacing={2}>
        {message && <Alert severity="success">{message}</Alert>}
        {error && <Alert severity="error">{error}</Alert>}
        <TextField
          label="Email address"
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          fullWidth
        />
        <Button
          variant="outlined"
          size="large"
          fullWidth
          sx={{ textTransform: 'none', fontWeight: 600 }}
          onClick={async () => {
            setError(null);
            try {
              const res = await requestOtp({ email }).unwrap();
              setMessage(`${res.message} Check API logs for OTP in Phase 1.`);
            } catch {
              setError('Failed to request OTP.');
            }
          }}
        >
          Request OTP
        </Button>
        <TextField label="OTP" value={otp} onChange={(e) => setOtp(e.target.value)} fullWidth />
        <TextField
          label="New password"
          type="password"
          value={newPassword}
          onChange={(e) => setNewPassword(e.target.value)}
          fullWidth
        />
        <Button
          variant="contained"
          size="large"
          fullWidth
          sx={{ py: 1.35, fontWeight: 600, textTransform: 'none', fontSize: '1rem' }}
          onClick={async () => {
            setError(null);
            try {
              const res = await resetPassword({ email, otp, newPassword }).unwrap();
              setMessage(res.message);
            } catch {
              setError('Reset failed.');
            }
          }}
        >
          Reset password
        </Button>
      </Stack>
    </AuthSplitLayout>
  );
}

export function VerifyEmailPage() {
  const [verifyEmail] = useVerifyEmailMutation();
  const [token, setToken] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  return (
    <AuthSplitLayout
      title="Verify email"
      subtitle="Paste the verification token from your registration email or API logs."
    >
      <Stack spacing={2}>
        {message && <Alert severity="success">{message}</Alert>}
        {error && <Alert severity="error">{error}</Alert>}
        <TextField
          label="Verification token"
          value={token}
          onChange={(e) => setToken(e.target.value)}
          helperText="Copy token from API logs after registration"
          fullWidth
        />
        <Button
          variant="contained"
          size="large"
          fullWidth
          sx={{ py: 1.35, fontWeight: 600, textTransform: 'none', fontSize: '1rem' }}
          onClick={async () => {
            setError(null);
            try {
              const res = await verifyEmail({ token }).unwrap();
              setMessage(res.message);
            } catch {
              setError('Verification failed.');
            }
          }}
        >
          Verify
        </Button>
      </Stack>
    </AuthSplitLayout>
  );
}

export function RoleAcceptancePage() {
  const { token = '' } = useParams();
  const { data, error: loadError } = useGetInvitationQuery(token, { skip: !token });
  const [acceptInvitation] = useAcceptInvitationMutation();
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const dispatch = useDispatch();
  const navigate = useNavigate();

  return (
    <AuthSplitLayout
      title="Accept invitation"
      subtitle="Set a password to activate your invited role on OPTIMUS."
    >
      <Stack spacing={2}>
        {loadError && <Alert severity="error">Invitation not found.</Alert>}
        {data && (
          <Alert severity="info">
            Invite for {data.firstName} {data.lastName} as {data.role}
          </Alert>
        )}
        {error && <Alert severity="error">{error}</Alert>}
        <TextField
          label="Set password"
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          fullWidth
        />
        <Button
          variant="contained"
          size="large"
          fullWidth
          disabled={!data}
          sx={{ py: 1.35, fontWeight: 600, textTransform: 'none', fontSize: '1rem' }}
          onClick={async () => {
            setError(null);
            try {
              const result = await acceptInvitation({ token, password }).unwrap();
              dispatch(
                setCredentials({
                  accessToken: result.accessToken,
                  refreshToken: result.refreshToken,
                  user: result.user,
                }),
              );
              navigate(postAuthHomePath(result.user.role));
            } catch {
              setError('Accept failed.');
            }
          }}
        >
          Accept invitation
        </Button>
      </Stack>
    </AuthSplitLayout>
  );
}
