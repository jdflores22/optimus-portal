/**
 * Philippines-style address picker (Region → Province → City/Municipality → Barangay).
 */
(function () {
    'use strict';

    function setSelectLoading(select, message) {
        if (!select) return;
        select.innerHTML = '<option value="">' + message + '</option>';
        select.disabled = true;
    }

    function fillSelect(select, items, placeholder, selectedValue) {
        if (!select) return;
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = String(item.id ?? item.value ?? item);
            option.textContent = item.name ?? item.label ?? item;
            if (selectedValue && String(selectedValue) === option.value) {
                option.selected = true;
            }
            select.appendChild(option);
        });
        select.disabled = false;
    }

    function syncHiddenName(select, hiddenInput) {
        if (!select || !hiddenInput) return;
        hiddenInput.value = select.selectedOptions[0]?.textContent?.trim() || '';
    }

    function initPicker(container) {
        if (!container || container.dataset.addressInitialized === 'true') {
            return;
        }

        const regionSelect = container.querySelector('.address-region');
        const provinceSelect = container.querySelector('.address-province');
        const citySelect = container.querySelector('.address-city');
        const barangaySelect = container.querySelector('.address-barangay');
        const streetInput = container.querySelector('.address-street');

        if (!regionSelect || !provinceSelect || !citySelect || !barangaySelect) {
            return;
        }

        const regionNameInput = container.querySelector('.address-region-name');
        const provinceNameInput = container.querySelector('.address-province-name');
        const cityNameInput = container.querySelector('.address-city-name');
        const barangayNameInput = container.querySelector('.address-barangay-name');

        setSelectLoading(regionSelect, 'Loading regions...');
        setSelectLoading(provinceSelect, 'Select region first');
        setSelectLoading(citySelect, 'Select province first');
        setSelectLoading(barangaySelect, 'Select city first');

        fetch('/api/locations/regions')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.regions) {
                    setSelectLoading(regionSelect, 'Unable to load regions');
                    return;
                }

                const selectedRegion = regionSelect.dataset.selected || '';
                fillSelect(regionSelect, data.regions, 'Select region', selectedRegion);
                syncHiddenName(regionSelect, regionNameInput);

                const selectedProvince = provinceSelect.dataset.selected || '';
                const selectedCity = citySelect.dataset.selected || '';
                const selectedBarangay = barangaySelect.dataset.selected || '';

                if (selectedRegion) {
                    loadProvinces(selectedRegion, provinceSelect, selectedProvince, function () {
                        if (selectedProvince) {
                            loadCities(selectedProvince, citySelect, selectedCity, function () {
                                if (selectedCity) {
                                    loadBarangays(selectedCity, barangaySelect, selectedBarangay);
                                }
                            });
                        }
                    });
                }
            })
            .catch(function () {
                setSelectLoading(regionSelect, 'Error loading regions');
            });

        regionSelect.addEventListener('change', function () {
            const regionId = this.value;
            syncHiddenName(regionSelect, regionNameInput);

            if (!regionId) {
                setSelectLoading(provinceSelect, 'Select region first');
                setSelectLoading(citySelect, 'Select province first');
                setSelectLoading(barangaySelect, 'Select city first');
                if (provinceNameInput) provinceNameInput.value = '';
                if (cityNameInput) cityNameInput.value = '';
                if (barangayNameInput) barangayNameInput.value = '';
                return;
            }

            loadProvinces(regionId, provinceSelect);
            setSelectLoading(citySelect, 'Select province first');
            setSelectLoading(barangaySelect, 'Select city first');
            if (provinceNameInput) provinceNameInput.value = '';
            if (cityNameInput) cityNameInput.value = '';
            if (barangayNameInput) barangayNameInput.value = '';
        });

        provinceSelect.addEventListener('change', function () {
            const provinceId = this.value;
            syncHiddenName(provinceSelect, provinceNameInput);

            if (!provinceId) {
                setSelectLoading(citySelect, 'Select province first');
                setSelectLoading(barangaySelect, 'Select city first');
                if (cityNameInput) cityNameInput.value = '';
                if (barangayNameInput) barangayNameInput.value = '';
                return;
            }

            loadCities(provinceId, citySelect);
            setSelectLoading(barangaySelect, 'Select city first');
            if (cityNameInput) cityNameInput.value = '';
            if (barangayNameInput) barangayNameInput.value = '';
        });

        citySelect.addEventListener('change', function () {
            const cityId = this.value;
            syncHiddenName(citySelect, cityNameInput);

            if (!cityId) {
                setSelectLoading(barangaySelect, 'Select city first');
                if (barangayNameInput) barangayNameInput.value = '';
                return;
            }

            loadBarangays(cityId, barangaySelect);
        });

        barangaySelect.addEventListener('change', function () {
            syncHiddenName(barangaySelect, barangayNameInput);
        });

        function loadProvinces(regionId, select, selectedProvince, onLoaded) {
            setSelectLoading(select, 'Loading provinces...');
            fetch('/api/locations/provinces/' + regionId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.provinces) {
                        setSelectLoading(select, 'No provinces found');
                        return;
                    }
                    fillSelect(select, data.provinces, 'Select province', selectedProvince || '');
                    syncHiddenName(select, provinceNameInput);
                    if (typeof onLoaded === 'function') {
                        onLoaded();
                    }
                })
                .catch(function () {
                    setSelectLoading(select, 'Error loading provinces');
                });
        }

        function loadCities(provinceId, select, selectedCity, onLoaded) {
            setSelectLoading(select, 'Loading cities...');
            fetch('/api/locations/cities/by-province/' + provinceId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.cities) {
                        setSelectLoading(select, 'No cities found');
                        return;
                    }
                    fillSelect(select, data.cities, 'Select city / municipality', selectedCity || '');
                    syncHiddenName(select, cityNameInput);
                    if (typeof onLoaded === 'function') {
                        onLoaded();
                    }
                })
                .catch(function () {
                    setSelectLoading(select, 'Error loading cities');
                });
        }

        function loadBarangays(cityId, select, selectedBarangay) {
            setSelectLoading(select, 'Loading barangays...');
            fetch('/api/locations/barangays/' + cityId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.barangays || data.barangays.length === 0) {
                        setSelectLoading(select, 'No barangays found');
                        return;
                    }
                    fillSelect(select, data.barangays, 'Select barangay', selectedBarangay || '');
                    syncHiddenName(select, barangayNameInput);
                })
                .catch(function () {
                    setSelectLoading(select, 'Error loading barangays');
                });
        }

        container.dataset.addressInitialized = 'true';
    }

    function initAll(root) {
        (root || document).querySelectorAll('.form-address-picker').forEach(initPicker);
    }

    window.FormAddressPicker = { initAll: initAll, initPicker: initPicker };

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);
    });
})();
