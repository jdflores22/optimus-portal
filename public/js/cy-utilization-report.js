/**
 * CY Utilization Report JavaScript
 * 
 * Requirements: 12.1, 12.2, 12.3, 12.4, 10.1, 10.2, 10.3, 10.4, 10.5, 10.6
 */

let utilizationTrendsChart = null;
let allocationBreakdownChart = null;
let currentReportData = null;
let currentFilters = {
    containerSize: 'both',
    sortBy: 'terminal'
};

document.addEventListener('DOMContentLoaded', function() {
    // Set default date range (last 30 days)
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(startDate.getDate() - 30);
    
    document.getElementById('startDate').valueAsDate = startDate;
    document.getElementById('endDate').valueAsDate = endDate;

    // Form submission
    document.getElementById('reportFiltersForm').addEventListener('submit', function(e) {
        e.preventDefault();
        generateReport();
    });

    // Container size filter change
    document.getElementById('containerSizeFilter').addEventListener('change', function() {
        currentFilters.containerSize = this.value;
        if (currentReportData) {
            displayReport(currentReportData);
        }
    });

    // Sort by change
    document.getElementById('sortBy').addEventListener('change', function() {
        currentFilters.sortBy = this.value;
        if (currentReportData) {
            displayReport(currentReportData);
        }
    });

    // Reset filters
    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('reportFiltersForm').reset();
        document.getElementById('startDate').valueAsDate = startDate;
        document.getElementById('endDate').valueAsDate = endDate;
        currentFilters.containerSize = 'both';
        currentFilters.sortBy = 'terminal';
        hideReportCards();
    });

    // Export buttons
    document.getElementById('exportCsv').addEventListener('click', function() {
        exportReport('csv');
    });

    document.getElementById('exportPdf').addEventListener('click', function() {
        exportReport('pdf');
    });
});

/**
 * Generate report with current filters
 */
function generateReport() {
    const formData = new FormData(document.getElementById('reportFiltersForm'));
    const params = new URLSearchParams(formData);

    showLoading();

    fetch(`/admin/reports/cy-utilization/data?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                currentReportData = data.data;
                displayReport(data.data);
                document.getElementById('exportButtons').style.display = 'flex';
            } else {
                alert('Error generating report: ' + data.message);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('An error occurred while generating the report');
        });
}

/**
 * Display report data
 */
function displayReport(reportData) {
    // Show all report cards
    document.getElementById('trendsCard').style.display = 'block';
    document.getElementById('breakdownCard').style.display = 'block';
    document.getElementById('statusCard').style.display = 'block';
    document.getElementById('locationCard').style.display = 'block';

    // Display utilization trends chart
    displayUtilizationTrends(reportData.utilization_trends);

    // Display allocation breakdown chart
    displayAllocationBreakdown(reportData.utilization_by_location);

    // Display status breakdown
    displayStatusBreakdown(reportData.status_breakdown);

    // Display utilization table
    displayUtilizationTable(reportData.utilization_by_location);
}

/**
 * Display utilization trends line chart
 * 
 * Requirements: 12.3
 */
function displayUtilizationTrends(trendsData) {
    const dates = trendsData.map(item => item.date);
    const allocatedTeu = trendsData.map(item => item.allocated_teu);

    const options = {
        series: [{
            name: 'Allocated TEU',
            data: allocatedTeu
        }],
        chart: {
            type: 'line',
            height: 350,
            toolbar: {
                show: true
            }
        },
        stroke: {
            curve: 'smooth',
            width: 3
        },
        xaxis: {
            categories: dates,
            title: {
                text: 'Date'
            }
        },
        yaxis: {
            title: {
                text: 'Allocated TEU'
            }
        },
        colors: ['#008FFB'],
        markers: {
            size: 4
        },
        tooltip: {
            y: {
                formatter: function(value) {
                    return value.toFixed(1) + ' TEU';
                }
            }
        }
    };

    if (utilizationTrendsChart) {
        utilizationTrendsChart.destroy();
    }

    utilizationTrendsChart = new ApexCharts(
        document.querySelector("#utilizationTrendsChart"),
        options
    );
    utilizationTrendsChart.render();
}

/**
 * Display allocation breakdown stacked bar chart
 * 
 * Requirements: 12.3
 */
function displayAllocationBreakdown(locationData) {
    const terminals = locationData.map(item => 
        `${item.terminal_name} (${item.shipping_line_name})`
    );
    const allocatedTeu = locationData.map(item => item.allocated_teu);
    const availableTeu = locationData.map(item => item.available_teu);

    const options = {
        series: [
            {
                name: 'Allocated TEU',
                data: allocatedTeu
            },
            {
                name: 'Available TEU',
                data: availableTeu
            }
        ],
        chart: {
            type: 'bar',
            height: 400,
            stacked: true,
            toolbar: {
                show: true
            }
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '55%'
            }
        },
        xaxis: {
            categories: terminals,
            labels: {
                rotate: -45,
                rotateAlways: true
            }
        },
        yaxis: {
            title: {
                text: 'TEU'
            }
        },
        colors: ['#00E396', '#FEB019'],
        legend: {
            position: 'top'
        },
        tooltip: {
            y: {
                formatter: function(value) {
                    return value.toFixed(1) + ' TEU';
                }
            }
        }
    };

    if (allocationBreakdownChart) {
        allocationBreakdownChart.destroy();
    }

    allocationBreakdownChart = new ApexCharts(
        document.querySelector("#allocationBreakdownChart"),
        options
    );
    allocationBreakdownChart.render();
}

/**
 * Display status breakdown
 * 
 * Requirements: 12.5
 */
function displayStatusBreakdown(statusData) {
    document.getElementById('preForecastCount').textContent = statusData.pre_forecast.count;
    document.getElementById('preForecastTeu').textContent = statusData.pre_forecast.teu.toFixed(1);
    document.getElementById('allocatedCount').textContent = statusData.allocated.count;
    document.getElementById('allocatedTeu').textContent = statusData.allocated.teu.toFixed(1);
}

/**
 * Display utilization table with size-specific columns
 * 
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5
 */
function displayUtilizationTable(locationData) {
    const tbody = document.getElementById('utilizationTableBody');
    tbody.innerHTML = '';

    // Clone data to avoid mutating original
    let filteredData = [...locationData];

    // Apply sorting
    filteredData = applySorting(filteredData, currentFilters.sortBy);

    filteredData.forEach(location => {
        const row = document.createElement('tr');
        
        // Determine row color based on utilization
        let rowClass = '';
        let maxUtilization = location.utilization_percentage;
        
        // Consider size-specific utilization based on filter
        if (currentFilters.containerSize === '20ft') {
            maxUtilization = location.utilization_percentage_20ft;
        } else if (currentFilters.containerSize === '40ft') {
            maxUtilization = location.utilization_percentage_40ft;
        } else {
            // For 'both', use the maximum utilization
            maxUtilization = Math.max(
                location.utilization_percentage_20ft,
                location.utilization_percentage_40ft
            );
        }
        
        if (maxUtilization >= 90) {
            rowClass = 'table-danger';
        } else if (maxUtilization >= 70) {
            rowClass = 'table-warning';
        } else {
            rowClass = 'table-success';
        }
        row.className = rowClass;

        // Build row HTML based on container size filter
        let rowHTML = `
            <td>${location.terminal_name}</td>
            <td>${location.terminal_location}</td>
            <td>${location.shipping_line_name}</td>
        `;

        // TEU-based columns (always shown for backward compatibility)
        rowHTML += `
            <td>${location.total_capacity_teu.toFixed(1)}</td>
            <td>${location.allocated_teu.toFixed(1)}</td>
            <td>${location.utilization_percentage.toFixed(2)}%</td>
        `;

        // 20ft columns (show based on filter)
        if (currentFilters.containerSize === 'both' || currentFilters.containerSize === '20ft') {
            rowHTML += `
                <td>${location.capacity_20ft}</td>
                <td>${location.allocated_20ft}</td>
                <td>${location.available_20ft}</td>
                <td>${location.utilization_percentage_20ft.toFixed(2)}%</td>
            `;
        }

        // 40ft columns (show based on filter)
        if (currentFilters.containerSize === 'both' || currentFilters.containerSize === '40ft') {
            rowHTML += `
                <td>${location.capacity_40ft}</td>
                <td>${location.allocated_40ft}</td>
                <td>${location.available_40ft}</td>
                <td>${location.utilization_percentage_40ft.toFixed(2)}%</td>
            `;
        }

        row.innerHTML = rowHTML;
        tbody.appendChild(row);
    });

    // Update table header visibility based on filter
    updateTableHeaderVisibility();
}

/**
 * Apply sorting to location data
 * 
 * Requirements: 10.5
 */
function applySorting(data, sortBy) {
    const sorted = [...data];
    
    switch (sortBy) {
        case 'terminal':
            sorted.sort((a, b) => a.terminal_name.localeCompare(b.terminal_name));
            break;
        case 'utilization_teu':
            sorted.sort((a, b) => b.utilization_percentage - a.utilization_percentage);
            break;
        case 'utilization_20ft':
            sorted.sort((a, b) => b.utilization_percentage_20ft - a.utilization_percentage_20ft);
            break;
        case 'utilization_40ft':
            sorted.sort((a, b) => b.utilization_percentage_40ft - a.utilization_percentage_40ft);
            break;
    }
    
    return sorted;
}

/**
 * Update table header visibility based on container size filter
 * 
 * Requirements: 10.4
 */
function updateTableHeaderVisibility() {
    const table = document.getElementById('utilizationTable');
    const headerRows = table.querySelectorAll('thead tr');
    
    if (headerRows.length < 2) return;
    
    const secondHeaderRow = headerRows[1];
    const cells = secondHeaderRow.querySelectorAll('th');
    
    // TEU columns (indices 0-2) always visible
    // 20ft columns (indices 3-6)
    // 40ft columns (indices 7-10)
    
    if (currentFilters.containerSize === '20ft') {
        // Hide 40ft columns
        for (let i = 7; i <= 10; i++) {
            if (cells[i]) cells[i].style.display = 'none';
        }
        // Show 20ft columns
        for (let i = 3; i <= 6; i++) {
            if (cells[i]) cells[i].style.display = '';
        }
    } else if (currentFilters.containerSize === '40ft') {
        // Hide 20ft columns
        for (let i = 3; i <= 6; i++) {
            if (cells[i]) cells[i].style.display = 'none';
        }
        // Show 40ft columns
        for (let i = 7; i <= 10; i++) {
            if (cells[i]) cells[i].style.display = '';
        }
    } else {
        // Show all columns
        cells.forEach(cell => cell.style.display = '');
    }
    
    // Update first header row colspan
    const firstHeaderRow = headerRows[0];
    const headerCells = firstHeaderRow.querySelectorAll('th');
    
    if (headerCells.length >= 6) {
        // 20ft header
        if (currentFilters.containerSize === '40ft') {
            headerCells[3].style.display = 'none';
        } else {
            headerCells[3].style.display = '';
        }
        
        // 40ft header
        if (currentFilters.containerSize === '20ft') {
            headerCells[4].style.display = 'none';
        } else {
            headerCells[4].style.display = '';
        }
    }
}

/**
 * Export report to CSV or PDF
 * 
 * Requirements: 12.4
 */
function exportReport(format) {
    const formData = new FormData(document.getElementById('reportFiltersForm'));
    const params = new URLSearchParams(formData);

    showLoading();

    const url = `/admin/reports/cy-utilization/export/${format}?${params.toString()}`;
    
    // Create a temporary link and trigger download
    const link = document.createElement('a');
    link.href = url;
    link.download = `cy_utilization_report.${format}`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    // Hide loading after a short delay
    setTimeout(() => {
        hideLoading();
    }, 1000);
}

/**
 * Show loading overlay
 */
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('active');
}

/**
 * Hide loading overlay
 */
function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('active');
}

/**
 * Hide all report cards
 */
function hideReportCards() {
    document.getElementById('trendsCard').style.display = 'none';
    document.getElementById('breakdownCard').style.display = 'none';
    document.getElementById('statusCard').style.display = 'none';
    document.getElementById('locationCard').style.display = 'none';
    document.getElementById('exportButtons').style.display = 'none';
}
