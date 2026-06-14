/**
 * eDO Renewal Request Form Handler
 * Requirements: 3.2, 5.1, 5.2, 13.1, 13.2, 13.3
 * 
 * This module handles:
 * - Dynamic charge calculation based on return date selection
 * - Office hours validation (Monday-Friday, 8:00 AM - 5:00 PM)
 * - Real-time display of detention charges
 * - Form validation before submission
 */

class EDORenewalRequestForm {
    constructor() {
        this.returnDateInput = document.getElementById('returnDate');
        this.chargesDisplay = document.getElementById('chargesDisplay');
        this.overdueDaysDisplay = document.getElementById('overdueDaysDisplay');
        this.detentionRateDisplay = document.getElementById('detentionRateDisplay');
        this.totalChargeDisplay = document.getElementById('totalChargeDisplay');
        this.submitBtn = document.getElementById('submitBtn');
        this.form = document.getElementById('renewalRequestForm');
        
        // Get expiration date from data attribute or hidden field
        const expirationDateStr = this.form?.dataset.expirationDate;
        this.expirationDate = expirationDateStr ? new Date(expirationDateStr) : null;
        
        // Get eDO ID from form action URL
        this.edoId = this.extractEdoIdFromForm();
        
        this.isCalculating = false;
        this.validationErrors = [];
        
        this.initializeEventListeners();
    }

    /**
     * Extract eDO ID from form action URL
     */
    extractEdoIdFromForm() {
        if (!this.form) return null;
        
        const action = this.form.getAttribute('action');
        const matches = action.match(/\/broker\/edos\/(\d+)\/request-renewal/);
        return matches ? parseInt(matches[1]) : null;
    }

    /**
     * Initialize event listeners
     */
    initializeEventListeners() {
        if (!this.returnDateInput || !this.form) {
            console.error('Required form elements not found');
            return;
        }

        // Listen for return date changes
        this.returnDateInput.addEventListener('change', () => this.handleReturnDateChange());
        
        // Listen for form submission
        this.form.addEventListener('submit', (e) => this.handleFormSubmit(e));
        
        // Disable submit button initially
        this.updateSubmitButton(false);
    }

    /**
     * Handle return date selection change
     */
    async handleReturnDateChange() {
        const returnDateValue = this.returnDateInput.value;
        
        if (!returnDateValue) {
            this.hideCharges();
            this.updateSubmitButton(false);
            return;
        }

        // Combine date with default time (10:00 AM)
        const returnDateTime = returnDateValue + ' 10:00:00';
        const returnDate = new Date(returnDateTime);
        
        // Validate the selected date
        if (!this.validateReturnDate(returnDate)) {
            this.hideCharges();
            this.updateSubmitButton(false);
            return;
        }

        // Calculate and display charges
        await this.calculateCharges(returnDateTime);
    }

    /**
     * Validate return date against business rules
     * Requirements: 3.2, 5.2
     */
    validateReturnDate(returnDate) {
        this.validationErrors = [];

        // Check if date is in the past
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Reset to start of day for comparison
        const checkDate = new Date(returnDate);
        checkDate.setHours(0, 0, 0, 0);
        
        if (checkDate < now) {
            this.showError('Return date cannot be in the past');
            this.validationErrors.push('past_date');
            return false;
        }

        // No restrictions on day of week or time - brokers can submit anytime
        return true;
    }

    /**
     * Calculate detention charges via AJAX
     * Requirements: 13.1, 13.2, 13.3
     */
    async calculateCharges(returnDateValue) {
        if (this.isCalculating) {
            return;
        }

        if (!this.edoId) {
            console.error('eDO ID not found');
            this.showError('Unable to calculate charges: eDO ID missing');
            return;
        }

        this.isCalculating = true;
        this.showCalculatingState();

        try {
            const response = await fetch(`/broker/edos/${this.edoId}/calculate-detention`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    return_date: returnDateValue
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                this.displayCharges(data.data);
                this.updateSubmitButton(true);
            } else {
                this.showError(data.message || 'Failed to calculate detention charges');
                this.hideCharges();
                this.updateSubmitButton(false);
            }

        } catch (error) {
            console.error('Error calculating charges:', error);
            this.showError('Failed to calculate detention charges. Please try again.');
            this.hideCharges();
            this.updateSubmitButton(false);
        } finally {
            this.isCalculating = false;
        }
    }

    /**
     * Display calculated charges in the UI
     * Requirements: 13.2, 13.3
     */
    displayCharges(chargeData) {
        if (!chargeData) {
            this.hideCharges();
            return;
        }

        const { overdue_days, detention_rate, total_charge } = chargeData;

        // If no charges, hide the display
        if (overdue_days === 0 || total_charge === 0) {
            this.hideCharges();
            return;
        }

        // Update charge details
        if (this.overdueDaysDisplay) {
            this.overdueDaysDisplay.textContent = `${overdue_days} day${overdue_days !== 1 ? 's' : ''}`;
        }

        if (this.detentionRateDisplay) {
            this.detentionRateDisplay.textContent = this.formatCurrency(detention_rate);
        }

        if (this.totalChargeDisplay) {
            this.totalChargeDisplay.textContent = this.formatCurrency(total_charge);
        }

        // Show the charges display
        if (this.chargesDisplay) {
            this.chargesDisplay.classList.remove('hidden');
        }
    }

    /**
     * Hide charges display
     */
    hideCharges() {
        if (this.chargesDisplay) {
            this.chargesDisplay.classList.add('hidden');
        }
    }

    /**
     * Show calculating state
     */
    showCalculatingState() {
        if (this.totalChargeDisplay) {
            this.totalChargeDisplay.textContent = 'Calculating...';
        }
    }

    /**
     * Handle form submission
     */
    async handleFormSubmit(event) {
        event.preventDefault(); // Always prevent default to handle via AJAX
        
        console.log('Form submit triggered');
        
        // Validate return date is selected
        if (!this.returnDateInput.value) {
            this.showError('Please select an empty container return date');
            return false;
        }

        // Combine date with default time (10:00 AM)
        const returnDateTime = this.returnDateInput.value + ' 10:00:00';
        const returnDate = new Date(returnDateTime);
        
        console.log('Return date:', this.returnDateInput.value);
        console.log('Return datetime:', returnDateTime);
        
        // Validate return date one more time
        if (!this.validateReturnDate(returnDate)) {
            return false;
        }

        // Disable submit button and show loading state
        this.updateSubmitButton(false, true);
        
        try {
            // Create form data and add the combined datetime
            const formData = new FormData(this.form);
            formData.set('return_date', returnDateTime);
            
            console.log('Submitting to:', this.form.action);
            console.log('Form data:', Object.fromEntries(formData));
            
            const response = await fetch(this.form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            
            const data = await response.json();
            
            console.log('Response data:', data);

            if (data.success) {
                // Show success message
                this.showSuccess(data.message || 'Renewal request submitted successfully!');
                
                // Redirect to eDO list page after a short delay
                setTimeout(() => {
                    window.location.href = `/broker/edos/page`;
                }, 1500);
            } else {
                // Show error message
                const errorMessage = data.error?.message || 'Failed to submit renewal request';
                this.showError(errorMessage);
                this.updateSubmitButton(true, false);
            }
        } catch (error) {
            console.error('Error submitting form:', error);
            this.showError('Failed to submit renewal request. Please try again.');
            this.updateSubmitButton(true, false);
        }
        
        return false;
    }

    /**
     * Update submit button state
     */
    updateSubmitButton(enabled, loading = false) {
        if (!this.submitBtn) return;

        if (loading) {
            this.submitBtn.disabled = true;
            this.submitBtn.innerHTML = '<span class="loading loading-spinner loading-sm"></span> Submitting...';
        } else if (enabled) {
            this.submitBtn.disabled = false;
            this.submitBtn.innerHTML = '<span class="icon-[tabler--send] size-5"></span> Submit Renewal Request';
        } else {
            this.submitBtn.disabled = true;
            this.submitBtn.innerHTML = '<span class="icon-[tabler--send] size-5"></span> Submit Renewal Request';
        }
    }

    /**
     * Format currency value
     */
    formatCurrency(amount) {
        const prefix = window.MONEY_PREFIX || '₱';
        const numAmount = parseFloat(amount);
        if (Number.isNaN(numAmount)) {
            return prefix + '0.00';
        }
        return prefix + numAmount.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /**
     * Show error notification
     */
    showError(message) {
        // Use global notyf if available
        if (window.notyf) {
            window.notyf.error(message);
        } else {
            // Fallback to alert
            alert(message);
        }
    }

    /**
     * Show success notification
     */
    showSuccess(message) {
        // Use global notyf if available
        if (window.notyf) {
            window.notyf.success(message);
        } else {
            // Fallback to alert
            alert(message);
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if we're on the renewal request page
    const form = document.getElementById('renewalRequestForm');
    if (form) {
        window.edoRenewalRequestForm = new EDORenewalRequestForm();
    }
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { EDORenewalRequestForm };
}
