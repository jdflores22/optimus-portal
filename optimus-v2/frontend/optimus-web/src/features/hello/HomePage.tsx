import { Stack, Typography } from '@mui/material';
import { useSelector } from 'react-redux';
import type { RootState } from '../../app/store';
import { WorkspaceSwitcher } from '../workspace/WorkspaceSwitcher';

export function HomePage() {
  const { user } = useSelector((state: RootState) => state.auth);

  return (
    <Stack spacing={2}>
      <Typography variant="h4">{user?.role} Dashboard</Typography>
      <Typography color="text.secondary">
        Welcome {user?.fullName}. Phase 5 SAS / transfers / appeals is active.
      </Typography>
      <Typography variant="body2">Email: {user?.email}</Typography>
      <Typography variant="body2">
        Active shipping line: {user?.activeShippingLineId ?? 'None'}
      </Typography>
      {user?.businessName && <Typography variant="body2">Business: {user.businessName}</Typography>}
      {user?.role === 'Broker' && <WorkspaceSwitcher />}
    </Stack>
  );
}
