import { useMemo } from 'react';
import {
  Alert,
  Avatar,
  Box,
  Button,
  Chip,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import ArrowBackOutlinedIcon from '@mui/icons-material/ArrowBackOutlined';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { useGetShippingAdminBrokerQuery } from '../../app/api';
import { parseFormFields, parseSubmittedValues } from '../../shared/formSchema';
import { SubmissionDetailsPreview } from '../ops/SubmissionDetailsPreview';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function ShippingAdminBrokerDetailPage() {
  const { id = '' } = useParams();
  const { data, isLoading, isError } = useGetShippingAdminBrokerQuery(id, { skip: !id });

  const fields = useMemo(
    () => parseFormFields(data?.accreditation?.fieldsJson),
    [data?.accreditation?.fieldsJson],
  );
  const values = useMemo(
    () => parseSubmittedValues(data?.accreditation?.submittedDataJson),
    [data?.accreditation?.submittedDataJson],
  );

  if (isLoading) return <Typography sx={{ py: 4 }}>Loading...</Typography>;
  if (isError || !data) {
    return (
      <Stack spacing={2}>
        <Alert severity="error">Broker not found or not on your shipping line.</Alert>
        <Button component={RouterLink} to="/shipping-admin/brokers" startIcon={<ArrowBackOutlinedIcon />}>
          Back
        </Button>
      </Stack>
    );
  }

  const b = data.broker;
  const accreditation = data.accreditation;

  return (
    <WorkflowPage
      eyebrow="Broker detail"
      title={b.fullName}
      subtitle={b.email}
      chips={
        <>
          <Chip size="small" label={b.isActive ? 'Active' : 'Inactive'} color={b.isActive ? 'success' : 'default'} />
          {accreditation && (
            <Chip size="small" color="success" variant="outlined" label={`Accredited · v${accreditation.formVersion}`} />
          )}
        </>
      }
      actions={
        <Button component={RouterLink} to="/shipping-admin/brokers" startIcon={<ArrowBackOutlinedIcon />}>
          Back to brokers
        </Button>
      }
      stats={[
        { label: 'Consignees', value: b.consigneeCount, hint: 'Active links', tone: 'success' },
        { label: 'Manifests', value: b.manifestCount, hint: 'On your line', tone: 'primary' },
        { label: 'Containers', value: data.containerCount, hint: 'On your line', tone: 'warning' },
        { label: 'eDOs', value: b.edoCount, hint: 'On your line', tone: 'info' },
      ]}
    >
      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', md: '2fr 1fr' },
        }}
      >
        <Stack spacing={2}>
          <WorkflowSection title="Account" subtitle="Registered broker profile.">
            <Box
              sx={{
                display: 'grid',
                gap: 1.5,
                gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' },
              }}
            >
              <DetailItem label="Name" value={b.fullName} />
              <DetailItem label="Email" value={b.email} />
              <DetailItem label="Status" value={b.status} />
            </Box>
          </WorkflowSection>

          <WorkflowSection
            title="Accreditation form"
            subtitle={
              accreditation
                ? `${accreditation.formName} · submitted ${new Date(accreditation.submittedAt).toLocaleString()}`
                : 'Approved accreditation submission for your shipping line.'
            }
            actions={
              accreditation?.approvedAt ? (
                <Chip
                  size="small"
                  color="success"
                  label={`Approved ${new Date(accreditation.approvedAt).toLocaleDateString()}`}
                />
              ) : undefined
            }
          >
            {!accreditation ? (
              <Alert severity="info" variant="outlined">
                No approved accreditation form is on file for this broker.
              </Alert>
            ) : fields.length === 0 ? (
              <Alert severity="warning" variant="outlined">
                Accreditation exists, but the form definition could not be loaded.
              </Alert>
            ) : (
              <SubmissionDetailsPreview fields={fields} values={values} />
            )}
          </WorkflowSection>

          <WorkflowSection title="Recent manifests" subtitle="Latest cargo activity on your shipping line.">
            {data.recentManifests.length === 0 ? (
              <Typography variant="body2" color="text.secondary">No manifests yet.</Typography>
            ) : (
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Manifest</TableCell>
                    <TableCell>State</TableCell>
                    <TableCell>Created</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {data.recentManifests.map((m) => (
                    <TableRow key={m.id}>
                      <TableCell>
                        <Button component={RouterLink} to={`/manifests/${m.id}`} size="small" sx={{ textTransform: 'none' }}>
                          {m.manifestNumber}
                        </Button>
                      </TableCell>
                      <TableCell>{m.workflowState}</TableCell>
                      <TableCell>{new Date(m.createdAt).toLocaleDateString()}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </WorkflowSection>
        </Stack>

        <WorkflowSection title="Linked consignees" subtitle="Active relationships accredited to your line.">
          {b.linkedConsignees.length === 0 ? (
            <Typography variant="body2" color="text.secondary">No linked consignees.</Typography>
          ) : (
            <Stack spacing={1.25}>
              {b.linkedConsignees.map((c) => (
                <Stack
                  key={c.id}
                  component={RouterLink}
                  to={`/shipping-admin/consignees/${c.id}`}
                  direction="row"
                  spacing={1}
                  alignItems="center"
                  sx={{ textDecoration: 'none', color: 'inherit' }}
                >
                  <Avatar sx={{ width: 32, height: 32, bgcolor: 'primary.main', fontSize: 12 }}>
                    {c.name.slice(0, 2).toUpperCase()}
                  </Avatar>
                  <Box>
                    <Typography fontWeight={600}>{c.name}</Typography>
                    <Typography variant="caption" color="text.secondary">{c.email}</Typography>
                  </Box>
                </Stack>
              ))}
            </Stack>
          )}
        </WorkflowSection>
      </Box>
    </WorkflowPage>
  );
}

function DetailItem({ label, value }: { label: string; value: string }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary" display="block">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600}>
        {value}
      </Typography>
    </Box>
  );
}
