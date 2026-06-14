/**
 * Broker eDO List Handler
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 2.1, 2.4, 2.5, 2.9, 3.1, 3.2, 3.3, 3.4, 8.1
 */

let allEdos = [];
let filteredEdos = [];
let currentPage = 1;
let itemsPerPage = 20;
let currentFilter = '';

function formatMoneyAmount(amount) {
    const prefix = window.MONEY_PREFIX || '₱';
    const value = parseFloat(amount);
    if (Number.isNaN(value)) {
        return prefix + '0.00';
    }
    return prefix + value.toFixed(2);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    loadEDOs();
});

/**
 * Initialize all event listeners
 */
function initializeEventListeners() {
    // Status filter change
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', handleFilterChange);
    }

    // Pagination - mobile
    const prevPageMobile = document.getElementById('prevPageMobile');
    const nextPageMobile = document.getElementById('nextPageMobile');
    
    if (prevPageMobile) {
        prevPageMobile.addEventListener('click', () => changePage(currentPage - 1));
    }
    if (nextPageMobile) {
        nextPageMobile.addEventListener('click', () => changePage(currentPage + 1));
    }
}

/**
 * Load eDOs from API
 */
function loadEDOs() {
    showLoading();
    
    fetch('/broker/edos', {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            allEdos = data.data.edos || [];
            applyFilter();
        } else {
            showError('Failed to load eDOs');
            hideLoading();
        }
    })
    .catch(error => {
        console.error('Error loading eDOs:', error);
        showError('An error occurred while loading eDOs');
        hideLoading();
    });
}

/**
 * Handle status filter change
 */
function handleFilterChange(e) {
    currentFilter = e.target.value;
    currentPage = 1;
    applyFilter();
}

/**
 * Apply current filter to eDO list
 */
function applyFilter() {
    if (currentFilter) {
        filteredEdos = allEdos.filter(edo => edo.status === currentFilter);
    } else {
        filteredEdos = [...allEdos];
    }
    
    renderEDOs();
}

/**
 * Render eDOs in table and cards
 */
function renderEDOs() {
    hideLoading();
    
    const tableBody = document.getElementById('edoTableBody');
    const cardsContainer = document.getElementById('edoCardsContainer');
    const emptyState = document.getElementById('emptyState');
    const tableContainer = document.getElementById('edoTableContainer');
    const paginationContainer = document.getElementById('paginationContainer');
    
    if (filteredEdos.length === 0) {
        if (tableBody) tableBody.innerHTML = '';
        if (cardsContainer) cardsContainer.innerHTML = '';
        if (emptyState) emptyState.classList.remove('hidden');
        if (tableContainer) tableContainer.classList.add('hidden');
        if (cardsContainer) cardsContainer.classList.add('hidden');
        if (paginationContainer) paginationContainer.classList.add('hidden');
        return;
    }
    
    if (emptyState) emptyState.classList.add('hidden');
    if (tableContainer) tableContainer.classList.remove('hidden');
    if (cardsContainer) cardsContainer.classList.remove('hidden');
    
    // Calculate pagination
    const totalPages = Math.ceil(filteredEdos.length / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = Math.min(startIndex + itemsPerPage, filteredEdos.length);
    const pageEdos = filteredEdos.slice(startIndex, endIndex);
    
    // Render desktop table
    if (tableBody) {
        tableBody.innerHTML = pageEdos.map(edo => renderEdoRow(edo)).join('');
    }
    
    // Render mobile cards
    if (cardsContainer) {
        cardsContainer.innerHTML = pageEdos.map(edo => renderEdoCard(edo)).join('');
    }
    
    // Render pagination
    if (filteredEdos.length > itemsPerPage) {
        renderPagination(totalPages, startIndex, endIndex);
        if (paginationContainer) paginationContainer.classList.remove('hidden');
    } else {
        if (paginationContainer) paginationContainer.classList.add('hidden');
    }
}

/**
 * Render single eDO row for desktop table
 */
function renderEdoRow(edo) {
    const statusBadge = getStatusBadge(edo);
    const submittedAt = edo.currentPayment ? formatDate(edo.currentPayment.submittedAt) : '-';
    const actionButton = getActionButton(edo);

    return `
        <tr class="hover" onclick="window.location.href='/broker/edos/${edo.id}/page'" style="cursor: pointer;">
            <td><span class="font-semibold text-primary">${escapeHtml(edo.edoNumber)}</span></td>
            <td>${escapeHtml(edo.containerNumber)}</td>
            <td>${escapeHtml(edo.manifestNumber)}</td>
            <td>${statusBadge}</td>
            <td>${formatMoneyAmount(edo.feeAmount || 0)}</td>
            <td>${submittedAt}</td>
            <td onclick="event.stopPropagation()">${actionButton}</td>
        </tr>
    `;
}

/**
 * Render single eDO card for mobile
 */
function renderEdoCard(edo) {
    const statusBadge = getStatusBadge(edo);
    const submittedAt = edo.currentPayment ? formatDate(edo.currentPayment.submittedAt) : 'Not submitted';
    const actionButton = getActionButton(edo);
    
    return `
        <div class="p-4 hover:bg-base-200">
            <div class="flex flex-col gap-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1">
                        <div class="font-bold">${escapeHtml(edo.edoNumber)}</div>
                        <div class="text-sm opacity-60 mt-1">Container: ${escapeHtml(edo.containerNumber)}</div>
                        <div class="text-sm opacity-60">Manifest: ${escapeHtml(edo.manifestNumber)}</div>
                    </div>
                    <div>${actionButton}</div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    ${statusBadge}
                    <span class="text-sm font-semibold">${formatMoneyAmount(edo.feeAmount || 0)}</span>
                </div>
                <div class="text-sm opacity-60">Submitted: ${submittedAt}</div>
            </div>
        </div>
    `;
}

/**
 * Get status badge HTML
 */
function getStatusBadge(edo) {
    // Check if there's a current payment first
    if (edo.currentPayment) {
        const paymentStatus = edo.currentPayment.status;
        if (paymentStatus === 'pending_validation') {
            return '<span class="badge badge-soft badge-warning text-xs">Pending Validation</span>';
        } else if (paymentStatus === 'approved') {
            return '<span class="badge badge-soft badge-success text-xs">Released</span>';
        } else if (paymentStatus === 'rejected') {
            return '<span class="badge badge-soft badge-error text-xs">Rejected</span>';
        }
    }
    
    // Check eDO status
    const badges = {
        'pending_release': '<span class="badge badge-soft text-xs">Pending Payment</span>',
        'pending_validation': '<span class="badge badge-soft badge-warning text-xs">Pending Validation</span>',
        'released': '<span class="badge badge-soft badge-success text-xs">Released</span>',
    };
    return badges[edo.status] || '<span class="badge badge-soft text-xs">Unknown</span>';
}

/**
 * Get action button HTML
 */
function getActionButton(edo) {
    // Check if there's a current payment first
    if (edo.currentPayment) {
        const paymentStatus = edo.currentPayment.status;
        
        if (paymentStatus === 'pending_validation') {
            return `
                <span class="btn btn-ghost btn-sm rounded-lg cursor-not-allowed opacity-50">
                    <span class="icon-[tabler--clock] size-4"></span>
                    Awaiting Approval
                </span>
            `;
        } else if (paymentStatus === 'approved' || edo.status === 'released') {
            return `
                <a href="/broker/edos/${edo.id}/download" class="btn btn-success btn-sm rounded-lg gap-2">
                    <span class="icon-[tabler--download] size-4"></span>
                    Download PDF
                </a>
            `;
        } else if (paymentStatus === 'rejected') {
            return `
                <a href="/broker/manifests/${edo.manifestId}/${encodeURIComponent(edo.edoNumber)}/edo-payment" class="btn btn-warning btn-sm rounded-lg gap-2">
                    <span class="icon-[tabler--refresh] size-4"></span>
                    Resubmit
                </a>
            `;
        }
    }
    
    // Check eDO status
    if (edo.status === 'pending_validation') {
        return `
            <span class="btn btn-ghost btn-sm rounded-lg cursor-not-allowed opacity-50">
                <span class="icon-[tabler--clock] size-4"></span>
                Awaiting Approval
            </span>
        `;
    } else if (edo.status === 'pending_release') {
        return `
            <a href="/broker/manifests/${edo.manifestId}/${encodeURIComponent(edo.edoNumber)}/edo-payment" class="btn btn-primary btn-sm rounded-lg gap-2">
                <span class="icon-[tabler--credit-card] size-4"></span>
                Pay Now
            </a>
        `;
    } else if (edo.status === 'released') {
        return `
            <a href="/broker/edos/${edo.id}/download" class="btn btn-success btn-sm rounded-lg gap-2">
                <span class="icon-[tabler--download] size-4"></span>
                Download PDF
            </a>
        `;
    }
    
    return `
        <span class="text-xs font-medium text-base-content/60">
            View Details
        </span>
    `;
}

/**
 * Render pagination controls
 */
function renderPagination(totalPages, startIndex, endIndex) {
    const pageStart = document.getElementById('pageStart');
    const pageEnd = document.getElementById('pageEnd');
    const totalItems = document.getElementById('totalItems');
    const paginationButtons = document.getElementById('paginationButtons');
    const prevPageMobile = document.getElementById('prevPageMobile');
    const nextPageMobile = document.getElementById('nextPageMobile');
    
    if (pageStart) pageStart.textContent = startIndex + 1;
    if (pageEnd) pageEnd.textContent = endIndex;
    if (totalItems) totalItems.textContent = filteredEdos.length;
    
    // Desktop pagination buttons
    if (paginationButtons) {
        let buttonsHtml = '';
        
        // Previous button
        buttonsHtml += `
            <button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} 
                    class="btn btn-ghost btn-sm join-item rounded-lg ${currentPage === 1 ? 'opacity-50 cursor-not-allowed' : ''}">
                <span class="icon-[tabler--chevron-left] size-4"></span>
            </button>
        `;
        
        // Page numbers
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                buttonsHtml += `
                    <button onclick="changePage(${i})" 
                            class="btn btn-sm join-item rounded-lg ${i === currentPage ? 'btn-primary' : 'btn-ghost'}">
                        ${i}
                    </button>
                `;
            } else if (i === currentPage - 2 || i === currentPage + 2) {
                buttonsHtml += `<span class="btn btn-ghost btn-sm join-item rounded-lg cursor-default">...</span>`;
            }
        }
        
        // Next button
        buttonsHtml += `
            <button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} 
                    class="btn btn-ghost btn-sm join-item rounded-lg ${currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : ''}">
                <span class="icon-[tabler--chevron-right] size-4"></span>
            </button>
        `;
        
        paginationButtons.innerHTML = buttonsHtml;
    }
    
    // Mobile pagination buttons
    if (prevPageMobile) {
        prevPageMobile.disabled = currentPage === 1;
    }
    if (nextPageMobile) {
        nextPageMobile.disabled = currentPage === totalPages;
    }
}

/**
 * Change page
 */
function changePage(page) {
    const totalPages = Math.ceil(filteredEdos.length / itemsPerPage);
    if (page < 1 || page > totalPages) return;
    
    currentPage = page;
    renderEDOs();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/**
 * Show loading state
 */
function showLoading() {
    document.getElementById('loadingState').classList.remove('hidden');
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('edoTableContainer').classList.add('hidden');
    document.getElementById('edoCardsContainer').classList.add('hidden');
}

/**
 * Hide loading state
 */
function hideLoading() {
    document.getElementById('loadingState').classList.add('hidden');
}

/**
 * Show success toast
 */
function showSuccess(message) {
    const toast = document.getElementById('successToast');
    const messageEl = document.getElementById('successMessage');
    messageEl.textContent = message;
    toast.classList.remove('hidden');
    
    setTimeout(() => {
        toast.classList.add('hidden');
    }, 5000);
}

/**
 * Hide success toast
 */
function hideSuccessToast() {
    document.getElementById('successToast').classList.add('hidden');
}

/**
 * Show error toast
 */
function showError(message) {
    const toast = document.getElementById('errorToast');
    const messageEl = document.getElementById('errorMessage');
    messageEl.textContent = message;
    toast.classList.remove('hidden');
    
    setTimeout(() => {
        toast.classList.add('hidden');
    }, 5000);
}

/**
 * Hide error toast
 */
function hideErrorToast() {
    document.getElementById('errorToast').classList.add('hidden');
}

/**
 * Format date
 */
function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}