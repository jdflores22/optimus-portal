import { useMemo } from 'react';
import { useSelector } from 'react-redux';
import { useGetShippingLinesQuery } from '../app/api';
import type { RootState } from '../app/store';
import type { ShippingLineDto } from '../shared/types';

/**
 * OPTIMUS runs with a single shipping line. Prefer the user's active id,
 * otherwise the first active (or only) line from the API.
 */
export function useDefaultShippingLine(): {
  shippingLine: ShippingLineDto | undefined;
  shippingLineId: string;
  isLoading: boolean;
} {
  const { user, accessToken } = useSelector((state: RootState) => state.auth);
  const { data: lines = [], isLoading } = useGetShippingLinesQuery(undefined, {
    skip: !accessToken,
  });

  const shippingLine = useMemo(() => {
    if (!lines.length) return undefined;
    const preferred = user?.activeShippingLineId
      ? lines.find((l) => l.id === user.activeShippingLineId)
      : undefined;
    if (preferred) return preferred;
    return lines.find((l) => l.isActive) ?? lines[0];
  }, [lines, user?.activeShippingLineId]);

  return {
    shippingLine,
    shippingLineId: shippingLine?.id ?? '',
    isLoading,
  };
}
