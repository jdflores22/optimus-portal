import { useEffect, useState } from 'react';
import { Alert, Button, Chip, Stack, TextField, Typography } from '@mui/material';
import {
  useGetShippingLinesQuery,
  useUpdateShippingLineMutation,
} from '../../app/api';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

/** Single shipping line brand settings (multi-line create/switch removed). */
export function ShippingLinesPage() {
  const { data = [], refetch, isFetching } = useGetShippingLinesQuery();
  const [updateLine, { isLoading }] = useUpdateShippingLineMutation();
  const line = data[0];
  const [brandName, setBrandName] = useState('');
  const [brandColor, setBrandColor] = useState('#0B3D5C');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (line) {
      setBrandName(line.brandName);
      setBrandColor(line.brandColor || '#0B3D5C');
    }
  }, [line]);

  const onSave = async () => {
    if (!line) return;
    setError(null);
    setMessage(null);
    try {
      await updateLine({
        id: line.id,
        brandName,
        brandColor,
        isActive: true,
      }).unwrap();
      setMessage('Brand settings saved.');
      refetch();
    } catch {
      setError('Failed to update brand settings.');
    }
  };

  return (
    <WorkflowPage
      eyebrow="Administration"
      title="Brand settings"
      subtitle="Maintain the single shipping-line identity used throughout the portal, documents, and shared operator views."
      chips={
        <>
          <Chip size="small" label={line?.brandName ?? 'No line configured'} color="primary" />
          <Chip size="small" label={line?.isActive ? 'Active' : 'Setup required'} color={line?.isActive ? 'success' : 'warning'} />
        </>
      }
      stats={[
        { label: 'Shipping lines', value: data.length, hint: 'Single-line deployment', tone: 'primary' },
        { label: 'Brand color', value: brandColor, hint: 'Current theme accent', tone: 'info' },
      ]}
    >
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}
      {!line && !isFetching && (
        <Alert severity="warning">
          No shipping line configured. Seed the database or create one via migration/seed.
        </Alert>
      )}
      {line && (
        <WorkflowSection title="Visual identity" subtitle="Update the shipping line name and color used across the Shipping portal.">
          <Typography variant="body2" color="text.secondary" mb={2}>
            OPTIMUS uses a single shipping line. Update brand details here only.
          </Typography>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField
              label="Brand name"
              value={brandName}
              onChange={(e) => setBrandName(e.target.value)}
              fullWidth
            />
            <TextField
              label="Brand color"
              value={brandColor}
              onChange={(e) => setBrandColor(e.target.value)}
              sx={{ minWidth: 160 }}
            />
            <Button variant="contained" onClick={onSave} disabled={isLoading || !brandName}>
              Save
            </Button>
          </Stack>
        </WorkflowSection>
      )}
    </WorkflowPage>
  );
}
