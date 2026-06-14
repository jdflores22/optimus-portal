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
    sortBy: 'terminal',
};

document.addEventListener('DOMContentLoaded', function () {
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(startDate.getDate() - 30);

    const startEl = document.getElementById('startDate');
    const endEl = document.getElementById('endDate');
    if (startEl && !startEl.value) startEl.valueAsDate = startDate;
    if (endEl && !endEl.value) endEl.valueAsDate = endDate;

    document.getElementById('reportFiltersForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        generateReport();
    });

    document.getElementById('containerSizeFilter')?.addEventListener('change', function () {
        currentFilters.containerSize = this.value;
        if (currentReportData) {
            displayReport(currentReportData);
        }
    });

    document.getElementById('sortBy')?.addEventListener('change', function () {
        currentFilters.sortBy = this.value;
        if (currentReportData) {
            displayReport(currentReportData);
        }
    });

    document.getElementById('resetFilters')?.addEventListener('click', function () {
        document.getElementById('reportFiltersForm')?.reset();
        if (startEl) startEl.valueAsDate = startDate;
        if (endEl) endEl.valueAsDate = endDate;
        currentFilters.containerSize = 'both';
        currentFilters.sortBy = 'terminal';
        hideReportCards();
    });

    document.getElementById('exportCsv')?.addEventListener('click', () => exportReport('csv'));
    document.getElementById('exportPdf')?.addEventListener('click', () => exportReport('pdf'));
});

function generateReport() {
    const formData = new FormData(document.getElementById('reportFiltersForm'));
    const params = new URLSearchParams(formData);

    showLoading();

    fetch(`/admin/reports/port-utilization/data?${params.toString()}`)
        .then(async response => {
            const data = await response.json().catch(() => null);
            hideLoading();

            if (!data) {
                alert(`Server returned an invalid response (HTTP ${response.status}). Check the console for details.`);
                return;
            }

            if (data.success) {
                currentReportData = data.data;
                displayReport(data.data);
                document.getElementById('emptyState')?.classList.add('hidden');
                document.getElementById('exportButtons')?.classList.remove('hidden');
                document.getElementById('exportButtons')?.classList.add('flex');
            } else {
                alert('Error generating report: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('An error occurred while generating the report: ' + error.message);
        });
}

function displayReport(reportData) {
    ['trendsCard', 'breakdownCard', 'statusCard', 'locationCard'].forEach(id => {
        document.getElementById(id)?.classList.remove('hidden');
    });

    displayUtilizationTrends(reportData.utilization_trends);
    displayAllocationBreakdown(reportData.utilization_by_location);
    displayStatusBreakdown(reportData.status_breakdown);
    displayUtilizationTable(reportData.utilization_by_location);
}

function displayUtilizationTrends(trendsData) {
    const dates = trendsData.map(item => item.date);
    const allocatedTeu = trendsData.map(item => item.allocated_teu);

    const options = {
        series: [{ name: 'Projected TEU', data: allocatedTeu }],
        chart: { type: 'line', height: 350, toolbar: { show: true }, fontFamily: 'inherit' },
        stroke: { curve: 'smooth', width: 3 },
        xaxis: { categories: dates, title: { text: 'Date' } },
        yaxis: { title: { text: 'Projected TEU' } },
        colors: ['#570df8'],
        markers: { size: 4 },
        tooltip: { y: { formatter: value => value.toFixed(1) + ' TEU' } },
    };

    if (utilizationTrendsChart) {
        utilizationTrendsChart.destroy();
    }

    utilizationTrendsChart = new ApexCharts(
        document.querySelector('#utilizationTrendsChart'),
        options
    );
    utilizationTrendsChart.render();
}

function displayAllocationBreakdown(locationData) {
    const terminals = locationData.map(item =>
        `${item.terminal_name} (${item.shipping_line_name})`
    );
    const allocatedTeu = locationData.map(item => item.allocated_teu);
    const availableTeu = locationData.map(item => item.available_teu);

    const options = {
        series: [
            { name: 'Projected TEU', data: allocatedTeu },
            { name: 'Available TEU', data: availableTeu },
        ],
        chart: { type: 'bar', height: 400, stacked: true, toolbar: { show: true }, fontFamily: 'inherit' },
        plotOptions: { bar: { horizontal: false, columnWidth: '55%' } },
        xaxis: {
            categories: terminals,
            labels: { rotate: -45, rotateAlways: true },
        },
        yaxis: { title: { text: 'TEU' } },
        colors: ['#36d399', '#fbbd23'],
        legend: { position: 'top' },
        tooltip: { y: { formatter: value => value.toFixed(1) + ' TEU' } },
    };

    if (allocationBreakdownChart) {
        allocationBreakdownChart.destroy();
    }

    allocationBreakdownChart = new ApexCharts(
        document.querySelector('#allocationBreakdownChart'),
        options
    );
    allocationBreakdownChart.render();
}

function displayStatusBreakdown(statusData) {
    document.getElementById('preForecastCount').textContent = statusData.pre_forecast.count;
    document.getElementById('preForecastTeu').textContent = statusData.pre_forecast.teu.toFixed(1);
    document.getElementById('allocatedCount').textContent = statusData.allocated.count;
    document.getElementById('allocatedTeu').textContent = statusData.allocated.teu.toFixed(1);
    const pendingCount = document.getElementById('pendingCount');
    const pendingTeu = document.getElementById('pendingTeu');
    if (pendingCount && statusData.pending) {
        pendingCount.textContent = statusData.pending.count;
        pendingTeu.textContent = statusData.pending.teu.toFixed(1);
    }
}

function displayUtilizationTable(locationData) {
    const tbody = document.getElementById('utilizationTableBody');
    tbody.innerHTML = '';

    let filteredData = applySorting([...locationData], currentFilters.sortBy);

    filteredData.forEach(location => {
        const row = document.createElement('tr');
        const maxUtilization = location.utilization_percentage;

        if (maxUtilization >= 90) {
            row.className = 'bg-error/10';
        } else if (maxUtilization >= 70) {
            row.className = 'bg-warning/10';
        } else {
            row.className = 'bg-success/10';
        }

        const show20 = currentFilters.containerSize === 'both' || currentFilters.containerSize === '20ft';
        const show40 = currentFilters.containerSize === 'both' || currentFilters.containerSize === '40ft';

        let rowHTML = `
            <td class="font-medium">${escapeHtml(location.terminal_name)}</td>
            <td><span class="badge badge-ghost badge-sm">${escapeHtml(location.terminal_code ?? '')}</span></td>
            <td>${escapeHtml(location.terminal_location ?? '')}</td>
            <td>${escapeHtml(location.shipping_line_name)}</td>
            <td>${location.total_capacity_teu.toFixed(1)}</td>
            <td>${location.allocated_teu.toFixed(1)}</td>
            <td>${location.utilization_percentage.toFixed(2)}%</td>
        `;

        if (show20) {
            rowHTML += `<td>${location.allocated_20ft}</td><td>—</td>`;
        }
        if (show40) {
            rowHTML += `<td>${location.allocated_40ft}</td><td>—</td>`;
        }

        row.innerHTML = rowHTML;
        tbody.appendChild(row);
    });
}

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
            sorted.sort((a, b) => b.allocated_20ft - a.allocated_20ft);
            break;
        case 'utilization_40ft':
            sorted.sort((a, b) => b.allocated_40ft - a.allocated_40ft);
            break;
    }

    return sorted;
}

function updateTableHeaderVisibility() {
    const table = document.getElementById('utilizationTable');
    if (!table) return;

    const headerRows = table.querySelectorAll('thead tr');
    if (headerRows.length < 2) return;

    const secondHeaderRow = headerRows[1];
    const cells = secondHeaderRow.querySelectorAll('th');

    if (currentFilters.containerSize === '20ft') {
        for (let i = 7; i <= 10; i++) {
            if (cells[i]) cells[i].classList.add('hidden');
        }
        for (let i = 3; i <= 6; i++) {
            if (cells[i]) cells[i].classList.remove('hidden');
        }
    } else if (currentFilters.containerSize === '40ft') {
        for (let i = 3; i <= 6; i++) {
            if (cells[i]) cells[i].classList.add('hidden');
        }
        for (let i = 7; i <= 10; i++) {
            if (cells[i]) cells[i].classList.remove('hidden');
        }
    } else {
        cells.forEach(cell => cell.classList.remove('hidden'));
    }

    const firstHeaderRow = headerRows[0];
    const headerCells = firstHeaderRow.querySelectorAll('th');

    if (headerCells.length >= 6) {
        if (currentFilters.containerSize === '40ft') {
            headerCells[3].classList.add('hidden');
        } else {
            headerCells[3].classList.remove('hidden');
        }

        if (currentFilters.containerSize === '20ft') {
            headerCells[4].classList.add('hidden');
        } else {
            headerCells[4].classList.remove('hidden');
        }
    }
}

function exportReport(format) {
    const formData = new FormData(document.getElementById('reportFiltersForm'));
    const params = new URLSearchParams(formData);

    showLoading();

    const url = `/admin/reports/port-utilization/export/${format}?${params.toString()}`;
    const link = document.createElement('a');
    link.href = url;
    link.download = `port_utilization_report.${format}`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    setTimeout(hideLoading, 1000);
}

function showLoading() {
    document.getElementById('loadingOverlay')?.classList.remove('hidden');
}

function hideLoading() {
    document.getElementById('loadingOverlay')?.classList.add('hidden');
}

function hideReportCards() {
    ['trendsCard', 'breakdownCard', 'statusCard', 'locationCard'].forEach(id => {
        document.getElementById(id)?.classList.add('hidden');
    });
    document.getElementById('exportButtons')?.classList.add('hidden');
    document.getElementById('exportButtons')?.classList.remove('flex');
    document.getElementById('emptyState')?.classList.remove('hidden');
    currentReportData = null;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
