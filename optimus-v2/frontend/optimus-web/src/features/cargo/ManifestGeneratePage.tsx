import { FormEvent, useEffect, useMemo, useState } from 'react';
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import PictureAsPdfOutlinedIcon from '@mui/icons-material/PictureAsPdfOutlined';
import { Link as RouterLink, Navigate, useNavigate, useParams } from 'react-router-dom';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import {
  useGenerateBlMutation,
  useGetContainersQuery,
  useGetManifestQuery,
} from '../../app/api';
import { API_BASE_URL } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function toLocalInputValue(iso?: string | null): string {
  if (!iso) {
    const now = new Date();
    now.setSeconds(0, 0);
    return now.toISOString().slice(0, 16);
  }
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function teuFromSize(sizeCode?: string | null): number {
  if (!sizeCode) return 1;
  if (/40|45|hc/i.test(sizeCode)) return 2;
  return 1;
}

export function ManifestGeneratePage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useSelector((state: RootState) => state.auth);
  const isStaff = ['SlStaff', 'ShippingLinesAdmin', 'SystemAdmin'].includes(user?.role ?? '');
  const { data, error, isLoading } = useGetManifestQuery(id, { skip: !id });
  const { data: containers = [] } = useGetContainersQuery();
  const [generateBl] = useGenerateBlMutation();

  const [manifestBlNumber, setManifestBlNumber] = useState('');
  const [actualArrivalDate, setActualArrivalDate] = useState('');
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!data) return;
    setManifestBlNumber(data.manifestNumber || '');
    setActualArrivalDate(toLocalInputValue(data.arrivalDate));
  }, [data]);

  const linkedContainers = useMemo(
    () => containers.filter((c) => c.manifestId === id),
    [containers, id],
  );
  const totalTeu = useMemo(
    () => linkedContainers.reduce((sum, c) => sum + teuFromSize(c.sizeCode), 0),
    [linkedContainers],
  );

  if (!isStaff) return <Navigate to={`/manifests/${id}`} replace />;
  if (error) return <Alert severity="error">Manifest not found.</Alert>;
  if (isLoading || !data) return <Typography>Loading...</Typography>;

  if (data.workflowState !== 'NoaGenerated' || data.blPdfPath || data.manifestFilePath) {
    return <Navigate to={`/manifests/${id}`} replace />;
  }

  if (!data.brokerId) {
    return (
      <WorkflowPage
        eyebrow="Manifest workflow · Step 2"
        title="Generate Manifest/BL"
        subtitle="Broker assignment is required before this step."
        actions={
          <Button component={RouterLink} to={`/manifests/${id}`} startIcon={<ArrowBackOutlinedIcon />}>
            Back
          </Button>
        }
      >
        <Alert severity="warning">
          Cannot generate Manifest/BL until the consignee assigns a broker. Ask the consignee to assign a connected
          broker while this shipment is still in NOA Generated status.
        </Alert>
        <Box mt={2}>
          <Button component={RouterLink} to={`/manifests/${id}`} variant="contained">
            Back to manifest
          </Button>
        </Box>
      </WorkflowPage>
    );
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setFormError(null);
    if (!manifestBlNumber.trim()) {
      setFormError('Manifest/BL Number is required.');
      return;
    }
    if (!actualArrivalDate) {
      setFormError('Actual Arrival Date is required.');
      return;
    }
    setConfirmOpen(true);
  };

  const onConfirmGenerate = async () => {
    setFormError(null);
    setSubmitting(true);
    try {
      const updated = await generateBl({
        id,
        manifestBlNumber: manifestBlNumber.trim(),
        actualArrivalDate: new Date(actualArrivalDate).toISOString(),
      }).unwrap();
      setConfirmOpen(false);
      const pdf = updated.blPdfPath || updated.manifestFilePath;
      if (pdf) {
        window.open(`${API_BASE_URL}${pdf}`, '_blank', 'noopener,noreferrer');
      }
      navigate(`/manifests/${id}`, { replace: true });
    } catch (err: unknown) {
      setConfirmOpen(false);
      const msg =
        err && typeof err === 'object' && 'data' in err
          ? String(
              (err as { data?: { error?: string; detail?: string; title?: string } }).data?.error
                ?? (err as { data?: { detail?: string } }).data?.detail
                ?? (err as { data?: { title?: string } }).data?.title
                ?? 'Failed to generate Manifest/BL PDF.',
            )
          : 'Failed to generate Manifest/BL PDF.';
      setFormError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <WorkflowPage
      eyebrow="Manifest workflow · Step 2"
      title="Generate Manifest/BL"
      subtitle={`Confirm arrival details for ${data.noaNumber ?? data.manifestNumber}, then generate the Manifest/Bill of Lading PDF.`}
      actions={
        <Button component={RouterLink} to={`/manifests/${id}`} startIcon={<ArrowBackOutlinedIcon />}>
          Back
        </Button>
      }
    >
      <Alert severity="info" sx={{ mb: 2 }}>
        Enter the official Manifest/BL Number and confirm the actual arrival date before generating the document.
      </Alert>

      {formError && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setFormError(null)}>
          {formError}
        </Alert>
      )}

      <Box component="form" onSubmit={onSubmit}>
        <Stack spacing={3}>
          <WorkflowSection title="Required information" subtitle="These values are written onto the generated PDF and update the cargo record.">
            <Box
              sx={{
                p: 2.5,
                borderRadius: 2,
                border: 2,
                borderColor: 'warning.main',
                bgcolor: (t) => (t.palette.mode === 'dark' ? 'rgba(239,108,0,0.08)' : 'rgba(255,243,224,0.9)'),
              }}
            >
              <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
                <TextField
                  required
                  label="Manifest/BL Number"
                  value={manifestBlNumber}
                  onChange={(e) => setManifestBlNumber(e.target.value)}
                  helperText="Enter the official Manifest/Bill of Lading number"
                  fullWidth
                />
                <TextField
                  required
                  label="Actual Arrival Date"
                  type="datetime-local"
                  value={actualArrivalDate}
                  onChange={(e) => setActualArrivalDate(e.target.value)}
                  helperText={
                    data.arrivalDate
                      ? `Original ETA: ${new Date(data.arrivalDate).toLocaleString()}`
                      : 'Confirm when the vessel actually arrived'
                  }
                  InputLabelProps={{ shrink: true }}
                  fullWidth
                />
              </Box>
            </Box>
          </WorkflowSection>

          <WorkflowSection title="NOA information" subtitle="Read-only context carried from the Notice of Arrival.">
            <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
              {[
                ['NOA Number', data.noaNumber ?? '—'],
                ['B/L Number', data.blNumber ?? '—'],
                ['Vessel', data.vesselName ?? '—'],
                ['Port Location', data.portLocation ?? '—'],
                ['Consignee', data.consigneeName ?? '—'],
                ['Total Containers', `${linkedContainers.length} container(s)`],
              ].map(([label, value]) => (
                <Paper
                  key={label}
                  elevation={0}
                  sx={{ p: 1.75, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.default' }}
                >
                  <Typography variant="caption" color="text.secondary" fontWeight={600}>
                    {label}
                  </Typography>
                  <Typography fontWeight={700}>{value}</Typography>
                </Paper>
              ))}
            </Box>
          </WorkflowSection>

          <WorkflowSection title="Container summary" subtitle="Containers currently linked to this manifest.">
            {linkedContainers.length === 0 ? (
              <Alert severity="info" variant="outlined">
                No containers are linked to this manifest yet. The PDF will still generate without a container list.
              </Alert>
            ) : (
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>#</TableCell>
                    <TableCell>Container Number</TableCell>
                    <TableCell>Type</TableCell>
                    <TableCell>Size</TableCell>
                    <TableCell>TEU</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {linkedContainers.map((c, index) => (
                    <TableRow key={c.id}>
                      <TableCell>{index + 1}</TableCell>
                      <TableCell>
                        <Typography fontFamily="monospace" fontWeight={700}>
                          {c.containerNumber}
                        </Typography>
                      </TableCell>
                      <TableCell>{c.typeCode ?? '—'}</TableCell>
                      <TableCell>{c.sizeCode ?? '—'}</TableCell>
                      <TableCell>{teuFromSize(c.sizeCode)}</TableCell>
                    </TableRow>
                  ))}
                  <TableRow>
                    <TableCell colSpan={4} align="right">
                      <Typography fontWeight={700}>Total</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography fontWeight={700}>{totalTeu} TEU</Typography>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            )}
          </WorkflowSection>

          <Alert severity="warning">
            Once the Manifest/BL is generated, the arrival date will be updated in the system. Ensure all information is
            accurate before proceeding.
          </Alert>

          <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" spacing={1.5}>
            <Button component={RouterLink} to={`/manifests/${id}`} disabled={submitting}>
              Cancel
            </Button>
            <Button
              type="submit"
              variant="contained"
              color="success"
              disabled={submitting}
              startIcon={<PictureAsPdfOutlinedIcon />}
            >
              Generate Manifest/BL PDF
            </Button>
          </Stack>
        </Stack>
      </Box>

      <Dialog
        open={confirmOpen}
        onClose={() => (submitting ? null : setConfirmOpen(false))}
        fullWidth
        maxWidth="sm"
      >
        <DialogTitle>Confirm Manifest/BL generation</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2}>
            <Alert severity="warning" variant="outlined">
              This will generate the Manifest/BL PDF and update the actual arrival date. This step cannot be undone from
              this screen.
            </Alert>
            <Box sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
              {[
                ['Manifest/BL Number', manifestBlNumber.trim()],
                ['Actual Arrival', actualArrivalDate ? new Date(actualArrivalDate).toLocaleString() : '—'],
                ['NOA Number', data.noaNumber ?? '—'],
                ['Consignee', data.consigneeName ?? '—'],
                ['Vessel', data.vesselName ?? '—'],
                ['Containers', `${linkedContainers.length} · ${totalTeu} TEU`],
              ].map(([label, value]) => (
                <Paper
                  key={label}
                  elevation={0}
                  sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.default' }}
                >
                  <Typography variant="caption" color="text.secondary" fontWeight={600}>
                    {label}
                  </Typography>
                  <Typography fontWeight={700}>{value}</Typography>
                </Paper>
              ))}
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setConfirmOpen(false)} disabled={submitting}>
            Back to edit
          </Button>
          <Button
            variant="contained"
            color="success"
            onClick={onConfirmGenerate}
            disabled={submitting}
            startIcon={<PictureAsPdfOutlinedIcon />}
          >
            {submitting ? 'Generating PDF...' : 'Confirm Generate PDF'}
          </Button>
        </DialogActions>
      </Dialog>
    </WorkflowPage>
  );
}
