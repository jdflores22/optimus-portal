import { useMemo } from 'react';
import { Alert, Box, MenuItem, Stack, TextField, Typography } from '@mui/material';
import {
  useGetBarangaysQuery,
  useGetCitiesByProvinceQuery,
  useGetProvincesQuery,
  useGetRegionsQuery,
} from '../../app/api';

export type AddressValue = {
  region_id: string;
  region_name: string;
  province_id: string;
  province_name: string;
  city_id: string;
  city_name: string;
  barangay_id: string;
  barangay_name: string;
  street: string;
};

const EMPTY: AddressValue = {
  region_id: '',
  region_name: '',
  province_id: '',
  province_name: '',
  city_id: '',
  city_name: '',
  barangay_id: '',
  barangay_name: '',
  street: '',
};

export function parseAddressValue(raw: string | undefined | null): AddressValue {
  if (!raw || !String(raw).trim()) return { ...EMPTY };
  try {
    const parsed = JSON.parse(String(raw)) as Record<string, unknown>;
    if (!parsed || typeof parsed !== 'object') return { ...EMPTY };
    return {
      region_id: String(parsed.region_id ?? ''),
      region_name: String(parsed.region_name ?? ''),
      province_id: String(parsed.province_id ?? parsed.province ?? ''),
      province_name: String(parsed.province_name ?? parsed.province ?? ''),
      city_id: String(parsed.city_id ?? ''),
      city_name: String(parsed.city_name ?? ''),
      barangay_id: String(parsed.barangay_id ?? ''),
      barangay_name: String(parsed.barangay_name ?? parsed.barangay ?? ''),
      street: String(parsed.street ?? ''),
    };
  } catch {
    // Legacy plain-text address — keep as street only
    return { ...EMPTY, street: String(raw) };
  }
}

export function serializeAddressValue(value: AddressValue): string {
  const hasCore = value.region_id || value.province_id || value.city_id || value.barangay_id || value.street;
  if (!hasCore) return '';
  return JSON.stringify(value);
}

type Props = {
  label: string;
  required?: boolean;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  helpText?: string;
};

export function AddressPicker({ label, required, value, onChange, disabled, helpText }: Props) {
  const address = useMemo(() => parseAddressValue(value), [value]);

  const { data: regionsRes, isLoading: regionsLoading, isError: regionsError } = useGetRegionsQuery();
  const regions = regionsRes?.regions ?? [];

  const { data: provincesRes, isFetching: provincesLoading } = useGetProvincesQuery(address.region_id, {
    skip: !address.region_id,
  });
  const provinces = provincesRes?.provinces ?? [];

  const { data: citiesRes, isFetching: citiesLoading } = useGetCitiesByProvinceQuery(address.province_id, {
    skip: !address.province_id,
  });
  const cities = citiesRes?.cities ?? [];

  const { data: barangaysRes, isFetching: barangaysLoading } = useGetBarangaysQuery(address.city_id, {
    skip: !address.city_id,
  });
  const barangays = barangaysRes?.barangays ?? [];

  const emit = (next: AddressValue) => onChange(serializeAddressValue(next));

  const setRegion = (regionId: string) => {
    const region = regions.find((r) => r.id === regionId);
    emit({
      ...EMPTY,
      region_id: regionId,
      region_name: region?.name ?? '',
    });
  };

  const setProvince = (provinceId: string) => {
    const province = provinces.find((p) => p.id === provinceId);
    emit({
      ...address,
      province_id: provinceId,
      province_name: province?.name ?? '',
      city_id: '',
      city_name: '',
      barangay_id: '',
      barangay_name: '',
    });
  };

  const setCity = (cityId: string) => {
    const city = cities.find((c) => c.id === cityId);
    emit({
      ...address,
      city_id: cityId,
      city_name: city?.name ?? '',
      barangay_id: '',
      barangay_name: '',
    });
  };

  const setBarangay = (barangayId: string) => {
    const barangay = barangays.find((b) => b.id === barangayId);
    emit({
      ...address,
      barangay_id: barangayId,
      barangay_name: barangay?.name ?? '',
    });
  };

  return (
    <Box>
      <Typography variant="subtitle2" mb={1}>
        {label}
        {required ? ' *' : ''}
      </Typography>
      {regionsError && (
        <Alert severity="warning" sx={{ mb: 1.5 }}>
          Could not load location database. Refresh and try again.
        </Alert>
      )}
      <Stack spacing={1.5}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
          <TextField
            select
            label="Region"
            required={required}
            disabled={disabled || regionsLoading}
            value={address.region_id}
            onChange={(e) => setRegion(e.target.value)}
            fullWidth
            size="small"
          >
            <MenuItem value="">{regionsLoading ? 'Loading regions…' : 'Select region'}</MenuItem>
            {regions.map((r) => (
              <MenuItem key={r.id} value={r.id}>
                {r.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField
            select
            label="Province"
            required={required}
            disabled={disabled || !address.region_id || provincesLoading}
            value={address.province_id}
            onChange={(e) => setProvince(e.target.value)}
            fullWidth
            size="small"
          >
            <MenuItem value="">
              {!address.region_id
                ? 'Select region first'
                : provincesLoading
                  ? 'Loading provinces…'
                  : 'Select province'}
            </MenuItem>
            {provinces.map((p) => (
              <MenuItem key={p.id} value={p.id}>
                {p.name}
              </MenuItem>
            ))}
          </TextField>
        </Stack>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
          <TextField
            select
            label="City / Municipality"
            required={required}
            disabled={disabled || !address.province_id || citiesLoading}
            value={address.city_id}
            onChange={(e) => setCity(e.target.value)}
            fullWidth
            size="small"
          >
            <MenuItem value="">
              {!address.province_id
                ? 'Select province first'
                : citiesLoading
                  ? 'Loading cities…'
                  : 'Select city'}
            </MenuItem>
            {cities.map((c) => (
              <MenuItem key={c.id} value={c.id}>
                {c.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField
            select
            label="Barangay"
            required={required}
            disabled={disabled || !address.city_id || barangaysLoading}
            value={address.barangay_id}
            onChange={(e) => setBarangay(e.target.value)}
            fullWidth
            size="small"
          >
            <MenuItem value="">
              {!address.city_id
                ? 'Select city first'
                : barangaysLoading
                  ? 'Loading barangays…'
                  : 'Select barangay'}
            </MenuItem>
            {barangays.map((b) => (
              <MenuItem key={b.id} value={b.id}>
                {b.name}
              </MenuItem>
            ))}
          </TextField>
        </Stack>
        <TextField
          label="Street / Building (optional)"
          disabled={disabled}
          value={address.street}
          onChange={(e) => emit({ ...address, street: e.target.value })}
          fullWidth
          size="small"
          placeholder="Street, unit, or building"
          helperText={helpText || undefined}
        />
      </Stack>
    </Box>
  );
}
