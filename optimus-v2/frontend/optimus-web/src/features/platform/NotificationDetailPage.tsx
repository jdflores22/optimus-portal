import { Alert, Button, Chip, Stack, Typography } from '@mui/material';
import { Link as RouterLink, useParams } from 'react-router-dom';
import {
  useGetNotificationQuery,
  useMarkNotificationsReadMutation,
} from '../../app/api';
import type { NotificationDto } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

function relatedPath(n: NotificationDto): string | null {
  switch (n.category) {
    case 'sas':
      return n.title.toLowerCase().includes('final approval') ? '/approvals' : '/sas';
    case 'transfer':
    case 'suspension':
      return '/transfers';
    case 'appeal':
      return '/appeals';
    case 'dwell':
      return '/dwell';
    case 'pre_advice':
      return '/pre-advice';
    default:
      return null;
  }
}

export function NotificationDetailPage() {
  const { id = '' } = useParams();
  const { data, error, isLoading } = useGetNotificationQuery(id, { skip: !id });
  const [markRead, { isLoading: marking }] = useMarkNotificationsReadMutation();

  if (error) {
    return (
      <Stack spacing={2}>
        <Button component={RouterLink} to="/notifications" sx={{ alignSelf: 'flex-start' }}>
          Back to alerts
        </Button>
        <Alert severity="error">Alert not found.</Alert>
      </Stack>
    );
  }

  if (isLoading || !data) {
    return <Typography>Loading...</Typography>;
  }

  const related = relatedPath(data);

  return (
    <WorkflowPage
      eyebrow="Alert detail"
      title={data.title}
      subtitle="Review the full alert context, confirm ownership, and jump back into the related queue."
      chips={
        <>
          <Chip size="small" label={data.category} />
          <Chip size="small" color={data.isRead ? 'default' : 'primary'} label={data.isRead ? 'Read' : 'Unread'} />
        </>
      }
      actions={
        <>
          <Button component={RouterLink} to="/notifications">
            Back to alerts
          </Button>
          {!data.isRead && (
            <Button
              variant="contained"
              disabled={marking}
              onClick={async () => {
                await markRead({ notificationId: data.id }).unwrap();
              }}
            >
              Mark as read
            </Button>
          )}
          {related && (
            <Button component={RouterLink} to={related} variant="outlined">
              Open related page
            </Button>
          )}
        </>
      }
      stats={[
        { label: 'Status', value: data.isRead ? 'Read' : 'Unread', hint: 'Current acknowledgment state', tone: data.isRead ? 'success' : 'warning' },
        { label: 'Category', value: data.category, hint: 'Workflow source', tone: 'info' },
        { label: 'Created', value: new Date(data.createdAt).toLocaleDateString(), hint: new Date(data.createdAt).toLocaleTimeString(), tone: 'default' },
      ]}
    >
      <WorkflowSection title="Message" subtitle="This is the full body delivered to the user.">
        <Typography variant="body1" sx={{ whiteSpace: 'pre-wrap' }}>
          {data.message}
        </Typography>
      </WorkflowSection>

      <WorkflowSection title="Context" subtitle="Use the related subject to navigate back into the owning workflow.">
        <Stack spacing={1}>
          <Typography variant="body2" color="text.secondary">
            Logged at {new Date(data.createdAt).toLocaleString()}
          </Typography>
          {data.subjectType && (
            <Typography variant="body2" color="text.secondary">
              Related subject: {data.subjectType}
              {data.subjectId ? ` · ${data.subjectId}` : ''}
            </Typography>
          )}
        </Stack>
      </WorkflowSection>
    </WorkflowPage>
  );
}
