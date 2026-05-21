/**
 * System Admin eDO Payment Dashboard
 * Handles pending payment list, sorting, pagination, and payment actions
 */

let currentPage = 1;
let currentSort = 'submission_date';
let currentSortDirection = 'asc';
const itemsPerPage = 20;
let paymentsData = [];

// Initialize dashboard on page load
document.addEventListener('DOMContentLoaded', function() {
    loadPendingPayments();
    initializeSortHandlers();
});

/**
 * Load pending payments from API
 */
function loadPendingPayments() {
    const params = new URLSearchParams({
        page: currentPage,
        limit: itemsPerPage,
        sort: currentSort,
        direction: currentSortDirection
    });

    fetch(`/admin/edo-payments?${params.toString()}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Failed to load payments');
        }
        return response.json();
    })
    .then(data => {
        paymentsData = data.payments || [];
        renderPaymentsTable(paymentsData);
        updatePagination(data.pagination);
        updatePendingCount(data.pendingCount || paymentsData.length);
    })
    .catch(error => {
        console.error('Error loading payments:', error);
        showToast('error', 'Error', 'Failed to load pending payments');
        showEmptyState();
    });
}

/**
 * Render payments table
 */
function renderPaymentsTable(payments) {
    const tbody = document.getElementById('paymentsTableBody');
    const loadingRow = document.getElementById('loadingRow');
    const emptyRow = document.getElementById('emptyRow');

    // Hide loading state
    if (loadingRow) {
        loadingRow.classList.add('hidden');
    }

    // Clear existing rows except loading and empty
    const existingRows = tbody.querySelectorAll('tr:not(#loadingRow):not(#emptyRow)');
    existingRows.forEach(row => row.remove());

    if (payments.length === 0) {
        emptyRow.classList.remove('hidden');
        return;
    }

    emptyRow.classList.add('hidden');

    payments.forEach(payment => {
        const row = createPaymentRow(payment);
        tbody.appendChild(row);
    });
}

/**
 * Create a payment table row
 */
function createPaymentRow(payment) {
    const tr = document.createElement('tr');
    tr.className = 'hover:bg-gray-50 transition-colors';
    tr.dataset.paymentId = payment.id;

    const formattedAmount = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(payment.amount);

    const submittedDate = new Date(payment.submittedAt);
    const formattedDate = submittedDate.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });

    tr.innerHTML = `
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="text-sm font-medium text-gray-900">${escapeHtml(payment.edoNumber)}</div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="text-sm text-gray-900">${escapeHtml(payment.containerNumber)}</div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="text-sm text-gray-900">${escapeHtml(payment.manifestNumber)}</div>
        </td>
        <td class="px-6 py-4">
            <div class="text-sm text-gray-900">${escapeHtml(payment.brokerName)}</div>
            <div class="text-xs text-gray-500">${escapeHtml(payment.brokerCompany || '')}</div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="text-sm font-medium text-gray-900">${formattedAmount}</div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="text-sm text-gray-900">${formattedDate}</div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
            <div class="flex items-center justify-end space-x-2">
                <button onclick="viewReceipt(${payment.id})" 
                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    View Receipt
                </button>
                <button onclick="approvePayment(${payment.id})" 
                        class="inline-flex items-center px-3 py-1.5 border border-transparent shadow-sm text-xs font-medium rounded text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Approve
                </button>
                <button onclick="rejectPayment(${payment.id})" 
                        class="inline-flex items-center px-3 py-1.5 border border-transparent shadow-sm text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Reject
                </button>
            </div>
        </td>
    `;

    return tr;
}

/**
 * Initialize sort handlers for table headers
 */
function initializeSortHandlers() {
    const sortableHeaders = document.querySelectorAll('[data-sort]');
    
    sortableHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const sortField = this.dataset.sort;
            
            // Toggle direction if clicking same column
            if (currentSort === sortField) {
                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort = sortField;
                currentSortDirection = 'asc';
            }
            
            // Reset to first page when sorting
            currentPage = 1;
            
            // Update UI to show active sort
            updateSortIndicators();
            
            // Reload data
            loadPendingPayments();
        });
    });
}

/**
 * Update sort indicators in table headers
 */
function updateSortIndicators() {
    const sortableHeaders = document.querySelectorAll('[data-sort]');
    
    sortableHeaders.forEach(header => {
        const svg = header.querySelector('svg');
        if (!svg) return;
        
        if (header.dataset.sort === currentSort) {
            header.classList.add('bg-gray-100');
            svg.classList.remove('text-gray-400');
            svg.classList.add('text-indigo-600');
            
            // Update arrow direction
            if (currentSortDirection === 'desc') {
                svg.style.transform = 'rotate(180deg)';
            } else {
                svg.style.transform = 'rotate(0deg)';
            }
        } else {
            header.classList.remove('bg-gray-100');
            svg.classList.remove('text-indigo-600');
            svg.classList.add('text-gray-400');
            svg.style.transform = 'rotate(0deg)';
        }
    });
}

/**
 * Update pagination controls
 */
function updatePagination(pagination) {
    const showingFrom = document.getElementById('showingFrom');
    const showingTo = document.getElementById('showingTo');
    const totalRecords = document.getElementById('totalRecords');
    const paginationControls = document.getElementById('paginationControls');

    if (!pagination) return;

    showingFrom.textContent = pagination.from || 0;
    showingTo.textContent = pagination.to || 0;
    totalRecords.textContent = pagination.total || 0;

    // Clear existing pagination buttons
    paginationControls.innerHTML = '';

    if (pagination.totalPages <= 1) return;

    // Previous button
    const prevButton = createPaginationButton('Previous', currentPage - 1, currentPage === 1);
    paginationControls.appendChild(prevButton);

    // Page number buttons
    for (let i = 1; i <= pagination.totalPages; i++) {
        // Show first, last, current, and adjacent pages
        if (i === 1 || i === pagination.totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
            const pageButton = createPaginationButton(i.toString(), i, false, i === currentPage);
            paginationControls.appendChild(pageButton);
        } else if (i === currentPage - 2 || i === currentPage + 2) {
            // Show ellipsis
            const ellipsis = document.createElement('span');
            ellipsis.className = 'px-3 py-2 text-gray-500';
            ellipsis.textContent = '...';
            paginationControls.appendChild(ellipsis);
        }
    }

    // Next button
    const nextButton = createPaginationButton('Next', currentPage + 1, currentPage === pagination.totalPages);
    paginationControls.appendChild(nextButton);
}

/**
 * Create pagination button
 */
function createPaginationButton(label, page, disabled, active = false) {
    const button = document.createElement('button');
    button.textContent = label;
    button.disabled = disabled;
    
    let classes = 'px-4 py-2 text-sm font-medium rounded-md ';
    if (active) {
        classes += 'bg-indigo-600 text-white';
    } else if (disabled) {
        classes += 'bg-gray-100 text-gray-400 cursor-not-allowed';
    } else {
        classes += 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50';
    }
    
    button.className = classes;
    
    if (!disabled) {
        button.addEventListener('click', () => {
            currentPage = page;
            loadPendingPayments();
        });
    }
    
    return button;
}

/**
 * View payment receipt
 */
function viewReceipt(paymentId) {
    // Load receipt preview modal content
    fetch(`/admin/edo-payments/${paymentId}/receipt`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Failed to load receipt');
        }
        return response.json();
    })
    .then(data => {
        openReceiptPreviewModal(data);
    })
    .catch(error => {
        console.error('Error loading receipt:', error);
        showToast('error', 'Error', 'Failed to load payment receipt');
    });
}

/**
 * Approve payment with confirmation
 */
function approvePayment(paymentId) {
    if (!confirm('Are you sure you want to approve this payment? This will release the eDO to the broker.')) {
        return;
    }

    fetch(`/admin/edo-payments/${paymentId}/approve`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(data => {
                throw new Error(data.message || 'Failed to approve payment');
            });
        }
        return response.json();
    })
    .then(data => {
        showToast('success', 'Payment Approved', data.message || 'Payment approved and eDO released successfully');
        
        // Remove the row from table
        const row = document.querySelector(`tr[data-payment-id="${paymentId}"]`);
        if (row) {
            row.remove();
        }
        
        // Update pending count
        updatePendingCountBadge(-1);
        
        // Reload if table is empty
        const remainingRows = document.querySelectorAll('#paymentsTableBody tr:not(#loadingRow):not(#emptyRow)');
        if (remainingRows.length === 0) {
            loadPendingPayments();
        }
    })
    .catch(error => {
        console.error('Error approving payment:', error);
        showToast('error', 'Approval Failed', error.message);
    });
}

/**
 * Reject payment - opens rejection modal
 */
function rejectPayment(paymentId) {
    openRejectionModal(paymentId);
}

/**
 * Update pending count display
 */
function updatePendingCount(count) {
    const display = document.getElementById('pendingCountDisplay');
    if (display) {
        display.textContent = count;
    }
}

/**
 * Update pending count badge (increment/decrement)
 */
function updatePendingCountBadge(delta) {
    const display = document.getElementById('pendingCountDisplay');
    if (display) {
        const currentCount = parseInt(display.textContent) || 0;
        const newCount = Math.max(0, currentCount + delta);
        display.textContent = newCount;
    }
}

/**
 * Show empty state
 */
function showEmptyState() {
    const loadingRow = document.getElementById('loadingRow');
    const emptyRow = document.getElementById('emptyRow');
    
    if (loadingRow) {
        loadingRow.classList.add('hidden');
    }
    if (emptyRow) {
        emptyRow.classList.remove('hidden');
    }
}

/**
 * Show toast notification
 */
function showToast(type, title, message) {
    const toast = document.getElementById('toastNotification');
    const toastContent = document.getElementById('toastContent');
    const toastIcon = document.getElementById('toastIcon');
    const toastTitle = document.getElementById('toastTitle');
    const toastMessage = document.getElementById('toastMessage');

    // Set border color based on type
    const borderColors = {
        success: 'border-green-500',
        error: 'border-red-500',
        warning: 'border-yellow-500',
        info: 'border-blue-500'
    };

    toastContent.className = `bg-white rounded-lg shadow-lg border-l-4 p-4 ${borderColors[type] || borderColors.info}`;

    // Set icon based on type
    const icons = {
        success: '<svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        error: '<svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        warning: '<svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
        info: '<svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
    };

    toastIcon.innerHTML = icons[type] || icons.info;
    toastTitle.textContent = title;
    toastMessage.textContent = message;

    toast.classList.remove('hidden');

    // Auto-hide after 5 seconds
    setTimeout(() => {
        hideToast();
    }, 5000);
}

/**
 * Hide toast notification
 */
function hideToast() {
    const toast = document.getElementById('toastNotification');
    toast.classList.add('hidden');
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
}

// Export functions for modal usage
window.viewReceipt = viewReceipt;
window.approvePayment = approvePayment;
window.rejectPayment = rejectPayment;
window.hideToast = hideToast;
