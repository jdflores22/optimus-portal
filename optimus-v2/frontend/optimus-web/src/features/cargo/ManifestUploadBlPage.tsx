import { FormEvent, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import InsertDriveFileOutlinedIcon from '@mui/icons-material/InsertDriveFileOutlined';
import { Link as RouterLink, Navigate, useNavigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { useGetManifestQuery, useUploadBlMutation } from '../../app/api';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function ManifestUploadBlPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const isBroker = user?.role === 'Broker' || user?.role === 'SystemAdmin';
  const { data, error, isLoading } = useGetManifestQuery(id, { skip: !id });
  const [uploadBl] = useUploadBlMutation();

  const [blNumber, setBlNumber] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!data) return;
    setBlNumber(data.blNumber || data.manifestNumber || '');
  }, [data]);

  if (!isBroker) return <Navigate to={`/manifests/${id}`} replace />;
  if (error) return <Alert severity="error">Manifest not found.</Alert>;
  if (isLoading || !data) return <Typography>Loading...</Typography>;

  if (data.workflowState !== 'BlGenerated' || data.blFilePath) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  if (user?.role === 'Broker' && data.brokerId && data.brokerId !== user.id) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  const expectedBl = data.blNumber || data.manifestNumber;
  const blLocked = Boolean(data.blNumber);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setFormError(null);
    if (!blNumber.trim()) {
      setFormError('BL Number is required.');
      return;
    }
    if (
      data.blNumber &&
      blNumber.trim().toUpperCase() !== data.blNumber.trim().toUpperCase()
    ) {
      setFormError(`BL Number must match ${data.blNumber}.`);
      return;
    }
    if (!file) {
      setFormError('Choose a BL file (PDF, JPG, or PNG).');
      return;
    }
    setConfirmOpen(true);
  };

  const onConfirmUpload = async () => {
    if (!file) return;
    setFormError(null);
    setSubmitting(true);
    try {
      await uploadBl({
        id,
        file,
        blNumber: blNumber.trim().toUpperCase(),
      }).unwrap();
      setConfirmOpen(false);
      navigate(`/manifests/${id}`, {
        replace: true,
        state: { flash: 'BL uploaded successfully. Accounting can generate billing next.' },
      });
    } catch (err: unknown) {
      setConfirmOpen(false);
      const msg =
        err && typeof err === 'object' && 'data' in err
          ? String((err as { data?: { error?: string } }).data?.error ?? 'Failed to upload BL.')
          : 'Failed to upload BL.';
      setFormError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <WorkflowPage
      eyebrow="Manifest workflow · Step 3"
      title="Upload Bill of Lading"
      subtitle="Confirm the issued BL number and attach your signed copy to unlock billing."
      chips={
        <>
          <Chip size="small" label={data.manifestNumber} variant="outlined" sx={{ fontWeight: 700 }} />
          {data.vesselName && <Chip size="small" label={data.vesselName} variant="outlined" />}
          {data.consigneeName && <Chip size="small" label={data.consigneeName} variant="outlined" />}
        </>
      }
      actions={
        <Button component={RouterLink} to={`/manifests/${id}`} startIcon={<ArrowBackOutlinedIcon />}>
          Back
        </Button>
      }
    >
      {formError && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setFormError(null)}>
          {formError}
        </Alert>
      )}

      <Box component="form" onSubmit={onSubmit} sx={{ maxWidth: 640 }}>
        <WorkflowSection title="BL upload" subtitle="Match the shipping-line number, then attach the document.">
          <Stack spacing={2.5}>
            <TextField
              required
              label="BL Number"
              value={blNumber}
              onChange={(e) => setBlNumber(e.target.value)}
              InputProps={{ readOnly: blLocked }}
              helperText={blLocked ? `Issued by shipping line · ${expectedBl}` : 'Enter the official Bill of Lading number'}
              fullWidth
            />

            <Box
              component="label"
              sx={{
                display: 'flex',
                alignItems: 'center',
                gap: 1.75,
                px: 2,
                py: 2.25,
                border: 1,
                borderStyle: 'dashed',
                borderColor: file ? 'primary.main' : 'divider',
                borderRadius: 2,
                bgcolor: file ? 'action.hover' : 'background.default',
                cursor: 'pointer',
                transition: 'border-color 160ms ease, background-color 160ms ease',
                '&:hover': { borderColor: 'primary.main' },
              }}
            >
              <Box
                sx={{
                  width: 44,
                  height: 44,
                  borderRadius: 1.5,
                  display: 'grid',
                  placeItems: 'center',
                  flexShrink: 0,
                  bgcolor: file ? 'primary.main' : 'action.selected',
                  color: file ? 'primary.contrastText' : 'text.secondary',
                }}
              >
                {file ? <InsertDriveFileOutlinedIcon /> : <UploadFileOutlinedIcon />}
              </Box>
              <Box minWidth={0} flex={1}>
                <Typography fontWeight={700} noWrap>
                  {file ? file.name : 'Choose BL file'}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {file
                    ? `${Math.max(1, Math.round(file.size / 1024))} KB · click to replace`
                    : 'PDF, JPG, or PNG'}
                </Typography>
              </Box>
              <input
                hidden
                type="file"
                accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              />
            </Box>

            <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="flex-end" spacing={1.25} pt={0.5}>
              <Button component={RouterLink} to={`/manifests/${id}`} disabled={submitting}>
                Cancel
              </Button>
              <Button
                type="submit"
                variant="contained"
                disabled={submitting}
                startIcon={<UploadFileOutlinedIcon />}
              >
                Continue
              </Button>
            </Stack>
          </Stack>
        </WorkflowSection>
      </Box>

      <Dialog
        open={confirmOpen}
        onClose={() => (submitting ? null : setConfirmOpen(false))}
        fullWidth
        maxWidth="xs"
      >
        <DialogTitle>Confirm upload</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={1.5}>
            <Typography variant="body2" color="text.secondary">
              Manifest moves to BL Uploaded and shipping-line staff are notified.
            </Typography>
            <Box>
              <Typography variant="caption" color="text.secondary" fontWeight={600}>
                BL Number
              </Typography>
              <Typography fontWeight={700}>{blNumber.trim().toUpperCase()}</Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary" fontWeight={600}>
                File
              </Typography>
              <Typography fontWeight={700} sx={{ wordBreak: 'break-word' }}>
                {file?.name}
              </Typography>
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setConfirmOpen(false)} disabled={submitting}>
            Edit
          </Button>
          <Button
            variant="contained"
            onClick={onConfirmUpload}
            disabled={submitting}
            startIcon={<UploadFileOutlinedIcon />}
          >
            {submitting ? 'Uploading...' : 'Upload'}
          </Button>
        </DialogActions>
      </Dialog>
    </WorkflowPage>
  );
}
