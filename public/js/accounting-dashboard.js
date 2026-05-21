/**
 * Accounting Dashboard Handler
 * Requirements: 7.1-7.8, 9.1-9.8
 */

let currentTab = 'requests';

document.addEventListener('DOMContentLoaded', function() {
    loadDashboardData();
    setInterval(loadDashboardData, 60000); // Refresh every minute
});

function switchTab(tab) {
    currentTab = tab;
    
    // Update tab buttons
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active', 'border-blue-500', 'text-blue-600');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    
    const activeTab = document.getElementById(tab + 'Tab');
    activeTab.classList.add('active', 'border-blue-500', 'text-blue-600');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
    
    // Update content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    document.getElementById(tab + 'Content').classList.remove('hidden');
    
    // Load data for the selected tab
    if (tab === 'requests') {
        loadRegenerationRequests();
    } else if (tab === 'payments') {
        loadPaymentConfirmations();
    } else if (tab === 'history') {
        loadHistory();
    }
}

function loadDashboardData() {
    loadSummaryStats();
    loadRegenerationRequests();
    loadPaymentConfirmations();
}

function loadSummaryStats() {
    fetch('/api/accounting/edo/summary')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('pendingRequestsCount').textContent = data.pendingRequests;
                document.getElementById('pendingPaymentsCount').textContent = data.pendingPayments;
                document.getElementById('completedTodayCount').textContent = data.completedToday;
                document.getElementById('requestsBadge').textContent = data.pendingRequests;
                document.getElementById('paymentsBadge').textContent = data.pendingPayments;
            }
        })
        .catch(error => console.error('Error loading summary stats:', error));
}

function loadRegenerationRequests() {
    fetch('/api/accounting/edo/regeneration-requests/pending')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderRegenerationRequests(data.requests);
            }
        })
        .catch(error => console.error('Error loading regeneration requests:', error));
}

function renderRegenerationRequests(requests) {
    const tbody = document.getElementById('requestsTableBody');
    
    if (requests.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                    No pending regeneration requests
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = requests.map(request => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#${request.id}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${request.edoNumber}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${request.containerNumber}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600">${request.expiredDays} days</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${formatDate(request.requestedAt)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <button onclick="generateBilling(${request.id})"
                        class="text-blue-600 hover:text-blue-900">Generate Billing</button>
            </td>
        </tr>
    `).join('');
}

function loadPaymentConfirmations() {
    fetch('/api/accounting/edo/payment-receipts/pending')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderPaymentConfirmations(data.payments);
            }
        })
        .catch(error => console.error('Error loading payment confirmations:', error));
}

function renderPaymentConfirmations(payments) {
    const tbody = document.getElementById('paymentsTableBody');
    
    if (payments.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                    No pending payment confirmations
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = payments.map(payment => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#${payment.id}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${payment.edoNumber}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${payment.containerNumber}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">$${payment.amount.toFixed(2)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${formatDate(payment.submittedAt)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                <a href="/accounting/edo/payment-receipts/${payment.id}"
                   class="text-blue-600 hover:text-blue-900">Review</a>
                <button onclick="confirmPayment(${payment.id})"
                        class="text-green-600 hover:text-green-900">Confirm</button>
                <button onclick="rejectPayment(${payment.id})"
                        class="text-red-600 hover:text-red-900">Reject</button>
            </td>
        </tr>
    `).join('');
}

function loadHistory() {
    fetch('/api/accounting/edo/history')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderHistory(data.history);
            }
        })
        .catch(error => console.error('Error loading history:', error));
}

function renderHistory(history) {
    const tbody = document.getElementById('historyTableBody');
    
    if (history.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                    No history records found
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = history.map(record => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${formatDate(record.date)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${record.type}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${record.edoNumber}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${record.containerNumber}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">$${record.amount.toFixed(2)}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusBadgeClass(record.status)}">
                    ${record.status}
                </span>
            </td>
        </tr>
    `).join('');
}

function generateBilling(requestId) {
    if (!confirm('Generate billing for this regeneration request?')) {
        return;
    }
    
    fetch(`/accounting/edo/regeneration-requests/${requestId}/generate-billing`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Billing generated successfully!');
            loadDashboardData();
        } else {
            alert('Error: ' + (data.error || 'Failed to generate billing'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while generating billing');
    });
}

function confirmPayment(paymentId) {
    if (!confirm('Confirm this payment? This will generate a new eDO.')) {
        return;
    }
    
    fetch(`/accounting/edo/payment-receipts/${paymentId}/confirm`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Payment confirmed! New eDO has been generated.');
            loadDashboardData();
        } else {
            alert('Error: ' + (data.error || 'Failed to confirm payment'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while confirming payment');
    });
}

function rejectPayment(paymentId) {
    const reason = prompt('Enter rejection reason:');
    if (!reason) {
        return;
    }
    
    fetch(`/accounting/edo/payment-receipts/${paymentId}/reject`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ rejection_reason: reason })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Payment rejected.');
            loadDashboardData();
        } else {
            alert('Error: ' + (data.error || 'Failed to reject payment'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while rejecting payment');
    });
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

function getStatusBadgeClass(status) {
    const classes = {
        'completed': 'bg-green-100 text-green-800',
        'confirmed': 'bg-green-100 text-green-800',
        'rejected': 'bg-red-100 text-red-800',
        'pending': 'bg-yellow-100 text-yellow-800'
    };
    return classes[status.toLowerCase()] || 'bg-gray-100 text-gray-800';
}
