/** Where to send a user right after login / invitation accept. */
export function postAuthHomePath(role?: string | null): string {
  if (role === 'Broker') return '/workspace';
  return '/';
}
