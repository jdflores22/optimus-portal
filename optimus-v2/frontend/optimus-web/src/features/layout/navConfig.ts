import type { SvgIconComponent } from '@mui/icons-material';
import DashboardOutlinedIcon from '@mui/icons-material/DashboardOutlined';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import PaymentsOutlinedIcon from '@mui/icons-material/PaymentsOutlined';
import LocalShippingOutlinedIcon from '@mui/icons-material/LocalShippingOutlined';
import ReplayOutlinedIcon from '@mui/icons-material/ReplayOutlined';
import WarehouseOutlinedIcon from '@mui/icons-material/WarehouseOutlined';
import TimerOutlinedIcon from '@mui/icons-material/TimerOutlined';
import DirectionsBoatOutlinedIcon from '@mui/icons-material/DirectionsBoatOutlined';
import AssessmentOutlinedIcon from '@mui/icons-material/AssessmentOutlined';
import AssignmentOutlinedIcon from '@mui/icons-material/AssignmentOutlined';
import SwapHorizOutlinedIcon from '@mui/icons-material/SwapHorizOutlined';
import GavelOutlinedIcon from '@mui/icons-material/GavelOutlined';
import MoveUpOutlinedIcon from '@mui/icons-material/MoveUpOutlined';
import NotificationsNoneOutlinedIcon from '@mui/icons-material/NotificationsNoneOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import FactCheckOutlinedIcon from '@mui/icons-material/FactCheckOutlined';
import VerifiedOutlinedIcon from '@mui/icons-material/VerifiedOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import BusinessOutlinedIcon from '@mui/icons-material/BusinessOutlined';
import AccountTreeOutlinedIcon from '@mui/icons-material/AccountTreeOutlined';

export const ALL_ROLES = [
  'SystemAdmin',
  'ShippingLinesAdmin',
  'SlStaff',
  'Evaluator',
  'Accounting',
  'TerminalTeam',
  'Broker',
  'Consignee',
  'Trucker',
] as const;

export type AppRole = (typeof ALL_ROLES)[number] | string;

export type NavItem = {
  id: string;
  label: string;
  path: string;
  roles: string[];
  icon: SvgIconComponent;
  exact?: boolean;
  /** Optional badge key rendered by SideNav (e.g. awaitingFinalApprovals). */
  badgeKey?: 'awaitingFinalApprovals' | 'pendingPayments';
};

export type NavGroup = {
  id: string;
  label: string;
  items: NavItem[];
};

const STAFF = ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Evaluator', 'Accounting'];
const RELEASE = ['SlStaff', 'ShippingLinesAdmin', 'TerminalTeam', 'SystemAdmin'];
const PAYMENT_ADMIN = ['SystemAdmin'];
const ADMIN = ['SystemAdmin', 'ShippingLinesAdmin'];
const HIERARCHY = ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Evaluator', 'Accounting'];
const YARD = ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'TerminalTeam', 'Trucker'];
const OPS = ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Evaluator', 'Broker', 'Consignee', 'TerminalTeam'];

const NAV_GROUPS: NavGroup[] = [
  {
    id: 'main',
    label: 'Main',
    items: [
      {
        id: 'dashboard',
        label: 'Dashboard',
        path: '/',
        roles: [...ALL_ROLES],
        icon: DashboardOutlinedIcon,
      },
    ],
  },
  {
    id: 'cargo',
    label: 'Cargo Workflow',
    items: [
      {
        id: 'manifests',
        label: 'Manifests',
        path: '/manifests',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Accounting', 'Broker', 'Consignee'],
        icon: DescriptionOutlinedIcon,
      },
      {
        id: 'payments',
        label: 'Payments',
        path: '/payments',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'Accounting', 'Broker', 'Consignee'],
        icon: PaymentsOutlinedIcon,
        badgeKey: 'pendingPayments',
      },
    ],
  },
  {
    id: 'edo',
    label: 'Release Workflow',
    items: [
      {
        id: 'edo',
        label: 'eDO / CRO',
        path: '/edo',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Accounting', 'Broker', 'Consignee', 'TerminalTeam'],
        icon: LocalShippingOutlinedIcon,
        exact: true,
      },
      {
        id: 'renewals',
        label: 'Renewals',
        path: '/edo/renewals',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Accounting', 'Broker', 'Consignee'],
        icon: ReplayOutlinedIcon,
      },
      {
        id: 'edo-payment-validation',
        label: 'eDO Payment Validation',
        path: '/edo/payment-validation',
        roles: PAYMENT_ADMIN,
        icon: FactCheckOutlinedIcon,
      },
      {
        id: 'edo-release',
        label: 'Release eDO / CRO',
        path: '/edo/release',
        roles: RELEASE,
        icon: VerifiedOutlinedIcon,
      },
    ],
  },
  {
    id: 'yard',
    label: 'Terminal & Yard',
    items: [
      {
        id: 'container-inventory',
        label: 'Container Inventory',
        path: '/container-inventory',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Accounting', 'TerminalTeam'],
        icon: WarehouseOutlinedIcon,
      },
      {
        id: 'yard',
        label: 'Yard admin',
        path: '/yard',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'TerminalTeam'],
        icon: WarehouseOutlinedIcon,
      },
      {
        id: 'dwell',
        label: 'Dwell',
        path: '/dwell',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'TerminalTeam'],
        icon: TimerOutlinedIcon,
      },
      {
        id: 'pre-advice',
        label: 'Pre-advice',
        path: '/pre-advice',
        roles: YARD,
        icon: DirectionsBoatOutlinedIcon,
      },
      {
        id: 'utilization',
        label: 'Utilization',
        path: '/reports/utilization',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'TerminalTeam'],
        icon: AssessmentOutlinedIcon,
      },
    ],
  },
  {
    id: 'ops',
    label: 'Review Queues',
    items: [
      {
        id: 'sas',
        label: 'SAS',
        path: '/sas',
        roles: OPS,
        icon: AssignmentOutlinedIcon,
      },
      {
        id: 'transfers',
        label: 'Transfers',
        path: '/transfers',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Consignee', 'Broker'],
        icon: SwapHorizOutlinedIcon,
      },
      {
        id: 'appeals',
        label: 'Appeals',
        path: '/appeals',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'Broker', 'Consignee'],
        icon: GavelOutlinedIcon,
      },
      {
        id: 'repositioning',
        label: 'Reposition',
        path: '/repositioning',
        roles: ['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'TerminalTeam', 'Broker'],
        icon: MoveUpOutlinedIcon,
      },
    ],
  },
  {
    id: 'reports',
    label: 'Oversight',
    items: [
      {
        id: 'audit-reports',
        label: 'Reports & audit',
        path: '/reports/audit',
        roles: STAFF,
        icon: FactCheckOutlinedIcon,
      },
    ],
  },
  {
    id: 'admin',
    label: 'Administration',
    items: [
      {
        id: 'platform',
        label: 'Platform',
        path: '/admin/platform',
        roles: ADMIN,
        icon: SettingsOutlinedIcon,
      },
      {
        id: 'shipping-lines',
        label: 'Brand settings',
        path: '/admin/shipping-lines',
        roles: ADMIN,
        icon: BusinessOutlinedIcon,
      },
      {
        id: 'hierarchy',
        label: 'Hierarchy',
        path: '/admin/hierarchy',
        roles: HIERARCHY,
        icon: AccountTreeOutlinedIcon,
      },
    ],
  },
];

function canAccess(roles: string[], role: string): boolean {
  if (role === 'SystemAdmin') return true;
  return roles.includes(role);
}

export function getNavGroups(role: string | undefined | null): NavGroup[] {
  const r = role ?? '';

  // Broker portal: workspace switcher lives in sidebar header
  if (r === 'Broker') {
    return [
      {
        id: 'dashboards',
        label: 'Dashboards',
        items: [
          {
            id: 'dashboard',
            label: 'Overview',
            path: '/',
            roles: ['Broker'],
            icon: DashboardOutlinedIcon,
          },
        ],
      },
      {
        id: 'my-work',
        label: 'My Work',
        items: [
          {
            id: 'sas',
            label: 'Accreditation',
            path: '/sas',
            roles: ['Broker'],
            icon: AssignmentOutlinedIcon,
          },
          {
            id: 'manifests',
            label: 'My Manifests',
            path: '/manifests',
            roles: ['Broker'],
            icon: DescriptionOutlinedIcon,
          },
          {
            id: 'edo',
            label: 'My eDOs',
            path: '/edo',
            roles: ['Broker'],
            icon: LocalShippingOutlinedIcon,
            exact: true,
          },
          {
            id: 'payments',
            label: 'Detention Billings',
            path: '/payments',
            roles: ['Broker'],
            icon: PaymentsOutlinedIcon,
          },
          {
            id: 'renewals',
            label: 'Renewals',
            path: '/edo/renewals',
            roles: ['Broker'],
            icon: ReplayOutlinedIcon,
          },
          {
            id: 'transfers',
            label: 'Transfers',
            path: '/transfers',
            roles: ['Broker'],
            icon: SwapHorizOutlinedIcon,
          },
          {
            id: 'appeals',
            label: 'Appeals',
            path: '/appeals',
            roles: ['Broker'],
            icon: GavelOutlinedIcon,
          },
        ],
      },
    ];
  }

  // Old consignee portal: Dashboard + My Account
  if (r === 'Consignee') {
    return [
      {
        id: 'main',
        label: 'Main',
        items: [
          {
            id: 'dashboard',
            label: 'Dashboard',
            path: '/',
            roles: ['Consignee'],
            icon: DashboardOutlinedIcon,
          },
        ],
      },
      {
        id: 'my-account',
        label: 'My Account',
        items: [
          {
            id: 'sas',
            label: 'Accreditation',
            path: '/sas',
            roles: ['Consignee'],
            icon: AssignmentOutlinedIcon,
          },
          {
            id: 'manifests',
            label: 'My Manifests',
            path: '/manifests',
            roles: ['Consignee'],
            icon: DescriptionOutlinedIcon,
          },
          {
            id: 'brokers',
            label: 'Brokers',
            path: '/brokers',
            roles: ['Consignee'],
            icon: PeopleOutlineOutlinedIcon,
          },
          {
            id: 'edo',
            label: 'eDO / CRO',
            path: '/edo',
            roles: ['Consignee'],
            icon: LocalShippingOutlinedIcon,
            exact: true,
          },
          {
            id: 'payments',
            label: 'Payments',
            path: '/payments',
            roles: ['Consignee'],
            icon: PaymentsOutlinedIcon,
          },
          {
            id: 'transfers',
            label: 'Transfers',
            path: '/transfers',
            roles: ['Consignee'],
            icon: SwapHorizOutlinedIcon,
          },
        ],
      },
    ];
  }

  if (r === 'ShippingLinesAdmin') {
    return [
      {
        id: 'main',
        label: 'Main',
        items: [
          {
            id: 'dashboard',
            label: 'Dashboard',
            path: '/',
            roles: ['ShippingLinesAdmin'],
            icon: DashboardOutlinedIcon,
          },
        ],
      },
      {
        id: 'operations',
        label: 'Operations',
        items: [
          {
            id: 'manifests',
            label: 'NOA & BL Workflow',
            path: '/manifests',
            roles: ['ShippingLinesAdmin'],
            icon: DescriptionOutlinedIcon,
          },
          {
            id: 'yard',
            label: 'Container Inventory',
            path: '/container-inventory',
            roles: ['ShippingLinesAdmin'],
            icon: WarehouseOutlinedIcon,
          },
          {
            id: 'repositioning',
            label: 'Outbound Requests',
            path: '/repositioning',
            roles: ['ShippingLinesAdmin'],
            icon: MoveUpOutlinedIcon,
          },
          {
            id: 'hierarchy',
            label: 'My Team',
            path: '/admin/hierarchy',
            roles: ['ShippingLinesAdmin'],
            icon: AccountTreeOutlinedIcon,
          },
        ],
      },
      {
        id: 'management',
        label: 'Management',
        items: [
          {
            id: 'approvals',
            label: 'Accreditations',
            path: '/approvals',
            roles: ['ShippingLinesAdmin'],
            icon: AssignmentOutlinedIcon,
            badgeKey: 'awaitingFinalApprovals',
          },
          {
            id: 'consignees',
            label: 'Consignees',
            path: '/shipping-admin/consignees',
            roles: ['ShippingLinesAdmin'],
            icon: PeopleOutlineOutlinedIcon,
          },
          {
            id: 'brokers',
            label: 'Brokers',
            path: '/shipping-admin/brokers',
            roles: ['ShippingLinesAdmin'],
            icon: BusinessOutlinedIcon,
          },
        ],
      },
    ];
  }

  if (r === 'SlStaff') {
    return [
      {
        id: 'main',
        label: 'Main',
        items: [
          {
            id: 'dashboard',
            label: 'Dashboard',
            path: '/',
            roles: ['SlStaff'],
            icon: DashboardOutlinedIcon,
          },
          {
            id: 'notifications',
            label: 'Notifications',
            path: '/notifications',
            roles: ['SlStaff'],
            icon: NotificationsNoneOutlinedIcon,
          },
        ],
      },
      {
        id: 'operations',
        label: 'Operations',
        items: [
          {
            id: 'manifests',
            label: 'NOA & BL Workflow',
            path: '/manifests',
            roles: ['SlStaff'],
            icon: DescriptionOutlinedIcon,
          },
          {
            id: 'yard',
            label: 'Container Inventory',
            path: '/container-inventory',
            roles: ['SlStaff'],
            icon: WarehouseOutlinedIcon,
          },
          {
            id: 'repositioning',
            label: 'Repositioning Requests',
            path: '/repositioning',
            roles: ['SlStaff'],
            icon: MoveUpOutlinedIcon,
          },
          {
            id: 'edo',
            label: 'eDO Generation',
            path: '/edo',
            roles: ['SlStaff'],
            icon: LocalShippingOutlinedIcon,
            exact: true,
          },
        ],
      },
      {
        id: 'edo-renewals',
        label: 'eDO Renewals',
        items: [
          {
            id: 'renewals',
            label: 'Request eDO',
            path: '/edo/renewals',
            roles: ['SlStaff'],
            icon: ReplayOutlinedIcon,
          },
        ],
      },
    ];
  }

  return NAV_GROUPS.map((group) => ({
    ...group,
    items: group.items.filter((item) => canAccess(item.roles, r)),
  })).filter((group) => group.items.length > 0);
}

/** Primary shortcuts for hub tiles + mobile bottom bar (max ~4). */
export function getQuickActions(role: string | undefined | null): NavItem[] {
  const r = role ?? '';
  const catalog = getNavGroups(r).flatMap((g) => g.items);
  const byId = (id: string) => catalog.find((i) => i.id === id);

  const prefs: Record<string, string[]> = {
    SystemAdmin: ['dashboard', 'manifests', 'edo-payment-validation', 'edo-release', 'platform'],
    ShippingLinesAdmin: ['dashboard', 'approvals', 'manifests', 'edo-release'],
    SlStaff: ['dashboard', 'manifests', 'edo', 'edo-release'],
    Evaluator: ['dashboard', 'sas', 'hierarchy', 'notifications'],
    Accounting: ['dashboard', 'payments', 'notifications'],
    TerminalTeam: ['dashboard', 'pre-advice', 'edo-release', 'dwell'],
    Broker: ['dashboard', 'manifests', 'edo', 'payments'],
    Consignee: ['manifests', 'brokers', 'sas', 'dashboard'],
    Trucker: ['pre-advice', 'dashboard', 'profile'],
  };

  const ids = prefs[r] ?? ['dashboard', 'profile'];
  return ids.map(byId).filter((x): x is NavItem => Boolean(x)).slice(0, 4);
}

export function getBottomNavItems(role: string | undefined | null): NavItem[] {
  const actions = getQuickActions(role);
  const profile = getNavGroups(role).flatMap((g) => g.items).find((i) => i.id === 'profile');
  const merged = [...actions];
  if (profile && !merged.some((i) => i.id === 'profile')) {
    merged.push(profile);
  }
  return merged.slice(0, 4);
}

export const DRAWER_WIDTH = 260;
