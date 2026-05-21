// NOA Creation - Redesigned UI
let containers = [];
let terminalAllocations = [];
let containerCounter = 0;
let consignees = [];
let containerSizes = {};
let containerTypes = {};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    loadTerminalAllocations();
    loadConsignees();
    loadContainerTypes();
    loadContainerSizes();
    renderContainersTable(); // Show empty state initially
    updateCYAllocationSummary(); // Initialize with 0 values
});

function initializeEventListeners() {
    const addContainerBtn = document.getElementById('addContainerBtn');
    const submitBtn = document.getElementById('submitBtn');
    const addContainerForm = document.getElementById('addContainerForm');
    const containerSize = document.getElementById('containerSize');
    
    if (addContainerBtn) {
        addContainerBtn.addEventListener('click', openAddContainerModal);
    }
    
    if (submitBtn) {
        submitBtn.addEventListener('click', handleCreateNOA);
    }
    
    if (addContainerForm) {
        addContainerForm.addEventListener('submit', handleAddContainer);
    }
    
    if (containerSize) {
        containerSize.addEventListener('change', updateTEUDisplay);
    }
}

// Load consignees
async function loadConsignees() {
    try {
        const response = await fetch('/api/consignees');
        if (!response.ok) throw new Error('Failed to load consignees');
        const data = await response.json();
        consignees = data.consignees || [];
    } catch (error) {
        console.error('Error loading consignees:', error);
    }
}

// Load container types
async function loadContainerTypes() {
    try {
        const response = await fetch('/api/container-types');
        if (!response.ok) throw new Error('Failed to load container types');
        const data = await response.json();
        containerTypes = data.types || {};
    } catch (error) {
        console.error('Error loading container types:', error);
    }
}

// Load container sizes
async function loadContainerSizes() {
    try {
        const response = await fetch('/api/container-sizes');
        if (!response.ok) throw new Error('Failed to load container sizes');
        const data = await response.json();
        containerSizes = data.sizes || {};
    } catch (error) {
        console.error('Error loading container sizes:', error);
    }
}

// Load terminal allocations from API
async function loadTerminalAllocations() {
    try {
        const response = await fetch('/api/cy-allocations/all');
        if (!response.ok) {
            // Try to load from cache
            const cached = loadFromCache();
            if (cached) {
                terminalAllocations = cached.data;
                renderTerminalCards();
                updateCYLocationDropdown();
                showNotification('Loaded from cache - data may be outdated', 'info');
                return;
            }
            throw new Error('Failed to load terminal allocations');
        }
        
        const data = await response.json();
        terminalAllocations = data.allocations || [];
        
        // Cache the data
        try {
            localStorage.setItem('cyAllocationsCache', JSON.stringify({
                data: terminalAllocations,
                timestamp: new Date().toISOString()
            }));
        } catch (e) {
            console.warn('Failed to cache data:', e);
        }
        
        renderTerminalCards();
        updateCYLocationDropdown();
    } catch (error) {
        console.error('Error loading terminal allocations:', error);
        showNotification('Failed to load terminal data', 'error');
    }
}

// Load from cache
function loadFromCache() {
    try {
        const cached = localStorage.getItem('cyAllocationsCache');
        if (cached) {
            return JSON.parse(cached);
        }
    } catch (e) {
        console.warn('Failed to load from cache:', e);
    }
    return null;
}

// Render terminal cards
function renderTerminalCards() {
    const grid = document.getElementById('terminalCardsGrid');
    if (!grid) return;
    
    grid.innerHTML = terminalAllocations.map(allocation => {
        const terminal = allocation.terminal_name || 'Unknown Terminal';
        const capacity20 = allocation.capacity_20ft || 0;
        const capacity40 = allocation.capacity_40ft || 0;
        const allocated20 = allocation.allocated_20ft || 0;
        const allocated40 = allocation.allocated_40ft || 0;
        const preforecast20 = allocation.preforecast_20ft || 0;
        const preforecast40 = allocation.preforecast_40ft || 0;
        const available20 = capacity20 - allocated20 - preforecast20;
        const available40 = capacity40 - allocated40 - preforecast40;
        
        const totalCapacity = capacity20 + capacity40;
        const totalAllocated = allocated20 + allocated40;
        const utilization = totalCapacity > 0 ? ((totalAllocated / totalCapacity) * 100).toFixed(1) : 0;
        
        const isAvailable = available20 > 0 || available40 > 0;
        const statusBadge = isAvailable 
            ? '<span class="inline-flex items-center px-2 py-1 text-xs font-medium text-green-700 bg-green-100 rounded">Available</span>'
            : '<span class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-700 bg-gray-100 rounded">Full</span>';
        
        return `
            <div class="bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                <div class="p-4">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <h3 class="text-sm font-semibold text-gray-900">${terminal}</h3>
                        </div>
                        ${statusBadge}
                    </div>
                    
                    <!-- 20ft Containers -->
                    <div class="mb-3">
                        <div class="flex items-center justify-between text-xs text-gray-600 mb-1">
                            <span class="font-medium">20ft Containers</span>
                            <span class="text-gray-500">20ft</span>
                        </div>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Capacity</span>
                                <span class="font-semibold text-gray-900">${capacity20} containers</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Allocated</span>
                                <span class="font-semibold ${allocated20 > 0 ? 'text-blue-600' : 'text-gray-900'}">${allocated20} containers</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Pre-Forecast</span>
                                <span class="font-semibold ${preforecast20 > 0 ? 'text-purple-600' : 'text-gray-900'}">${preforecast20} containers</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Available</span>
                                <span class="font-semibold ${available20 > 0 ? 'text-green-600' : 'text-gray-900'}">${available20} containers</span>
                            </div>
                            <div class="flex justify-between pt-1 border-t border-gray-200">
                                <span class="text-gray-600">Utilization</span>
                                <span class="font-semibold text-gray-900">${capacity20 > 0 ? ((allocated20 / capacity20) * 100).toFixed(1) : 0}%</span>
                            </div>
                        </div>
                        <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full" style="width: ${capacity20 > 0 ? ((allocated20 / capacity20) * 100) : 0}%"></div>
                        </div>
                    </div>
                    
                    <!-- 40ft Containers -->
                    <div>
                        <div class="flex items-center justify-between text-xs text-gray-600 mb-1">
                            <span class="font-medium">40ft Containers</span>
                            <span class="text-gray-500">40ft</span>
                        </div>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Capacity</span>
                                <span class="font-semibold text-gray-900">${capacity40} containers</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Allocated</span>
                                <span class="font-semibold ${allocated40 > 0 ? 'text-blue-600' : 'text-gray-900'}">${allocated40} containers</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Pre-Forecast</span>
                                <span class="font-semibold ${preforecast40 > 0 ? 'text-purple-600' : 'text-gray-900'}">${preforecast40} containers</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Available</span>
                                <span class="font-semibold ${available40 > 0 ? 'text-green-600' : 'text-gray-900'}">${available40} containers</span>
                            </div>
                            <div class="flex justify-between pt-1 border-t border-gray-200">
                                <span class="text-gray-600">Utilization</span>
                                <span class="font-semibold text-gray-900">${capacity40 > 0 ? ((allocated40 / capacity40) * 100).toFixed(1) : 0}%</span>
                            </div>
                        </div>
                        <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full" style="width: ${capacity40 > 0 ? ((allocated40 / capacity40) * 100) : 0}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// Open add container modal
function openAddContainerModal() {
    const modal = document.getElementById('addContainerModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.getElementById('addContainerForm').reset();
        updateCYLocationDropdown();
    }
}

// Close add container modal
function closeAddContainerModal() {
    const modal = document.getElementById('addContainerModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

// Update TEU display based on container size
function updateTEUDisplay() {
    const size = document.getElementById('containerSize').value;
    const teuInput = document.getElementById('containerTeu');
    
    if (size === '20') {
        teuInput.value = '1 TEU';
    } else if (size === '40') {
        teuInput.value = '2 TEU';
    } else {
        teuInput.value = '';
    }
}

// Update CY location dropdown
function updateCYLocationDropdown() {
    const dropdown = document.getElementById('containerCyLocation');
    if (!dropdown) return;
    
    dropdown.innerHTML = '<option value="">Select location</option>' + 
        terminalAllocations.map(allocation => {
            const terminal = allocation.terminal_name || 'Unknown';
            return `<option value="${allocation.id}">${terminal}</option>`;
        }).join('');
}

// Handle add container form submission
function handleAddContainer(e) {
    e.preventDefault();
    
    const containerNumber = document.getElementById('containerNumber').value;
    const size = document.getElementById('containerSize').value;
    const type = document.getElementById('containerType').value;
    const cyLocationId = document.getElementById('containerCyLocation').value;
    
    const cyLocation = terminalAllocations.find(a => a.id == cyLocationId);
    const teu = size === '20' ? 1 : 2;
    
    containerCounter++;
    containers.push({
        id: containerCounter,
        number: containerNumber,
        size: size + ' Feet',
        type: type,
        teu: teu,
        cyLocation: cyLocation ? cyLocation.terminal_name : 'Unknown',
        cyLocationId: cyLocationId
    });
    
    renderContainersTable();
    updateCYAllocationSummary();
    closeAddContainerModal();
    showNotification('Container added successfully', 'success');
}

// Render containers table
function renderContainersTable() {
    const tbody = document.getElementById('containersTableBody');
    if (!tbody) return;
    
    if (containers.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="px-6 py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">No containers added yet</p>
                    <p class="text-xs text-gray-400">Click "Add Container" to get started</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = containers.map((container, index) => `
        <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${index + 1}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${container.number}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">${container.size}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">${container.type}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">${container.teu} TEU</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">${container.cyLocation}</td>
            <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                <button onclick="removeContainer(${container.id})" 
                        class="text-red-600 hover:text-red-800 transition-colors"
                        title="Delete container">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            </td>
        </tr>
    `).join('');
}

// Remove container
function removeContainer(id) {
    containers = containers.filter(c => c.id !== id);
    renderContainersTable();
    updateCYAllocationSummary();
    showNotification('Container removed', 'info');
}

// Update CY allocation summary
function updateCYAllocationSummary() {
    const totalTeu = containers.reduce((sum, c) => sum + c.teu, 0);
    const totalAvailable = terminalAllocations.reduce((sum, a) => {
        const available20 = (a.capacity_20ft || 0) - (a.allocated_20ft || 0) - (a.preforecast_20ft || 0);
        const available40 = (a.capacity_40ft || 0) - (a.allocated_40ft || 0) - (a.preforecast_40ft || 0);
        return sum + available20 + (available40 * 2);
    }, 0);
    
    document.getElementById('totalTeuRequired').textContent = totalTeu.toFixed(1);
    document.getElementById('availableTeuCapacity').textContent = totalAvailable;
    document.getElementById('remainingCapacity').textContent = totalAvailable - totalTeu;
}

// Handle Create NOA
async function handleCreateNOA() {
    if (containers.length === 0) {
        showNotification('Please add at least one container', 'error');
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';
    
    try {
        // Get form data from hidden fields (if they exist from original form)
        const consigneeId = document.getElementById('consigneeId')?.value;
        const blNumber = document.getElementById('blNumber')?.value;
        const vesselNumber = document.getElementById('vesselNumber')?.value;
        const eta = document.getElementById('eta')?.value;
        
        if (!consigneeId || !blNumber || !vesselNumber || !eta) {
            showNotification('Please fill in all required fields (Consignee, BL Number, Vessel Number, ETA)', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create NOA';
            return;
        }
        
        // Prepare container data
        const containerData = containers.map(c => ({
            containerNumber: c.number,
            size: c.size.replace(' Feet', ''),
            type: c.type,
            cyAllocationId: c.cyLocationId
        }));
        
        const payload = {
            consigneeId: parseInt(consigneeId),
            blNumber: blNumber,
            vesselNumber: vesselNumber,
            eta: eta,
            containers: containerData
        };
        
        const response = await fetch('/api/noa/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'Failed to create NOA');
        }
        
        const result = await response.json();
        showNotification('NOA created successfully!', 'success');
        
        // Redirect to NOA list after 2 seconds
        setTimeout(() => {
            window.location.href = '/manifest-workflow/list';
        }, 2000);
        
    } catch (error) {
        console.error('Error creating NOA:', error);
        showNotification(error.message || 'Failed to create NOA', 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create NOA';
    }
}

// Show notification
function showNotification(message, type = 'info') {
    const colors = {
        success: 'bg-green-100 text-green-800 border-green-200',
        error: 'bg-red-100 text-red-800 border-red-200',
        info: 'bg-blue-100 text-blue-800 border-blue-200'
    };
    
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 px-4 py-3 rounded-lg border ${colors[type]} shadow-lg z-50 transition-opacity`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}
