/**
 * eDO Generation JavaScript Module
 * Handles eDO generation modal interactions and API calls for SL Staff
 */

class EDOGenerationManager {
    constructor() {
        this.currentManifestId = null;
        this.currentManifestNumber = null;
        this.manifestData = null;
        this.isGenerating = false;
        
        this.initializeEventListeners();
    }

    /**
     * Initialize event listeners for modals and forms
     */
    initializeEventListeners() {
        // Close modal handlers
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-close-modal]')) {
                this.closeModal(e.target.getAttribute('data-close-modal'));
            }
        });

        // Expiration date validation
        document.addEventListener('change', (e) => {
            if (e.target.matches('#expirationDate')) {
                this.validateExpirationDate(e.target.value);
            }
        });

        // Generate eDOs form submission
        document.addEventListener('submit', (e) => {
            if (e.target.matches('#edoGenerationForm')) {
                e.preventDefault();
                this.handleEDOGeneration();
            }
        });
    }

    formatMoney(amount) {
        const prefix = window.MONEY_PREFIX || '₱';
        const value = parseFloat(amount);
        if (Number.isNaN(value)) {
            return prefix + '0.00';
        }
        return prefix + value.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    /**
     * Open eDO generation modal for a specific manifest
     * @param {number} manifestId - The manifest ID
     * @param {string} manifestNumber - The manifest number for display
     */
    async openEDOGenerationModal(manifestId, manifestNumber) {
        if (this.isGenerating) {
            this.showError('eDO generation is already in progress. Please wait.');
            return;
        }

        this.currentManifestId = manifestId;
        this.currentManifestNumber = manifestNumber;

        try {
            // Show loading state
            this.showLoadingModal();

            // Fetch manifest details from API
            const response = await fetch(`/sl-staff/edo-generation/manifest/${manifestId}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to fetch manifest details');
            }

            this.manifestData = data.data;
            this.populateExpirationDateModal();
            this.showExpirationDateModal();

        } catch (error) {
            console.error('Error fetching manifest details:', error);
            this.showError(`Failed to load manifest details: ${error.message}`);
            this.closeModal('loadingModal');
        }
    }

    /**
     * Show loading modal while fetching data
     */
    showLoadingModal() {
        const modal = document.getElementById('loadingModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    }

    /**
     * Populate the expiration date selection modal with manifest data
     */
    populateExpirationDateModal() {
        // Update manifest number in header
        const manifestNumberElement = document.getElementById('modalManifestNumber');
        if (manifestNumberElement) {
            manifestNumberElement.textContent = this.manifestData.manifestNumber;
        }

        // Update container count
        const containerCountElement = document.getElementById('containerCount');
        if (containerCountElement) {
            containerCountElement.textContent = this.manifestData.containerCount;
        }

        // Populate container list
        const containerListElement = document.getElementById('containerList');
        if (containerListElement && this.manifestData.containers) {
            containerListElement.innerHTML = '';
            
            this.manifestData.containers.forEach(container => {
                const containerRow = document.createElement('tr');
                containerRow.className = 'border-b border-gray-200';
                containerRow.innerHTML = `
                    <td class="px-4 py-3 text-sm font-medium text-gray-900">${container.containerNumber}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">${container.size || 'N/A'}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">${container.type || 'N/A'}</td>
                `;
                containerListElement.appendChild(containerRow);
            });
        }

        // Update fee information
        const edoFeeElement = document.getElementById('edoFeePerContainer');
        if (edoFeeElement) {
            edoFeeElement.textContent = this.formatMoney(this.manifestData.edoFeePerContainer);
        }

        const totalFeesElement = document.getElementById('totalEdoFees');
        if (totalFeesElement) {
            totalFeesElement.textContent = this.formatMoney(this.manifestData.totalEdoFees);
        }

        // Reset form
        const form = document.getElementById('edoGenerationForm');
        if (form) {
            form.reset();
        }

        // Reset validation state
        this.resetValidationState();
    }

    /**
     * Show the expiration date selection modal
     */
    showExpirationDateModal() {
        this.closeModal('loadingModal');
        
        const modal = document.getElementById('expirationDateModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Focus on date input
            const dateInput = document.getElementById('expirationDate');
            if (dateInput) {
                setTimeout(() => dateInput.focus(), 100);
            }
        }
    }

    /**
     * Validate the selected expiration date
     * @param {string} dateValue - The selected date value
     */
    validateExpirationDate(dateValue) {
        const dateInput = document.getElementById('expirationDate');
        const errorElement = document.getElementById('expirationDateError');
        const generateButton = document.getElementById('generateEDOsButton');

        if (!dateValue) {
            this.showDateError('Please select an expiration date');
            this.disableGenerateButton();
            return false;
        }

        const selectedDate = new Date(dateValue);
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(0, 0, 0, 0);

        if (selectedDate < tomorrow) {
            this.showDateError('Expiration date must be at least 1 day from now');
            this.disableGenerateButton();
            return false;
        }

        // Valid date
        this.clearDateError();
        this.enableGenerateButton();
        return true;
    }

    /**
     * Show date validation error
     * @param {string} message - Error message to display
     */
    showDateError(message) {
        const errorElement = document.getElementById('expirationDateError');
        const dateInput = document.getElementById('expirationDate');
        
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');
        }
        
        if (dateInput) {
            dateInput.classList.add('border-red-500');
            dateInput.classList.remove('border-gray-300');
        }
    }

    /**
     * Clear date validation error
     */
    clearDateError() {
        const errorElement = document.getElementById('expirationDateError');
        const dateInput = document.getElementById('expirationDate');
        
        if (errorElement) {
            errorElement.classList.add('hidden');
        }
        
        if (dateInput) {
            dateInput.classList.remove('border-red-500');
            dateInput.classList.add('border-gray-300');
        }
    }

    /**
     * Enable the generate eDOs button
     */
    enableGenerateButton() {
        const button = document.getElementById('generateEDOsButton');
        if (button) {
            button.disabled = false;
            button.classList.remove('bg-gray-400', 'cursor-not-allowed');
            button.classList.add('bg-green-600', 'hover:bg-green-700');
        }
    }

    /**
     * Disable the generate eDOs button
     */
    disableGenerateButton() {
        const button = document.getElementById('generateEDOsButton');
        if (button) {
            button.disabled = true;
            button.classList.add('bg-gray-400', 'cursor-not-allowed');
            button.classList.remove('bg-green-600', 'hover:bg-green-700');
        }
    }

    /**
     * Reset validation state
     */
    resetValidationState() {
        this.clearDateError();
        this.disableGenerateButton();
    }

    /**
     * Handle eDO generation form submission
     */
    async handleEDOGeneration() {
        if (this.isGenerating) {
            return;
        }

        const expirationDate = document.getElementById('expirationDate').value;
        
        if (!this.validateExpirationDate(expirationDate)) {
            return;
        }

        this.isGenerating = true;

        try {
            // Close expiration date modal and show progress modal
            this.closeModal('expirationDateModal');
            this.showProgressModal();

            // Submit eDO generation request
            const response = await fetch(`/sl-staff/edo-generation/generate/${this.currentManifestId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    expirationDate: expirationDate
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to generate eDOs');
            }

            // Show success message
            this.showSuccessMessage(data.message, data.data.count);
            
            // Refresh dashboard after short delay
            setTimeout(() => {
                this.refreshDashboard();
            }, 2000);

        } catch (error) {
            console.error('Error generating eDOs:', error);
            this.showProgressError(error.message);
        } finally {
            this.isGenerating = false;
        }
    }

    /**
     * Show progress modal during eDO generation
     */
    showProgressModal() {
        const modal = document.getElementById('progressModal');
        const messageElement = document.getElementById('progressMessage');
        const spinnerElement = document.getElementById('progressSpinner');
        const closeButton = document.getElementById('progressCloseButton');

        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        if (messageElement) {
            messageElement.textContent = `Generating ${this.manifestData.containerCount} eDOs...`;
            messageElement.className = 'text-lg font-medium text-gray-900';
        }

        if (spinnerElement) {
            spinnerElement.classList.remove('hidden');
        }

        if (closeButton) {
            closeButton.classList.add('hidden');
        }
    }

    /**
     * Show success message in progress modal
     * @param {string} message - Success message
     * @param {number} count - Number of eDOs generated
     */
    showSuccessMessage(message, count) {
        const messageElement = document.getElementById('progressMessage');
        const spinnerElement = document.getElementById('progressSpinner');
        const closeButton = document.getElementById('progressCloseButton');
        const iconElement = document.getElementById('progressIcon');

        if (messageElement) {
            messageElement.textContent = message;
            messageElement.className = 'text-lg font-medium text-green-700';
        }

        if (spinnerElement) {
            spinnerElement.classList.add('hidden');
        }

        if (iconElement) {
            iconElement.innerHTML = `
                <svg class="w-12 h-12 text-green-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            `;
        }

        if (closeButton) {
            closeButton.classList.remove('hidden');
        }
    }

    /**
     * Show error message in progress modal
     * @param {string} errorMessage - Error message to display
     */
    showProgressError(errorMessage) {
        const messageElement = document.getElementById('progressMessage');
        const spinnerElement = document.getElementById('progressSpinner');
        const closeButton = document.getElementById('progressCloseButton');
        const iconElement = document.getElementById('progressIcon');

        if (messageElement) {
            messageElement.textContent = `Failed to generate eDOs: ${errorMessage}`;
            messageElement.className = 'text-lg font-medium text-red-700';
        }

        if (spinnerElement) {
            spinnerElement.classList.add('hidden');
        }

        if (iconElement) {
            iconElement.innerHTML = `
                <svg class="w-12 h-12 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"></path>
                </svg>
            `;
        }

        if (closeButton) {
            closeButton.classList.remove('hidden');
        }
    }

    /**
     * Close a modal by ID
     * @param {string} modalId - The modal ID to close
     */
    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Reset state when closing main modals
        if (modalId === 'expirationDateModal' || modalId === 'progressModal') {
            this.currentManifestId = null;
            this.currentManifestNumber = null;
            this.manifestData = null;
        }
    }

    /**
     * Show general error message
     * @param {string} message - Error message to display
     */
    showError(message) {
        // Create or update error toast
        let errorToast = document.getElementById('errorToast');
        
        if (!errorToast) {
            errorToast = document.createElement('div');
            errorToast.id = 'errorToast';
            errorToast.className = 'fixed top-4 right-4 bg-red-500 text-white px-6 py-4 rounded-lg shadow-lg z-50 max-w-md';
            document.body.appendChild(errorToast);
        }

        errorToast.innerHTML = `
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"></path>
                </svg>
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        `;

        errorToast.classList.remove('hidden');

        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (errorToast && errorToast.parentNode) {
                errorToast.remove();
            }
        }, 5000);
    }

    /**
     * Refresh the dashboard to update the manifest list
     */
    refreshDashboard() {
        // Close all modals first
        this.closeModal('progressModal');
        
        // Reload the page to refresh the dashboard
        window.location.reload();
    }
}

// Global function to open eDO generation modal (called from template)
function openEDOGenerationModal(manifestId, manifestNumber) {
    if (window.edoGenerationManager) {
        window.edoGenerationManager.openEDOGenerationModal(manifestId, manifestNumber);
    } else {
        console.error('EDO Generation Manager not initialized');
    }
}

// Initialize the eDO generation manager when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.edoGenerationManager = new EDOGenerationManager();
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { EDOGenerationManager, openEDOGenerationModal };
}