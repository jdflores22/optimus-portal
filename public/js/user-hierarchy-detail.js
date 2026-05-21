/**
 * User Hierarchy Detail Page
 * Handles user management actions with FlyonUI modals
 */

console.log('User hierarchy detail JS loaded');

const userId = window.location.pathname.split('/').pop();
console.log('User ID:', userId);

// Make functions globally accessible
window.deactivateUser = deactivateUser;
window.activateUser = activateUser;
window.unlockAccount = unlockAccount;
window.removeFromHierarchy = removeFromHierarchy;
window.changeAdmin = changeAdmin;
window.submitAdminChange = submitAdminChange;
window.transferUsers = transferUsers;
window.submitTransfer = submitTransfer;
window.viewActivityLogs = viewActivityLogs;
window.closeModal = closeModal;

// Deactivate User
function deactivateUser() {
    console.log('deactivateUser called');
    showConfirmModal(
        'Deactivate User',
        'Are you sure you want to deactivate this user? They will no longer be able to access the system.',
        'error',
        () => {
            fetch(`/admin/user-hierarchy/${userId}/deactivate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('User deactivated successfully', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message || 'Failed to deactivate user', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    );
}

// Activate User
function activateUser() {
    showConfirmModal(
        'Activate User',
        'Are you sure you want to activate this user? They will be able to access the system.',
        'success',
        () => {
            fetch(`/admin/user-hierarchy/${userId}/activate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('User activated successfully', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message || 'Failed to activate user', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    );
}

// Unlock Account
function unlockAccount() {
    showConfirmModal(
        'Unlock Account',
        'This will reset failed login attempts and remove any temporary locks. Continue?',
        'warning',
        () => {
            fetch(`/admin/user-hierarchy/${userId}/unlock`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Account unlocked successfully', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message || 'Failed to unlock account', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    );
}

// Remove from Hierarchy
function removeFromHierarchy() {
    console.log('removeFromHierarchy called');
    showConfirmModal(
        'Remove from Hierarchy',
        'This will permanently remove this user from the hierarchy. This action cannot be undone.',
        'error',
        () => {
            fetch(`/admin/user-hierarchy/${userId}/remove`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('User removed from hierarchy', 'success');
                    setTimeout(() => window.location.href = '/admin/user-hierarchy', 1500);
                } else {
                    showToast(data.message || 'Failed to remove user', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    );
}

// Change Admin
function changeAdmin() {
    console.log('changeAdmin called');
    
    // Remove existing modal if any
    const existingModal = document.getElementById('changeAdminModal');
    if (existingModal) {
        console.log('Removing existing changeAdmin modal');
        existingModal.remove();
    }
    
    // Create modal for selecting new admin
    const modalHtml = `
        <dialog id="changeAdminModal" class="modal modal-bottom sm:modal-middle">
            <div class="modal-box">
                <h3 class="text-lg font-bold mb-4">Change Shipping Line Admin</h3>
                <div class="space-y-4">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Select New Admin</span>
                        </label>
                        <select id="newAdminSelect" class="select select-bordered w-full">
                            <option value="">Loading admins...</option>
                        </select>
                    </div>
                </div>
                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('changeAdminModal')">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitAdminChange()">Change Admin</button>
                </div>
            </div>
            <form method="dialog" class="modal-backdrop">
                <button>close</button>
            </form>
        </dialog>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = document.getElementById('changeAdminModal');
    console.log('changeAdmin modal created:', modal);
    
    if (typeof modal.showModal === 'function') {
        console.log('Showing changeAdmin modal');
        modal.showModal();
    } else {
        console.log('Fallback: setting open attribute');
        modal.setAttribute('open', 'open');
    }
    
    // Load available admins
    fetch('/admin/user-hierarchy/admins')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('newAdminSelect');
            select.innerHTML = '<option value="">Select an admin...</option>';
            data.admins.forEach(admin => {
                select.innerHTML += `<option value="${admin.id}">${admin.firstName} ${admin.lastName} (${admin.email})</option>`;
            });
        })
        .catch(error => {
            console.error('Error loading admins:', error);
            showToast('Failed to load admins', 'error');
        });
}

function submitAdminChange() {
    const newAdminId = document.getElementById('newAdminSelect').value;
    if (!newAdminId) {
        showToast('Please select an admin', 'warning');
        return;
    }
    
    fetch(`/admin/user-hierarchy/${userId}/change-admin`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ newAdminId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Admin changed successfully', 'success');
            closeModal('changeAdminModal');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message || 'Failed to change admin', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred', 'error');
    });
}

// Transfer Users
function transferUsers() {
    console.log('transferUsers called');
    
    // Remove existing modal if any
    const existingModal = document.getElementById('transferUsersModal');
    if (existingModal) {
        console.log('Removing existing transferUsers modal');
        existingModal.remove();
    }
    
    const modalHtml = `
        <dialog id="transferUsersModal" class="modal modal-bottom sm:modal-middle">
            <div class="modal-box max-w-2xl">
                <h3 class="text-lg font-bold mb-4">Transfer Subordinates</h3>
                <div class="space-y-4">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Transfer to Admin</span>
                        </label>
                        <select id="transferAdminSelect" class="select select-bordered w-full">
                            <option value="">Loading admins...</option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Select Users to Transfer</span>
                        </label>
                        <div id="userCheckboxes" class="space-y-2 max-h-64 overflow-y-auto border border-base-300 rounded-lg p-3">
                            <p class="text-sm text-base-content/50">Loading users...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('transferUsersModal')">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitTransfer()">Transfer Users</button>
                </div>
            </div>
            <form method="dialog" class="modal-backdrop">
                <button>close</button>
            </form>
        </dialog>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = document.getElementById('transferUsersModal');
    console.log('transferUsers modal created:', modal);
    
    if (typeof modal.showModal === 'function') {
        console.log('Showing transferUsers modal');
        modal.showModal();
    } else {
        console.log('Fallback: setting open attribute');
        modal.setAttribute('open', 'open');
    }
    
    // Load available admins and subordinates
    Promise.all([
        fetch('/admin/user-hierarchy/admins').then(r => r.json()),
        fetch(`/admin/user-hierarchy/${userId}/subordinates`).then(r => r.json())
    ])
    .then(([adminsData, subordinatesData]) => {
        // Populate admin select
        const adminSelect = document.getElementById('transferAdminSelect');
        adminSelect.innerHTML = '<option value="">Select an admin...</option>';
        adminsData.admins.filter(a => a.id != userId).forEach(admin => {
            adminSelect.innerHTML += `<option value="${admin.id}">${admin.firstName} ${admin.lastName} (${admin.email})</option>`;
        });
        
        // Populate user checkboxes
        const checkboxContainer = document.getElementById('userCheckboxes');
        if (subordinatesData.subordinates.length === 0) {
            checkboxContainer.innerHTML = '<p class="text-sm text-base-content/50">No subordinates to transfer</p>';
        } else {
            checkboxContainer.innerHTML = subordinatesData.subordinates.map(user => `
                <label class="flex items-center gap-3 p-2 rounded-lg hover:bg-base-200 cursor-pointer">
                    <input type="checkbox" class="checkbox checkbox-sm" value="${user.id}">
                    <div class="flex-1">
                        <p class="text-sm font-medium">${user.firstName} ${user.lastName}</p>
                        <p class="text-xs text-base-content/50">${user.email} · ${user.role}</p>
                    </div>
                </label>
            `).join('');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to load data', 'error');
    });
}

function submitTransfer() {
    const newAdminId = document.getElementById('transferAdminSelect').value;
    const checkboxes = document.querySelectorAll('#userCheckboxes input[type="checkbox"]:checked');
    const userIds = Array.from(checkboxes).map(cb => cb.value);
    
    if (!newAdminId) {
        showToast('Please select an admin', 'warning');
        return;
    }
    
    if (userIds.length === 0) {
        showToast('Please select at least one user', 'warning');
        return;
    }
    
    fetch(`/admin/user-hierarchy/${userId}/transfer`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ newAdminId, userIds })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(`${userIds.length} user(s) transferred successfully`, 'success');
            closeModal('transferUsersModal');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message || 'Failed to transfer users', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred', 'error');
    });
}

// View Activity Logs
function viewActivityLogs() {
    window.location.href = `/admin/audit-trail?user_id=${userId}`;
}

// Helper: Show confirmation modal
function showConfirmModal(title, message, type, onConfirm) {
    console.log('showConfirmModal called:', title);
    
    const typeColors = {
        success: 'btn-success',
        error: 'btn-error',
        warning: 'btn-warning',
        info: 'btn-info'
    };
    
    const typeIcons = {
        success: 'icon-[tabler--circle-check]',
        error: 'icon-[tabler--alert-circle]',
        warning: 'icon-[tabler--alert-triangle]',
        info: 'icon-[tabler--info-circle]'
    };
    
    // Remove existing modal if any
    const existingModal = document.getElementById('confirmModal');
    if (existingModal) {
        console.log('Removing existing modal');
        existingModal.remove();
    }
    
    const modalHtml = `
        <dialog id="confirmModal" class="modal modal-bottom sm:modal-middle">
            <div class="modal-box">
                <div class="flex items-start gap-4">
                    <span class="${typeIcons[type] || typeIcons.info} size-6 text-${type} flex-shrink-0 mt-1"></span>
                    <div class="flex-1">
                        <h3 class="text-lg font-bold mb-2">${title}</h3>
                        <p class="text-sm text-base-content/70">${message}</p>
                    </div>
                </div>
                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" onclick="window.closeConfirmModal()">Cancel</button>
                    <button type="button" class="btn ${typeColors[type] || 'btn-primary'}" onclick="window.confirmModalAction()">Confirm</button>
                </div>
            </div>
            <form method="dialog" class="modal-backdrop">
                <button>close</button>
            </form>
        </dialog>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = document.getElementById('confirmModal');
    console.log('Modal element created:', modal);
    
    // Store callback globally
    window.confirmCallback = onConfirm;
    
    // Show modal using native dialog API
    if (typeof modal.showModal === 'function') {
        console.log('Using showModal()');
        modal.showModal();
    } else {
        console.log('Fallback: setting open attribute');
        modal.setAttribute('open', 'open');
    }
    
    console.log('Modal should be visible now');
}

// Global functions for modal actions
window.confirmModalAction = function() {
    if (window.confirmCallback) {
        window.confirmCallback();
        window.confirmCallback = null;
    }
    window.closeConfirmModal();
};

window.closeConfirmModal = function() {
    const modal = document.getElementById('confirmModal');
    if (modal) {
        if (modal.close) {
            modal.close();
        } else {
            modal.removeAttribute('open');
        }
        setTimeout(() => modal.remove(), 300);
    }
};

// Helper: Close modal
function closeModal(modalId) {
    console.log('closeModal called for:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        if (typeof modal.close === 'function') {
            console.log('Closing modal with close()');
            modal.close();
        } else {
            console.log('Removing open attribute');
            modal.removeAttribute('open');
        }
        setTimeout(() => {
            console.log('Removing modal from DOM');
            modal.remove();
        }, 300);
    } else {
        console.log('Modal not found:', modalId);
    }
}

// Helper: Show toast notification
function showToast(message, type = 'info') {
    const typeClasses = {
        success: 'alert-success',
        error: 'alert-error',
        warning: 'alert-warning',
        info: 'alert-info'
    };
    
    const typeIcons = {
        success: 'icon-[tabler--circle-check]',
        error: 'icon-[tabler--alert-circle]',
        warning: 'icon-[tabler--alert-triangle]',
        info: 'icon-[tabler--info-circle]'
    };
    
    const toast = document.createElement('div');
    toast.className = `alert ${typeClasses[type]} fixed top-4 right-4 w-auto max-w-md shadow-lg z-50 animate-in slide-in-from-top`;
    toast.innerHTML = `
        <span class="${typeIcons[type]} size-5"></span>
        <span>${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('animate-out', 'slide-out-to-top');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
