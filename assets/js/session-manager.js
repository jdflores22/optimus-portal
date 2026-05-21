/**
 * Session Manager
 * Handles session timeout differently for desktop and PWA
 * 
 * Desktop: Force logout on session timeout (security)
 * PWA: Infinite session with auto-refresh (convenience)
 */

class SessionManager {
    constructor() {
        this.isPWA = this.detectPWA();
        this.sessionCheckInterval = null;
        this.lastActivityTime = Date.now();
        this.sessionTimeout = 30 * 60 * 1000; // Default 30 minutes in milliseconds
        this.checkInterval = 60 * 1000; // Default check every 1 minute
        this.pwaPingInterval = 5 * 60 * 1000; // Default ping every 5 minutes
        
        this.loadConfigAndInit();
    }
    
    /**
     * Load configuration from API and initialize
     */
    async loadConfigAndInit() {
        try {
            const response = await fetch('/api/session/config', {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            if (response.ok) {
                const config = await response.json();
                this.sessionTimeout = config.desktop_timeout_minutes * 60 * 1000;
                this.checkInterval = config.check_interval_seconds * 1000;
                this.pwaPingInterval = config.pwa_ping_interval_minutes * 60 * 1000;
                console.log('Session config loaded:', config);
            }
        } catch (error) {
            console.warn('Failed to load session config, using defaults:', error);
        }
        
        this.init();
    }
    
    /**
     * Detect if app is running as PWA
     */
    detectPWA() {
        // Check if running in standalone mode (installed PWA)
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
        const isIOSStandalone = window.navigator.standalone === true;
        const isAndroidPWA = document.referrer.includes('android-app://');
        
        return isStandalone || isIOSStandalone || isAndroidPWA;
    }
    
    /**
     * Initialize session manager
     */
    init() {
        if (!this.isAuthenticated()) {
            return; // Not logged in, nothing to manage
        }
        
        console.log(`Session Manager initialized - Mode: ${this.isPWA ? 'PWA (Infinite)' : 'Desktop (Timeout)'}`);
        
        // Listen for logout events from other tabs
        window.addEventListener('storage', (e) => {
            if (e.key === 'session_expired' && e.newValue === 'true') {
                console.log('Session expired in another tab - logging out');
                this.forceLogout();
            }
        });
        
        if (this.isPWA) {
            this.initPWAMode();
        } else {
            this.initDesktopMode();
        }
    }
    
    /**
     * Check if user is authenticated
     */
    isAuthenticated() {
        const body = document.body;
        return body && body.hasAttribute('data-authenticated') && body.getAttribute('data-authenticated') === 'true';
    }
    
    /**
     * Initialize PWA mode (infinite session with auto-refresh)
     */
    initPWAMode() {
        // Keep session alive by pinging server periodically
        this.sessionCheckInterval = setInterval(() => {
            this.keepSessionAlive();
        }, this.pwaPingInterval);
        
        // Refresh session on visibility change (when user returns to app)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.keepSessionAlive();
            }
        });
        
        // Keep session alive on any user activity
        this.trackActivity();
    }
    
    /**
     * Initialize Desktop mode (session timeout with forced logout)
     */
    initDesktopMode() {
        // Track user activity
        this.trackActivity();
        
        // Check session status periodically
        this.sessionCheckInterval = setInterval(() => {
            this.checkSessionTimeout();
        }, this.checkInterval);
        
        // Check session on visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.checkSessionTimeout();
            }
        });
    }
    
    /**
     * Track user activity
     */
    trackActivity() {
        const events = ['mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
        let lastUpdateTime = Date.now();
        
        const updateServerActivity = async () => {
            // Only update server every 30 seconds to avoid too many requests
            const now = Date.now();
            if (now - lastUpdateTime < 30000) {
                return;
            }
            
            lastUpdateTime = now;
            
            try {
                await fetch('/api/session/activity', {
                    method: 'POST',
                    credentials: 'same-origin'
                });
            } catch (error) {
                console.error('Failed to update activity:', error);
            }
        };
        
        events.forEach(event => {
            document.addEventListener(event, () => {
                this.lastActivityTime = Date.now();
                updateServerActivity();
            }, { passive: true });
        });
    }
    
    /**
     * Keep session alive (PWA mode)
     */
    async keepSessionAlive() {
        try {
            const response = await fetch('/api/session/ping', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                console.warn('Session ping failed:', response.status);
            }
        } catch (error) {
            console.error('Failed to keep session alive:', error);
        }
    }
    
    /**
     * Check if session has timed out (Desktop mode)
     */
    async checkSessionTimeout() {
        const inactiveTime = Date.now() - this.lastActivityTime;
        
        // If inactive for longer than session timeout
        if (inactiveTime > this.sessionTimeout) {
            console.log('Session timeout detected - forcing logout');
            await this.forceLogout();
            return;
        }
        
        // Check server-side session status
        try {
            const response = await fetch('/api/session/status', {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            // If we get HTML instead of JSON, session has expired (redirected to login)
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('text/html')) {
                console.log('Session expired - received HTML instead of JSON');
                await this.forceLogout();
                return;
            }
            
            const data = await response.json();
            
            if (response.status === 401 || data.status === 'expired') {
                // Session expired on server
                console.log('Server session expired - forcing logout');
                await this.forceLogout();
            } else {
                console.log('Session check passed - inactive time:', Math.floor(inactiveTime / 1000), 'seconds');
            }
        } catch (error) {
            console.error('Failed to check session status:', error);
            // If JSON parse error, likely got HTML (login page)
            if (error instanceof SyntaxError) {
                console.log('Session expired - JSON parse error (got HTML)');
                await this.forceLogout();
            }
        }
    }
    
    /**
     * Force logout and redirect to login page
     */
    async forceLogout() {
        // Clear interval
        if (this.sessionCheckInterval) {
            clearInterval(this.sessionCheckInterval);
        }
        
        // Notify other tabs
        localStorage.setItem('session_expired', 'true');
        setTimeout(() => localStorage.removeItem('session_expired'), 1000);
        
        // Show logout message
        this.showLogoutModal();
        
        // Wait 3 seconds then redirect
        setTimeout(() => {
            window.location.href = '/logout?reason=session_timeout';
        }, 3000);
    }
    
    /**
     * Show logout modal
     */
    showLogoutModal() {
        // Create modal
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-75 flex items-center justify-center z-[10000]';
        modal.innerHTML = `
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <div class="flex items-center justify-center w-12 h-12 mx-auto bg-yellow-100 rounded-full mb-4">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 text-center mb-2">Session Expired</h3>
                <p class="text-sm text-gray-600 text-center mb-4">
                    Your session has expired due to inactivity. You will be redirected to the login page.
                </p>
                <div class="flex justify-center">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }
    
    /**
     * Cleanup
     */
    destroy() {
        if (this.sessionCheckInterval) {
            clearInterval(this.sessionCheckInterval);
        }
    }
}

// Initialize session manager when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.sessionManager = new SessionManager();
    });
} else {
    window.sessionManager = new SessionManager();
}
