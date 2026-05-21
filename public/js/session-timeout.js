/**
 * Session Timeout Warning System
 * Displays warnings to users before their session expires
 */
class SessionTimeoutWarning {
    constructor() {
        this.sessionTimeout = 30 * 60 * 1000; // 30 minutes in milliseconds
        this.warningTime = 5 * 60 * 1000; // Show warning 5 minutes before expiration
        this.lastActivity = Date.now();
        this.warningShown = false;
        this.timeoutId = null;
        this.warningTimeoutId = null;
        this.activityHandler = null;
        
        this.init();
    }
    
    init() {
        // Track user activity
        this.trackActivity();
        
        // Start the timeout timer
        this.resetTimer();
    }
    
    trackActivity() {
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        
        // Store the handler so we can remove it later
        this.activityHandler = () => {
            this.lastActivity = Date.now();
            this.resetTimer();
        };
        
        events.forEach(event => {
            document.addEventListener(event, this.activityHandler, true);
        });
    }
    
    resetTimer() {
        // Clear existing timers
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }
        if (this.warningTimeoutId) {
            clearTimeout(this.warningTimeoutId);
        }
        
        // Hide warning if shown
        this.hideWarning();
        this.warningShown = false;
        
        // Set warning timer (show warning 5 minutes before expiration)
        this.warningTimeoutId = setTimeout(() => {
            this.showWarning();
        }, this.sessionTimeout - this.warningTime);
        
        // Set logout timer (logout after full timeout)
        this.timeoutId = setTimeout(() => {
            this.logout();
        }, this.sessionTimeout);
    }
    
    showWarning() {
        if (this.warningShown) {
            return;
        }
        
        this.warningShown = true;
        
        // Create warning modal
        const modal = document.createElement('div');
        modal.id = 'session-timeout-warning';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        modal.innerHTML = `
            <div class="bg-white rounded-lg p-6 max-w-md mx-4 shadow-xl">
                <div class="flex items-center mb-4">
                    <div class="flex-shrink-0">
                        <svg class="h-6 w-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-lg font-medium text-gray-900">Session Timeout Warning</h3>
                    </div>
                </div>
                <div class="mb-4">
                    <p class="text-sm text-gray-600">
                        Your session will expire in <span id="countdown" class="font-semibold text-red-600">5:00</span> due to inactivity.
                        Click "Stay Logged In" to continue your session.
                    </p>
                </div>
                <div class="flex justify-end space-x-3">
                    <button id="logout-btn" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Logout Now
                    </button>
                    <button id="stay-logged-btn" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Stay Logged In
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Add event listeners
        document.getElementById('logout-btn').addEventListener('click', () => {
            this.logout();
        });
        
        document.getElementById('stay-logged-btn').addEventListener('click', () => {
            this.extendSession();
        });
        
        // Start countdown
        this.startCountdown();
    }
    
    startCountdown() {
        let timeLeft = this.warningTime / 1000; // 5 minutes in seconds
        const countdownElement = document.getElementById('countdown');
        
        const updateCountdown = () => {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            countdownElement.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeLeft <= 0) {
                this.logout();
                return;
            }
            
            timeLeft--;
            setTimeout(updateCountdown, 1000);
        };
        
        updateCountdown();
    }
    
    hideWarning() {
        const modal = document.getElementById('session-timeout-warning');
        if (modal) {
            modal.remove();
        }
    }
    
    extendSession() {
        // Make a request to extend the session
        fetch('/api/extend-session', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(response => {
            if (response.ok) {
                this.resetTimer();
            } else {
                // If session extension fails, logout
                this.logout();
            }
        }).catch(() => {
            // If request fails, logout
            this.logout();
        });
    }
    
    logout() {
        // Redirect to logout
        window.location.href = '/logout';
    }
    
    /**
     * Destroy the session timeout warning system
     * Called when PWA mode is detected to disable timeout logic
     */
    destroy() {
        // Clear all timers
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
        if (this.warningTimeoutId) {
            clearTimeout(this.warningTimeoutId);
            this.warningTimeoutId = null;
        }
        
        // Hide warning if shown
        this.hideWarning();
        
        // Remove event listeners
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        events.forEach(event => {
            document.removeEventListener(event, this.activityHandler, true);
        });
        
        console.log('[SessionTimeoutWarning] Destroyed');
    }
}

// Initialize session timeout warning when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    const instance = new SessionTimeoutWarning();
    // Make instance globally available for PWA detection to destroy it
    window.sessionTimeoutWarning = instance;
});