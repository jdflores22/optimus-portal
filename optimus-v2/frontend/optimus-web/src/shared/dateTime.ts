/** Parse API timestamps. Backend stores UTC but often omits the `Z` suffix. */
export function parseApiDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null;
  if (value instanceof Date) return value;

  const raw = value.trim();
  if (!raw) return null;

  // Treat timezone-less ISO values as UTC so PH/local clocks are not shifted.
  const normalized =
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?$/.test(raw) ? `${raw}Z` : raw;

  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function relativeTime(value: string | Date | null | undefined): string {
  const date = parseApiDate(value);
  if (!date) return '';

  const diffMs = Date.now() - date.getTime();
  if (diffMs < 0) return 'Just now';

  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;

  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;

  const days = Math.floor(hours / 24);
  return `${days}d ago`;
}
