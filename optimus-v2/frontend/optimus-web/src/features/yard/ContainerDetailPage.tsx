import {
  Alert,
  Box,
  Button,
  Chip,
  Divider,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import type { ReactNode } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { useGetContainerDetailsByNumberQuery } from '../../app/api';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function statusTone(status: string): 'success' | 'info' | 'warning' | 'default' {
  if (status === 'Available') return 'success';
  if (status === 'Reserved') return 'info';
  if (status === 'Pre-Forecast') return 'warning';
  return 'default';
}

function conditionTone(condition: string): 'success' | 'warning' | 'error' {
  if (condition === 'Good') return 'success';
  if (condition === 'Fair') return 'warning';
  return 'error';
}

function dwellTone(days: number): 'success' | 'warning' | 'error' {
  if (days <= 7) return 'success';
  if (days <= 21) return 'warning';
  return 'error';
}

function inspectionTone(result: string): 'success' | 'warning' | 'error' {
  if (result === 'Pass') return 'success';
  if (/minor|conditional/i.test(result)) return 'warning';
  return 'error';
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary" fontWeight={600}>
        {label}
      </Typography>
      <Box mt={0.5}>{children}</Box>
    </Box>
  );
}

function DocRow({ label, value }: { label: string; value: string }) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="center" spacing={2}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" fontFamily="ui-monospace, monospace" fontWeight={600}>
        {value}
      </Typography>
    </Stack>
  );
}

function ChargeRow({ label, value, strong }: { label: string; value: number; strong?: boolean }) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="center" spacing={2}>
      <Typography variant="body2" color={strong ? 'text.primary' : 'text.secondary'} fontWeight={strong ? 700 : 400}>
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={strong ? 700 : 500}>
        ${value.toFixed(2)}
      </Typography>
    </Stack>
  );
}

export function ContainerDetailPage() {
  const { containerNumber = '' } = useParams();
  const { data, error, isLoading } = useGetContainerDetailsByNumberQuery(containerNumber, {
    skip: !containerNumber,
  });

  if (error) {
    return (
      <Alert severity="error">
        Container not found or you do not have access to view this container.
      </Alert>
    );
  }
  if (isLoading || !data) return <Typography>Loading...</Typography>;

  const { basicInfo, specifications, movement, documentation, charges, history, inspections } = data;
  const totalCharges = charges.storageCharges + charges.handlingCharges + charges.documentationFee;

  return (
    <WorkflowPage
      eyebrow="Container details & history"
      title={basicInfo.containerNumber}
      subtitle={`${basicInfo.shippingLineName} · ${basicInfo.location}`}
      chips={
        <>
          <Chip size="small" label={basicInfo.status} color={statusTone(basicInfo.status)} />
          <Chip size="small" label={basicInfo.condition} color={conditionTone(basicInfo.condition)} variant="outlined" />
        </>
      }
      actions={
        <Button component={RouterLink} to="/container-inventory" variant="outlined">
          Back to inventory
        </Button>
      }
    >
      <Box
        sx={{
          display: 'grid',
          gap: 3,
          gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 2fr) minmax(280px, 1fr)' },
        }}
      >
        <Stack spacing={3}>
          <WorkflowSection title="Basic Information">
            <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' } }}>
              <Field label="Container Number">
                <Typography fontFamily="ui-monospace, monospace" fontWeight={700}>
                  {basicInfo.containerNumber}
                </Typography>
              </Field>
              <Field label="Size/Type">
                <Chip
                  size="small"
                  label={basicInfo.sizeType}
                  color={basicInfo.sizeType.toLowerCase().startsWith('20') ? 'info' : 'secondary'}
                  variant="outlined"
                />
              </Field>
              <Field label="TEU Count">
                <Typography>{basicInfo.teuCount}</Typography>
              </Field>
              <Field label="Current Location">
                <Typography>{basicInfo.location}</Typography>
              </Field>
              <Field label="Gate In Date">
                <Typography>
                  {basicInfo.gateInDate
                    ? new Date(basicInfo.gateInDate).toLocaleString(undefined, {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                      })
                    : '—'}
                </Typography>
              </Field>
              <Field label="Dwell Time">
                <Chip
                  size="small"
                  label={`${basicInfo.dwellTime} days`}
                  color={dwellTone(basicInfo.dwellTime)}
                />
              </Field>
              {basicInfo.stackPosition && (
                <Field label="Stack Position">
                  <Typography fontFamily="ui-monospace, monospace">{basicInfo.stackPosition}</Typography>
                </Field>
              )}
            </Box>
          </WorkflowSection>

          <WorkflowSection title="Technical Specifications">
            <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' } }}>
              <Field label="Manufacturer">
                <Typography>{specifications.manufacturer}</Typography>
              </Field>
              <Field label="Year Built">
                <Typography>{specifications.yearBuilt}</Typography>
              </Field>
              <Field label="ISO Code">
                <Typography fontFamily="ui-monospace, monospace">{specifications.isoCode}</Typography>
              </Field>
              <Field label="CSC Plate">
                <Typography>{specifications.cscPlate}</Typography>
              </Field>
              <Field label="Max Gross Weight">
                <Typography>{specifications.maxGrossWeight}</Typography>
              </Field>
              <Field label="Tare Weight">
                <Typography>{specifications.tareWeight}</Typography>
              </Field>
              <Field label="Max Payload">
                <Typography>{specifications.maxPayload}</Typography>
              </Field>
              <Field label="Dimensions (L×W×H)">
                <Typography>
                  {specifications.length} × {specifications.width} × {specifications.height}
                </Typography>
              </Field>
            </Box>
          </WorkflowSection>

          <WorkflowSection title="Last Movement">
            <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' } }}>
              <Field label="Date & Time">
                <Typography>{movement.lastMovement}</Typography>
              </Field>
              <Field label="Movement Type">
                <Typography>{movement.movementType}</Typography>
              </Field>
              <Field label="From Location">
                <Typography>{movement.fromLocation}</Typography>
              </Field>
              <Field label="To Location">
                <Typography>{movement.toLocation}</Typography>
              </Field>
              <Field label="Operator">
                <Typography>{movement.operator}</Typography>
              </Field>
              <Field label="Equipment Used">
                <Typography>{movement.equipment}</Typography>
              </Field>
              <Box sx={{ gridColumn: { md: '1 / -1' } }}>
                <Field label="Remarks">
                  <Typography>{movement.remarks}</Typography>
                </Field>
              </Box>
            </Box>
          </WorkflowSection>
        </Stack>

        <Stack spacing={3}>
          <WorkflowSection title="Documentation">
            <Stack spacing={1.5} divider={<Divider flexItem />}>
              <DocRow label="Bill of Lading" value={documentation.billOfLading} />
              <DocRow label="Manifest" value={documentation.manifest} />
              <DocRow label="Customs Declaration" value={documentation.customsDeclaration} />
              {documentation.deliveryOrder && <DocRow label="Delivery Order" value={documentation.deliveryOrder} />}
              <DocRow label="Gate Pass" value={documentation.gatePass} />
            </Stack>
          </WorkflowSection>

          <WorkflowSection title="Charges">
            <Stack spacing={1.5} divider={<Divider flexItem />}>
              <ChargeRow label="Storage Charges" value={charges.storageCharges} />
              <ChargeRow label="Handling Charges" value={charges.handlingCharges} />
              <ChargeRow label="Documentation Fee" value={charges.documentationFee} />
              <ChargeRow label="Total Charges" value={totalCharges} strong />
            </Stack>
          </WorkflowSection>

          <WorkflowSection title="Quick Actions">
            <Stack spacing={1.25}>
              <Button variant="contained" fullWidth disabled>
                Generate Delivery Order
              </Button>
              <Button variant="outlined" color="inherit" fullWidth disabled>
                Print Gate Pass
              </Button>
              <Button variant="outlined" color="success" fullWidth disabled>
                Schedule Inspection
              </Button>
              <Button variant="outlined" fullWidth disabled>
                View Photos
              </Button>
              <Typography variant="caption" color="text.secondary">
                Actions match V1 stubs and will wire to workflows when those modules are enabled.
              </Typography>
            </Stack>
          </WorkflowSection>
        </Stack>
      </Box>

      <Box mt={3}>
        <WorkflowSection title="Movement History">
          {history.length === 0 ? (
            <Alert severity="info" variant="outlined">
              No movement history recorded yet.
            </Alert>
          ) : (
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Date & Time</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell>From</TableCell>
                  <TableCell>To</TableCell>
                  <TableCell>Operator</TableCell>
                  <TableCell>Equipment</TableCell>
                  <TableCell>Remarks</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {history.map((row, index) => (
                  <TableRow key={`${row.date}-${row.type}-${index}`} hover>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}>{row.date}</TableCell>
                    <TableCell>{row.type}</TableCell>
                    <TableCell>{row.fromLocation}</TableCell>
                    <TableCell>{row.toLocation}</TableCell>
                    <TableCell>{row.operator}</TableCell>
                    <TableCell>{row.equipment}</TableCell>
                    <TableCell>{row.remarks}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </WorkflowSection>
      </Box>

      <Box mt={3}>
        <WorkflowSection title="Inspection History">
          {inspections.length === 0 ? (
            <Alert severity="info" variant="outlined">
              No inspections recorded yet.
            </Alert>
          ) : (
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Date</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell>Inspector</TableCell>
                  <TableCell>Result</TableCell>
                  <TableCell>Photos</TableCell>
                  <TableCell>Remarks</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {inspections.map((row, index) => (
                  <TableRow key={`${row.date}-${row.type}-${index}`} hover>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}>{row.date}</TableCell>
                    <TableCell>{row.type}</TableCell>
                    <TableCell>{row.inspector}</TableCell>
                    <TableCell>
                      <Chip size="small" label={row.result} color={inspectionTone(row.result)} />
                    </TableCell>
                    <TableCell>{row.photos}</TableCell>
                    <TableCell>{row.remarks}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </WorkflowSection>
      </Box>
    </WorkflowPage>
  );
}
