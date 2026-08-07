import { FormEvent, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import LinkOutlinedIcon from '@mui/icons-material/LinkOutlined';
import HelpOutlineOutlinedIcon from '@mui/icons-material/HelpOutlineOutlined';
import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { useApplyReferralMutation } from '../../app/api';

const HOW_IT_WORKS = [
  {
    title: 'Get a code',
    body: 'Ask the consignee you want to work with for a referral code.',
  },
  {
    title: 'Apply it here',
    body: 'Enter the code below to create the relationship.',
  },
  {
    title: 'Access their cargo',
    body: 'Once linked, you can open their manifests and eDOs.',
  },
  {
    title: 'Handle assignments',
    body: 'The consignee can assign you to their shipments.',
  },
] as const;

function apiErrorMessage(err: unknown, fallback: string): string {
  if (typeof err === 'object' && err && 'data' in err) {
    const data = (err as { data?: { error?: string; title?: string; detail?: string; message?: string } })
      .data;
    return data?.error || data?.detail || data?.message || data?.title || fallback;
  }
  return fallback;
}

export function ApplyReferralPage() {
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const [applyReferral, { isLoading }] = useApplyReferralMutation();
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  if (user?.role !== 'Broker') {
    return <Alert severity="warning">Only brokers can apply referral codes.</Alert>;
  }

  const trimmed = code.trim().toUpperCase();

  const openConfirm = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setMessage(null);
    if (trimmed.length < 5) {
      setError('Please enter a valid referral code (at least 5 characters).');
      return;
    }
    setConfirmOpen(true);
  };

  const onConfirm = async () => {
    setError(null);
    setMessage(null);
    try {
      await applyReferral({ code: trimmed }).unwrap();
      setConfirmOpen(false);
      setMessage('Linked successfully. Choose your workspace next.');
      setTimeout(() => navigate('/workspace'), 700);
    } catch (err) {
      setConfirmOpen(false);
      setError(apiErrorMessage(err, 'Could not apply that referral code. Check it and try again.'));
    }
  };

  return (
    <Stack spacing={3} flex={1} maxWidth={640} mx="auto" width="100%">
      <Box>
        <Button
          component={RouterLink}
          to="/workspace"
          startIcon={<ArrowBackOutlinedIcon />}
          color="inherit"
          sx={{ mb: 2, ml: -1 }}
        >
          Back to workspaces
        </Button>
        <Box textAlign="center" mb={1}>
          <Box
            sx={{
              width: 64,
              height: 64,
              borderRadius: 3,
              bgcolor: 'secondary.main',
              color: 'secondary.contrastText',
              display: 'grid',
              placeItems: 'center',
              mx: 'auto',
              mb: 2.5,
              boxShadow: (t) =>
                t.palette.mode === 'dark'
                  ? '0 12px 28px rgba(0,0,0,0.35)'
                  : '0 12px 28px rgba(196,92,38,0.22)',
            }}
          >
            <LinkOutlinedIcon sx={{ fontSize: 30 }} />
          </Box>
          <Typography variant="h4" fontWeight={800} lineHeight={1.2}>
            Apply referral code
          </Typography>
          <Typography color="text.secondary" mt={1} maxWidth={420} mx="auto">
            Link with a consignee using their referral code to unlock workspace access.
          </Typography>
        </Box>
      </Box>

      {error && (
        <Alert severity="error" onClose={() => setError(null)}>
          {error}
        </Alert>
      )}
      {message && <Alert severity="success">{message}</Alert>}

      <Box
        sx={{
          display: 'grid',
          gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' },
          gap: 1.25,
        }}
      >
        {HOW_IT_WORKS.map((step, index) => (
          <Paper
            key={step.title}
            elevation={0}
            sx={{
              p: 1.75,
              border: 1,
              borderColor: 'divider',
              borderRadius: 2,
              bgcolor: 'background.paper',
            }}
          >
            <Stack direction="row" spacing={1.25} alignItems="flex-start">
              <Box
                sx={{
                  width: 26,
                  height: 26,
                  borderRadius: '50%',
                  bgcolor: 'primary.main',
                  color: 'primary.contrastText',
                  display: 'grid',
                  placeItems: 'center',
                  fontWeight: 800,
                  fontSize: 12,
                  flexShrink: 0,
                }}
              >
                {index + 1}
              </Box>
              <Box minWidth={0}>
                <Typography variant="subtitle2" fontWeight={700}>
                  {step.title}
                </Typography>
                <Typography variant="caption" color="text.secondary" display="block" mt={0.25}>
                  {step.body}
                </Typography>
              </Box>
            </Stack>
          </Paper>
        ))}
      </Box>

      <Paper
        component="form"
        onSubmit={openConfirm}
        elevation={0}
        sx={{
          p: { xs: 2.5, sm: 3 },
          border: 1,
          borderColor: 'divider',
          borderRadius: 3,
          bgcolor: 'background.paper',
        }}
      >
        <Stack spacing={0.5} mb={2.5}>
          <Typography variant="subtitle1" fontWeight={700}>
            Enter code
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Codes are case-insensitive and provided by the consignee.
          </Typography>
        </Stack>

        <TextField
          label="Referral code"
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase())}
          required
          fullWidth
          autoComplete="off"
          autoFocus
          placeholder="ENTER REFERRAL CODE"
          helperText="At least 5 characters · provided by the consignee"
          inputProps={{
            style: {
              fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
              letterSpacing: 2,
              fontWeight: 700,
              fontSize: '1.05rem',
            },
            spellCheck: false,
          }}
          sx={{ mb: 3 }}
        />

        <Stack direction={{ xs: 'column-reverse', sm: 'row' }} spacing={1.5} justifyContent="flex-end">
          <Button component={RouterLink} to="/workspace" color="inherit" disabled={isLoading}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="contained"
            size="large"
            disabled={isLoading || trimmed.length < 5}
            startIcon={<LinkOutlinedIcon />}
          >
            Apply code
          </Button>
        </Stack>
      </Paper>

      <Paper
        elevation={0}
        sx={{
          p: 2.5,
          border: 1,
          borderColor: 'divider',
          borderRadius: 3,
          bgcolor: 'background.paper',
        }}
      >
        <Stack direction="row" spacing={1.25} alignItems="flex-start">
          <HelpOutlineOutlinedIcon color="action" sx={{ mt: 0.15 }} />
          <Box>
            <Typography variant="subtitle2" fontWeight={700} mb={0.5}>
              Need help?
            </Typography>
            <Typography variant="body2" color="text.secondary">
              If you don&apos;t have a referral code, contact the consignee and ask them to generate
              one from their brokers dashboard.
            </Typography>
          </Box>
        </Stack>
      </Paper>

      <Dialog
        open={confirmOpen}
        onClose={() => !isLoading && setConfirmOpen(false)}
        maxWidth="xs"
        fullWidth
      >
        <DialogTitle sx={{ textAlign: 'center', pt: 3, pb: 1 }}>
          <Box
            sx={{
              width: 56,
              height: 56,
              borderRadius: '50%',
              bgcolor: 'action.selected',
              color: 'primary.main',
              display: 'grid',
              placeItems: 'center',
              mx: 'auto',
              mb: 1.5,
            }}
          >
            <LinkOutlinedIcon />
          </Box>
          Confirm referral code
        </DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" textAlign="center" mb={2}>
            Are you sure you want to apply this referral code?
          </Typography>
          <Box
            sx={{
              bgcolor: (t) =>
                t.palette.mode === 'dark' ? 'rgba(255,255,255,0.06)' : 'action.hover',
              borderRadius: 2,
              px: 2,
              py: 1.5,
              mb: 2,
              textAlign: 'center',
            }}
          >
            <Typography variant="caption" color="text.secondary" display="block" mb={0.5}>
              Referral code
            </Typography>
            <Typography
              fontFamily="ui-monospace, SFMono-Regular, Menlo, monospace"
              fontWeight={800}
              letterSpacing={2}
              fontSize="1.15rem"
            >
              {trimmed}
            </Typography>
          </Box>
          <Stack direction="row" spacing={1} alignItems="flex-start">
            <CheckCircleOutlineOutlinedIcon color="success" sx={{ fontSize: 18, mt: 0.2 }} />
            <Typography variant="caption" color="text.secondary">
              This creates a relationship with the consignee and lets you access their manifests.
            </Typography>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 3, gap: 1 }}>
          <Button onClick={() => setConfirmOpen(false)} color="inherit" disabled={isLoading} fullWidth>
            Cancel
          </Button>
          <Button onClick={onConfirm} variant="contained" disabled={isLoading} fullWidth>
            {isLoading ? 'Applying…' : 'Confirm'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
