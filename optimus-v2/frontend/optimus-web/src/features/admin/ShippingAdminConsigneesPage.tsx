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
import { useGetShippingAdminConsigneesQuery } from '../../app/api';
import { useDefaultShippingLine } from '../../shared/useDefaultShippingLine';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function ShippingAdminConsigneesPage() {
  const { data = [], isLoading, isError, refetch } = useGetShippingAdminConsigneesQuery();
  const { shippingLine } = useDefaultShippingLine();

  const withBrokers = data.filter((c) => c.brokerCount > 0).length;
  const withoutBrokers = data.length - withBrokers;
  const totalNoas = data.reduce((sum, c) => sum + c.noaCount, 0);

  return (
    <WorkflowPage
      eyebrow="Shipping Lines Admin"
      title="Consignee Management"
      subtitle={`Accredited consignees registered to ${shippingLine?.brandName ?? 'your shipping line'}.`}
      chips={
        <>
          <Chip size="small" color="primary" label={`${data.length} consignees`} />
          <Chip size="small" color="success" label={`${withBrokers} with brokers`} />
        </>
      }
      actions={
        <Chip
          size="small"
          variant="outlined"
          label="Refresh"
          onClick={() => refetch()}
          clickable
        />
      }
      stats={[
        { label: 'Total Consignees', value: data.length, hint: 'Approved on your line', tone: 'primary' },
        { label: 'With Brokers', value: withBrokers, hint: 'Active broker links', tone: 'success' },
        { label: 'Without Brokers', value: withoutBrokers, hint: 'No active link', tone: withoutBrokers ? 'warning' : 'success' },
        { label: 'Total NOAs', value: totalNoas, hint: 'Across listed consignees', tone: 'info' },
      ]}
    >
      {isError && <Alert severity="error">Could not load consignees.</Alert>}

      <WorkflowSection title="Consignees" subtitle="Browse accredited consignees and their operational footprint on your line.">
        {isLoading ? (
          <Typography color="text.secondary">Loading...</Typography>
        ) : data.length === 0 ? (
          <Alert severity="info" variant="outlined">
            No accredited consignees yet.
          </Alert>
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Consignee</TableCell>
                <TableCell>Linked Brokers</TableCell>
                <TableCell align="center">NOAs</TableCell>
                <TableCell align="center">Manifests</TableCell>
                <TableCell align="center">Containers</TableCell>
                <TableCell>Status</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {data.map((c) => (
                <TableRow key={c.id} hover>
                  <TableCell>
                    <Stack
                      component={RouterLink}
                      to={`/shipping-admin/consignees/${c.id}`}
                      direction="row"
                      spacing={1.25}
                      alignItems="center"
                      sx={{ textDecoration: 'none', color: 'inherit' }}
                    >
                      <Avatar sx={{ width: 36, height: 36, bgcolor: 'primary.main', fontSize: 13 }}>
                        {(c.businessName || c.fullName).slice(0, 2).toUpperCase()}
                      </Avatar>
                      <Box>
                        <Typography fontWeight={700}>{c.businessName || c.fullName}</Typography>
                        <Typography variant="caption" color="text.secondary">
                          {c.email}
                        </Typography>
                      </Box>
                    </Stack>
                  </TableCell>
                  <TableCell>
                    {c.linkedBrokers.length === 0 ? (
                      <Typography variant="body2" color="text.secondary">
                        None
                      </Typography>
                    ) : (
                      <Stack spacing={0.25}>
                        {c.linkedBrokers.map((b) => (
                          <Typography key={b.id} variant="body2">
                            {b.name}
                            <Typography component="span" variant="caption" color="text.secondary" display="block">
                              {b.email}
                            </Typography>
                          </Typography>
                        ))}
                      </Stack>
                    )}
                  </TableCell>
                  <TableCell align="center">{c.noaCount}</TableCell>
                  <TableCell align="center">{c.manifestCount}</TableCell>
                  <TableCell align="center">{c.containerCount}</TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      label={c.isActive ? 'Active' : 'Inactive'}
                      color={c.isActive ? 'success' : 'default'}
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
