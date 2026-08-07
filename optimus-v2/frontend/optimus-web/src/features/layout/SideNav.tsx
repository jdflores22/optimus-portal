import {
  Badge,
  Box,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Typography,
} from '@mui/material';
import { NavLink } from 'react-router-dom';
import { useMemo } from 'react';
import { useGetAccreditationsQuery, useGetFinalPaymentsQuery } from '../../app/api';
import { getNavGroups } from './navConfig';

type SideNavProps = {
  role?: string | null;
  onNavigate?: () => void;
  dense?: boolean;
};

export function SideNav({ role, onNavigate, dense }: SideNavProps) {
  const groups = getNavGroups(role);
  const needsApprovalBadge = role === 'ShippingLinesAdmin';
  const needsPendingPaymentsBadge = role === 'Accounting' || role === 'SystemAdmin';
  const { data: accreditations = [] } = useGetAccreditationsQuery(undefined, {
    skip: !needsApprovalBadge,
    pollingInterval: needsApprovalBadge ? 30_000 : 0,
  });
  const { data: finalPayments } = useGetFinalPaymentsQuery(
    { status: 'pending_validation', page: 1, limit: 1 },
    {
      skip: !needsPendingPaymentsBadge,
      pollingInterval: needsPendingPaymentsBadge ? 30_000 : 0,
    },
  );
  const awaitingFinalCount = useMemo(
    () => accreditations.filter((a) => a.status === 'AwaitingFinalApproval').length,
    [accreditations],
  );
  const pendingPaymentsCount = finalPayments?.stats.pending ?? 0;

  const badgeFor = (badgeKey?: 'awaitingFinalApprovals' | 'pendingPayments') => {
    if (badgeKey === 'awaitingFinalApprovals') return awaitingFinalCount;
    if (badgeKey === 'pendingPayments') return needsPendingPaymentsBadge ? pendingPaymentsCount : 0;
    return 0;
  };

  return (
    <Box
      sx={{
        flex: 1,
        minHeight: 0,
        overflowY: 'auto',
        overflowX: 'hidden',
        py: 1,
        scrollbarWidth: 'thin',
        scrollbarColor: (t) =>
          `${t.palette.mode === 'dark' ? 'rgba(154,173,184,0.35)' : 'rgba(11,61,92,0.28)'} transparent`,
        '&::-webkit-scrollbar': { width: 6 },
        '&::-webkit-scrollbar-thumb': {
          bgcolor: (t) =>
            t.palette.mode === 'dark' ? 'rgba(154,173,184,0.35)' : 'rgba(11,61,92,0.28)',
          borderRadius: 8,
        },
        '&::-webkit-scrollbar-track': { bgcolor: 'transparent' },
      }}
    >
      {groups.map((group) => (
        <Box key={group.id} sx={{ mb: 1.5 }}>
          <Typography
            variant="body2"
            sx={{
              px: 2.5,
              py: 1,
              display: 'block',
              color: 'text.secondary',
              fontWeight: 600,
            }}
          >
            {group.label}
          </Typography>
          <List dense={dense} disablePadding>
            {group.items.map((item) => {
              const Icon = item.icon;
              const badgeCount = badgeFor(item.badgeKey);
              return (
                <ListItemButton
                  key={item.id}
                  component={NavLink}
                  to={item.path}
                  end={item.exact ?? item.path === '/'}
                  onClick={onNavigate}
                  sx={{
                    mx: 1,
                    borderRadius: 1.5,
                    '&.active': {
                      bgcolor: 'primary.main',
                      color: 'primary.contrastText',
                      '& .MuiListItemIcon-root': { color: 'inherit' },
                    },
                  }}
                >
                  <ListItemIcon sx={{ minWidth: 40 }}>
                    <Icon fontSize="small" />
                  </ListItemIcon>
                  <ListItemText
                    primary={item.label}
                    primaryTypographyProps={{ variant: 'body2', fontWeight: 500 }}
                  />
                  {badgeCount > 0 && (
                    <Badge
                      badgeContent={badgeCount}
                      color="warning"
                      sx={{
                        '& .MuiBadge-badge': {
                          position: 'static',
                          transform: 'none',
                          fontWeight: 700,
                        },
                      }}
                    />
                  )}
                </ListItemButton>
              );
            })}
          </List>
        </Box>
      ))}
    </Box>
  );
}
