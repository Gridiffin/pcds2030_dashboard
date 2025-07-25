/**
 * Outcomes Chart
 * 
 * JavaScript for visualizing outcomes data in chart format.
 * Used by both admin and agency views to provide consistent chart displays.
 * @deprecated Use outcomes-chart.js instead
 */

// Load the new outcomes-chart.js script dynamically
(function loadOutcomesChart() {
    const script = document.createElement('script');
    script.src = '/pcds2030_dashboard/assets/js/charts/outcomes-chart.js';
    script.onload = function() {
        console.log('Outcomes chart script loaded successfully');
    };
    script.onerror = function() {
        console.error('Failed to load outcomes chart script, using metrics chart as fallback');
    };
    document.head.appendChild(script);
})();

let metricsChart = null;
let chartData = null;
let chartMonths = null;
let isCumulative = false; // Flag for cumulative display
let chartOptions = {
    type: 'line',
    metrics: []
};

/**
 * Initialize outcomes chart with data
 * 
 * @param {Object} metricsData Full outcomes data structure
 * @param {Array} tableData Array of month data objects
 * @param {Array} monthNames Array of month names
 * @param {String} tableName Name of the outcome table
 * @deprecated Use initOutcomesChart from outcomes-chart.js instead
 */
function initMetricsChart(metricsData, tableData, monthNames, tableName) {
    // Try to use the new outcomes chart if available
    if (typeof initOutcomesChart === 'function') {
        return initOutcomesChart(metricsData, tableData, monthNames, tableName);
    }

    // Fallback to old implementation
    console.log('Using legacy metrics chart');

    // Save data globally
    chartData = metricsData;
    chartMonths = monthNames;
    // Set default outcomes to display (all of them)
    chartOptions.metrics = metricsData.columns || [];

    // Get select elements
    const chartTypeSelect = document.getElementById('chartType');
    const metricsSelect = document.getElementById('metricToChart');
    const cumulativeToggle = document.getElementById('cumulativeToggle');

    // Set up event listeners
    if (chartTypeSelect) {
        chartTypeSelect.addEventListener('change', function() {
            chartOptions.type = this.value;
            updateChart();
        });
    }

    if (metricsSelect) {
        metricsSelect.addEventListener('change', function() {
            chartOptions.metrics = Array.from(this.selectedOptions).map(option => option.value);
            updateChart();
        });
    }

    if (cumulativeToggle) {
        cumulativeToggle.addEventListener('change', function() {
            isCumulative = this.checked;
            updateChart();
        });
    }

    // Setup download buttons
    const downloadChartBtn = document.getElementById('downloadChartImage');
    if (downloadChartBtn) {
        downloadChartBtn.addEventListener('click', downloadChartAsImage);
    }

    const downloadDataBtn = document.getElementById('downloadDataCSV');
    if (downloadDataBtn) {
        downloadDataBtn.addEventListener('click', function() {
            downloadDataAsCSV(tableData, chartOptions.metrics, tableName);
        });
    }

    // Create initial chart
    updateChart();
}

/**
 * Update chart based on current options
 */
function updateChart() {
    const ctx = document.getElementById('metricChart').getContext('2d');

    // Destroy existing chart if it exists
    if (metricsChart) {
        metricsChart.destroy();
    }

    // Prepare datasets
    const datasets = [];
    const colors = [
        'rgba(75, 192, 192, 1)',  // Teal
        'rgba(54, 162, 235, 1)',  // Blue
        'rgba(255, 99, 132, 1)',  // Red/Pink
        'rgba(255, 159, 64, 1)',  // Orange
        'rgba(153, 102, 255, 1)', // Purple
        'rgba(255, 205, 86, 1)',  // Yellow
        'rgba(201, 203, 207, 1)', // Grey
        'rgba(0, 150, 136, 1)',   // Teal
        'rgba(233, 30, 99, 1)',   // Pink
        'rgba(156, 39, 176, 1)'   // Purple
    ];

    // Create a dataset for each selected metric
    chartOptions.metrics.forEach((metric, index) => {
        let data = chartMonths.map(month => {
            return chartData.data[month] && chartData.data[month][metric]
                ? parseFloat(chartData.data[month][metric])
                : 0;
        });

        // If cumulative mode is enabled, transform data to cumulative sums
        if (isCumulative) {
            for (let i = 1; i < data.length; i++) {
                data[i] += data[i - 1];
            }
        }

        // Unit string for the metric (if available)
        const unitString = chartData.units && chartData.units[metric] 
            ? ` (${chartData.units[metric]})` 
            : '';

        datasets.push({
            label: metric + unitString,
            data: data,
            borderColor: colors[index % colors.length],
            backgroundColor: colors[index % colors.length].replace('1)', '0.2)'),
            borderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: chartOptions.type === 'radar' ? true : false,
            tension: 0.1 // Slight curve in line charts
        });
    });

    // Create chart config
    const config = {
        type: chartOptions.type,
        data: {
            labels: chartMonths,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: 'Monthly Metrics',
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: {
                        top: 10,
                        bottom: 20
                    }
                },
                legend: {
                    position: 'top',
                    align: 'center',
                    labels: {
                        boxWidth: 12,
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.7)',
                    titleFont: {
                        size: 13
                    },
                    bodyFont: {
                        size: 12
                    },
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('en-US', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: chartOptions.type === 'radar' ? {} : {
                x: {
                    title: {
                        display: true,
                        text: 'Month',
                        font: {
                            weight: 'bold'
                        },
                        padding: {
                            top: 10
                        }
                    },
                    grid: {
                        display: true,
                        drawBorder: true,
                        drawOnChartArea: true,
                        drawTicks: true,
                        color: 'rgba(0, 0, 0, 0.1)'
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Value',
                        font: {
                            weight: 'bold'
                        },
                        padding: {
                            bottom: 10
                        }
                    },
                    grid: {
                        display: true,
                        drawBorder: true,
                        drawOnChartArea: true,
                        drawTicks: true,
                        color: 'rgba(0, 0, 0, 0.1)'
                    },
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US', {
                                minimumFractionDigits: 0,
                                maximumFractionDigits: 2
                            }).format(value);
                        }
                    }
                }
            }
        }
    };

    // Create new chart
    metricsChart = new Chart(ctx, config);
}

/**
 * Download chart as an image
 */
function downloadChartAsImage() {
    if (!metricsChart) return;
    
    const canvas = document.getElementById('metricChart');
    const image = canvas.toDataURL('image/png', 1.0);
    
    // Create download link
    const downloadLink = document.createElement('a');
    downloadLink.href = image;
    downloadLink.download = 'metric_chart_' + new Date().toISOString().slice(0, 10) + '.png';
    
    // Trigger download
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

/**
 * Download data as CSV
 * 
 * @param {Array} tableData Table data array
 * @param {Array} metrics Array of metric names
 * @param {String} tableName Name of the metric table
 */
function downloadDataAsCSV(tableData, metrics, tableName) {
    if (!tableData || !tableData.length || !metrics || !metrics.length) return;
    
    // Create CSV header
    let csvContent = 'Month,' + metrics.join(',') + '\n';
    
    // Add data rows
    tableData.forEach(row => {
        let rowData = [row.month_name];
        
        // Add each metric value
        metrics.forEach(metricName => {
            const value = row.metrics[metricName] !== undefined ? row.metrics[metricName] : '';
            rowData.push(value);
        });
        
        csvContent += rowData.join(',') + '\n';
    });
    
    // Create download link
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const downloadLink = document.createElement('a');
    downloadLink.href = url;
    downloadLink.setAttribute('download', `${tableName.replace(/\s+/g, '_').toLowerCase()}_data.csv`);
    
    // Trigger download
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
    URL.revokeObjectURL(url);
}
