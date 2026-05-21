/**
 * Account Status Monitor
 * Periodically checks user account status and shows suspension modal immediately
 */
class AccountStatusMonitor {
    constructor() {
        this.checkInterval = 10000; // Check every 10 seconds
        this.intervalId = null;
        this.lastKnownStatus = null;
        this.isChecking = false;
    }

    /**
     * Start monitoring account status
     */
    start() {
        console.log('[Account Monitor] Starting account status monitoring...');
        
        // Initial check
        this.checkStatus();
        
        // Set up periodic checks
        this.intervalId = setInterval(() => {
            this.checkStatus();
        }, this.checkInterval);
    }

    /**
     * Stop monitoring
     */
    stop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
            console.log('[Account Monitor] Stopped account status monitoring');
        }
    }

    /**
     * Check account status via API
     */
    async checkStatus() {
        // Prevent concurrent checks
        if (this.isChecking) {
            return;
        }

        this.isChecking = true;

        try {
            const response = await fetch('/api/account/status', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });

            // Check if we got HTML instead of JSON (session expired)
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('text/html')) {
                console.log('[Account Monitor] Session expired - received HTML');
                this.stop();
                this.isChecking = false;
                return;
            }

            // If 401, user is not authenticated - stop monitoring but don't redirect
            // (they might be on login page or session expired naturally)
            if (response.status === 401) {
                console.log('[Account Monitor] User is not authenticated, stopping monitor');
                this.stop();
                this.isChecking = false;
                return;
            }

            if (!response.ok) {
                console.error('[Account Monitor] Status check failed:', response.status);
                this.isChecking = false;
                return;
            }

            const data = await response.json();

            // Check if user is no longer authenticated
            if (!data.authenticated) {
                console.log('[Account Monitor] User is no longer authenticated');
                this.stop();
                this.isChecking = false;
                return;
            }

            // Check if status changed to DENIED (suspended)
            if (this.lastKnownStatus && this.lastKnownStatus !== 'DENIED' && data.status === 'DENIED') {
                console.log('[Account Monitor] Account has been suspended!');
                this.handleSuspension(data);
            }

            // Check if status changed from DENIED to APPROVED (reactivated)
            if (this.lastKnownStatus === 'DENIED' && data.status === 'APPROVED') {
                console.log('[Account Monitor] Account has been reactivated!');
                this.handleReactivation(data);
            }

            // Update last known status
            this.lastKnownStatus = data.status;

        } catch (error) {
            console.error('[Account Monitor] Error checking account status:', error);
        } finally {
            this.isChecking = false;
        }
    }

    /**
     * Handle account suspension
     */
    handleSuspension(data) {
        // For brokers, show the suspension modal
        if (data.role === 'BROKER') {
            // Force reload to show the suspension modal
            console.log('[Account Monitor] Reloading page to show suspension modal...');
            window.location.reload();
        } else {
            // For other roles, redirect to login with message
            alert('Your account has been suspended. Please contact the administrator.');
            window.location.href = '/logout';
        }
    }

    /**
     * Handle account reactivation
     */
    handleReactivation(data) {
        // Show success message and reload
        console.log('[Account Monitor] Account reactivated, reloading page...');
        
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = 'fixed top-20 right-4 z-[10000] rounded-lg p-4 shadow-lg bg-green-50 border border-green-200';
        toast.innerHTML = `
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-sm font-medium text-green-800">Your account has been reactivated!</span>
            </div>
        `;
        document.body.appendChild(toast);
        
        // Reload after 2 seconds
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    }
}

// Initialize and start monitoring when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Only start monitoring for authenticated users
    const isAuthenticated = document.body.dataset.authenticated === 'true';
    
    if (!isAuthenticated) {
        console.log('[Account Monitor] User not authenticated, skipping monitor initialization');
        return;
    }
    
    console.log('[Account Monitor] User is authenticated, initializing monitor...');
    
    const monitor = new AccountStatusMonitor();
    monitor.start();
    
    // Make it globally available for debugging
    window.accountStatusMonitor = monitor;
    
    console.log('[Account Monitor] Initialized and started');
});
