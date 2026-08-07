import type { ManifestDto, PaymentDto } from '../../shared/types';

export function isFinalPayment(p: PaymentDto) {
  return /final/i.test(p.paymentType);
}

export function finalPaymentsForManifest(payments: PaymentDto[]) {
  return [...payments]
    .filter(isFinalPayment)
    .sort(
      (a, b) =>
        (a.version ?? 0) - (b.version ?? 0) ||
        +new Date(a.createdAt) - +new Date(b.createdAt),
    );
}

export function latestFinalPayment(payments: PaymentDto[]) {
  const items = finalPaymentsForManifest(payments);
  return items.length > 0 ? items[items.length - 1] : undefined;
}

export function canViewManifestPaymentHistory(role?: string | null) {
  return [
    'Broker',
    'Consignee',
    'Accounting',
    'SystemAdmin',
    'ShippingLinesAdmin',
    'SlStaff',
  ].includes(role ?? '');
}

export function canResubmitFinalPayment(
  user: { role?: string | null; id?: string } | null | undefined,
  manifest: ManifestDto,
  latest?: PaymentDto,
) {
  const canPay =
    user?.role === 'Broker' ||
    user?.role === 'Consignee' ||
    user?.role === 'SystemAdmin';
  if (!canPay) return false;
  if (user?.role === 'Broker' && manifest.brokerId && manifest.brokerId !== user.id) return false;
  if (user?.role === 'Consignee' && manifest.consigneeId && manifest.consigneeId !== user.id) {
    return false;
  }
  return (
    manifest.workflowState === 'BillingGenerated' &&
    Boolean(manifest.billingPdfPath) &&
    (!latest || /reject/i.test(latest.status))
  );
}
