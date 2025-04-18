/**
 * Agency Dashboard Chart
 * Enhanced chart visualization for the agency dashboard
 */

// Wrap everything in an IIFE to avoid global class declaration
(function() {
    // Private class not exposed to global scope
    class ChartManager {
        constructor(chartId, legendId) {
            this.chartId = chartId;
            this.legendId = legendId;
            this.chart = null;
            this.data = null;
            this.initialAnimation = true;
            console.log("ChartManager created for", chartId);
        }

        /**
         * Initialize the chart with data
         * @param {Object} data - Chart data containing values and colors
         */
        init(data) {
            console.log("ChartManager.init called with data:", data);
            this.data = data;
            
            // Verify data integrity
            if (!data || !data.data || !Array.isArray(data.data)) {
                console.error("Invalid chart data format:", data);
                return this;
            }
            
            // Check if we have data for this period
            const containerElement = document.getElementById(this.chartId).parentElement;
            const legendElement = document.getElementById(this.legendId);
            
            // Handle no-data scenario - display message instead of empty chart
            if (data.hasPeriodData === false) {
                // Create a message element for no data
                this.showNoDataMessage(containerElement);
                
                // Hide the legend when there's no data
                if (legendElement) {
                    legendElement.style.display = 'none';
                }
                
                return this;
            } else {
                // Make sure previous no-data message is removed if it exists
                const noDataMsg = containerElement.querySelector('.no-data-message');
                if (noDataMsg) {
                    noDataMsg.remove();
                }
                
                // Show the legend again
                if (legendElement) {
                    legendElement.style.display = '';
                }
                
                // Create chart
                this.createChart();
                
                // Set up legend interactivity
                this.setupLegend();
            }
            
            return this;
        }
        
        /**
         * Show a message when no data is available for the period
         * @param {HTMLElement} container - The container element
         */
        showNoDataMessage(container) {
            // Check if no data message already exists
            if (container.querySelector('.no-data-message')) {
                return;
            }
            
            // Create a message with styling - improved for better vertical centering
            const message = document.createElement('div');
            message.className = 'no-data-message d-flex justify-content-center align-items-center';
            message.style.position = 'absolute';
            message.style.top = '0';
            message.style.left = '0';
            message.style.width = '100%';
            message.style.height = '100%';
            message.innerHTML = `
                <div class="text-center text-muted">
                    <i class="fas fa-chart-pie fa-3x mb-3"></i>
                    <p class="mb-1">No program data available for this period</p>
                    <p class="small">Select a different reporting period or create new programs</p>
                </div>
            `;
            
            // Clear any existing chart
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }
            
            // Make sure container has position relative for absolute positioning
            if (window.getComputedStyle(container).position === 'static') {
                container.style.position = 'relative';
            }
            
            // Add message to container
            container.appendChild(message);
        }

        /**
         * Create the chart instance
         */
        createChart() {
            const ctx = document.getElementById(this.chartId);
            if (!ctx) {
                console.error(`Canvas element with ID ${this.chartId} not found`);
                return;
            }
            
            console.log("Creating chart with data:", this.data.data);
            
            // Check for zero data - show empty message if all values are 0
            const hasData = this.data.data.some(value => value > 0);
            if (!hasData) {
                console.warn("All chart data values are zero");
                // Create a message element
                const container = ctx.parentElement;
                const message = document.createElement('div');
                message.className = 'text-center py-4 text-muted';
                message.innerHTML = '<i class="fas fa-chart-pie fa-3x mb-3"></i><p>No program data available</p>';
                container.appendChild(message);
                
                // Still render empty chart
            }
            
            // Check for and destroy existing chart instance
            const existingChart = Chart.getChart(ctx);
            if (existingChart) {
                console.log("Destroying existing chart instance");
                existingChart.destroy();
            }
            
            // Chart animation options
            const animationOptions = this.initialAnimation ? {
                animateScale: true,
                animateRotate: true
            } : false;
            
            // Create chart with correct status colors
            this.chart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['On Track', 'Delayed', 'Monthly Target Achieved', 'Not Started'],
                    datasets: [{
                        data: this.data.data,
                        // Updated colors to match correct status colors
                        backgroundColor: [
                            '#ffc107', // on-track (yellow)
                            '#dc3545', // delayed (red)
                            '#28a745', // completed/monthly target achieved (green)
                            '#6c757d'  // not-started (grey)
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverBorderWidth: 4,
                        hoverBorderColor: '#ffffff',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: animationOptions,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                title: (tooltipItems) => {
                                    return tooltipItems[0].label;
                                },
                                label: (tooltipItem) => {
                                    const value = tooltipItem.raw;
                                    const total = tooltipItem.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${value} programs (${percentage}%)`;
                                }
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 13
                            },
                            bodySpacing: 8,
                            boxPadding: 6
                        }
                    },
                    cutout: '70%',
                    elements: {
                        arc: {
                            borderWidth: 0
                        }
                    }
                }
            });
            
            console.log("Chart created successfully");
            
            // After first render, disable initial animation
            this.initialAnimation = false;
        }
        
        /**
         * Set up interactive legend
         */
        setupLegend() {
            const legendElement = document.getElementById(this.legendId);
            if (!legendElement) return;
            
            // Get all legend items
            const legendItems = legendElement.querySelectorAll('.chart-legend-item');
            
            legendItems.forEach((item, index) => {
                // Add click event to toggle visibility
                item.addEventListener('click', () => {
                    // Toggle visibility in the chart
                    const meta = this.chart.getDatasetMeta(0);
                    const dataVisible = meta.data[index].hidden ? true : false;
                    meta.data[index].hidden = !dataVisible;
                    
                    // Update legend item style
                    if (!dataVisible) {
                        item.classList.add('disabled');
                    } else {
                        item.classList.remove('disabled');
                    }
                    
                    // Update chart
                    this.chart.update();
                });
                
                // Add hover effect to highlight corresponding chart section
                item.addEventListener('mouseenter', () => {
                    // Add active class to current legend item
                    item.classList.add('active');
                    
                    // Set active elements in chart
                    this.chart.setActiveElements([{datasetIndex: 0, index: index}]);
                    this.chart.update();
                });
                
                item.addEventListener('mouseleave', () => {
                    // Remove active class
                    item.classList.remove('active');
                    
                    this.chart.setActiveElements([]);
                    this.chart.update();
                });
            });
        }
        
        /**
         * Update chart with new data
         * @param {Object} newData - New chart data
         */
        update(newData) {
            if (!this.chart) return;
            
            this.data = newData;
            this.chart.data.datasets[0].data = newData.data;
            this.chart.update();
        }
    }

    /**
     * Initialize the dashboard chart with program status data
     */
    function initializeDashboardChart(chartData) {
        // Check if we have a chart container
        const chartContainer = document.getElementById('programStatusChart');
        if (!chartContainer) return;
        
        // Define colors based on status meaning
        const chartColors = {
            onTrack: '#ffc107',       // Yellow - Still on track for the year
            delayed: '#dc3545',       // Red - Delayed
            completed: '#28a745',     // Green - Monthly target achieved
            notStarted: '#6c757d'     // Gray - Not started
        };
        
        // Setup data for the chart
        const data = {
            labels: ['On Track', 'Delayed', 'Target Achieved', 'Not Started'],
            datasets: [{
                data: chartData.data,
                backgroundColor: [
                    chartColors.onTrack,
                    chartColors.delayed,
                    chartColors.completed,
                    chartColors.notStarted
                ],
                borderWidth: 1,
                borderColor: '#ffffff'
            }]
        };

        // ...existing code...
    }

    // Only expose the initialization function to global scope
    window.initializeDashboardChart = function(chartData) {
        console.log("initializeDashboardChart called with data:", chartData);
        const chartInstance = new ChartManager('programStatusChart', 'programStatusLegend');
        chartInstance.init(chartData);
        
        // Make chart available globally for updates
        window.dashboardChart = chartInstance;
        return chartInstance;
    };
})();
