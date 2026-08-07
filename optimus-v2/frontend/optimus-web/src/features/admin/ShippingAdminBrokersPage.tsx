import {
  Alert,
  Avatar,
  Box,
  Chip,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import { useGetShippingAdminBrokersQuery } from '../../app/api';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function ShippingAdminBrokersPage() {
  const { data = [], isLoading, isError, refetch } = useGetShippingAdminBrokersQuery();
  const { shippingLine } = useDefaultShippingLine();

  const totalConsignees = data.reduce((sum, b) => sum + b.consigneeCount, 0);
  const totalManifests = data.reduce((sum, b) => sum + b.manifestCount, 0);
  const totalEdos = data.reduce((sum, b) => sum + b.edoCount, 0);

  return (
    <WorkflowPage
      eyebrow="Shipping Lines Admin"
      title="Broker Management"
      subtitle={`Accredited brokers registered to ${shippingLine?.brandName ?? 'your shipping line'}.`}
      chips={
        <>
          <Chip size="small" color="primary" label={`${data.length} brokers`} />
          <Chip size="small" color="info" label={`${totalManifests} manifests`} />
        </>
      }
      actions={
        <Chip size="small" variant="outlined" label="Refresh" onClick={() => refetch()} clickable />
      }
      stats={[
        { label: 'Total Brokers', value: data.length, hint: 'Approved on your line', tone: 'primary' },
        { label: 'Linked Consignees', value: totalConsignees, hint: 'Active relationships', tone: 'success' },
        { label: 'Total Manifests', value: totalManifests, hint: 'On your line', tone: 'info' },
        { label: 'Total eDOs', value: totalEdos, hint: 'On your line', tone: 'warning' },
      ]}
    >
      {isError && <Alert severity="error">Could not load brokers.</Alert>}

      <WorkflowSection title="Brokers" subtitle="Browse accredited brokers and their cargo activity on your line.">
        {isLoading ? (
          <Typography color="text.secondary">Loading...</Typography>
        ) : data.length === 0 ? (
          <Alert severity="info" variant="outlined">
            No accredited brokers yet.
          </Alert>
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Broker</TableCell>
                <TableCell>Linked Consignees</TableCell>
                <TableCell align="center">Manifests</TableCell>
                <TableCell align="center">eDOs</TableCell>
                <TableCell>Status</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {data.map((b) => (
                <TableRow key={b.id} hover>
                  <TableCell>
                    <Stack
                      component={RouterLink}
                      to={`/shipping-admin/brokers/${b.id}`}
                      direction="row"
                      spacing={1.25}
                      alignItems="center"
                      sx={{ textDecoration: 'none', color: 'inherit' }}
                    >
                      <Avatar sx={{ width: 36, height: 36, bgcolor: 'secondary.main', fontSize: 13 }}>
                        {b.fullName.slice(0, 2).toUpperCase()}
                      </Avatar>
                      <Box>
                        <Typography fontWeight={700}>{b.fullName}</Typography>
                        <Typography variant="caption" color="text.secondary">
                          {b.email}
                        </Typography>
                      </Box>
                    </Stack>
                  </TableCell>
                  <TableCell>
                    {b.linkedConsignees.length === 0 ? (
                      <Typography variant="body2" color="text.secondary">
                        None
                      </Typography>
                    ) : (
                      <Stack spacing={0.25}>
                        {b.linkedConsignees.map((c) => (
                          <Typography key={c.id} variant="body2">
                            {c.name}
                            <Typography component="span" variant="caption" color="text.secondary" display="block">
                              {c.email}
                            </Typography>
                          </Typography>
                        ))}
                      </Stack>
                    )}
                  </TableCell>
                  <TableCell align="center">{b.manifestCount}</TableCell>
                  <TableCell align="center">{b.edoCount}</TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      label={b.isActive ? 'Active' : 'Inactive'}
                      color={b.isActive ? 'success' : 'default'}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </WorkflowSection>
    </WorkflowPage>
  );
}
