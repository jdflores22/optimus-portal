/**
 * Session Manager
 * Handles session timeout differently for desktop and PWA.
 *
 * Desktop: server is the source of truth; activity in any tab keeps the shared session alive.
 * PWA: infinite session with periodic ping.
 */

class SessionManager {
    static LAST_ACTIVITY_KEY = 'optimus_last_activity';
    static SESSION_EXPIRED_KEY = 'session_expired';

    constructor() {
        this.isPWA = this.detectPWA();
        this.sessionCheckInterval = null;
        this.lastActivityTime = this.readSharedActivityTime();
        this.sessionTimeout = 30 * 60 * 1000;
        this.checkInterval = 60 * 1000;
        this.pwaPingInterval = 5 * 60 * 1000;
        this.isLoggingOut = false;

        this.loadConfigAndInit();
    }

    readSharedActivityTime() {
        const stored = localStorage.getItem(SessionManager.LAST_ACTIVITY_KEY);
        const parsed = stored ? parseInt(stored, 10) : NaN;

        return Number.isFinite(parsed) ? parsed : Date.now();
    }

    recordActivity() {
        const now = Date.now();
        this.lastActivityTime = now;
        localStorage.setItem(SessionManager.LAST_ACTIVITY_KEY, String(now));
    }

    syncActivityFromServer(lastActivitySeconds) {
        if (!lastActivitySeconds) {
            return;
        }

        const serverActivityMs = lastActivitySeconds * 1000;
        if (serverActivityMs > this.lastActivityTime) {
            this.lastActivityTime = serverActivityMs;
            localStorage.setItem(SessionManager.LAST_ACTIVITY_KEY, String(serverActivityMs));
        }
    }

    async loadConfigAndInit() {
        try {
            const response = await fetch('/api/session/config', {
                method: 'GET',
                credentials: 'same-origin',
            });

            if (response.ok) {
                const config = await response.json();
                this.sessionTimeout = config.desktop_timeout_minutes * 60 * 1000;
                this.checkInterval = config.check_interval_seconds * 1000;
                this.pwaPingInterval = config.pwa_ping_interval_minutes * 60 * 1000;
            }
        } catch (error) {
            console.warn('Failed to load session config, using defaults:', error);
        }

        this.init();
    }

    detectPWA() {
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
        const isIOSStandalone = window.navigator.standalone === true;
        const isAndroidPWA = document.referrer.includes('android-app://');

        return isStandalone || isIOSStandalone || isAndroidPWA;
    }

    init() {
        if (!this.isAuthenticated()) {
            return;
        }

        window.addEventListener('storage', (event) => {
            if (event.key === SessionManager.LAST_ACTIVITY_KEY && event.newValue) {
                const activityTime = parseInt(event.newValue, 10);
                if (Number.isFinite(activityTime) && activityTime > this.lastActivityTime) {
                    this.lastActivityTime = activityTime;
                }
                return;
            }

            if (event.key === SessionManager.SESSION_EXPIRED_KEY && event.newValue === 'true') {
                this.handleServerConfirmedExpiry(false);
            }
        });

        if (this.isPWA) {
            this.initPWAMode();
        } else {
            this.initDesktopMode();
        }
    }

    isAuthenticated() {
        const body = document.body;

        return body && body.hasAttribute('data-authenticated') && body.getAttribute('data-authenticated') === 'true';
    }

    initPWAMode() {
        this.sessionCheckInterval = setInterval(() => {
            this.keepSessionAlive();
        }, this.pwaPingInterval);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.keepSessionAlive();
            }
        });

        this.trackActivity();
    }

    initDesktopMode() {
        this.trackActivity();

        this.sessionCheckInterval = setInterval(() => {
            this.checkSessionTimeout();
        }, this.checkInterval);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.recordActivity();
                this.checkSessionTimeout();
            }
        });
    }

    trackActivity() {
        const events = ['mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
        let lastUpdateTime = 0;

        const updateServerActivity = async () => {
            const now = Date.now();
            if (now - lastUpdateTime < 30000) {
                return;
            }

            lastUpdateTime = now;

            try {
                await fetch('/api/session/activity', {
                    method: 'POST',
                    credentials: 'same-origin',
                });
            } catch (error) {
                console.error('Failed to update activity:', error);
            }
        };

        events.forEach((event) => {
            document.addEventListener(event, () => {
                this.recordActivity();
                updateServerActivity();
            }, { passive: true });
        });
    }

    async keepSessionAlive() {
        try {
            const response = await fetch('/api/session/ping', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                console.warn('Session ping failed:', response.status);
            }
        } catch (error) {
            console.error('Failed to keep session alive:', error);
        }
    }

    async checkSessionTimeout() {
        if (this.isLoggingOut || document.hidden) {
            return;
        }

        try {
            const response = await fetch('/api/session/status', {
                method: 'GET',
                credentials: 'same-origin',
            });

            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('text/html')) {
                this.handleServerConfirmedExpiry(true);
                return;
            }

            const data = await response.json();

            if (response.status === 401 || data.status === 'expired') {
                this.handleServerConfirmedExpiry(true);
                return;
            }

            this.syncActivityFromServer(data.last_activity);
        } catch (error) {
            if (error instanceof SyntaxError) {
                this.handleServerConfirmedExpiry(true);
                return;
            }

            console.error('Failed to check session status:', error);
        }
    }

    handleServerConfirmedExpiry(shouldBroadcast) {
        if (this.isLoggingOut) {
            return;
        }

        this.isLoggingOut = true;

        if (this.sessionCheckInterval) {
            clearInterval(this.sessionCheckInterval);
        }

        if (shouldBroadcast) {
            localStorage.setItem(SessionManager.SESSION_EXPIRED_KEY, 'true');
            setTimeout(() => localStorage.removeItem(SessionManager.SESSION_EXPIRED_KEY), 1000);
        }

        this.showLogoutModal();

        setTimeout(() => {
            window.location.href = '/logout?reason=session_timeout';
        }, 3000);
    }

    showLogoutModal() {
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

    destroy() {
        if (this.sessionCheckInterval) {
            clearInterval(this.sessionCheckInterval);
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.sessionManager = new SessionManager();
    });
} else {
    window.sessionManager = new SessionManager();
}
