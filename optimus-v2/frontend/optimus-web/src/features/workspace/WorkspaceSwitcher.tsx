import { FormEvent, useState } from 'react';
import { Alert, Button, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useDispatch, useSelector } from 'react-redux';
import { useGetWorkspacesQuery, useSwitchWorkspaceMutation } from '../../app/api';
import { setCredentials } from '../../app/authSlice';
import type { RootState } from '../../app/store';

export function WorkspaceSwitcher() {
  const dispatch = useDispatch();
  const { user } = useSelector((state: RootState) => state.auth);
  const { data: workspaces = [] } = useGetWorkspacesQuery();
  const [switchWorkspace, { isLoading }] = useSwitchWorkspaceMutation();
  const [selected, setSelected] = useState(user?.activeWorkspaceConsigneeId ?? '');
  const [error, setError] = useState<string | null>(null);

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    try {
      const result = await switchWorkspace({ consigneeId: selected }).unwrap();
      dispatch(
        setCredentials({
          accessToken: result.accessToken,
          refreshToken: result.refreshToken,
          user: result.user,
        }),
      );
    } catch {
      setError('Unable to switch workspace.');
    }
  };

  return (
    <Stack spacing={1.5} component="form" onSubmit={onSubmit} maxWidth={420}>
      <Typography variant="h6">Broker workspace</Typography>
      {error && <Alert severity="error">{error}</Alert>}
      <TextField
        select
        label="Act as consignee"
        value={selected}
        onChange={(e) => setSelected(e.target.value)}
        required
      >
        {workspaces.map((w) => (
          <MenuItem key={w.id} value={w.id}>
            {w.businessName || w.fullName}
          </MenuItem>
        ))}
      </TextField>
      <Button type="submit" variant="outlined" disabled={isLoading || !selected}>
        Switch workspace
      </Button>
    </Stack>
  );
}
