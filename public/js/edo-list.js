/**
 * eDO List Handler
 * Requirements: 3.4, 4.5, 12.6, 12.7
 */

let allEdos = [];
let currentFilter = '';

document.addEventListener('DOMContentLoaded', function() {
    loadEDOs();
    initializeEventListeners();
});

function initializeEventListeners() {
    const statusFilter = document.getElementById('statusFilter');
    statusFilter.addEventListener('change', handleFilterChange);
}

function loadEDOs() {
    showLoading();
    
    fetch('/api/edos/my-edos')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allEdos = data.edos;
                renderEDOs(allEdos);
            } else {
                showError('Failed to load eDOs');
            }
        })
        .catch(error => {
            console.error('Error loading eDOs:', error);
            showError('An error occurred while loading eDOs');
        });
}

function handleFilterChange(e) {
    currentFilter = e.target.value;
    filterAndRenderEDOs();
}

function filterAndRenderEDOs() {
    let filteredEdos = allEdos;
    
    if (currentFilter) {
        filteredEdos = allEdos.filter(edo => edo.status === currentFilter);
    }
    
    renderEDOs(filteredEdos);
}

function renderEDOs(edos) {
    hideLoading();
    
    const tbody = document.getElementById('edoTableBody');
    const emptyState = document.getElementById('emptyState');
    
    if (edos.length === 0) {
        tbody.innerHTML = '';
        emptyState.classList.remove('hidden');
        return;
    }
    
    emptyState.classList.add('hidden');
    
    tbody.innerHTML = edos.map(edo => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900">${edo.edoNumber}</div>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">${edo.containerNumber}</div>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusBadgeClass(edo.status)}">
                    ${edo.status.toUpperCase()}
                </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                ${formatDate(edo.generatedAt)}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                ${edo.expiresAt ? formatDate(edo.expiresAt) : 'N/A'}
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                ${edo.expiredDays ? `<span class="text-sm font-semibold text-red-600">${edo.expiredDays} days</span>` : '<span class="text-sm text-gray-500">-</span>'}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <a href="/edo/${edo.id}/detail" class="text-blue-600 hover:text-blue-900">View Details</a>
            </td>
        </tr>
    `).join('');
}

function getStatusBadgeClass(status) {
    const classes = {
        'active': 'bg-green-100 text-green-800',
        'expired': 'bg-red-100 text-red-800',
        'locked': 'bg-yellow-100 text-yellow-800',
        'released': 'bg-blue-100 text-blue-800',
        'superseded': 'bg-gray-100 text-gray-800'
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function showLoading() {
    document.getElementById('loadingState').classList.remove('hidden');
    document.getElementById('edoTableBody').innerHTML = '';
    document.getElementById('emptyState').classList.add('hidden');
}

function hideLoading() {
    document.getElementById('loadingState').classList.add('hidden');
}

function showError(message) {
    hideLoading();
    alert(message);
}
