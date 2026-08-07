export function edoPaymentSubmitted(paymentStatus?: string | null): boolean {
  return /pendingvalidation/i.test(paymentStatus ?? '');
}

export function edoPaymentRejected(paymentStatus?: string | null): boolean {
  return /rejected/i.test(paymentStatus ?? '');
}

export function formatEdoStatus(status: string, paymentStatus?: string | null): string {
  if (/pendingvalidation/i.test(status) && edoPaymentSubmitted(paymentStatus)) {
    return 'Submitted';
  }
  if (/pendingvalidation/i.test(status)) return 'Pending Validation';
  if (/pendingrelease/i.test(status)) return 'Pending Release';
  if (/released/i.test(status)) return 'Released';
  if (/active/i.test(status)) return 'Active';
  if (/expired/i.test(status)) return 'Expired';
  if (/locked/i.test(status)) return 'Locked';
  if (/rejected/i.test(status)) return 'Rejected';
  if (/superseded/i.test(status)) return 'Superseded';
  return status.replace(/([a-z])([A-Z])/g, '$1 $2');
}

export function edoStatusChipColor(
  status: string,
  paymentStatus?: string | null,
): 'warning' | 'success' | 'error' | 'default' | 'info' {
  if (/pendingvalidation/i.test(status) && edoPaymentSubmitted(paymentStatus)) return 'info';
  if (/pendingvalidation/i.test(status)) return 'warning';
  if (/pendingrelease/i.test(status)) return 'info';
  if (/released|active/i.test(status)) return 'success';
  if (/expired|locked|rejected/i.test(status)) return 'error';
  return 'default';
}

/** True when broker/consignee still needs to pay and upload a receipt. */
export function edoNeedsPayment(status: string, paymentStatus?: string | null): boolean {
  if (!/pendingvalidation/i.test(status)) return false;
  if (edoPaymentSubmitted(paymentStatus)) return false;
  return true;
}

/**
 * V1 parity: only SystemAdmin may download before release.
 * Everyone else (broker, consignee, SL staff, shipping admin, accounting, terminal)
 * may download only when Released or Expired.
 */
export function edoCanDownload(status: string, role?: string | null): boolean {
  if (role === 'SystemAdmin') {
    return !/rejected|superseded/i.test(status);
  }
  return /released|expired/i.test(status);
}

export function edoDownloadBlockedMessage(
  status: string,
  role?: string | null,
  paymentStatus?: string | null,
): string | null {
  if (edoCanDownload(status, role)) return null;
  if (/pendingvalidation/i.test(status) && edoPaymentSubmitted(paymentStatus)) {
    return 'Payment submitted. Waiting for accounting validation.';
  }
  if (/pendingvalidation/i.test(status)) {
    return 'Pay the eDO access fee before this document can be downloaded.';
  }
  if (/pendingrelease/i.test(status)) {
    return 'Payment verified. Download will be available once the eDO is released.';
  }
  if (/locked/i.test(status)) {
    return 'This eDO is locked. Contact support or request a renewal.';
  }
  if (/rejected/i.test(status)) {
    return 'This eDO was rejected. Contact support for assistance.';
  }
  if (/active/i.test(status)) {
    return 'Download is not available for this eDO status.';
  }
  return 'Download is not available yet.';
}
