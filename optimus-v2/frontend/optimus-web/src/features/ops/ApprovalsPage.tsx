import { Alert, Typography } from '@mui/material';
import { useSelector } from 'react-redux';
import { Navigate } from 'react-router-dom';
import { useGetAccreditationsQuery } from '../../app/api';
import type { RootState } from '../../app/store';
import { ShippingAdminAccreditationsPanel } from './ShippingAdminAccreditationsPanel';

/** Final accreditation approval queue — Shipping Lines Admin only. */
export function ApprovalsPage() {
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const role = user?.role ?? '';
  const { data: submissions = [], refetch, isLoading, isError } = useGetAccreditationsQuery(
    undefined,
    { skip: !accessToken || role !== 'ShippingLinesAdmin' },
  );

  if (role !== 'ShippingLinesAdmin') {
    return <Navigate to="/" replace />;
  }

  if (isLoading) {
    return (
      <Typography color="text.secondary" sx={{ py: 4 }}>
        Loading accreditations...
      </Typography>
    );
  }

  if (isError) {
    return <Alert severity="error">Could not load accreditation approvals.</Alert>;
  }

  return (
    <ShippingAdminAccreditationsPanel
      submissions={submissions}
      onRefresh={() => {
        refetch();
      }}
    />
  );
}
