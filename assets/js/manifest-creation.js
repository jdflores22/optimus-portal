/**
 * Manifest Creation Form Handler
 * Requirements: 2.1-2.8
 */

let selectedNOA = null;

document.addEventListener('DOMContentLoaded', function() {
    loadNOAs();
    initializeEventListeners();
});

function initializeEventListeners() {
    const form = document.getElementById('manifestCreationForm');
    const noaSelect = document.getElementById('noaId');
    const blFileInput = document.getElementById('blFile');
    
    form.addEventListener('submit', handleFormSubmit);
    noaSelect.addEventListener('change', handleNOASelection);
    blFileInput.addEventListener('change', handleFileSelection);
}

function loadNOAs() {
    fetch('/api/noas/available')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('noaId');
            data.noas.forEach(noa => {
                const option = document.createElement('option');
                option.value = noa.id;
                option.textContent = `${noa.noaNumber} - BL: ${noa.blNumber} - Vessel: ${noa.vesselNumber} - ETA: ${noa.eta}`;
                option.dataset.noa = JSON.stringify(noa);
                select.appendChild(option);
            });
        })
        .catch(error => console.error('Error loading NOAs:', error));
}

function handleNOASelection(e) {
    const selectedOption = e.target.options[e.target.selectedIndex];
    
    if (selectedOption.value) {
        selectedNOA = JSON.parse(selectedOption.dataset.noa);
        displayNOADetails(selectedNOA);
    } else {
        selectedNOA = null;
        document.getElementById('noaDetailsDisplay').classList.add('hidden');
    }
}

function displayNOADetails(noa) {
    document.getElementById('displayNoaNumber').textContent = noa.noaNumber;
    document.getElementById('displayBlNumber').textContent = noa.blNumber;
    document.getElementById('displayVesselNumber').textContent = noa.vesselNumber;
    document.getElementById('displayEta').textContent = noa.eta;
    document.getElementById('displayCyLocation').textContent = noa.cyLocation;
    document.getElementById('displayContainerCount').textContent = noa.containerCount;
    
    document.getElementById('noaDetailsDisplay').classList.remove('hidden');
}

function handleFileSelection(e) {
    const file = e.target.files[0];
    if (file) {
        document.getElementById('fileNameDisplay').textContent = `Selected: ${file.name} (${formatFileSize(file.size)})`;
    }
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

function handleFormSubmit(e) {
    e.preventDefault();
    
    // Clear previous errors
    clearErrors();
    
    // Validate
    if (!validateForm()) {
        return;
    }
    
    // Prepare form data
    const formData = new FormData();
    formData.append('noaId', document.getElementById('noaId').value);
    formData.append('blFile', document.getElementById('blFile').files[0]);
    formData.append('blNumberValidation', document.getElementById('blNumberValidation').value);
    
    // Submit
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';
    
    fetch('/manifest/create', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Manifest created successfully! ${data.edoCount} eDOs have been generated.`);
            window.location.href = '/broker/manifest/list';
        } else {
            alert('Error: ' + (data.error || 'Failed to create Manifest'));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Manifest';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while creating the Manifest');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Manifest';
    });
}

function validateForm() {
    let isValid = true;
    
    const noaId = document.getElementById('noaId').value;
    if (!noaId) {
        showError('noaIdError', 'NOA selection is required');
        isValid = false;
    }
    
    const blFile = document.getElementById('blFile').files[0];
    if (!blFile) {
        showError('blFileError', 'BL file is required');
        isValid = false;
    } else if (blFile.size > 10 * 1024 * 1024) {
        showError('blFileError', 'File size must not exceed 10MB');
        isValid = false;
    }
    
    const blNumberValidation = document.getElementById('blNumberValidation').value;
    if (!blNumberValidation) {
        showError('blNumberValidationError', 'BL number is required for validation');
        isValid = false;
    } else if (selectedNOA && blNumberValidation !== selectedNOA.blNumber) {
        showError('blNumberValidationError', `BL number does not match NOA BL number (${selectedNOA.blNumber})`);
        isValid = false;
    }
    
    return isValid;
}

function showError(elementId, message) {
    const errorElement = document.getElementById(elementId);
    errorElement.textContent = message;
    errorElement.classList.remove('hidden');
}

function clearErrors() {
    const errorElements = document.querySelectorAll('[id$="Error"]');
    errorElements.forEach(element => {
        element.textContent = '';
        element.classList.add('hidden');
    });
}
