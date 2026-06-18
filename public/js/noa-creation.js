/**
 * NOA Creation Form Handler
 * Updated for CY card selection, consignee search, and table-based containers
 */

let containerCount = 0;
let containerSizes = {};
let containerTypes = {};
let consignees = [];
let cyAllocations = [];
let selectedAllocation = null;
let selectedConsignee = null;
const containerValidationTimers = new Map();
let pendingNoaFormData = null;
let isSubmittingNoa = false;

document.addEventListener('DOMContentLoaded', function() {
    loadCYAllocations();
    loadConsignees();
    loadContainerTypes();
    loadContainerSizes();
    initializeEventListeners();
});

function initializeEventListeners() {
    const form = document.getElementById('noaCreationForm');
    const addContainerBtn = document.getElementById('addContainerBtn');
    const consigneeSearch = document.getElementById('consigneeSearch');
    const clearConsigneeBtn = document.getElementById('clearConsigneeBtn');
    
    form.addEventListener('submit', handleFormSubmit);
    addContainerBtn.addEventListener('click', addContainer);
    consigneeSearch.addEventListener('input', handleConsigneeSearch);
    clearConsigneeBtn.addEventListener('click', clearConsigneeSelection);

    const confirmModal = document.getElementById('noaConfirmModal');
    const confirmBtn = document.getElementById('confirmNoaCreateBtn');
    const cancelConfirmBtn = document.getElementById('cancelNoaConfirmBtn');

    confirmBtn?.addEventListener('click', function () {
        if (!pendingNoaFormData || isSubmittingNoa) {
            return;
        }
        const formData = pendingNoaFormData;
        closeNoaConfirmModal({ keepPending: true });
        submitNoaCreation(formData);
    });

    cancelConfirmBtn?.addEventListener('click', closeNoaConfirmModal);

    document.querySelectorAll('[data-close-modal="noaConfirmModal"]').forEach(function (el) {
        el.addEventListener('click', closeNoaConfirmModal);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        const confirmModal = document.getElementById('noaConfirmModal');
        const successModal = document.getElementById('successModal');
        const errorModal = document.getElementById('errorModal');
        if (successModal && !successModal.classList.contains('hidden')) {
            closeSuccessModal();
            return;
        }
        if (errorModal && !errorModal.classList.contains('hidden')) {
            closeErrorModal();
            return;
        }
        if (confirmModal && !confirmModal.classList.contains('hidden')) {
            closeNoaConfirmModal();
        }
    });

    document.getElementById('successModalCloseBtn')?.addEventListener('click', closeSuccessModal);
    document.getElementById('successDismissBtn')?.addEventListener('click', function () {
        window.location.href = window.location.pathname;
    });
    document.getElementById('successViewWorkflowBtn')?.addEventListener('click', redirectToWorkflow);
    document.querySelectorAll('[data-close-modal="successModal"]').forEach(function (el) {
        el.addEventListener('click', closeSuccessModal);
    });

    document.getElementById('errorModalCloseBtn')?.addEventListener('click', closeErrorModal);
    document.querySelectorAll('[data-close-modal="errorModal"]').forEach(function (el) {
        el.addEventListener('click', closeErrorModal);
    });
    
    // Close search results when clicking outside
    document.addEventListener('click', function(e) {
        const searchResults = document.getElementById('consigneeSearchResults');
        if (!consigneeSearch.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.add('hidden');
        }
    });
}

function loadCYAllocations() {
    fetch('/api/cy-allocations/all')
        .then(response => response.json())
        .then(data => {
            cyAllocations = data.allocations || [];
            const hasServerCards = document.querySelector('#cyLocationsGrid .cy-card');
            if (!hasServerCards && cyAllocations.length > 0) {
                renderCYCards();
            }
        })
        .catch(error => {
            console.error('Error loading CY allocations:', error);
            const grid = document.getElementById('cyLocationsGrid');
            if (grid && !grid.querySelector('.cy-card')) {
                grid.innerHTML =
                    '<p class="text-sm text-error col-span-full">Failed to load CY locations. Please refresh the page.</p>';
            }
        });
}

function getCYStatus(utilizationPercent) {
    if (utilizationPercent >= 90) return { label: 'At Capacity', type: 'error', cssVar: '--er' };
    if (utilizationPercent >= 70) return { label: 'Near Capacity', type: 'warning', cssVar: '--wa' };
    return { label: 'Available', type: 'success', cssVar: '--su' };
}

function sizeBlock(label, capacity, allocated, preForecast, available, utilPct) {
    const status = getCYStatus(utilPct);
    return `
    <div class="rounded-lg p-3" style="background:oklch(var(--b2))">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold" style="color:oklch(var(--bc))">${label}</span>
            <span class="text-xs font-mono" style="color:oklch(var(--bc)/.45)">${capacity} slots</span>
        </div>
        <div class="grid grid-cols-4 gap-1 mb-2">
            <div class="text-center">
                <div class="text-[10px] uppercase tracking-wide mb-0.5" style="color:oklch(var(--bc)/.4)">Cap</div>
                <div class="text-sm font-bold" style="color:oklch(var(--bc))">${capacity}</div>
            </div>
            <div class="text-center">
                <div class="text-[10px] uppercase tracking-wide mb-0.5" style="color:oklch(var(--bc)/.4)">Alloc</div>
                <div class="text-sm font-bold" style="color:oklch(var(--p))">${allocated}</div>
            </div>
            <div class="text-center">
                <div class="text-[10px] uppercase tracking-wide mb-0.5" style="color:oklch(var(--bc)/.4)">Pre-FC</div>
                <div class="text-sm font-bold" style="color:oklch(var(--wa))">${preForecast}</div>
            </div>
            <div class="text-center">
                <div class="text-[10px] uppercase tracking-wide mb-0.5" style="color:oklch(var(--bc)/.4)">Avail</div>
                <div class="text-sm font-bold" style="color:oklch(var(--su))">${available}</div>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex-1 rounded-full h-1.5" style="background:oklch(var(--b3))">
                <div class="h-1.5 rounded-full transition-all duration-500 cy-utilization-bar"
                     style="width:${Math.min(utilPct,100)}%; background:oklch(var(${status.cssVar}))"></div>
            </div>
            <span class="text-[10px] font-semibold cy-utilization-percent" style="color:oklch(var(--bc)/.5); min-width:2.5rem; text-align:right">${utilPct.toFixed(1)}%</span>
        </div>
    </div>`;
}

function renderCYCards() {
    const grid = document.getElementById('cyLocationsGrid');

    if (cyAllocations.length === 0) {
        grid.innerHTML = `<p class="text-sm col-span-full" style="color:oklch(var(--bc)/.5)">No CY allocations available.</p>`;
        return;
    }

    grid.innerHTML = cyAllocations.map(a => {
        const overallUtil = a.total_teu_capacity > 0
            ? ((a.allocated_teu + a.pre_forecast_teu) / a.total_teu_capacity) * 100
            : 0;
        const status = getCYStatus(overallUtil);

        return `
        <div class="cy-card rounded-xl border shadow-sm overflow-hidden"
             style="background:oklch(var(--b1)); border-color:oklch(var(${status.cssVar})/.3)"
             data-allocation-id="${a.id}"
             data-total-teu="${a.total_teu_capacity}"
             data-allocated-teu="${a.allocated_teu}"
             data-available-teu="${a.available_teu}">

            <!-- Card header -->
            <div class="flex items-center justify-between px-4 py-3 border-b" style="border-color:oklch(var(--b3))">
                <div class="flex items-center gap-2">
                    <span class="icon-[tabler--building-warehouse] size-4" style="color:oklch(var(${status.cssVar}))"></span>
                    <span class="font-semibold text-sm" style="color:oklch(var(--bc))">${a.terminal_name}</span>
                </div>
                <span class="badge badge-${status.type} badge-sm">${status.label}</span>
            </div>

            <!-- Size breakdowns -->
            <div class="p-3 space-y-2">
                ${sizeBlock('20ft Containers', a.capacity_20ft, a.allocated_20ft, a.pre_forecast_20ft, a.available_20ft, a.utilization_percentage_20ft)}
                ${sizeBlock('40ft Containers', a.capacity_40ft, a.allocated_40ft, a.pre_forecast_40ft, a.available_40ft, a.utilization_percentage_40ft)}
            </div>
        </div>`;
    }).join('');
}

function loadConsignees() {
    fetch('/api/consignees/all')
        .then(response => response.json())
        .then(data => {
            consignees = data.consignees || [];
        })
        .catch(error => console.error('Error loading consignees:', error));
}

function handleConsigneeSearch(e) {
    const query = e.target.value.toLowerCase().trim();
    const resultsDiv = document.getElementById('consigneeSearchResults');
    
    if (query.length < 2) {
        resultsDiv.classList.add('hidden');
        return;
    }
    
    const filtered = consignees.filter(c => 
        c.email.toLowerCase().includes(query) || 
        (c.fullName && c.fullName.toLowerCase().includes(query)) ||
        (c.businessName && c.businessName.toLowerCase().includes(query))
    );
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = `
            <div class="p-4 text-sm text-base-content/60 flex items-center gap-2 bg-base-100">
                <span class="icon-[tabler--search-off] size-4"></span>
                No consignees found
            </div>
        `;
        resultsDiv.classList.remove('hidden');
        return;
    }
    
    resultsDiv.innerHTML = filtered.map(c => `
        <div role="button"
             tabindex="0"
             class="w-full text-left cursor-pointer border-b border-base-content/10 last:border-b-0 px-4 py-3 hover:bg-base-200/60 transition-colors"
             onclick="selectConsignee(${c.id})"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectConsignee(${c.id});}">
            <div class="font-semibold text-base-content truncate">${c.businessName || c.fullName || c.email}</div>
            <div class="text-xs text-base-content/60 truncate mt-0.5">${c.email}</div>
        </div>
    `).join('');
    
    resultsDiv.classList.remove('hidden');
}

function selectConsignee(consigneeId) {
    selectedConsignee = consignees.find(c => c.id === consigneeId);
    
    if (!selectedConsignee) return;
    
    // Update hidden input
    document.getElementById('consigneeId').value = consigneeId;
    
    // Clear search input
    document.getElementById('consigneeSearch').value = '';
    document.getElementById('consigneeSearchResults').classList.add('hidden');
    
    // Show selected consignee card
    document.getElementById('selectedConsigneeName').textContent = 
        selectedConsignee.businessName || selectedConsignee.fullName || 'Consignee';
    document.getElementById('selectedConsigneeEmail').textContent = selectedConsignee.email;
    document.getElementById('selectedConsigneeCard').classList.remove('hidden');
}

function clearConsigneeSelection() {
    selectedConsignee = null;
    document.getElementById('consigneeId').value = '';
    document.getElementById('consigneeSearch').value = '';
    document.getElementById('selectedConsigneeCard').classList.add('hidden');
}

function loadContainerTypes() {
    fetch('/api/container-types')
        .then(response => response.json())
        .then(data => {
            containerTypes = data.types.reduce((acc, type) => {
                acc[type.id] = type;
                return acc;
            }, {});
        })
        .catch(error => console.error('Error loading container types:', error));
}

function loadContainerSizes() {
    fetch('/api/container-sizes')
        .then(response => response.json())
        .then(data => {
            containerSizes = data.sizes.reduce((acc, size) => {
                acc[size.id] = size;
                return acc;
            }, {});
        })
        .catch(error => console.error('Error loading container sizes:', error));
}

function addContainer() {
    const tbody = document.getElementById('containersTableBody');
    const index = containerCount++;
    
    const row = document.createElement('tr');
    row.className = 'container-row';
    row.dataset.index = index;
    row.innerHTML = `
        <td class="px-4 py-3 text-sm text-base-content">${index + 1}</td>
        <td class="px-4 py-3">
            <input type="text"
                   name="containers[${index}][number]"
                   required
                   maxlength="20"
                   class="container-number-input input input-bordered input-sm w-full"
                   data-index="${index}"
                   placeholder="e.g., ABCD1234567">
            <div class="container-number-error text-error text-xs mt-1 hidden"></div>
        </td>
        <td class="px-4 py-3">
            <select name="containers[${index}][sizeId]"
                    required
                    class="container-size-select select select-bordered select-sm w-full"
                    data-index="${index}"
                    onchange="updateContainerTEU(${index})">
                <option value="">Select size</option>
                ${Object.values(containerSizes).map(size =>
                    `<option value="${size.id}" data-teu="${size.teuValue}">${size.name}</option>`
                ).join('')}
            </select>
        </td>
        <td class="px-4 py-3">
            <select name="containers[${index}][typeId]"
                    required
                    class="select select-bordered select-sm w-full">
                <option value="">Select type</option>
                ${Object.values(containerTypes).map(type =>
                    `<option value="${type.id}">${type.name}</option>`
                ).join('')}
            </select>
        </td>
        <td class="px-4 py-3 text-sm text-base-content">
            <span class="teu-display badge badge-soft badge-primary" data-index="${index}">—</span>
        </td>
        <td class="px-4 py-3">
            <select name="containers[${index}][cyAllocationId]"
                    required
                    class="cy-allocation-select select select-bordered select-sm w-full"
                    data-index="${index}"
                    onchange="updateCYAllocationForContainer(${index})">
                <option value="">Select CY location</option>
                ${renderCYDropdownOptions(0)}
            </select>
        </td>
        <td class="px-4 py-3 text-center">
            <button type="button"
                    class="btn btn-ghost btn-sm btn-circle text-error"
                    onclick="removeContainer(${index})">
                <span class="icon-[tabler--trash] size-4"></span>
            </button>
        </td>
    `;
    
    tbody.appendChild(row);
    attachContainerNumberValidation(index);
    updateCYAllocation();
}

function normalizeContainerNumber(containerNumber) {
    return (containerNumber || '')
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '');
}

function setContainerNumberValidationState(input, isInvalid, message = '') {
    const errorElement = input.parentElement?.querySelector('.container-number-error');
    if (isInvalid) {
        input.classList.add('input-error');
        if (errorElement) {
            errorElement.textContent = message || 'Container number already exists.';
            errorElement.classList.remove('hidden');
        }
    } else {
        input.classList.remove('input-error');
        if (errorElement) {
            errorElement.textContent = '';
            errorElement.classList.add('hidden');
        }
    }
}

function checkContainerNumberAgainstInventory(input) {
    const rawValue = input.value.trim();
    const normalizedValue = normalizeContainerNumber(rawValue);

    if (!rawValue || normalizedValue.length < 5) {
        setContainerNumberValidationState(input, false);
        return;
    }

    fetch('/noa/validate-container-number', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ containerNumber: rawValue }),
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                if (data.message || data.error) {
                    setContainerNumberValidationState(
                        input,
                        true,
                        data.message || data.error
                    );
                }
                return;
            }

            if (data.exists) {
                setContainerNumberValidationState(
                    input,
                    true,
                    `Container number already exists in inventory (${data.matchedContainerNumber || rawValue}).`
                );
            } else {
                setContainerNumberValidationState(input, false);
            }
        })
        .catch(() => {
            // Keep form usable even if live validation endpoint fails.
        });
}

function attachContainerNumberValidation(index) {
    const input = document.querySelector(`[name="containers[${index}][number]"]`);
    if (!input) return;

    input.addEventListener('input', function () {
        const currentIndex = String(index);
        const existingTimer = containerValidationTimers.get(currentIndex);
        if (existingTimer) {
            clearTimeout(existingTimer);
        }

        setContainerNumberValidationState(input, false);

        const timer = setTimeout(() => {
            checkContainerNumberAgainstInventory(input);
        }, 350);

        containerValidationTimers.set(currentIndex, timer);
    });
}

function updateContainerTEU(index) {
    const select = document.querySelector(`[name="containers[${index}][sizeId]"]`);
    const selectedOption = select.options[select.selectedIndex];
    const teuDisplay = document.querySelector(`.teu-display[data-index="${index}"]`);
    
    if (selectedOption && selectedOption.dataset.teu) {
        const teuValue = parseFloat(selectedOption.dataset.teu);
        teuDisplay.textContent = teuValue + ' TEU';
        
        // Update CY dropdown options based on new TEU requirement
        refreshCYDropdownForContainer(index, teuValue);
    } else {
        teuDisplay.textContent = '-';
    }
    
    updateCYAllocation();
}

/**
 * Render CY dropdown options with utilization info
 * Sorted by available capacity (highest first)
 * Disabled if insufficient capacity
 */
function renderCYDropdownOptions(requiredTeu) {
    // Sort allocations by available capacity (highest first)
    const sortedAllocations = [...cyAllocations].sort((a, b) => 
        b.available_teu - a.available_teu
    );
    
    return sortedAllocations.map(allocation => {
        const hasCapacity = allocation.available_teu >= requiredTeu;
        const utilizationPercent = ((allocation.allocated_teu / allocation.total_teu_capacity) * 100).toFixed(1);
        
        return `<option value="${allocation.id}" 
                        ${!hasCapacity ? 'disabled' : ''}
                        data-available="${allocation.available_teu}"
                        data-total="${allocation.total_teu_capacity}">
                    ${allocation.terminal_name} - ${allocation.available_teu.toFixed(1)}/${allocation.total_teu_capacity.toFixed(1)} TEU (${utilizationPercent}% used)
                </option>`;
    }).join('');
}

/**
 * Refresh CY dropdown options for a specific container when TEU changes
 */
function refreshCYDropdownForContainer(index, requiredTeu) {
    const cySelect = document.querySelector(`[name="containers[${index}][cyAllocationId]"]`);
    if (!cySelect) return;
    
    const currentValue = cySelect.value;
    
    // Re-render options
    cySelect.innerHTML = `<option value="">Select CY location</option>` + 
                         renderCYDropdownOptions(requiredTeu);
    
    // Restore selection if still valid
    if (currentValue) {
        const option = cySelect.querySelector(`option[value="${currentValue}"]`);
        if (option && !option.disabled) {
            cySelect.value = currentValue;
        } else {
            // Selection is no longer valid, show warning
            showCYCapacityWarning(index, requiredTeu);
        }
    }
}

/**
 * Show capacity warning for a specific container
 */
function showCYCapacityWarning(index, requiredTeu) {
    const row = document.querySelector(`.container-row[data-index="${index}"]`);
    if (!row) return;
    
    const cySelect = row.querySelector('.cy-allocation-select');
    cySelect.classList.add('border-error', 'border-2');
    
    // Find alternative locations with sufficient capacity
    const alternatives = cyAllocations.filter(a => a.available_teu >= requiredTeu);
    
    if (alternatives.length > 0) {
        const altNames = alternatives.slice(0, 3).map(a => a.terminal_name).join(', ');
        alert(`Insufficient capacity at selected CY location. Required: ${requiredTeu} TEU.\n\nAlternative locations: ${altNames}`);
    } else {
        alert(`Insufficient capacity at all CY locations. Required: ${requiredTeu} TEU.\n\nPlease reduce container size or contact administrator.`);
    }
}

/**
 * Handle CY allocation selection for a container
 */
function updateCYAllocationForContainer(index) {
    const cySelect = document.querySelector(`[name="containers[${index}][cyAllocationId]"]`);
    
    // Remove any previous warning styling
    cySelect.classList.remove('border-error', 'border-2');
    
    // Update overall utilization display
    updateCYAllocation();
}

function removeContainer(index) {
    const row = document.querySelector(`.container-row[data-index="${index}"]`);
    if (row) {
        row.remove();
        // Renumber remaining rows
        const rows = document.querySelectorAll('.container-row');
        rows.forEach((r, i) => {
            r.querySelector('td:first-child').textContent = i + 1;
        });
        updateCYAllocation();
    }
}

function updateSizeMetrics(card, allocation, size, pendingCount) {
    const capacity = allocation[`capacity_${size}`] || 0;
    const allocated = allocation[`allocated_${size}`] || 0;
    const preForecast = allocation[`pre_forecast_${size}`] || 0;
    const used = allocated + preForecast + pendingCount;
    const available = Math.max(0, capacity - used);
    const util = capacity > 0 ? (used / capacity) * 100 : 0;

    const availableEl = card.querySelector(`.cy-available-${size}`);
    if (availableEl) availableEl.textContent = available;

    const percentEl = card.querySelector(`.cy-utilization-percent-${size}`);
    if (percentEl) percentEl.textContent = `${util.toFixed(1)}%`;

    const barEl = card.querySelector(`.cy-utilization-bar-${size}`);
    if (barEl) {
        barEl.style.width = `${Math.min(util, 100)}%`;
        barEl.className = `h-2.5 rounded-full transition-all cy-utilization-bar-${size} ${
            util >= 90 ? 'bg-error' : (util >= 70 ? 'bg-warning' : 'bg-success')
        }`;
    }

    return util;
}

function updateCYAllocation() {
    const containerRows = document.querySelectorAll('.container-row');
    
    // Calculate pending containers per CY allocation by size
    const allocationUtilization = {};
    
    containerRows.forEach(row => {
        const index = row.dataset.index;
        const sizeSelect = row.querySelector(`[name="containers[${index}][sizeId]"]`);
        const cySelect = row.querySelector(`[name="containers[${index}][cyAllocationId]"]`);
        
        if (!sizeSelect || !cySelect) return;
        
        const selectedSizeOption = sizeSelect.options[sizeSelect.selectedIndex];
        const allocationId = cySelect.value;
        
        if (selectedSizeOption && selectedSizeOption.dataset.teu && allocationId) {
            const teuValue = parseFloat(selectedSizeOption.dataset.teu);
            
            if (!allocationUtilization[allocationId]) {
                allocationUtilization[allocationId] = { used20: 0, used40: 0 };
            }
            
            if (teuValue >= 2) {
                allocationUtilization[allocationId].used40 += 1;
            } else {
                allocationUtilization[allocationId].used20 += 1;
            }
        }
    });
    
    // Update CY cards with projected utilization
    cyAllocations.forEach(allocation => {
        const card = document.querySelector(`[data-allocation-id="${allocation.id}"]`);
        if (!card) return;
        
        const pending = allocationUtilization[allocation.id] || { used20: 0, used40: 0 };
        const util20 = updateSizeMetrics(card, allocation, '20ft', pending.used20);
        const util40 = updateSizeMetrics(card, allocation, '40ft', pending.used40);
        const overallUtil = Math.max(util20, util40);
        const status = getCYStatus(overallUtil);

        const statusBadge = card.querySelector('.cy-status-badge');
        if (statusBadge) {
            statusBadge.className = `badge badge-sm ml-2 flex-shrink-0 cy-status-badge badge-${status.type}`;
            statusBadge.textContent = status.label;
        }
    });
    
    // Calculate total TEU and required TEU per selected CY allocation
    let totalTeu = 0;
    const requiredTeuByAllocation = {};

    containerRows.forEach(row => {
        const index = row.dataset.index;
        const sizeSelect = row.querySelector(`[name="containers[${index}][sizeId]"]`);
        const cySelect = row.querySelector(`[name="containers[${index}][cyAllocationId]"]`);
        const selectedOption = sizeSelect?.options[sizeSelect.selectedIndex];
        const allocationId = cySelect?.value;

        if (selectedOption && selectedOption.dataset.teu) {
            const teuValue = parseFloat(selectedOption.dataset.teu);
            totalTeu += teuValue;

            if (allocationId) {
                requiredTeuByAllocation[allocationId] = (requiredTeuByAllocation[allocationId] || 0) + teuValue;
            }
        }
    });

    let availableTeuCapacity = 0;
    let remainingCapacity = 0;
    let hasSelectedCy = false;
    let hasInsufficientCapacity = false;

    Object.entries(requiredTeuByAllocation).forEach(([allocationId, requiredTeu]) => {
        const allocation = cyAllocations.find(a => String(a.id) === String(allocationId));
        if (!allocation) {
            return;
        }

        hasSelectedCy = true;
        const available = parseFloat(allocation.available_teu) || 0;
        availableTeuCapacity += available;
        remainingCapacity += Math.max(0, available - requiredTeu);

        if (requiredTeu > available) {
            hasInsufficientCapacity = true;
        }
    });
    
    // Show allocation display if containers exist
    const summaryPanel = document.getElementById('cyAllocationDisplay');
    const availableEl = document.getElementById('availableTeuCapacity');
    const remainingEl = document.getElementById('remainingCapacity');
    const capacityWarning = document.getElementById('capacityWarning');

    if (totalTeu > 0) {
        summaryPanel.classList.remove('hidden');
        document.getElementById('totalTeuRequired').textContent = totalTeu.toFixed(1);
        availableEl.textContent = hasSelectedCy ? availableTeuCapacity.toFixed(1) : '0';
        remainingEl.textContent = hasSelectedCy ? remainingCapacity.toFixed(1) : '0';

        if (capacityWarning) {
            capacityWarning.classList.toggle('hidden', !hasInsufficientCapacity);
        }
    } else {
        summaryPanel.classList.add('hidden');
        availableEl.textContent = '0';
        remainingEl.textContent = '0';
        if (capacityWarning) {
            capacityWarning.classList.add('hidden');
        }
    }
}

function collectFormData() {
    const selectedPortLocation = document.querySelector('input[name="portLocation"]:checked')?.value
        || document.getElementById('portLocationFallback')?.value?.trim()
        || '';

    const formData = {
        blNumber: document.getElementById('blNumber').value.trim(),
        vesselNumber: document.getElementById('vesselNumber').value.trim(),
        eta: document.getElementById('eta').value,
        portLocation: selectedPortLocation,
        consigneeId: document.getElementById('consigneeId').value,
        containers: []
    };

    const containerRows = document.querySelectorAll('.container-row');
    containerRows.forEach(row => {
        const index = row.dataset.index;
        formData.containers.push({
            number: row.querySelector(`[name="containers[${index}][number]"]`).value,
            typeId: row.querySelector(`[name="containers[${index}][typeId]"]`).value,
            sizeId: row.querySelector(`[name="containers[${index}][sizeId]"]`).value,
            cyAllocationId: row.querySelector(`[name="containers[${index}][cyAllocationId]"]`).value
        });
    });

    return formData;
}

function formatConfirmEta(etaValue) {
    if (!etaValue) {
        return '—';
    }
    const date = new Date(etaValue);
    if (Number.isNaN(date.getTime())) {
        return etaValue;
    }
    return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function calculateTotalTeuFromForm(formData) {
    let totalTeu = 0;
    formData.containers.forEach(container => {
        const size = containerSizes[container.sizeId];
        if (size?.teuValue) {
            totalTeu += parseFloat(size.teuValue);
        }
    });
    return totalTeu;
}

function openNoaConfirmModal(formData) {
    const consigneeName = selectedConsignee
        ? (selectedConsignee.businessName || selectedConsignee.fullName || selectedConsignee.email)
        : '—';
    const totalTeu = calculateTotalTeuFromForm(formData);

    document.getElementById('confirmConsigneeName').textContent = consigneeName;
    document.getElementById('confirmBlNumber').textContent = formData.blNumber || '—';
    document.getElementById('confirmVesselNumber').textContent = formData.vesselNumber || '—';
    document.getElementById('confirmPortLocation').textContent = formData.portLocation || '—';
    document.getElementById('confirmEta').textContent = formatConfirmEta(formData.eta);
    document.getElementById('confirmContainerCount').textContent = String(formData.containers.length);
    document.getElementById('confirmTotalTeu').textContent = totalTeu > 0 ? totalTeu.toFixed(1) : '—';

    const modal = document.getElementById('noaConfirmModal');
    if (!modal) {
        return;
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function closeNoaConfirmModal(options = {}) {
    const modal = document.getElementById('noaConfirmModal');
    if (!modal) {
        return;
    }
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    if (!isSubmittingNoa) {
        document.body.classList.remove('overflow-hidden');
    }
    if (!isSubmittingNoa && !options.keepPending) {
        pendingNoaFormData = null;
    }
}

function setSubmittingState(isSubmitting) {
    const submitBtn = document.getElementById('submitBtn');
    const submitBtnText = document.getElementById('submitBtnText');
    const submitBtnSpinner = document.getElementById('submitBtnSpinner');
    const loadingModal = document.getElementById('loadingModal');

    isSubmittingNoa = isSubmitting;
    if (submitBtn) {
        submitBtn.disabled = isSubmitting;
    }
    if (submitBtnText) {
        submitBtnText.textContent = isSubmitting ? 'Creating...' : 'Create NOA';
    }
    if (submitBtnSpinner) {
        submitBtnSpinner.classList.toggle('hidden', !isSubmitting);
    }
    if (loadingModal) {
        loadingModal.classList.toggle('hidden', !isSubmitting);
        loadingModal.classList.toggle('flex', isSubmitting);
    }
    if (isSubmitting) {
        document.body.classList.add('overflow-hidden');
    } else if (!isAnyNoaModalOpen()) {
        document.body.classList.remove('overflow-hidden');
    }
}

function isAnyNoaModalOpen() {
    return ['noaConfirmModal', 'successModal', 'errorModal', 'loadingModal'].some(function (id) {
        const modal = document.getElementById(id);
        return modal && !modal.classList.contains('hidden');
    });
}

function handleFormSubmit(e) {
    e.preventDefault();

    if (isSubmittingNoa) {
        return;
    }
    
    // Clear previous errors
    clearErrors();
    
    const formData = collectFormData();
    
    // Validate
    if (!validateForm(formData)) {
        return;
    }

    pendingNoaFormData = formData;
    openNoaConfirmModal(formData);
}

function openSuccessModal(responseData) {
    const noa = responseData?.noa || {};
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    };

    setText('successNoaNumber', noa.noaNumber || '—');
    setText('successBlNumber', noa.blNumber || '—');
    setText('successVesselNumber', noa.vesselNumber || '—');
    setText('successContainerCount', noa.containerCount != null ? String(noa.containerCount) : '—');
    setText('successPdfStatus', noa.pdfPath ? 'Generated' : 'Pending');

    const modal = document.getElementById('successModal');
    if (!modal) {
        return;
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    if (!modal) {
        return;
    }
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    if (!isAnyNoaModalOpen()) {
        document.body.classList.remove('overflow-hidden');
    }
}

function redirectToWorkflow() {
    window.location.href = '/manifest-workflow';
}

function openErrorModal(message, title = 'Could not create NOA') {
    const titleEl = document.getElementById('errorModalTitle');
    const messageEl = document.getElementById('errorMessage');
    const subtitleEl = document.getElementById('errorModalSubtitle');
    const suggestionsEl = document.getElementById('errorSuggestions');

    if (titleEl) {
        titleEl.textContent = title;
    }
    if (messageEl) {
        messageEl.textContent = message;
    }
    if (subtitleEl) {
        subtitleEl.textContent = 'Please review the details below and try again.';
    }
    if (suggestionsEl) {
        suggestionsEl.classList.add('hidden');
    }

    const modal = document.getElementById('errorModal');
    if (!modal) {
        return;
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function closeErrorModal() {
    const modal = document.getElementById('errorModal');
    if (!modal) {
        return;
    }
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    if (!isAnyNoaModalOpen()) {
        document.body.classList.remove('overflow-hidden');
    }
}

function getApiErrorMessage(data, fallback = 'Failed to create NOA') {
    if (!data || typeof data !== 'object') {
        return fallback;
    }
    if (typeof data.message === 'string' && data.message.trim()) {
        return data.message;
    }
    if (typeof data.error === 'string' && data.error.trim()) {
        return data.error;
    }
    return fallback;
}

function submitNoaCreation(formData) {
    setSubmittingState(true);
    
    fetch('/noa/create', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(async (response) => {
        const data = await response.json().catch(() => ({}));
        setSubmittingState(false);
        pendingNoaFormData = null;

        if (response.ok && data.success) {
            openSuccessModal(data);
        } else {
            openErrorModal(getApiErrorMessage(data));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        setSubmittingState(false);
        pendingNoaFormData = null;
        openErrorModal('An unexpected error occurred while creating the NOA. Please try again.');
    });
}

function validateForm(formData) {
    let isValid = true;
    const seenContainerNumbers = new Set();
    
    if (!formData.blNumber) {
        showError('blNumberError', 'BL number is required');
        isValid = false;
    }
    
    if (!formData.vesselNumber) {
        showError('vesselNumberError', 'Vessel number is required');
        isValid = false;
    }
    
    if (!formData.eta) {
        showError('etaError', 'ETA is required');
        isValid = false;
    }

    if (!formData.portLocation) {
        showError('portLocationError', 'Port location is required');
        isValid = false;
    }
    
    if (!formData.consigneeId) {
        showError('consigneeIdError', 'Consignee is required');
        isValid = false;
    }
    
    if (formData.containers.length === 0) {
        showError('containersError', 'At least one container is required');
        isValid = false;
    }
    
    // Validate each container has a CY allocation
    formData.containers.forEach((container, idx) => {
        const normalizedNumber = (container.number || '').trim().toUpperCase();
        if (normalizedNumber) {
            container.number = normalizedNumber;
            if (seenContainerNumbers.has(normalizedNumber)) {
                showError('containersError', `Container ${idx + 1} is duplicated. Container numbers must be unique.`);
                isValid = false;
            } else {
                seenContainerNumbers.add(normalizedNumber);
            }
        }

        if (!container.cyAllocationId) {
            showError('containersError', `Container ${idx + 1} requires a CY location`);
            isValid = false;
        }
    });

    const duplicateInventoryInputs = document.querySelectorAll('.container-number-input.input-error');
    if (duplicateInventoryInputs.length > 0) {
        showError('containersError', 'One or more container numbers already exist in inventory.');
        isValid = false;
    }
    
    // Validate capacity for each CY allocation
    const capacityValidation = validateCYCapacity(formData.containers);
    if (!capacityValidation.valid) {
        showError('containersError', capacityValidation.message);
        
        // Highlight affected CY locations in grid
        capacityValidation.affectedAllocations.forEach(allocationId => {
            const card = document.querySelector(`[data-allocation-id="${allocationId}"]`);
            if (card) {
                card.classList.add('border-error', 'border-4');
            }
        });
        
        isValid = false;
    }
    
    return isValid;
}

/**
 * Validate CY capacity for all containers
 * Returns validation result with details
 */
function validateCYCapacity(containers) {
    const allocationUsage = {};
    
    // Calculate total TEU per allocation
    containers.forEach(container => {
        const allocationId = container.cyAllocationId;
        const sizeId = container.sizeId;
        const size = containerSizes[sizeId];
        
        if (!size) return;
        
        if (!allocationUsage[allocationId]) {
            allocationUsage[allocationId] = 0;
        }
        allocationUsage[allocationId] += parseFloat(size.teuValue);
    });
    
    // Check each allocation's capacity
    const affectedAllocations = [];
    const errors = [];
    
    for (const [allocationId, requiredTeu] of Object.entries(allocationUsage)) {
        const allocation = cyAllocations.find(a => a.id == allocationId);
        if (!allocation) continue;
        
        if (requiredTeu > allocation.available_teu) {
            const shortage = requiredTeu - allocation.available_teu;
            errors.push(
                `${allocation.terminal_name}: Insufficient capacity. ` +
                `Required: ${requiredTeu.toFixed(1)} TEU, ` +
                `Available: ${allocation.available_teu.toFixed(1)} TEU, ` +
                `Shortage: ${shortage.toFixed(1)} TEU`
            );
            affectedAllocations.push(allocationId);
        }
    }
    
    if (errors.length > 0) {
        // Find alternative locations
        const alternatives = cyAllocations
            .filter(a => !affectedAllocations.includes(a.id) && a.available_teu > 0)
            .slice(0, 3)
            .map(a => `${a.terminal_name} (${a.available_teu.toFixed(1)} TEU available)`)
            .join(', ');
        
        let message = errors.join('\n\n');
        if (alternatives) {
            message += `\n\nAlternative locations: ${alternatives}`;
        }
        
        return {
            valid: false,
            message: message,
            affectedAllocations: affectedAllocations
        };
    }
    
    return { valid: true };
}

function showError(elementId, message) {
    const errorElement = document.getElementById(elementId);
    errorElement.textContent = message;
    errorElement.classList.remove('hidden');
}

function clearErrors() {
    const errorElements = document.querySelectorAll('[id$="Error"]');
    errorElements.forEach(element => {
        element.textContent = '';
        element.classList.add('hidden');
    });
}
