/**
 * PWA Install Prompt Handler
 * Manages the PWA installation prompt and detects if app is already installed
 */
class PWAInstallPrompt {
    constructor() {
        this.deferredPrompt = null;
        this.isInstalled = false;
        this.installButton = null;
        
        this.init();
    }
    
    /**
     * Initialize the install prompt handler
     */
    init() {
        console.log('[PWA Install] Initializing...');
        
        // Check if already installed
        this.checkIfInstalled();
        
        // If not installed, show banner after a delay (even without beforeinstallprompt)
        if (!this.isInstalled) {
            console.log('[PWA Install] App not installed, will show banner');
            setTimeout(() => {
                if (!this.deferredPrompt) {
                    console.log('[PWA Install] No beforeinstallprompt event yet, showing banner anyway');
                    this.showInstallPrompt();
                }
            }, 2000); // Wait 2 seconds for beforeinstallprompt
        }
        
        // Listen for beforeinstallprompt event
        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('[PWA Install] beforeinstallprompt event fired');
            
            // Prevent the mini-infobar from appearing on mobile
            e.preventDefault();
            
            // Stash the event so it can be triggered later
            this.deferredPrompt = e;
            
            // Show custom install UI
            this.showInstallPrompt();
        });
        
        // Listen for app installed event
        window.addEventListener('appinstalled', () => {
            console.log('[PWA Install] App installed successfully');
            this.isInstalled = true;
            this.hideInstallPrompt();
            
            // Clear the deferredPrompt
            this.deferredPrompt = null;
        });
        
        // Check if app was installed (for returning users)
        if (window.matchMedia('(display-mode: standalone)').matches || 
            window.navigator.standalone === true) {
            console.log('[PWA Install] App is already installed');
            this.isInstalled = true;
        }
        
        // Debug info
        console.log('[PWA Install] Is installed:', this.isInstalled);
        console.log('[PWA Install] Display mode:', window.matchMedia('(display-mode: standalone)').matches ? 'standalone' : 'browser');
        console.log('[PWA Install] Service Worker support:', 'serviceWorker' in navigator);
    }
    
    /**
     * Check if PWA is already installed
     */
    checkIfInstalled() {
        // Check display mode
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
        
        // Check iOS standalone
        const isIOSStandalone = window.navigator.standalone === true;
        
        // Check if running in PWA mode
        if (isStandalone || isIOSStandalone) {
            this.isInstalled = true;
            console.log('[PWA Install] App is installed (running in standalone mode)');
            return true;
        }
        
        // Check if beforeinstallprompt was already fired and dismissed
        // This happens when user previously dismissed the install prompt
        const dismissedTime = localStorage.getItem('pwa-install-dismissed');
        if (dismissedTime) {
            const daysSinceDismissed = (Date.now() - parseInt(dismissedTime)) / (1000 * 60 * 60 * 24);
            if (daysSinceDismissed < 7) {
                console.log('[PWA Install] Install prompt was recently dismissed');
                return false;
            } else {
                // Clear old dismissal after 7 days
                localStorage.removeItem('pwa-install-dismissed');
            }
        }
        
        return false;
    }
    
    /**
     * Show the install prompt UI
     */
    showInstallPrompt() {
        // Don't show if already installed
        if (this.isInstalled) {
            console.log('[PWA Install] Not showing prompt - app already installed');
            return;
        }
        
        // Create install banner if it doesn't exist
        if (!document.getElementById('pwa-install-banner')) {
            this.createInstallBanner();
        }
        
        // Show the banner
        const banner = document.getElementById('pwa-install-banner');
        if (banner) {
            banner.classList.remove('hidden');
            console.log('[PWA Install] Showing install banner');
        }
    }
    
    /**
     * Hide the install prompt UI
     */
    hideInstallPrompt() {
        const banner = document.getElementById('pwa-install-banner');
        if (banner) {
            banner.classList.add('hidden');
            console.log('[PWA Install] Hiding install banner');
        }
    }
    
    /**
     * Create the install banner UI
     */
    createInstallBanner() {
        const banner = document.createElement('div');
        banner.id = 'pwa-install-banner';
        banner.className = 'fixed bottom-0 left-0 right-0 bg-blue-600 text-white p-4 shadow-lg z-50 transform transition-transform duration-300';
        banner.innerHTML = `
            <div class="container mx-auto flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    <div>
                        <div class="font-semibold">Install Optimus App</div>
                        <div class="text-sm text-blue-100">Get quick access and work offline</div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button id="pwa-install-button" class="bg-white text-blue-600 px-4 py-2 rounded-lg font-medium hover:bg-blue-50 transition-colors">
                        Install
                    </button>
                    <button id="pwa-install-dismiss" class="text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                        Not Now
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(banner);
        
        // Add event listeners
        document.getElementById('pwa-install-button').addEventListener('click', () => {
            this.triggerInstall();
        });
        
        document.getElementById('pwa-install-dismiss').addEventListener('click', () => {
            this.dismissInstall();
        });
    }
    
    /**
     * Trigger the install prompt
     */
    async triggerInstall() {
        if (!this.deferredPrompt) {
            console.log('[PWA Install] No deferred prompt available - showing manual instructions');
            
            // Show manual install instructions
            const instructions = this.getManualInstallInstructions();
            alert(instructions);
            return;
        }
        
        // Hide the banner
        this.hideInstallPrompt();
        
        // Show the install prompt
        this.deferredPrompt.prompt();
        
        // Wait for the user to respond to the prompt
        const { outcome } = await this.deferredPrompt.userChoice;
        console.log('[PWA Install] User choice:', outcome);
        
        if (outcome === 'accepted') {
            console.log('[PWA Install] User accepted the install prompt');
            this.isInstalled = true;
        } else {
            console.log('[PWA Install] User dismissed the install prompt');
            // Store dismissal time
            localStorage.setItem('pwa-install-dismissed', Date.now().toString());
        }
        
        // Clear the deferredPrompt
        this.deferredPrompt = null;
    }
    
    /**
     * Get manual install instructions based on browser
     */
    getManualInstallInstructions() {
        const isChrome = /Chrome/.test(navigator.userAgent) && /Google Inc/.test(navigator.vendor);
        const isEdge = /Edg/.test(navigator.userAgent);
        const isSafari = /Safari/.test(navigator.userAgent) && !/Chrome/.test(navigator.userAgent);
        
        if (isChrome || isEdge) {
            return 'To install:\n\n' +
                   '1. Click the ⊕ icon in the address bar\n' +
                   '2. Or click the three dots menu (⋮)\n' +
                   '3. Select "Install Optimus" or "Install app"\n\n' +
                   'Note: Make sure you\'re using HTTPS or localhost';
        } else if (isSafari) {
            return 'To install on iOS:\n\n' +
                   '1. Tap the Share button (□↑)\n' +
                   '2. Scroll down and tap "Add to Home Screen"\n' +
                   '3. Tap "Add"';
        } else {
            return 'To install:\n\n' +
                   'Look for an install option in your browser\'s menu.\n' +
                   'This feature may not be available in your current browser.';
        }
    }
    
    /**
     * Dismiss the install prompt
     */
    dismissInstall() {
        this.hideInstallPrompt();
        
        // Store dismissal time
        localStorage.setItem('pwa-install-dismissed', Date.now().toString());
        
        console.log('[PWA Install] Install prompt dismissed by user');
    }
    
    /**
     * Manually show install prompt (for button clicks)
     */
    showManualInstall() {
        if (this.isInstalled) {
            alert('App is already installed!');
            return;
        }
        
        if (this.deferredPrompt) {
            this.triggerInstall();
        } else {
            alert('Install prompt is not available. Please use your browser\'s install option.');
        }
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.pwaInstallPrompt = new PWAInstallPrompt();
    });
} else {
    window.pwaInstallPrompt = new PWAInstallPrompt();
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PWAInstallPrompt;
}
