/**
 * Leaflet-based geolocation picker for dynamic accreditation forms.
 */
(function () {
    'use strict';

    const DEFAULT_LAT = 14.5995;
    const DEFAULT_LNG = 120.9842;
    const DEFAULT_ZOOM = 13;

    const instances = {};

    function parseCoord(value, fallback) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : fallback;
    }

    function isValidCoord(lat, lng) {
        return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
    }

    function dispatchChange(input) {
        if (!input) return;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function configureLeafletIcons() {
        if (typeof L === 'undefined' || configureLeafletIcons._done) {
            return;
        }
        delete L.Icon.Default.prototype._getIconUrl;
        L.Icon.Default.mergeOptions({
            iconRetinaUrl: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-icon-2x.png',
            iconUrl: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-icon.png',
            shadowUrl: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-shadow.png',
        });
        configureLeafletIcons._done = true;
    }

    function initPicker(container) {
        if (!container || container.dataset.geolocationInitialized === 'true') {
            return;
        }

        if (typeof L === 'undefined') {
            console.error('Leaflet (L) is required for geolocation fields');
            return;
        }

        configureLeafletIcons();

        const fieldId = container.dataset.fieldId;
        const mapEl = container.querySelector('.geolocation-map');
        const latInput = container.querySelector('.geolocation-lat');
        const lngInput = container.querySelector('.geolocation-lng');
        const coordsDisplay = container.querySelector('.geolocation-coords-display');

        if (!mapEl || !latInput || !lngInput) {
            return;
        }

        const defaultLat = parseCoord(container.dataset.defaultLat, DEFAULT_LAT);
        const defaultLng = parseCoord(container.dataset.defaultLng, DEFAULT_LNG);
        const defaultZoom = parseInt(container.dataset.defaultZoom || DEFAULT_ZOOM, 10);

        const initialLat = parseCoord(latInput.value, defaultLat);
        const initialLng = parseCoord(lngInput.value, defaultLng);

        const map = L.map(mapEl, { scrollWheelZoom: true }).setView([initialLat, initialLng], defaultZoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        const marker = L.marker([initialLat, initialLng], { draggable: true }).addTo(map);

        function updateInputs(lat, lng, fitView) {
            if (!isValidCoord(lat, lng)) return;

            latInput.value = lat.toFixed(6);
            lngInput.value = lng.toFixed(6);

            if (coordsDisplay) {
                coordsDisplay.textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
            }

            marker.setLatLng([lat, lng]);
            if (fitView) {
                map.setView([lat, lng], Math.max(map.getZoom(), 15));
            }

            dispatchChange(latInput);
            dispatchChange(lngInput);
        }

        map.on('click', function (e) {
            updateInputs(e.latlng.lat, e.latlng.lng, false);
        });

        marker.on('dragend', function () {
            const pos = marker.getLatLng();
            updateInputs(pos.lat, pos.lng, false);
        });

        const useGpsBtn = container.querySelector('.geolocation-use-gps');
        if (useGpsBtn) {
            useGpsBtn.addEventListener('click', function () {
                if (!navigator.geolocation) {
                    alert('Geolocation is not supported by your browser.');
                    return;
                }

                useGpsBtn.disabled = true;
                const original = useGpsBtn.innerHTML;
                useGpsBtn.innerHTML = '<span class="loading loading-spinner loading-xs"></span> Locating...';

                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        updateInputs(position.coords.latitude, position.coords.longitude, true);
                        useGpsBtn.disabled = false;
                        useGpsBtn.innerHTML = original;
                    },
                    function () {
                        alert('Unable to retrieve your location. Please click the map to set your business location.');
                        useGpsBtn.disabled = false;
                        useGpsBtn.innerHTML = original;
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            });
        }

        // Fix map tile sizing after layout
        setTimeout(function () {
            map.invalidateSize();
        }, 200);

        container.dataset.geolocationInitialized = 'true';
        instances[fieldId] = { map, marker, updateInputs };
    }

    function initAll(root) {
        (root || document).querySelectorAll('.form-geolocation-picker').forEach(initPicker);
    }

    window.FormGeolocation = {
        initAll: initAll,
        initPicker: initPicker,
        getInstance: function (fieldId) {
            return instances[fieldId] || null;
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);
    });
})();
