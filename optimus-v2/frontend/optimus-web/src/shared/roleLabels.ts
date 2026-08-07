const ROLE_LABELS: Record<string, string> = {
  SystemAdmin: 'System Admin',
  ShippingLinesAdmin: 'Shipping Lines Admin',
  SlStaff: 'SL Staff',
  Evaluator: 'Evaluator',
  Accounting: 'Accounting',
  TerminalTeam: 'Terminal Team',
  Broker: 'Broker',
  Consignee: 'Consignee',
  Trucker: 'Trucker',
};

export function formatRoleLabel(role?: string | null): string {
  if (!role) return '';
  return ROLE_LABELS[role] ?? role;
}
