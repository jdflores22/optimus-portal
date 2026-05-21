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
            renderCYCards();
        })
        .catch(error => {
            console.error('Error loading CY allocations:', error);
            document.getElementById('cyLocationsGrid').innerHTML = 
                '<p class="text-sm text-error col-span-full">Failed to load CY locations. Please refresh the page.</p>';
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
        resultsDiv.innerHTML = '<div class="p-4 text-sm" style="color: var(--fallback-bc, oklch(var(--bc))) !important;">No consignees found</div>';
        resultsDiv.classList.remove('hidden');
        return;
    }
    
    resultsDiv.innerHTML = filtered.map(c => `
        <div class="p-3 hover:bg-base-300 cursor-pointer border-b border-base-300 last:border-b-0 transition-colors" 
             onclick="selectConsignee(${c.id})">
            <div class="font-semibold" style="color: var(--fallback-bc, oklch(var(--bc))) !important;">${c.businessName || c.fullName || c.email}</div>
            <div class="text-sm" style="color: var(--fallback-bc, oklch(var(--bc) / 0.7)) !important;">${c.email}</div>
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
                   class="input input-bordered input-sm w-full"
                   placeholder="e.g., ABCD1234567">
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
            <span class="teu-display badge badge-ghost" data-index="${index}">—</span>
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
    updateCYAllocation();
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

function updateCYAllocation() {
    const containerRows = document.querySelectorAll('.container-row');
    
    // Calculate utilization per CY allocation
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
                allocationUtilization[allocationId] = {
                    used: 0,
                    allocation: cyAllocations.find(a => a.id == allocationId)
                };
            }
            
            allocationUtilization[allocationId].used += teuValue;
        }
    });
    
    // Update CY grid cards with real-time utilization
    cyAllocations.forEach(allocation => {
        const card = document.querySelector(`[data-allocation-id="${allocation.id}"]`);
        if (!card) return;
        
        // Calculate new utilization including pending containers
        const pendingTeu = allocationUtilization[allocation.id]?.used || 0;
        const newAllocated = allocation.allocated_teu + pendingTeu;
        const newAvailable = allocation.total_teu_capacity - newAllocated;
        const utilizationPercent = (newAllocated / allocation.total_teu_capacity) * 100;

        const status = getCYStatus(utilizationPercent);

        // Update card border via inline style
        card.style.borderColor = `oklch(var(${status.cssVar})/.3)`;

        // Update status badge
        const statusBadge = card.querySelector('.badge');
        if (statusBadge) {
            statusBadge.className = `badge badge-${status.type} badge-sm`;
            statusBadge.textContent = status.label;
        }

        // Update utilization percentage
        const utilizationPercentDisplay = card.querySelector('.cy-utilization-percent');
        if (utilizationPercentDisplay) {
            utilizationPercentDisplay.textContent = `${utilizationPercent.toFixed(1)}%`;
        }

        // Update utilization bar
        const utilizationBar = card.querySelector('.cy-utilization-bar');
        if (utilizationBar) {
            utilizationBar.style.width = `${Math.min(utilizationPercent, 100)}%`;
            utilizationBar.style.background = `oklch(var(${status.cssVar}))`;
        }
    });
    
    // Calculate total TEU across all containers
    let totalTeu = 0;
    containerRows.forEach(row => {
        const index = row.dataset.index;
        const sizeSelect = row.querySelector(`[name="containers[${index}][sizeId]"]`);
        const selectedOption = sizeSelect?.options[sizeSelect.selectedIndex];
        
        if (selectedOption && selectedOption.dataset.teu) {
            totalTeu += parseFloat(selectedOption.dataset.teu);
        }
    });
    
    // Show allocation display if containers exist
    if (totalTeu > 0) {
        document.getElementById('cyAllocationDisplay').classList.remove('hidden');
        document.getElementById('totalTeuRequired').textContent = totalTeu.toFixed(1);
    } else {
        document.getElementById('cyAllocationDisplay').classList.add('hidden');
    }
}

function handleFormSubmit(e) {
    e.preventDefault();
    
    // Clear previous errors
    clearErrors();
    
    // Collect form data
    const formData = {
        blNumber: document.getElementById('blNumber').value,
        vesselNumber: document.getElementById('vesselNumber').value,
        eta: document.getElementById('eta').value,
        consigneeId: document.getElementById('consigneeId').value,
        containers: []
    };
    
    // Collect container data
    const containerRows = document.querySelectorAll('.container-row');
    containerRows.forEach(row => {
        const index = row.dataset.index;
        const container = {
            number: row.querySelector(`[name="containers[${index}][number]"]`).value,
            typeId: row.querySelector(`[name="containers[${index}][typeId]"]`).value,
            sizeId: row.querySelector(`[name="containers[${index}][sizeId]"]`).value,
            cyAllocationId: row.querySelector(`[name="containers[${index}][cyAllocationId]"]`).value
        };
        formData.containers.push(container);
    });
    
    // Validate
    if (!validateForm(formData)) {
        return;
    }
    
    // Submit
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';
    
    fetch('/noa/create', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('NOA created successfully!');
            window.location.href = '/manifest-workflow';
        } else {
            alert('Error: ' + (data.error || 'Failed to create NOA'));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create NOA';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while creating the NOA');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create NOA';
    });
}

function validateForm(formData) {
    let isValid = true;
    
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
        if (!container.cyAllocationId) {
            showError('containersError', `Container ${idx + 1} requires a CY location`);
            isValid = false;
        }
    });
    
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
