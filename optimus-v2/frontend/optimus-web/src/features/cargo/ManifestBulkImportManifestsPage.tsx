import { useState } from 'react';
import { Alert, Box, Button, Paper, Stack, Typography } from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import CloudUploadOutlinedIcon from '@mui/icons-material/CloudUploadOutlined';
import DownloadOutlinedIcon from '@mui/icons-material/DownloadOutlined';
import { Link as RouterLink } from 'react-router-dom';
import { useBulkImportManifestsMutation } from '../../app/api';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function downloadTemplate(filename: string, content: string) {
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export function ManifestBulkImportManifestsPage() {
  const { shippingLineId } = useDefaultShippingLine();
  const [bulkImport] = useBulkImportManifestsMutation();
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onImport = async (file: File | null | undefined) => {
    setMessage(null);
    setError(null);
    if (!file || !shippingLineId) {
      setError('Shipping line is not configured.');
      return;
    }
    try {
      const result = await bulkImport({ shippingLineId, file }).unwrap();
      setMessage(`Import done: ${result.successCount} ok, ${result.errorCount} errors`);
    } catch {
      setError('Bulk import failed.');
    }
  };

  return (
    <WorkflowPage
      eyebrow="Manifest workflow"
      title="Bulk Import Manifests"
      subtitle="Dedicated manifest import surface, modeled after the V1 bulk manifest import page with template guidance and a focused upload action."
      actions={
        <Button component={RouterLink} to="/manifests" startIcon={<ArrowBackOutlinedIcon />}>
          Back to manifests
        </Button>
      }
      stats={[
        { label: 'Mode', value: 'Bulk manifest import', hint: 'CSV task surface', tone: 'primary' },
        { label: 'Template', value: 'Ready', hint: 'Downloadable CSV format', tone: 'success' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}

      <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 1.2fr) minmax(280px, 360px)' } }}>
        <WorkflowSection title="Upload manifest file" subtitle="Upload a CSV containing the manifest-generation rows you want to process.">
          <Stack spacing={2}>
            <Button
              variant="contained"
              component="label"
              startIcon={<CloudUploadOutlinedIcon />}
              disabled={!shippingLineId}
            >
              Upload & import manifests
              <input
                hidden
                type="file"
                accept=".csv,text/csv"
                onChange={async (e) => {
                  await onImport(e.target.files?.[0]);
                }}
              />
            </Button>
            <Typography variant="caption" color="text.secondary">
              Supported format in the current V2 backend: `.csv` and `.txt`.
            </Typography>
          </Stack>
        </WorkflowSection>

        <Stack spacing={3}>
          <WorkflowSection
            title="Template"
            subtitle="Use the provided CSV structure to minimize import errors."
            actions={
              <Button
                size="small"
                startIcon={<DownloadOutlinedIcon />}
                onClick={() =>
                  downloadTemplate(
                    'manifest-import-template.csv',
                    'ManifestNumber,Vessel,Voyage,ArrivalDate,BlNumber\nMF-200001,MV Cargo,V002,2026-08-07,BL-200001\n',
                  )
                }
              >
                Download template
              </Button>
            }
          >
            <Paper elevation={0} sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.default' }}>
              <Typography variant="caption" component="pre" sx={{ m: 0, whiteSpace: 'pre-wrap' }}>
                ManifestNumber,Vessel,Voyage,ArrivalDate,BlNumber{'\n'}
                MF-200001,MV Cargo,V002,2026-08-07,BL-200001
              </Typography>
            </Paper>
          </WorkflowSection>

          <WorkflowSection title="How to use" subtitle="Operational guidance carried over from the V1 manifest import flow.">
            <Stack spacing={1}>
              <Typography variant="body2" color="text.secondary">
                1. Download the manifest template and fill one shipment per row.
              </Typography>
              <Typography variant="body2" color="text.secondary">
                2. Keep the column names unchanged.
              </Typography>
              <Typography variant="body2" color="text.secondary">
                3. Upload the file here and review the returned success and error totals.
              </Typography>
            </Stack>
          </WorkflowSection>
        </Stack>
      </Box>
    </WorkflowPage>
  );
}
