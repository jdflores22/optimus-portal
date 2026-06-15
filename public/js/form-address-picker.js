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

    function dispatchAddressChange(container) {
        container.dispatchEvent(new CustomEvent('addresschange', { bubbles: true }));
    }

    function fetchJson(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed (' + response.status + ')');
            }
            return response.json();
        });
    }

    function initPicker(container) {
        if (!container || container.dataset.addressInitialized === 'true') {
            return;
        }

        const regionSelect = container.querySelector('.address-region');
        const provinceSelect = container.querySelector('.address-province');
        const citySelect = container.querySelector('.address-city');
        const barangaySelect = container.querySelector('.address-barangay');

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

        function loadProvinces(regionId, select, selectedProvince, onLoaded) {
            if (!regionId) {
                setSelectLoading(select, 'Select region first');
                return;
            }

            setSelectLoading(select, 'Loading provinces...');
            fetchJson('/api/locations/provinces/' + encodeURIComponent(regionId))
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
            if (!provinceId) {
                setSelectLoading(select, 'Select province first');
                return;
            }

            setSelectLoading(select, 'Loading cities...');
            fetchJson('/api/locations/cities/by-province/' + encodeURIComponent(provinceId))
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
            if (!cityId) {
                setSelectLoading(select, 'Select city first');
                return;
            }

            setSelectLoading(select, 'Loading barangays...');
            fetchJson('/api/locations/barangays/' + encodeURIComponent(cityId))
                .then(function (data) {
                    if (!data.success || !data.barangays || data.barangays.length === 0) {
                        setSelectLoading(select, 'No barangays found');
                        return;
                    }
                    fillSelect(select, data.barangays, 'Select barangay', selectedBarangay || '');
                    syncHiddenName(select, barangayNameInput);
                    dispatchAddressChange(container);
                })
                .catch(function () {
                    setSelectLoading(select, 'Error loading barangays');
                });
        }

        fetchJson('/api/locations/regions')
            .then(function (data) {
                if (!data.success || !data.regions) {
                    setSelectLoading(regionSelect, 'Unable to load regions');
                    container.dataset.addressInitialized = 'false';
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
                container.dataset.addressInitialized = 'false';
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
                dispatchAddressChange(container);
                return;
            }

            loadProvinces(regionId, provinceSelect);
            setSelectLoading(citySelect, 'Select province first');
            setSelectLoading(barangaySelect, 'Select city first');
            if (provinceNameInput) provinceNameInput.value = '';
            if (cityNameInput) cityNameInput.value = '';
            if (barangayNameInput) barangayNameInput.value = '';
            dispatchAddressChange(container);
        });

        provinceSelect.addEventListener('change', function () {
            const provinceId = this.value;
            syncHiddenName(provinceSelect, provinceNameInput);

            if (!provinceId) {
                setSelectLoading(citySelect, 'Select province first');
                setSelectLoading(barangaySelect, 'Select city first');
                if (cityNameInput) cityNameInput.value = '';
                if (barangayNameInput) barangayNameInput.value = '';
                dispatchAddressChange(container);
                return;
            }

            loadCities(provinceId, citySelect);
            setSelectLoading(barangaySelect, 'Select city first');
            if (cityNameInput) cityNameInput.value = '';
            if (barangayNameInput) barangayNameInput.value = '';
            dispatchAddressChange(container);
        });

        citySelect.addEventListener('change', function () {
            const cityId = this.value;
            syncHiddenName(citySelect, cityNameInput);

            if (!cityId) {
                setSelectLoading(barangaySelect, 'Select city first');
                if (barangayNameInput) barangayNameInput.value = '';
                dispatchAddressChange(container);
                return;
            }

            if (barangayNameInput) barangayNameInput.value = '';
            loadBarangays(cityId, barangaySelect);
            dispatchAddressChange(container);
        });

        barangaySelect.addEventListener('change', function () {
            syncHiddenName(barangaySelect, barangayNameInput);
            dispatchAddressChange(container);
        });

        container.dataset.addressInitialized = 'true';
    }

    function initAll(root) {
        (root || document).querySelectorAll('.form-address-picker').forEach(initPicker);
    }

    function resetAll(root) {
        (root || document).querySelectorAll('.form-address-picker').forEach(function (container) {
            delete container.dataset.addressInitialized;
        });
    }

    window.FormAddressPicker = {
        initAll: initAll,
        initPicker: initPicker,
        resetAll: resetAll,
    };
})();
