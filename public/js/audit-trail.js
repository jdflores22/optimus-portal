/**
 * eDO Audit Trail Viewer
 * Requirements: 14.8, 14.9
 */

let currentSearchType = 'container';
let currentSearchValue = '';
let auditLogs = [];

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('searchValue').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchAuditLogs();
        }
    });
});

function searchAuditLogs() {
    currentSearchType = document.getElementById('searchType').value;
    currentSearchValue = document.getElementById('searchValue').value.trim();
    
    if (!currentSearchValue) {
        alert('Please enter a search value');
        return;
    }
    
    showLoading();
    
    const endpoint = currentSearchType === 'container' 
        ? `/api/audit-logs/container/${encodeURIComponent(currentSearchValue)}`
        : `/api/audit-logs/edo/${encodeURIComponent(currentSearchValue)}`;
    
    fetch(endpoint)
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                auditLogs = data.audit_logs;
                displayResults(auditLogs);
            } else {
                alert('Error: ' + (data.message || 'Failed to retrieve audit logs'));
                showEmptyState();
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('An error occurred while searching audit logs');
            showEmptyState();
        });
}

function displayResults(logs) {
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('resultsSection').classList.remove('hidden');
    document.getElementById('resultCount').textContent = logs.length;
    
    const timeline = document.getElementById('auditTimeline');
    
    if (logs.length === 0) {
        timeline.innerHTML = `
            <li class="text-center py-8 text-sm text-gray-500">
                No audit log entries found for this search
            </li>
        `;
        return;
    }
    
    timeline.innerHTML = logs.map((log, index) => {
        const isLast = index === logs.length - 1;
        const eventIcon = getEventIcon(log.event_type);
        const eventColor = getEventColor(log.event_type);
        
        return `
            <li>
                <div class="relative pb-8">
                    ${!isLast ? '<span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>' : ''}
                    <div class="relative flex space-x-3">
                        <div>
                            <span class="h-8 w-8 rounded-full ${eventColor} flex items-center justify-center ring-8 ring-white">
                                ${eventIcon}
                            </span>
                        </div>
                        <div class="min-w-0 flex-1 pt-1.5">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">${formatEventType(log.event_type)}</p>
                                    <p class="mt-1 text-sm text-gray-600">
                                        <span class="font-medium">eDO:</span> ${log.edo_number} | 
                                        <span class="font-medium">Container:</span> ${log.container_number}
                                    </p>
                                    <p class="mt-1 text-sm text-gray-600">
                                        <span class="font-medium">User:</span> ${log.user.email} (${log.user.full_name})
                                    </p>
                                    ${log.details ? `
                                        <div class="mt-2 text-sm text-gray-500">
                                            <details class="cursor-pointer">
                                                <summary class="font-medium text-blue-600 hover:text-blue-800">View Details</summary>
                                                <pre class="mt-2 p-3 bg-gray-50 rounded-md text-xs overflow-x-auto">${JSON.stringify(log.details, null, 2)}</pre>
                                            </details>
                                        </div>
                                    ` : ''}
                                </div>
                                <div class="ml-4 flex-shrink-0 text-right">
                                    <p class="text-sm text-gray-500">${formatDate(log.timestamp)}</p>
                                    <p class="text-xs text-gray-400">${formatTime(log.timestamp)}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </li>
        `;
    }).join('');
}

function getEventIcon(eventType) {
    const icons = {
        'edo_created': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"></path></svg>',
        'edo_expired': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>',
        'regeneration_requested': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"></path></svg>',
        'billing_generated': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"></path><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"></path></svg>',
        'payment_submitted': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path></svg>',
        'payment_confirmed': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>',
        'payment_rejected': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>',
        'admin_unlocked': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a5 5 0 00-5 5v2a2 2 0 00-2 2v5a2 2 0 002 2h10a2 2 0 002-2v-5a2 2 0 00-2-2H7V7a3 3 0 015.905-.75 1 1 0 001.937-.5A5.002 5.002 0 0010 2z"></path></svg>',
        'edo_released': '<svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.707l-3-3a1 1 0 00-1.414 1.414L10.586 9H7a1 1 0 100 2h3.586l-1.293 1.293a1 1 0 101.414 1.414l3-3a1 1 0 000-1.414z" clip-rule="evenodd"></path></svg>'
    };
    return icons[eventType] || icons['edo_created'];
}

function getEventColor(eventType) {
    const colors = {
        'edo_created': 'bg-blue-500',
        'edo_expired': 'bg-red-500',
        'regeneration_requested': 'bg-yellow-500',
        'billing_generated': 'bg-purple-500',
        'payment_submitted': 'bg-indigo-500',
        'payment_confirmed': 'bg-green-500',
        'payment_rejected': 'bg-red-500',
        'admin_unlocked': 'bg-orange-500',
        'edo_released': 'bg-green-500'
    };
    return colors[eventType] || 'bg-gray-500';
}

function formatEventType(eventType) {
    return eventType.split('_').map(word => 
        word.charAt(0).toUpperCase() + word.slice(1)
    ).join(' ');
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}

function exportAuditLogs() {
    if (auditLogs.length === 0) {
        alert('No audit logs to export');
        return;
    }
    
    // Convert to CSV
    const headers = ['Timestamp', 'Event Type', 'eDO Number', 'Container Number', 'User Email', 'User Name', 'Details'];
    const rows = auditLogs.map(log => [
        log.timestamp,
        log.event_type,
        log.edo_number,
        log.container_number,
        log.user.email,
        log.user.full_name,
        JSON.stringify(log.details || {})
    ]);
    
    const csvContent = [
        headers.join(','),
        ...rows.map(row => row.map(cell => `"${cell}"`).join(','))
    ].join('\n');
    
    // Download
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `audit_logs_${currentSearchType}_${currentSearchValue}_${new Date().toISOString()}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function showLoading() {
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('resultsSection').classList.add('hidden');
    document.getElementById('loadingState').classList.remove('hidden');
}

function hideLoading() {
    document.getElementById('loadingState').classList.add('hidden');
}

function showEmptyState() {
    document.getElementById('resultsSection').classList.add('hidden');
    document.getElementById('emptyState').classList.remove('hidden');
}
