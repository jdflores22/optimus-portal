/**
 * PWA Mode Detector
 * Detects whether the application is running as an installed PWA or in a regular browser
 * 
 * Requirements: 4.3, 4.13, 5.1
 */
class PWADetector {
    /**
     * Check if the application is running as an installed PWA
     * @returns {boolean} True if running as PWA, false otherwise
     */
    isPWA() {
        // Check display mode using media query
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
        
        // Check iOS standalone mode
        const isIOSStandalone = this.isIOSPWA();
        
        return isStandalone || isIOSStandalone;
    }
    
    /**
     * Get the current display mode
     * @returns {string} 'standalone' if PWA, 'browser' otherwise
     */
    getDisplayMode() {
        return this.isPWA() ? 'standalone' : 'browser';
    }
    
    /**
     * Check if running as PWA on iOS
     * @returns {boolean} True if iOS PWA, false otherwise
     */
    isIOSPWA() {
        // Check if navigator.standalone exists (iOS-specific)
        if ('standalone' in window.navigator) {
            return window.navigator.standalone === true;
        }
        return false;
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PWADetector;
}
