import { FormEvent, ReactNode, useState } from 'react';
import { useTheme } from '@mui/material/styles';
import { pageHeroGradient } from '../../shared/theme';
import {
  Alert,
  Avatar,
  Box,
  Button,
  Chip,
  Divider,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import PersonOutlineOutlinedIcon from '@mui/icons-material/PersonOutlineOutlined';
import BadgeOutlinedIcon from '@mui/icons-material/BadgeOutlined';
import EmailOutlinedIcon from '@mui/icons-material/EmailOutlined';
import WorkOutlineOutlinedIcon from '@mui/icons-material/WorkOutlineOutlined';
import BusinessOutlinedIcon from '@mui/icons-material/BusinessOutlined';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { useRequestOtpMutation, useResetPasswordMutation } from '../../app/api';
import { Link as RouterLink } from 'react-router-dom';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';

export function ProfilePage() {
  const theme = useTheme();
  const { user } = useSelector((state: RootState) => state.auth);
  const { shippingLine } = useDefaultShippingLine();

  const [requestOtp, { isLoading: requesting }] = useRequestOtpMutation();
  const [resetPassword, { isLoading: resetting }] = useResetPasswordMutation();
  const [otpSent, setOtpSent] = useState(false);
  const [otp, setOtp] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const email = user?.email ?? '';
  const initials = getInitials(user?.fullName || user?.businessName || user?.email || 'U');
  const displayName = user?.businessName || user?.fullName || 'Your account';

  const onRequestOtp = async () => {
    setError(null);
    setMessage(null);
    try {
      const res = await requestOtp({ email }).unwrap();
      setOtpSent(true);
      setMessage(res.message || 'OTP sent. Check your email or server logs in development.');
    } catch {
      setError('Could not request OTP.');
    }
  };

  const onResetPassword = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setMessage(null);
    if (newPassword.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.');
      return;
    }
    try {
      const res = await resetPassword({ email, otp, newPassword }).unwrap();
      setMessage(res.message || 'Password updated.');
      setOtp('');
      setNewPassword('');
      setConfirmPassword('');
      setOtpSent(false);
    } catch {
      setError('Password reset failed. Check the OTP and try again.');
    }
  };

  return (
    <Stack spacing={3} maxWidth={920}>
      <Paper
        elevation={0}
        sx={{
          p: { xs: 2.5, sm: 3.5 },
          border: 1,
          borderColor: 'divider',
          borderRadius: 3,
          background: pageHeroGradient(theme.palette.mode),
        }}
      >
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2.5} alignItems={{ sm: 'center' }}>
          <Avatar
            sx={{
              width: 72,
              height: 72,
              bgcolor: 'primary.main',
              fontSize: 28,
              fontWeight: 700,
            }}
          >
            {initials}
          </Avatar>
          <Box minWidth={0}>
            <Typography variant="h4" fontWeight={800} lineHeight={1.2}>
              Profile Settings
            </Typography>
            <Typography variant="body1" color="text.secondary" mt={0.75}>
              Manage your account details, workspace access, and password settings.
            </Typography>
            <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1} mt={2}>
              <Chip size="small" color="primary" label={user?.role ?? 'User'} />
              <Chip
                size="small"
                color={/approved|active/i.test(user?.status ?? '') ? 'success' : 'default'}
                label={user?.status ?? '—'}
              />
              {user?.businessName && <Chip size="small" variant="outlined" label={user.businessName} />}
            </Stack>
          </Box>
        </Stack>
      </Paper>

      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 1.6fr) minmax(280px, 1fr)' },
          alignItems: 'start',
        }}
      >
        <Stack spacing={2}>
          <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 3 }}>
            <Typography variant="h6" fontWeight={700} mb={0.5}>
              Profile Information
            </Typography>
            <Typography variant="body2" color="text.secondary" mb={2.5}>
              Basic account information and how your access is configured.
            </Typography>

            <Box
              sx={{
                display: 'grid',
                gap: 1.5,
                gridTemplateColumns: { xs: '1fr', xl: 'repeat(2, minmax(0, 1fr))' },
              }}
            >
              <InfoCard
                icon={<PersonOutlineOutlinedIcon fontSize="small" />}
                label="Full name"
                value={user?.fullName}
              />
              <InfoCard
                icon={<EmailOutlinedIcon fontSize="small" />}
                label="Email address"
                value={user?.email}
                helper="Email cannot be changed here."
              />
              <InfoCard
                icon={<BadgeOutlinedIcon fontSize="small" />}
                label="Role"
                value={user?.role}
                accent={<Chip label={user?.role ?? '—'} size="small" color="primary" />}
              />
              <InfoCard
                icon={<BadgeOutlinedIcon fontSize="small" />}
                label="Account status"
                value={user?.status}
                accent={
                  <Chip
                    label={user?.status ?? '—'}
                    size="small"
                    color={/approved|active/i.test(user?.status ?? '') ? 'success' : 'default'}
                  />
                }
              />
              <InfoCard
                icon={<WorkOutlineOutlinedIcon fontSize="small" />}
                label="User type"
                value={user?.userType}
              />
              {user?.businessName && (
                <InfoCard
                  icon={<BusinessOutlinedIcon fontSize="small" />}
                  label="Business"
                  value={user.businessName}
                />
              )}
            </Box>
          </Paper>

          <Paper elevation={0} sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 3 }}>
            <Typography variant="h6" fontWeight={700} mb={0.5}>
              Change Password
            </Typography>
            <Typography variant="body2" color="text.secondary" mb={2}>
              We send a one-time code to <strong>{email}</strong>, then you confirm a new password.
            </Typography>
            {message && <Alert severity="success" sx={{ mb: 2 }}>{message}</Alert>}
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

            <Stack spacing={2} component="form" onSubmit={onResetPassword}>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.25} alignItems={{ sm: 'center' }}>
                <Button
                  variant="outlined"
                  onClick={onRequestOtp}
                  disabled={requesting || !email}
                  startIcon={<LockOutlinedIcon />}
                  sx={{ alignSelf: 'flex-start', textTransform: 'none', fontWeight: 600 }}
                >
                  {otpSent ? 'Resend OTP' : 'Send OTP'}
                </Button>
                <Typography variant="caption" color="text.secondary">
                  Use this when you need to rotate your login password.
                </Typography>
              </Stack>

              {(otpSent || otp) && (
                <>
                  <Divider />
                  <Box
                    sx={{
                      display: 'grid',
                      gap: 1.5,
                      gridTemplateColumns: { xs: '1fr', md: 'repeat(2, minmax(0, 1fr))' },
                    }}
                  >
                    <TextField
                      label="OTP code"
                      value={otp}
                      onChange={(e) => setOtp(e.target.value)}
                      required
                      fullWidth
                    />
                    <Box />
                    <TextField
                      label="New password"
                      type="password"
                      value={newPassword}
                      onChange={(e) => setNewPassword(e.target.value)}
                      required
                      fullWidth
                      helperText="At least 8 characters"
                    />
                    <TextField
                      label="Confirm password"
                      type="password"
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      required
                      fullWidth
                    />
                  </Box>
                  <Button
                    type="submit"
                    variant="contained"
                    disabled={resetting}
                    sx={{ alignSelf: 'flex-start', textTransform: 'none', fontWeight: 600 }}
                  >
                    Update password
                  </Button>
                </>
              )}
            </Stack>
          </Paper>
        </Stack>

        <Stack spacing={2}>
          <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 3 }}>
            <Typography variant="subtitle1" fontWeight={700} mb={1.5}>
              Account Summary
            </Typography>
            <Stack spacing={1.5}>
              <SummaryRow label="Display name" value={displayName} />
              <SummaryRow label="Shipping line" value={shippingLine?.brandName ?? 'Default shipping line'} />
              <SummaryRow label="Email" value={email || '—'} />
            </Stack>
          </Paper>

          {user?.role === 'Broker' && (
            <Paper elevation={0} sx={{ p: 2.25, border: 1, borderColor: 'divider', borderRadius: 3 }}>
              <Typography variant="subtitle1" fontWeight={700} mb={0.75}>
                Workspace Access
              </Typography>
              <Typography variant="body2" color="text.secondary" mb={2}>
                Switch consignee workspace or apply a referral code from your broker tools.
              </Typography>
              <Button
                component={RouterLink}
                to="/workspace"
                variant="outlined"
                sx={{ textTransform: 'none', fontWeight: 600 }}
              >
                Open workspace
              </Button>
            </Paper>
          )}

          <Paper
            elevation={0}
            sx={{
              p: 2.25,
              border: 1,
              borderColor: 'divider',
              borderRadius: 3,
              bgcolor: 'rgba(11,61,92,0.04)',
            }}
          >
            <Typography variant="subtitle1" fontWeight={700} mb={0.75}>
              Security Notes
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Keep your password private and request a fresh OTP whenever you need to update your credentials.
            </Typography>
          </Paper>
        </Stack>
      </Box>
    </Stack>
  );
}

function getInitials(value: string) {
  return value
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || 'U';
}

function InfoCard({
  icon,
  label,
  value,
  helper,
  accent,
}: {
  icon: ReactNode;
  label: string;
  value?: string | null;
  helper?: string;
  accent?: ReactNode;
}) {
  return (
    <Paper elevation={0} sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2 }}>
      <Stack direction="row" spacing={1.25} alignItems="flex-start">
        <Box
          sx={{
            width: 36,
            height: 36,
            borderRadius: 2,
            bgcolor: 'rgba(11,61,92,0.08)',
            color: 'primary.main',
            display: 'grid',
            placeItems: 'center',
            flexShrink: 0,
          }}
        >
          {icon}
        </Box>
        <Box minWidth={0} flex={1}>
          <Typography variant="caption" color="text.secondary" fontWeight={600}>
            {label}
          </Typography>
          {accent ? (
            <Box mt={0.75}>
              {accent}
            </Box>
          ) : (
            <Typography
              variant="body1"
              fontWeight={700}
              sx={{ wordBreak: 'break-word', overflowWrap: 'anywhere' }}
            >
              {value || '—'}
            </Typography>
          )}
          {helper && (
            <Typography variant="caption" color="text.secondary" display="block" mt={0.5}>
              {helper}
            </Typography>
          )}
        </Box>
      </Stack>
    </Paper>
  );
}

function SummaryRow({ label, value }: { label: string; value?: string | null }) {
  return (
    <Stack direction="row" justifyContent="space-between" spacing={2}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600} textAlign="right">
        {value || '—'}
      </Typography>
    </Stack>
  );
}
