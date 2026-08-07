import { API_BASE_URL } from './types';

export async function loadEdoPaymentReceiptBlob(
  paymentId: string,
  accessToken: string,
): Promise<{ url: string; contentType: string }> {
  const res = await fetch(`${API_BASE_URL}/api/edo-payments/${paymentId}/receipt`, {
    headers: { Authorization: `Bearer ${accessToken}` },
  });
  if (!res.ok) {
    throw new Error('Could not load payment receipt.');
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  return { url, contentType: blob.type || res.headers.get('content-type') || 'application/pdf' };
}
