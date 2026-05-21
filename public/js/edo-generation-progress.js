/**
 * eDO Generation Progress Modal Controller
 * Handles batch eDO generation progress tracking and UI updates
 */

class EDOGenerationProgressController {
    constructor() {
        this.modal = document.getElementById('edoGenerationProgressModal');
        this.progressSection = document.getElementById('progressSection');
        this.completionSection = document.getElementById('completionSection');
        this.cancelSection = document.getElementById('cancelSection');
        this.progressBar = document.getElementById('progressBar');
        this.progressText = document.getElementById('progressText');
        this.progressPercentage = document.getElementById('progressPercentage');
        this.currentContainer = document.getElementById('currentContainer');
        this.successCount = document.getElementById('successCount');
        this.failureCount = document.getElementById('failureCount');
        this.statusBadge = document.getElementById('statusBadge');
        this.progressIcon = document.getElementById('progressIcon');
        this.documentTypeDisplay = document.getElementById('documentTypeDisplay');
        this.documentNumberDisplay = document.getElementById('documentNumberDisplay');
        
        this.sessionId = null;
        this.pollInterval = null;
        this.manifestId = null;
        
        this.initializeEventListeners();
    }
    
    initializeEventListeners() {
        // Close button
        const closeModalBtn = document.getElementById('closeModalBtn');
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', () => this.closeModal());
        }
        
        // View eDOs button
        const viewEDOsBtn = document.getElementById('viewEDOsBtn');
        if (viewEDOsBtn) {
            viewEDOsBtn.addEventListener('click', () => this.viewGeneratedEDOs());
        }
        
        // Cancel generation button
        const cancelGenerationBtn = document.getElementById('cancelGenerationBtn');
        if (cancelGenerationBtn) {
            cancelGenerationBtn.addEventListener('click', () => this.cancelGeneration());
        }
        
        // Prevent modal close during generation
        this.modal.addEventListener('click', (e) => {
            if (e.target === this.modal && !this.completionSection.classList.contains('hidden')) {
                this.closeModal();
            }
        });
        
        // Prevent escape key from closing modal during generation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !this.modal.classList.contains('hidden')) {
                if (!this.completionSection.classList.contains('hidden')) {
                    this.closeModal();
                }
            }
        });
    }
    
    /**
     * Start batch eDO generation
     */
    async startGeneration(manifestId, expirationDate, documentType = 'manifest') {
        this.manifestId = manifestId;
        
        try {
            const response = await fetch(`/sl-staff/manifests/${manifestId}/generate-edos`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    expiration_date: expirationDate,
                    document_type: documentType
                })
            });
            
            const data = await response.json();
            
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to start generation');
            }
            
            this.sessionId = data.session_id;
            this.showModal();
            this.startPolling();
            
        } catch (error) {
            console.error('Failed to start eDO generation:', error);
            this.showErrorNotification(error.message);
        }
    }
    
    /**
     * Show the progress modal
     */
    showModal() {
        this.modal.classList.remove('hidden');
        this.progressSection.classList.remove('hidden');
        this.completionSection.classList.add('hidden');
        this.cancelSection.classList.remove('hidden');
        
        // Reset progress display
        this.updateProgressDisplay({
            completed: 0,
            total: 0,
            failed: 0,
            percentage: 0,
            current_container: 'Initializing...',
            status: 'in_progress'
        });
    }
    
    /**
     * Close the modal and refresh page
     */
    closeModal() {
        this.modal.classList.add('hidden');
        this.stopPolling();
        
        // Refresh the page to show updated manifest status
        window.location.reload();
    }
    
    /**
     * Start polling for progress updates
     */
    startPolling() {
        // Poll every 1 second
        this.pollInterval = setInterval(() => {
            this.fetchProgress();
        }, 1000);
        
        // Fetch immediately
        this.fetchProgress();
    }
    
    /**
     * Stop polling for progress updates
     */
    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }
    
    /**
     * Fetch current progress from server
     */
    async fetchProgress() {
        if (!this.sessionId) return;
        
        try {
            const response = await fetch(`/sl-staff/edo-generation/${this.sessionId}/progress`);
            
            if (!response.ok) {
                throw new Error('Failed to fetch progress');
            }
            
            const progressData = await response.json();
            
            this.updateProgressDisplay(progressData);
            
            // Check if generation is complete
            if (['completed', 'cancelled', 'failed'].includes(progressData.status)) {
                this.stopPolling();
                this.showCompletionSummary(progressData);
            }
            
        } catch (error) {
            console.error('Failed to fetch progress:', error);
            this.stopPolling();
        }
    }
    
    /**
     * Update progress display
     */
    updateProgressDisplay(progressData) {
        // Update progress bar
        const percentage = progressData.percentage || 0;
        this.progressBar.style.width = percentage + '%';
        this.progressPercentage.textContent = percentage + '%';
        
        // Update progress text
        this.progressText.textContent = `${progressData.completed} of ${progressData.total} completed`;
        
        // Update current container
        if (progressData.current_container) {
            this.currentContainer.textContent = progressData.current_container;
        } else if (progressData.status === 'completed') {
            this.currentContainer.textContent = 'All containers processed';
        }
        
        // Update success/failure counts
        const successfulCount = progressData.completed - (progressData.failed || 0);
        this.successCount.textContent = `${successfulCount} successful`;
        this.failureCount.textContent = `${progressData.failed || 0} failed`;
        
        // Update document type and number display
        if (progressData.document_type && progressData.document_number) {
            const docTypeLabel = progressData.document_type.toUpperCase();
            this.documentTypeDisplay.textContent = `${docTypeLabel}: `;
            this.documentNumberDisplay.textContent = progressData.document_number;
        }
        
        // Update status badge
        this.updateStatusBadge(progressData.status);
    }
    
    /**
     * Update status badge
     */
    updateStatusBadge(status) {
        if (status === 'completed') {
            this.statusBadge.textContent = 'Completed';
            this.statusBadge.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-green-500 text-white';
            this.progressIcon.classList.remove('animate-spin');
        } else if (status === 'cancelled') {
            this.statusBadge.textContent = 'Cancelled';
            this.statusBadge.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-gray-500 text-white';
            this.progressIcon.classList.remove('animate-spin');
        } else if (status === 'failed') {
            this.statusBadge.textContent = 'Failed';
            this.statusBadge.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-red-500 text-white';
            this.progressIcon.classList.remove('animate-spin');
        } else {
            this.statusBadge.textContent = 'In Progress';
            this.statusBadge.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-blue-500 text-white';
        }
    }
    
    /**
     * Show completion summary
     */
    showCompletionSummary(completionData) {
        // Hide progress section, show completion section
        this.progressSection.classList.add('hidden');
        this.completionSection.classList.remove('hidden');
        
        // Hide all summary sections first
        document.getElementById('successSummary').classList.add('hidden');
        document.getElementById('partialSuccessSummary').classList.add('hidden');
        document.getElementById('cancellationSummary').classList.add('hidden');
        document.getElementById('failedContainersSection').classList.add('hidden');
        
        // Determine which summary to show
        if (completionData.status === 'cancelled') {
            this.showCancellationSummary(completionData);
        } else if (completionData.failed === 0) {
            this.showSuccessSummary(completionData);
        } else {
            this.showPartialSuccessSummary(completionData);
        }
        
        // Show failed containers if any
        if (completionData.failures && completionData.failures.length > 0) {
            this.showFailedContainers(completionData.failures);
        }
    }
    
    /**
     * Show success summary
     */
    showSuccessSummary(completionData) {
        const successSummary = document.getElementById('successSummary');
        successSummary.classList.remove('hidden');
        document.getElementById('totalSuccessCount').textContent = completionData.completed;
    }
    
    /**
     * Show partial success summary
     */
    showPartialSuccessSummary(completionData) {
        const partialSuccessSummary = document.getElementById('partialSuccessSummary');
        partialSuccessSummary.classList.remove('hidden');
        document.getElementById('partialSuccessCount').textContent = completionData.completed - completionData.failed;
        document.getElementById('partialFailureCount').textContent = completionData.failed;
    }
    
    /**
     * Show cancellation summary
     */
    showCancellationSummary(completionData) {
        const cancellationSummary = document.getElementById('cancellationSummary');
        cancellationSummary.classList.remove('hidden');
        document.getElementById('cancelledCompletedCount').textContent = completionData.completed;
    }
    
    /**
     * Show failed containers list
     */
    showFailedContainers(failures) {
        const failedContainersSection = document.getElementById('failedContainersSection');
        const failedContainersList = document.getElementById('failedContainersList');
        
        failedContainersSection.classList.remove('hidden');
        failedContainersList.innerHTML = '';
        
        failures.forEach(failure => {
            const li = document.createElement('li');
            li.className = 'px-4 py-3';
            li.innerHTML = `
                <div class="flex items-start">
                    <svg class="h-5 w-5 text-red-600 mt-0.5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-red-900">${this.escapeHtml(failure.container)}</p>
                        <p class="text-xs text-red-700 mt-1">${this.escapeHtml(failure.error)}</p>
                    </div>
                </div>
            `;
            failedContainersList.appendChild(li);
        });
    }
    
    /**
     * Cancel generation
     */
    async cancelGeneration() {
        if (!this.sessionId) return;
        
        if (!confirm('Are you sure you want to cancel the eDO generation? eDOs already generated will be kept.')) {
            return;
        }
        
        try {
            const response = await fetch(`/sl-staff/edo-generation/${this.sessionId}/cancel`, {
                method: 'POST'
            });
            
            const data = await response.json();
            
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to cancel generation');
            }
            
            // Fetch final progress to update display
            this.fetchProgress();
            
        } catch (error) {
            console.error('Failed to cancel generation:', error);
            this.showErrorNotification(error.message);
        }
    }
    
    /**
     * View generated eDOs
     */
    viewGeneratedEDOs() {
        if (this.sessionId) {
            window.location.href = `/sl-staff/edos?session=${this.sessionId}`;
        }
    }
    
    /**
     * Show error notification
     */
    showErrorNotification(message) {
        // You can integrate with your existing notification system
        alert('Error: ' + message);
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize controller when DOM is ready
let edoProgressController;

document.addEventListener('DOMContentLoaded', function() {
    edoProgressController = new EDOGenerationProgressController();
});

// Export for use in other scripts
window.EDOGenerationProgressController = EDOGenerationProgressController;
window.edoProgressController = edoProgressController;
