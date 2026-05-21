/**
 * eDO Container Selection Module
 * Handles individual container selection for eDO generation
 */

class EDOContainerSelection {
    constructor() {
        this.selectedContainers = new Set();
        console.log('EDOContainerSelection initialized');
        this.init();
    }

    init() {
        this.attachEventListeners();
        this.updateSelectionCount();
        console.log('EDOContainerSelection ready');
    }

    attachEventListeners() {
        // Select All checkbox
        const selectAllCheckbox = document.getElementById('selectAllContainers');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => this.handleSelectAll(e));
            console.log('Select All checkbox listener attached');
        } else {
            console.warn('Select All checkbox not found');
        }

        // Attach listeners to existing checkboxes
        this.attachContainerCheckboxListeners();
    }

    attachContainerCheckboxListeners() {
        // Individual container checkboxes
        const checkboxes = document.querySelectorAll('.container-checkbox');
        console.log(`Found ${checkboxes.length} container checkboxes`);
        checkboxes.forEach(checkbox => {
            // Remove existing listener if any to avoid duplicates
            checkbox.removeEventListener('change', this.handleContainerSelect);
            checkbox.addEventListener('change', (e) => this.handleContainerSelect(e));
        });
    }

    handleSelectAll(event) {
        const isChecked = event.target.checked;
        const checkboxes = document.querySelectorAll('.container-checkbox:not([disabled])');
        console.log(`Select All ${isChecked ? 'checked' : 'unchecked'}, found ${checkboxes.length} enabled checkboxes`);
        
        if (checkboxes.length === 0) {
            console.warn('No enabled checkboxes found!');
            return;
        }
        
        checkboxes.forEach((checkbox, index) => {
            console.log(`Setting checkbox ${index} (container ${checkbox.value}) to ${isChecked}`);
            checkbox.checked = isChecked;
            const containerId = parseInt(checkbox.value);
            
            if (isChecked) {
                this.selectedContainers.add(containerId);
            } else {
                this.selectedContainers.delete(containerId);
            }
        });
        
        console.log(`Selected containers after Select All: ${Array.from(this.selectedContainers)}`);
        this.updateSelectionCount();
    }

    handleContainerSelect(event) {
        const containerId = parseInt(event.target.value);
        console.log(`Container ${containerId} ${event.target.checked ? 'selected' : 'deselected'}`);
        
        if (event.target.checked) {
            this.selectedContainers.add(containerId);
        } else {
            this.selectedContainers.delete(containerId);
            // Uncheck "Select All" if any container is unchecked
            const selectAllCheckbox = document.getElementById('selectAllContainers');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }
        }
        
        console.log(`Selected containers: ${Array.from(this.selectedContainers)}`);
        this.updateSelectionCount();
    }

    updateSelectionCount() {
        const count = this.selectedContainers.size;
        const countElement = document.getElementById('selectedContainerCount');
        const generateButton = document.getElementById('edoGenerateButton');
        
        console.log(`Updating selection count: ${count}`);
        
        if (countElement) {
            countElement.textContent = count;
        }
        
        // Enable/disable generate button based on selection
        if (generateButton) {
            if (count === 0) {
                generateButton.disabled = true;
                generateButton.classList.add('btn-disabled');
            } else {
                generateButton.disabled = false;
                generateButton.classList.remove('btn-disabled');
            }
        }
        
        // Update modal text
        const modalText = document.getElementById('edoModalSelectionText');
        if (modalText) {
            if (count === 0) {
                modalText.textContent = 'No containers selected. Please select at least one container.';
                modalText.classList.add('text-warning');
            } else {
                modalText.textContent = `Generate eDOs for ${count} selected container${count !== 1 ? 's' : ''}`;
                modalText.classList.remove('text-warning');
            }
        }
    }

    getSelectedContainerIds() {
        const ids = Array.from(this.selectedContainers);
        console.log(`Getting selected container IDs: ${ids}`);
        return ids;
    }

    hasSelection() {
        return this.selectedContainers.size > 0;
    }

    clearSelection() {
        this.selectedContainers.clear();
        document.querySelectorAll('.container-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        const selectAllCheckbox = document.getElementById('selectAllContainers');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = false;
        }
        this.updateSelectionCount();
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM Content Loaded - Initializing EDO Container Selection');
    window.edoContainerSelection = new EDOContainerSelection();
    console.log('window.edoContainerSelection set:', window.edoContainerSelection);
});
