import { FormEvent, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Divider,
  Link,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { useDispatch } from 'react-redux';
import { useLoginMutation } from '../../app/api';
import { setCredentials } from '../../app/authSlice';
import { AuthSplitLayout } from './AuthSplitLayout';
import { postAuthHomePath } from '../../shared/postAuthHomePath';

export function LoginPage() {
  const dispatch = useDispatch();
  const navigate = useNavigate();
  const [login, { isLoading }] = useLoginMutation();
  const [email, setEmail] = useState('admin@optimus.local');
  const [password, setPassword] = useState('Admin123!');
  const [error, setError] = useState<string | null>(null);

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    try {
      const result = await login({ email, password }).unwrap();
      dispatch(
        setCredentials({
          accessToken: result.accessToken,
          refreshToken: result.refreshToken,
          user: result.user,
        }),
      );
      navigate(postAuthHomePath(result.user.role));
    } catch (err) {
      const message =
        typeof err === 'object' && err && 'data' in err
          ? String((err as { data?: { error?: string } }).data?.error ?? 'Login failed')
          : 'Login failed';
      setError(message);
    }
  };

  return (
    <AuthSplitLayout
      title="Sign in"
      subtitle={
        <>
          Don&apos;t have an account?{' '}
          <Link component={RouterLink} to="/register/consignee" fontWeight={600}>
            Sign up
          </Link>
        </>
      }
    >
      <Stack spacing={2.25} component="form" onSubmit={onSubmit}>
        {error && <Alert severity="error">{error}</Alert>}

        <TextField
          label="Email address"
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
          fullWidth
          autoComplete="email"
        />
        <TextField
          label="Password"
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          fullWidth
          autoComplete="current-password"
        />

        <Button
          type="submit"
          variant="contained"
          size="large"
          fullWidth
          disabled={isLoading}
          sx={{ py: 1.35, fontWeight: 600, textTransform: 'none', fontSize: '1rem' }}
        >
          {isLoading ? <CircularProgress size={22} color="inherit" /> : 'Sign in'}
        </Button>

        <Box textAlign="center">
          <Link
            component={RouterLink}
            to="/forgot-password"
            underline="hover"
            fontWeight={600}
            variant="body2"
          >
            Forgot password?
          </Link>
        </Box>
      </Stack>

      <Divider />

      <Stack spacing={0.75}>
        <Typography variant="caption" color="text.secondary" fontWeight={600}>
          Other options
        </Typography>
        <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1.5}>
          <Link component={RouterLink} to="/register/broker" variant="body2">
            Register as Broker
          </Link>
          <Link component={RouterLink} to="/register/consignee" variant="body2">
            Register as Consignee
          </Link>
          <Link component={RouterLink} to="/verify-email" variant="body2">
            Verify email
          </Link>
        </Stack>
        <Typography variant="caption" color="text.secondary" pt={0.5}>
          Demo password: Admin123! (admin@, broker@, consignee@, …)
        </Typography>
      </Stack>
    </AuthSplitLayout>
  );
}
