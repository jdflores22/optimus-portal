/**
 * Permission Handler
 * Manages notification permission requests and push subscription registration
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6
 */
class PermissionHandler {
    constructor(vapidPublicKey) {
        this.vapidPublicKey = vapidPublicKey;
        this.maxRetries = 3;
        this.retryDelay = 2000; // Start with 2 seconds
    }
    
    /**
     * Request notification permission from the browser
     * Requirements: 2.1
     * @returns {Promise<string>} Permission status: 'granted', 'denied', or 'default'
     */
    async requestPermission() {
        try {
            if (!('Notification' in window)) {
                console.warn('[PermissionHandler] Notifications not supported in this browser');
                return 'denied';
            }
            
            // Check if already granted
            if (Notification.permission === 'granted') {
                console.log('[PermissionHandler] Permission already granted');
                return 'granted';
            }
            
            // Request permission
            const permission = await Notification.requestPermission();
            console.log('[PermissionHandler] Permission result:', permission);
            
            return permission;
        } catch (error) {
            console.error('[PermissionHandler] Permission request failed:', error);
            return 'denied';
        }
    }
    
    /**
     * Check current notification permission status
     * Requirements: 2.2
     * @returns {string} Permission status: 'granted', 'denied', or 'default'
     */
    checkPermission() {
        if (!('Notification' in window)) {
            return 'denied';
        }
        
        return Notification.permission;
    }
    
    /**
     * Handle permission denied scenario
     * Requirements: 2.2
     * @returns {void}
     */
    handlePermissionDenied() {
        // Create retry dialog
        const dialog = this.createRetryDialog();
        document.body.appendChild(dialog);
        
        console.log('[PermissionHandler] Permission denied - showing retry dialog');
    }
    
    /**
     * Create retry dialog for denied permissions
     * @private
     * @returns {HTMLElement}
     */
    createRetryDialog() {
        const dialog = document.createElement('div');
        dialog.id = 'permission-retry-dialog';
        dialog.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50';
        dialog.innerHTML = `
            <div class="bg-white rounded-lg p-6 max-w-md mx-4 shadow-xl">
                <div class="flex items-start mb-4">
                    <div class="flex-shrink-0">
                        <svg class="h-6 w-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div class="ml-3 flex-1">
                        <h3 class="text-lg font-medium text-gray-900">Enable Notifications</h3>
                        <p class="mt-2 text-sm text-gray-500">
                            Notifications help you stay informed about important updates such as:
                        </p>
                        <ul class="mt-2 text-sm text-gray-500 list-disc list-inside">
                            <li>Payment requirements and approvals</li>
                            <li>Document uploads and processing</li>
                            <li>Manifest status changes</li>
                            <li>Important system alerts</li>
                        </ul>
                    </div>
                </div>
                <div class="flex justify-end space-x-3">
                    <button id="permission-retry-cancel" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Not Now
                    </button>
                    <button id="permission-retry-button" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                        Enable Notifications
                    </button>
                </div>
            </div>
        `;
        
        // Add event listeners
        const retryButton = dialog.querySelector('#permission-retry-button');
        const cancelButton = dialog.querySelector('#permission-retry-cancel');
        
        retryButton.addEventListener('click', async () => {
            dialog.remove();
            const permission = await this.requestPermission();
            if (permission === 'granted') {
                await this.subscribeToPush();
            }
        });
        
        cancelButton.addEventListener('click', () => {
            dialog.remove();
        });
        
        return dialog;
    }
    
    /**
     * Subscribe to push notifications
     * Requirements: 2.3, 2.6
     * @param {number} attempt - Current attempt number (for retry logic)
     * @returns {Promise<boolean>} True if subscription successful, false otherwise
     */
    async subscribeToPush(attempt = 1) {
        try {
            // Check if service worker is registered
            const registration = await navigator.serviceWorker.ready;
            
            if (!registration) {
                console.error('[PermissionHandler] Service worker not registered');
                return false;
            }
            
            // Check if already subscribed
            let subscription = await registration.pushManager.getSubscription();
            
            if (!subscription) {
                // Subscribe to push
                console.log('[PermissionHandler] Subscribing to push notifications...');
                
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey)
                });
                
                console.log('[PermissionHandler] Push subscription created');
            } else {
                console.log('[PermissionHandler] Already subscribed to push');
            }
            
            // Send subscription to backend
            const success = await this.registerSubscriptionWithBackend(subscription, attempt);
            
            if (!success && attempt < this.maxRetries) {
                // Retry with exponential backoff
                console.log(`[PermissionHandler] Registration failed, retrying (attempt ${attempt + 1}/${this.maxRetries})...`);
                await this.sleep(this.retryDelay * Math.pow(2, attempt - 1));
                return await this.subscribeToPush(attempt + 1);
            }
            
            return success;
            
        } catch (error) {
            console.error('[PermissionHandler] Push subscription failed:', error);
            
            // Store pending subscription for retry on next launch
            if (attempt >= this.maxRetries) {
                console.log('[PermissionHandler] Max retries reached, storing for next launch');
                this.storePendingSubscription();
            }
            
            return false;
        }
    }
    
    /**
     * Register subscription with backend
     * Requirements: 2.3
     * @private
     * @param {PushSubscription} subscription - Push subscription object
     * @param {number} attempt - Current attempt number
     * @returns {Promise<boolean>} True if registration successful
     */
    async registerSubscriptionWithBackend(subscription, attempt) {
        try {
            const subscriptionData = {
                endpoint: subscription.endpoint,
                keys: {
                    p256dh: this.arrayBufferToBase64Url(subscription.getKey('p256dh')),
                    auth: this.arrayBufferToBase64Url(subscription.getKey('auth'))
                }
            };
            
            const response = await fetch('/api/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(subscriptionData)
            });
            
            if (!response.ok) {
                // Silently fail for 500 errors - push notifications are non-critical
                if (response.status === 500) {
                    console.warn('[PermissionHandler] Push notification service unavailable (non-critical)');
                    return false;
                }
                throw new Error(`Backend registration failed: ${response.status}`);
            }
            
            const result = await response.json();
            console.log('[PermissionHandler] Subscription registered with backend:', result);
            
            // Clear any pending subscription
            localStorage.removeItem('pending_push_subscription');
            
            return true;
            
        } catch (error) {
            // Only log as warning for non-critical push notification errors
            console.warn('[PermissionHandler] Push notification registration skipped:', error.message);
            
            // Store subscription for retry if this is the last attempt
            if (attempt >= this.maxRetries) {
                this.storePendingSubscription(subscription);
            }
            
            return false;
        }
    }
    
    /**
     * Store pending subscription in localStorage for retry
     * Requirements: 2.6
     * @private
     * @param {PushSubscription} subscription - Optional subscription object
     * @returns {void}
     */
    storePendingSubscription(subscription = null) {
        try {
            if (subscription) {
                const subscriptionData = {
                    endpoint: subscription.endpoint,
                    keys: {
                        p256dh: this.arrayBufferToBase64(subscription.getKey('p256dh')),
                        auth: this.arrayBufferToBase64(subscription.getKey('auth'))
                    }
                };
                localStorage.setItem('pending_push_subscription', JSON.stringify(subscriptionData));
                console.log('[PermissionHandler] Stored pending subscription for retry');
            } else {
                localStorage.setItem('pending_push_subscription', 'retry');
                console.log('[PermissionHandler] Marked subscription for retry');
            }
        } catch (error) {
            console.error('[PermissionHandler] Failed to store pending subscription:', error);
        }
    }
    
    /**
     * Initialize permissions on app launch
     * Shows blocking modal on first launch to request permissions
     * Requirements: 2.4, 2.5
     * @returns {Promise<void>}
     */
    async initializePermissions() {
        console.log('[PermissionHandler] Initializing permissions...');
        
        const permission = this.checkPermission();
        console.log('[PermissionHandler] Current permission status:', permission);
        
        // Check if this is first launch (permission not yet requested)
        const hasSeenPermissionPrompt = localStorage.getItem('has_seen_permission_prompt') === 'true';
        
        if (permission === 'default' && !hasSeenPermissionPrompt) {
            // First launch - show blocking modal
            console.log('[PermissionHandler] First launch detected, showing permission modal');
            await this.showFirstLaunchPermissionModal();
        } else if (permission === 'denied') {
            // Show persistent banner with re-enable instructions
            this.showPermissionBanner();
        } else if (permission === 'granted') {
            // Verify subscription is registered with backend
            await this.verifySubscription();
            
            // Retry pending subscription if found
            await this.retryPendingSubscription();
        }
    }
    
    /**
     * Show blocking permission modal on first launch
     * Forces user to make a decision about notifications
     * @private
     * @returns {Promise<void>}
     */
    async showFirstLaunchPermissionModal() {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.id = 'first-launch-permission-modal';
            modal.className = 'fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50';
            modal.style.cssText = 'position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; z-index: 10001 !important; background: rgba(0,0,0,0.85) !important; display: flex !important; align-items: center !important; justify-content: center !important;';
            
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-8 max-w-md mx-4 shadow-2xl" style="background: white; border-radius: 16px; padding: 32px; max-width: 480px; width: 90%;">
                    <div class="text-center mb-6">
                        <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-blue-100 mb-4" style="margin: 0 auto 16px; width: 64px; height: 64px; border-radius: 50%; background: #DBEAFE; display: flex; align-items: center; justify-content: center;">
                            <svg class="h-10 w-10 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 40px; height: 40px; color: #2563EB;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 mb-2" style="font-size: 24px; font-weight: 700; color: #111827; margin-bottom: 8px;">Enable Notifications</h2>
                        <p class="text-gray-600 mb-4" style="color: #6B7280; margin-bottom: 16px; font-size: 15px;">Stay updated with important information about your shipments and documents</p>
                    </div>
                    
                    <div class="space-y-3 mb-6" style="margin-bottom: 24px;">
                        <div class="flex items-start" style="display: flex; align-items: flex-start; margin-bottom: 12px;">
                            <svg class="h-5 w-5 text-green-500 mr-3 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 20px; height: 20px; color: #10B981; margin-right: 12px; margin-top: 2px; flex-shrink: 0;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-900" style="font-size: 14px; font-weight: 500; color: #111827;">Payment Updates</p>
                                <p class="text-xs text-gray-500" style="font-size: 12px; color: #9CA3AF;">Get notified when payments are approved or rejected</p>
                            </div>
                        </div>
                        <div class="flex items-start" style="display: flex; align-items: flex-start; margin-bottom: 12px;">
                            <svg class="h-5 w-5 text-green-500 mr-3 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 20px; height: 20px; color: #10B981; margin-right: 12px; margin-top: 2px; flex-shrink: 0;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-900" style="font-size: 14px; font-weight: 500; color: #111827;">Document Status</p>
                                <p class="text-xs text-gray-500" style="font-size: 12px; color: #9CA3AF;">Track when documents are uploaded or processed</p>
                            </div>
                        </div>
                        <div class="flex items-start" style="display: flex; align-items: flex-start; margin-bottom: 12px;">
                            <svg class="h-5 w-5 text-green-500 mr-3 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 20px; height: 20px; color: #10B981; margin-right: 12px; margin-top: 2px; flex-shrink: 0;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-900" style="font-size: 14px; font-weight: 500; color: #111827;">Manifest Assignments</p>
                                <p class="text-xs text-gray-500" style="font-size: 12px; color: #9CA3AF;">Know immediately when you're assigned to a manifest</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-3" style="display: flex; flex-direction: column; gap: 12px;">
                        <button id="permission-modal-allow" class="w-full bg-blue-600 text-white px-6 py-3 rounded-lg font-medium hover:bg-blue-700 transition-colors" style="width: 100%; background: #2563EB; color: white; padding: 12px 24px; border-radius: 8px; font-weight: 500; border: none; cursor: pointer; font-size: 15px;">
                            Enable Notifications
                        </button>
                        <button id="permission-modal-skip" class="w-full bg-gray-100 text-gray-700 px-6 py-3 rounded-lg font-medium hover:bg-gray-200 transition-colors" style="width: 100%; background: #F3F4F6; color: #374151; padding: 12px 24px; border-radius: 8px; font-weight: 500; border: none; cursor: pointer; font-size: 15px;">
                            Maybe Later
                        </button>
                    </div>
                    
                    <p class="text-xs text-gray-400 text-center mt-4" style="font-size: 11px; color: #9CA3AF; text-align: center; margin-top: 16px;">
                        You can change this setting anytime in your browser
                    </p>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            const allowButton = modal.querySelector('#permission-modal-allow');
            const skipButton = modal.querySelector('#permission-modal-skip');
            
            allowButton.addEventListener('click', async () => {
                console.log('[PermissionHandler] User clicked Enable Notifications');
                localStorage.setItem('has_seen_permission_prompt', 'true');
                
                const permission = await this.requestPermission();
                console.log('[PermissionHandler] Permission result:', permission);
                
                if (permission === 'granted') {
                    await this.subscribeToPush();
                }
                
                modal.remove();
                resolve();
            });
            
            skipButton.addEventListener('click', () => {
                console.log('[PermissionHandler] User clicked Maybe Later');
                localStorage.setItem('has_seen_permission_prompt', 'true');
                modal.remove();
                resolve();
            });
        });
    }
    
    /**
     * Show persistent banner for denied permissions
     * Requirements: 2.4
     * @private
     * @returns {void}
     */
    showPermissionBanner() {
        // Check if banner already exists
        if (document.getElementById('permission-denied-banner')) {
            return;
        }
        
        const banner = document.createElement('div');
        banner.id = 'permission-denied-banner';
        banner.className = 'fixed top-0 left-0 right-0 bg-yellow-50 border-b border-yellow-200 p-4 z-40';
        banner.innerHTML = `
            <div class="max-w-7xl mx-auto flex items-center justify-between">
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-yellow-400 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-yellow-800">
                            Notifications are disabled
                        </p>
                        <p class="text-xs text-yellow-700 mt-1">
                            To enable notifications, go to your browser settings and allow notifications for this site.
                        </p>
                    </div>
                </div>
                <button id="permission-banner-close" class="text-yellow-400 hover:text-yellow-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        `;
        
        document.body.insertBefore(banner, document.body.firstChild);
        
        // Add close button handler
        const closeButton = banner.querySelector('#permission-banner-close');
        closeButton.addEventListener('click', () => {
            banner.remove();
        });
        
        console.log('[PermissionHandler] Permission denied banner displayed');
    }
    
    /**
     * Verify subscription is registered with backend
     * Requirements: 2.5
     * @private
     * @returns {Promise<void>}
     */
    async verifySubscription() {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            if (!subscription) {
                console.log('[PermissionHandler] No subscription found, creating new one');
                await this.subscribeToPush();
                return;
            }
            
            // Verify with backend
            const response = await fetch('/api/push/subscriptions', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                console.warn('[PermissionHandler] Failed to verify subscription with backend');
                return;
            }
            
            const result = await response.json();
            const subscriptions = result.subscriptions || [];
            
            // Check if current subscription is registered
            const isRegistered = subscriptions.some(sub => 
                subscription.endpoint.includes(sub.id) || sub.endpoint === subscription.endpoint
            );
            
            if (!isRegistered) {
                console.log('[PermissionHandler] Subscription not registered with backend, registering now');
                await this.registerSubscriptionWithBackend(subscription, 1);
            } else {
                console.log('[PermissionHandler] Subscription verified with backend');
            }
            
        } catch (error) {
            console.error('[PermissionHandler] Subscription verification failed:', error);
        }
    }
    
    /**
     * Retry pending subscription registration
     * Requirements: 2.5
     * @private
     * @returns {Promise<void>}
     */
    async retryPendingSubscription() {
        try {
            const pending = localStorage.getItem('pending_push_subscription');
            
            if (!pending) {
                return;
            }
            
            console.log('[PermissionHandler] Found pending subscription, retrying registration...');
            
            // Get current subscription
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            if (!subscription) {
                console.warn('[PermissionHandler] No subscription available for retry');
                localStorage.removeItem('pending_push_subscription');
                return;
            }
            
            // Retry registration
            const success = await this.registerSubscriptionWithBackend(subscription, 1);
            
            if (success) {
                console.log('[PermissionHandler] Pending subscription registered successfully');
            } else {
                console.warn('[PermissionHandler] Pending subscription registration failed');
            }
            
        } catch (error) {
            console.error('[PermissionHandler] Pending subscription retry failed:', error);
        }
    }
    
    /**
     * Convert URL-safe base64 to Uint8Array
     * @private
     * @param {string} base64String - Base64 encoded string
     * @returns {Uint8Array}
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/\-/g, '+')
            .replace(/_/g, '/');
        
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        
        return outputArray;
    }
    
    /**
     * Convert ArrayBuffer to base64
     * @private
     * @param {ArrayBuffer} buffer - Array buffer
     * @returns {string} Base64 encoded string
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }
    
    /**
     * Convert ArrayBuffer to base64url (URL-safe base64 without padding)
     * This is the format expected by web-push libraries
     * @private
     * @param {ArrayBuffer} buffer - Array buffer
     * @returns {string} Base64url encoded string
     */
    arrayBufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=/g, '');
    }
    
    /**
     * Sleep for specified milliseconds
     * @private
     * @param {number} ms - Milliseconds to sleep
     * @returns {Promise<void>}
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PermissionHandler;
}
