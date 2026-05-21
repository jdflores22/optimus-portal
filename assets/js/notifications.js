/**
 * Real-time Notification System
 * Polls for new notifications and updates the UI
 */

class NotificationManager {
    constructor() {
        this.pollingInterval = 30000; // Poll every 30 seconds
        this.lastNotificationId = 0;
        this.unreadCount = 0;
        this.isPolling = false;
        this.pollTimer = null;
    }

    /**
     * Initialize the notification manager
     */
    init() {
        // Load initial notifications
        this.loadUnreadCount();
        
        // Start polling for new notifications
        this.startPolling();
        
        // Stop polling when page is hidden to save resources
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopPolling();
            } else {
                this.startPolling();
            }
        });
    }

    /**
     * Start polling for new notifications
     */
    startPolling() {
        if (this.isPolling) return;
        
        this.isPolling = true;
        this.poll();
        
        this.pollTimer = setInterval(() => {
            this.poll();
        }, this.pollingInterval);
    }

    /**
     * Stop polling
     */
    stopPolling() {
        this.isPolling = false;
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    /**
     * Poll for new notifications
     */
    async poll() {
        try {
            const response = await fetch('/notifications/unread-count', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                console.error('Failed to fetch unread count');
                return;
            }

            const data = await response.json();
            
            if (data.success) {
                const newCount = data.count;
                
                // If count increased, show notification toast
                if (newCount > this.unreadCount) {
                    this.showNewNotificationToast();
                    
                    // Reload notifications if dropdown is open
                    if (window.notificationDropdownOpen) {
                        window.loadNotifications();
                    }
                }
                
                this.unreadCount = newCount;
                this.updateBadge(newCount);
            }
        } catch (error) {
            console.error('Error polling notifications:', error);
        }
    }

    /**
     * Load initial unread count
     */
    async loadUnreadCount() {
        try {
            const response = await fetch('/notifications/unread-count', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) return;

            const data = await response.json();
            
            if (data.success) {
                this.unreadCount = data.count;
                this.updateBadge(data.count);
            }
        } catch (error) {
            console.error('Error loading unread count:', error);
        }
    }

    /**
     * Update notification badge
     */
    updateBadge(count) {
        const badge = document.getElementById('notificationBadge');
        const sidebarBadge = document.getElementById('sidebarNotificationBadge');
        
        if (badge) {
            if (count > 0) {
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
        
        if (sidebarBadge) {
            if (count > 0) {
                sidebarBadge.classList.remove('hidden');
                sidebarBadge.textContent = count > 99 ? '99+' : count;
            } else {
                sidebarBadge.classList.add('hidden');
            }
        }
    }

    /**
     * Show toast notification for new notifications
     */
    showNewNotificationToast() {
        // Check if we're on the notifications page
        if (window.location.pathname.includes('/notifications')) {
            // If on notifications page, reload the list
            if (typeof window.loadPageNotifications === 'function') {
                window.loadPageNotifications();
            }
            return;
        }

        // Create toast notification
        const toast = document.createElement('div');
        toast.className = 'fixed top-20 right-4 z-[9999] bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg shadow-lg flex items-center space-x-3 transform transition-all duration-300 ease-in-out translate-x-full opacity-0';
        toast.innerHTML = `
            <div class="flex-shrink-0">
                <svg class="w-5 h-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/>
                </svg>
            </div>
            <div class="flex-1">
                <p class="text-sm font-medium">New notification received</p>
            </div>
            <button onclick="this.parentElement.remove()" class="flex-shrink-0 text-blue-400 hover:text-blue-600">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
        `;
        
        document.body.appendChild(toast);
        
        // Animate in
        setTimeout(() => {
            toast.classList.remove('translate-x-full', 'opacity-0');
        }, 100);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            toast.classList.add('translate-x-full', 'opacity-0');
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.parentElement.removeChild(toast);
                }
            }, 300);
        }, 5000);
    }
}

// Initialize notification manager when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.notificationManager = new NotificationManager();
        window.notificationManager.init();
    });
} else {
    window.notificationManager = new NotificationManager();
    window.notificationManager.init();
}
