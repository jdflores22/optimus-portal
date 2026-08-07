import { useEffect, useRef, useState } from 'react';
import { Alert, Box, Button, Stack, TextField, Typography } from '@mui/material';
import MyLocationOutlinedIcon from '@mui/icons-material/MyLocationOutlined';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

// Fix default marker icons under Vite bundling
delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl;
L.Icon.Default.mergeOptions({
  iconRetinaUrl: markerIcon2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
});

const DEFAULT_LAT = 14.5995;
const DEFAULT_LNG = 120.9842;
const DEFAULT_ZOOM = 13;

export function parseGeoValue(raw: string | Record<string, unknown> | undefined | null): {
  lat: number | null;
  lng: number | null;
  zoom: number;
} {
  if (raw == null || raw === '') return { lat: null, lng: null, zoom: DEFAULT_ZOOM };
  try {
    const parsed =
      typeof raw === 'string' ? (JSON.parse(raw) as Record<string, unknown>) : raw;
    const lat = Number(parsed.lat ?? parsed.latitude);
    const lng = Number(parsed.lng ?? parsed.longitude);
    const zoom = Number(parsed.zoom ?? DEFAULT_ZOOM);
    return {
      lat: Number.isFinite(lat) ? lat : null,
      lng: Number.isFinite(lng) ? lng : null,
      zoom: Number.isFinite(zoom) ? zoom : DEFAULT_ZOOM,
    };
  } catch {
    return { lat: null, lng: null, zoom: DEFAULT_ZOOM };
  }
}

export function serializeGeoValue(lat: number, lng: number, zoom = DEFAULT_ZOOM): string {
  return JSON.stringify({
    lat,
    lng,
    zoom,
    latitude: lat,
    longitude: lng,
  });
}

type Props = {
  label: string;
  required?: boolean;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  helpText?: string;
  defaultLat?: number;
  defaultLng?: number;
  defaultZoom?: number;
};

export function GeolocationMapPicker({
  label,
  required,
  value,
  onChange,
  disabled,
  helpText,
  defaultLat = DEFAULT_LAT,
  defaultLng = DEFAULT_LNG,
  defaultZoom = DEFAULT_ZOOM,
}: Props) {
  const mapRef = useRef<HTMLDivElement | null>(null);
  const mapInstance = useRef<L.Map | null>(null);
  const markerRef = useRef<L.Marker | null>(null);
  const onChangeRef = useRef(onChange);
  const disabledRef = useRef(disabled);
  const zoomRef = useRef(defaultZoom);
  const [gpsError, setGpsError] = useState<string | null>(null);
  const [locating, setLocating] = useState(false);

  onChangeRef.current = onChange;
  disabledRef.current = disabled;
  zoomRef.current = defaultZoom;

  const parsed = parseGeoValue(value);
  const lat = parsed.lat;
  const lng = parsed.lng;
  const displayLat = lat ?? defaultLat;
  const displayLng = lng ?? defaultLng;
  const hasSelection = lat != null && lng != null;

  const applyCoords = (nextLat: number, nextLng: number) => {
    if (nextLat < -90 || nextLat > 90 || nextLng < -180 || nextLng > 180) return;
    onChangeRef.current(serializeGeoValue(nextLat, nextLng, zoomRef.current));
    markerRef.current?.setLatLng([nextLat, nextLng]);
    mapInstance.current?.setView([nextLat, nextLng], Math.max(mapInstance.current.getZoom(), 15));
  };

  useEffect(() => {
    if (!mapRef.current || mapInstance.current) return;

    const map = L.map(mapRef.current, {
      scrollWheelZoom: true,
      dragging: !disabledRef.current,
      doubleClickZoom: !disabledRef.current,
      boxZoom: !disabledRef.current,
      keyboard: !disabledRef.current,
    }).setView([displayLat, displayLng], defaultZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    const marker = L.marker([displayLat, displayLng], {
      draggable: !disabledRef.current,
    }).addTo(map);

    map.on('click', (e: L.LeafletMouseEvent) => {
      if (disabledRef.current) return;
      applyCoords(e.latlng.lat, e.latlng.lng);
    });
    marker.on('dragend', () => {
      if (disabledRef.current) return;
      const pos = marker.getLatLng();
      applyCoords(pos.lat, pos.lng);
    });

    mapInstance.current = map;
    markerRef.current = marker;

    const t = window.setTimeout(() => map.invalidateSize(), 200);
    return () => {
      window.clearTimeout(t);
      map.remove();
      mapInstance.current = null;
      markerRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!mapInstance.current || !markerRef.current) return;
    markerRef.current.setLatLng([displayLat, displayLng]);
    if (disabled) {
      markerRef.current.dragging?.disable();
      mapInstance.current.dragging.disable();
    } else {
      markerRef.current.dragging?.enable();
      mapInstance.current.dragging.enable();
    }
  }, [displayLat, displayLng, disabled]);

  const detectLocation = () => {
    if (disabled) return;
    if (!navigator.geolocation) {
      setGpsError('Geolocation is not supported by your browser.');
      return;
    }
    setGpsError(null);
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        applyCoords(position.coords.latitude, position.coords.longitude);
        setLocating(false);
      },
      () => {
        setGpsError('Unable to retrieve your location. Click the map to set it manually.');
        setLocating(false);
      },
      { enableHighAccuracy: true, timeout: 10000 },
    );
  };

  return (
    <Box>
      <Typography variant="subtitle2" mb={0.5}>
        {label}
        {required ? ' *' : ''}
      </Typography>
      <Typography variant="caption" color="text.secondary" display="block" mb={1}>
        Click the map or drag the pin to set your business location.
      </Typography>

      <Box
        ref={mapRef}
        sx={{
          width: '100%',
          height: 288,
          borderRadius: 2,
          border: 1,
          borderColor: 'divider',
          overflow: 'hidden',
          mb: 1.5,
          zIndex: 0,
          '& .leaflet-container': { height: '100%', width: '100%', fontFamily: 'inherit' },
        }}
      />

      <Stack
        direction={{ xs: 'column', sm: 'row' }}
        spacing={1.5}
        alignItems={{ sm: 'center' }}
        mb={1.5}
      >
        <Button
          type="button"
          variant="outlined"
          size="small"
          startIcon={<MyLocationOutlinedIcon />}
          onClick={detectLocation}
          disabled={disabled || locating}
        >
          {locating ? 'Locating…' : 'Detect my location'}
        </Button>
        <Typography variant="caption" color="text.secondary">
          {hasSelection
            ? `${lat!.toFixed(6)}, ${lng!.toFixed(6)}`
            : 'No location selected yet'}
        </Typography>
      </Stack>

      {gpsError && (
        <Alert severity="warning" sx={{ mb: 1.5 }} onClose={() => setGpsError(null)}>
          {gpsError}
        </Alert>
      )}

      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
        <TextField
          label="Latitude"
          size="small"
          type="number"
          disabled={disabled}
          value={lat != null ? lat.toFixed(6) : ''}
          onChange={(e) => {
            const next = e.target.value;
            if (!next) {
              onChange('');
              return;
            }
            applyCoords(Number(next), lng ?? defaultLng);
          }}
          fullWidth
          inputProps={{ step: 'any', readOnly: true }}
          helperText="Set via map or Detect my location"
        />
        <TextField
          label="Longitude"
          size="small"
          type="number"
          disabled={disabled}
          value={lng != null ? lng.toFixed(6) : ''}
          onChange={(e) => {
            const next = e.target.value;
            if (!next) {
              onChange('');
              return;
            }
            applyCoords(lat ?? defaultLat, Number(next));
          }}
          fullWidth
          inputProps={{ step: 'any', readOnly: true }}
        />
      </Stack>
      {helpText && (
        <Typography variant="caption" color="text.secondary" display="block" mt={0.75}>
          {helpText}
        </Typography>
      )}
    </Box>
  );
}

/** Compact read-only map for submitted / preview details. */
export function GeolocationMapPreview({
  value,
  height = 220,
}: {
  value: string | Record<string, unknown> | null | undefined;
  height?: number;
}) {
  const mapRef = useRef<HTMLDivElement | null>(null);
  const mapInstance = useRef<L.Map | null>(null);
  const markerRef = useRef<L.Marker | null>(null);
  const { lat, lng, zoom } = parseGeoValue(value);
  const hasCoords = lat != null && lng != null;

  useEffect(() => {
    if (!hasCoords || !mapRef.current) return;

    const map = L.map(mapRef.current, {
      zoomControl: true,
      dragging: true,
      scrollWheelZoom: false,
      doubleClickZoom: false,
      boxZoom: false,
      keyboard: false,
    }).setView([lat!, lng!], zoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap',
      maxZoom: 19,
    }).addTo(map);

    markerRef.current = L.marker([lat!, lng!], { interactive: false }).addTo(map);
    mapInstance.current = map;

    const t1 = window.setTimeout(() => map.invalidateSize(), 50);
    const t2 = window.setTimeout(() => map.invalidateSize(), 250);

    return () => {
      window.clearTimeout(t1);
      window.clearTimeout(t2);
      map.remove();
      mapInstance.current = null;
      markerRef.current = null;
    };
  }, [hasCoords, lat, lng, zoom]);

  if (!hasCoords) {
    return (
      <Typography variant="body2" color="text.secondary">
        No location provided
      </Typography>
    );
  }

  const mapsHref = `https://www.google.com/maps?q=${lat},${lng}`;

  return (
    <Stack spacing={1} sx={{ width: '100%', minWidth: 0 }}>
      <Box
        ref={mapRef}
        sx={{
          width: '100%',
          height,
          borderRadius: 2,
          border: 1,
          borderColor: 'divider',
          overflow: 'hidden',
          zIndex: 0,
          bgcolor: (t) =>
            t.palette.mode === 'dark' ? 'rgba(255,255,255,0.04)' : 'grey.100',
          '& .leaflet-container': { height: '100%', width: '100%', fontFamily: 'inherit' },
        }}
      />
      <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap">
        <Typography variant="body2" fontWeight={600}>
          {lat!.toFixed(6)}, {lng!.toFixed(6)}
        </Typography>
        <Button
          component="a"
          href={mapsHref}
          target="_blank"
          rel="noopener noreferrer"
          size="small"
          sx={{ textTransform: 'none' }}
        >
          Open in Maps
        </Button>
      </Stack>
    </Stack>
  );
}
