/**
 * Hybrid Status Grid Component
 * 
 * A responsive hybrid table-based status grid that combines HTML table structure 
 * with Bootstrap styling for optimal alignment, sticky headers, and performance.
 */

class StatusGrid {
    constructor(containerId, apiUrl) {
        this.containerId = containerId;
        this.apiUrl = apiUrl;
        this.container = document.getElementById(containerId);
        this.data = null;
        this.timeline = null;
        
        if (!this.container) {
            console.error(`StatusGrid: Container with ID '${containerId}' not found`);
            return;
        }
        
        this.init();
    }
    
    /**
     * Initialize the status grid
     */
    async init() {
        this.showLoading();
        
        try {
            await this.fetchData();
            this.generateTimeline();
            this.render();
        } catch (error) {
            console.error('StatusGrid initialization error:', error);
            this.showError('Failed to load status grid data');
        }
    }
    
    /**
     * Fetch data from API
     */
    async fetchData() {
        try {
            const response = await fetch(this.apiUrl);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'API returned unsuccessful response');
            }
            
            this.data = result.data;
            console.log('StatusGrid data loaded:', this.data);
            
            // Debug: Check if programs have targets with status_by_period
            if (this.data.programs && this.data.programs.length > 0) {
                console.log('First program structure:', this.data.programs[0]);
                if (this.data.programs[0].targets) {
                    console.log('First program targets:', this.data.programs[0].targets);
                    if (this.data.programs[0].targets[0]) {
                        console.log('First target structure:', this.data.programs[0].targets[0]);
                    }
                } else {
                    console.warn('Programs missing targets array!');
                }
            }
            
        } catch (error) {
            console.error('StatusGrid data fetch error:', error);
            throw error;
        }
    }
    
    /**
     * Generate timeline structure from initiative dates
     */
    generateTimeline() {
        // Use timeline data from API if available
        if (this.data && this.data.timeline) {
            this.timeline = {
                startYear: Math.min(...this.data.timeline.years),
                endYear: Math.max(...this.data.timeline.years),
                years: this.data.timeline.years,
                quarters: [],
                periods_map: this.data.timeline.periods_map || {}
            };
            
            // Generate quarters array from years
            this.data.timeline.years.forEach(year => {
                for (let quarter = 1; quarter <= 4; quarter++) {
                    this.timeline.quarters.push({
                        year,
                        quarter,
                        label: `Q${quarter}`,
                        id: `${year}-Q${quarter}`
                    });
                }
            });
            
            console.log('Timeline from API:', this.timeline);
            return;
        }
        
        // Fallback: Generate timeline from initiative dates or use defaults  
        if (!this.data || !this.data.initiative) {
            throw new Error('No initiative data available for timeline generation');
        }
        
        const startDate = new Date(this.data.initiative.start_date);
        const endDate = new Date(this.data.initiative.end_date);
        
        const startYear = startDate.getFullYear();
        const endYear = endDate.getFullYear();
        
        this.timeline = {
            startYear,
            endYear,
            years: [],
            quarters: [],
            periods_map: {}
        };
        
        // Generate years and quarters
        for (let year = startYear; year <= endYear; year++) {
            this.timeline.years.push(year);
            
            for (let quarter = 1; quarter <= 4; quarter++) {
                this.timeline.quarters.push({
                    year,
                    quarter,
                    label: `Q${quarter}`,
                    id: `${year}-Q${quarter}`
                });
            }
        }
        
        console.log('Generated timeline:', this.timeline);
    }
    
    /**
     * Render the complete hybrid status grid
     */
    render() {
        const html = `
            ${this.renderLegend()}
            ${this.renderHybridTable()}
        `;
        
        this.container.innerHTML = html;
        this.attachEventListeners();
    }
    
    /**
     * Render the hybrid table structure
     */
    renderHybridTable() {
        if (!this.timeline || !this.timeline.years) {
            return '<div class="status-grid-no-data">No timeline data available</div>';
        }
        
        return `
            <div class="table-responsive hybrid-status-grid">
                <table class="table table-bordered table-hover">
                    ${this.renderTableHeader()}
                    ${this.renderTableBody()}
                </table>
            </div>
        `;
    }
    
    /**
     * Render table header with two-tier structure
     */
    renderTableHeader() {
        const totalQuarters = this.timeline.years.length * 4;
        
        // First row: Main header + Years
        let yearRow = `
            <thead class="sticky-header">
                <tr class="year-header">
                    <th rowspan="2" class="sticky-left year-header-cell">
                        <strong>Programs & Targets</strong>
                    </th>
        `;
        
        // Add year columns with colspan=4 for quarters
        this.timeline.years.forEach(year => {
            yearRow += `<th colspan="4" class="text-center">${year}</th>`;
        });
        
        yearRow += '</tr>';
        
        // Second row: Quarters only (no left column cell)
        let quarterRow = '<tr class="quarter-header">';
        
        this.timeline.years.forEach(year => {
            for (let q = 1; q <= 4; q++) {
                quarterRow += `<th class="text-center">Q${q}</th>`;
            }
        });
        
        quarterRow += '</tr></thead>';
        
        return yearRow + quarterRow;
    }
    
    /**
     * Render table body with programs and targets
     */
    renderTableBody() {
        if (!this.data || !this.data.programs) {
            return `
                <tbody>
                    <tr>
                        <td colspan="${(this.timeline.years.length * 4) + 1}" class="text-center p-4 text-muted">
                            <i class="fas fa-info-circle me-2"></i>
                            No program data available
                        </td>
                    </tr>
                </tbody>
            `;
        }
        
        let html = '<tbody>';
        
        this.data.programs.forEach((program, programIndex) => {
            // Program row
            html += this.renderProgramRow(program, programIndex);
            
            // Target rows
            if (program.targets && program.targets.length > 0) {
                program.targets.forEach((target, targetIndex) => {
                    html += this.renderTargetRow(target, programIndex, targetIndex);
                });
            }
        });
        
        html += '</tbody>';
        return html;
    }
    
    /**
     * Render a program row
     */
    renderProgramRow(program, programIndex) {
        let row = `
            <tr class="program-row" data-program-id="${program.program_id || programIndex}">
                <td class="sticky-left program-cell">
                    <span class="program-badge">${program.program_number || `P${programIndex + 1}`}</span>
                    <strong>${this.escapeHtml(program.program_name || 'Unnamed Program')}</strong>
                </td>
        `;
        
        // Add empty status cells for program row (programs don't have status indicators)
        this.timeline.years.forEach(year => {
            for (let q = 1; q <= 4; q++) {
                row += '<td class="status-cell"></td>';
            }
        });
        
        row += '</tr>';
        return row;
    }
    
    /**
     * Render a target row
     */
    renderTargetRow(target, programIndex, targetIndex) {
        let row = `
            <tr class="target-row" data-target-id="${target.target_number || `${programIndex}-${targetIndex}`}">
                <td class="sticky-left target-cell">
                    <span class="target-badge">${target.target_number || `T${targetIndex + 1}`}</span>
                    ${this.escapeHtml(target.target_text || 'Unnamed Target')}
                </td>
        `;
        
        // Add status cells for each quarter
        this.timeline.years.forEach(year => {
            for (let q = 1; q <= 4; q++) {
                const quarter = { year, quarter: q, id: `${year}-Q${q}` };
                const status = this.getTargetStatusForQuarter(target, quarter);
                
                if (status) {
                    row += `
                        <td class="status-cell" data-quarter="${quarter.id}" data-target="${target.target_number || 'unknown'}">
                            <div class="status-indicator ${status.class}" title="${status.tooltip}"></div>
                        </td>
                    `;
                } else {
                    row += '<td class="status-cell"></td>';
                }
            }
        });
        
        row += '</tr>';
        return row;
    }
    
    
    /**
     * Render status legend with hybrid styling
     */
    renderLegend() {
        const statusTypes = [
            { key: 'not-started', label: 'Not Started', class: 'status-not-started' },
            { key: 'in-progress', label: 'In Progress', class: 'status-at-risk' },
            { key: 'completed', label: 'Completed', class: 'status-completed' },
            { key: 'delayed', label: 'Delayed', class: 'status-off-target' }
        ];
        
        return `
            <div class="hybrid-status-grid-legend">
                <small class="text-muted"><strong>Status Legend:</strong></small>
                <div class="d-flex flex-wrap gap-3 mt-2">
                    ${statusTypes.map(status => `
                        <div class="legend-item">
                            <div class="legend-indicator ${status.class}"></div>
                            <small>${status.label}</small>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    /**
     * Get target status for a specific quarter with hybrid CSS classes
     */
    getTargetStatusForQuarter(target, quarter) {
        console.log('Getting status for target:', target.target_text, 'quarter:', quarter);
        
        // Check if we have status data by period
        if (!target.status_by_period) {
            console.log('No status_by_period data for target:', target.target_text);
            return null;
        }
        
        console.log('Available status periods:', Object.keys(target.status_by_period));
        
        // Find the period ID for this year/quarter combination
        let targetPeriodId = null;
        if (this.timeline && this.timeline.periods_map) {
            console.log('Available periods_map:', this.timeline.periods_map);
            // Look through periods_map to find matching year/quarter
            for (const [periodId, periodInfo] of Object.entries(this.timeline.periods_map)) {
                // Handle both "Q1" format and number format for quarter matching
                const periodQuarter = periodInfo.quarter;
                const targetQuarter = quarter.quarter;
                
                const quarterMatch = (periodQuarter === `Q${targetQuarter}`) || 
                                   (periodQuarter === targetQuarter) ||
                                   (periodQuarter === `${targetQuarter}`);
                                   
                if (periodInfo.year === quarter.year && quarterMatch) {
                    targetPeriodId = periodId;
                    console.log(`Found period ${periodId} for ${quarter.year} Q${quarter.quarter}`);
                    break;
                }
            }
        }
        
        // If no specific period found, return null (no status to display)
        if (!targetPeriodId || !target.status_by_period[targetPeriodId]) {
            console.log(`No status found for period ${targetPeriodId} in quarter ${quarter.year} ${quarter.quarter}`);
            return null;
        }
        
        // Get the status for this period
        const rawStatus = target.status_by_period[targetPeriodId];
        console.log('Raw status found:', rawStatus, 'for period:', targetPeriodId);
        
        // Map the 4 valid status values to CSS classes and tooltips
        const statusMap = {
            'not started': { class: 'status-not-started', tooltip: 'Not Started' },
            'not_started': { class: 'status-not-started', tooltip: 'Not Started' },
            'not-started': { class: 'status-not-started', tooltip: 'Not Started' },
            'in progress': { class: 'status-at-risk', tooltip: 'In Progress' },
            'in_progress': { class: 'status-at-risk', tooltip: 'In Progress' },
            'in-progress': { class: 'status-at-risk', tooltip: 'In Progress' },
            'completed': { class: 'status-completed', tooltip: 'Completed' },
            'delayed': { class: 'status-off-target', tooltip: 'Delayed' }
        };
        
        // Normalize status string (lowercase, handle spaces)
        const normalizedStatus = String(rawStatus).toLowerCase().trim();
        console.log('Normalized status:', normalizedStatus);
        
        // Get status configuration or return null if no valid status
        const status = statusMap[normalizedStatus];
        
        if (!status) {
            console.log('No valid status mapping found for:', normalizedStatus);
            // No valid status found, don't display anything for this quarter
            return null;
        }
        
        console.log('Status mapped to:', status);
        
        return {
            class: status.class,
            tooltip: `${quarter.year} Q${quarter.quarter}: ${status.tooltip}`,
            rawStatus: rawStatus // Include raw status for debugging
        };
    }
    
    /**
     * Show loading state
     */
    showLoading() {
        this.container.innerHTML = `
            <div class="hybrid-status-grid">
                <div class="status-grid-loading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">Loading status grid...</div>
                </div>
            </div>
        `;
    }
    
    /**
     * Show error state
     */
    showError(message) {
        this.container.innerHTML = `
            <div class="hybrid-status-grid">
                <div class="status-grid-error">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ${this.escapeHtml(message)}
                </div>
            </div>
        `;
    }
    
    /**
     * Attach event listeners for the hybrid table
     */
    attachEventListeners() {
        // Status indicator hover functionality
        const statusIndicators = this.container.querySelectorAll('.status-indicator');
        
        statusIndicators.forEach(indicator => {
            indicator.addEventListener('mouseenter', this.showTooltip.bind(this));
            indicator.addEventListener('mouseleave', this.hideTooltip.bind(this));
            indicator.addEventListener('click', this.handleStatusClick.bind(this));
        });
        
        // Row hover highlighting
        const tableRows = this.container.querySelectorAll('tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', this.highlightRow.bind(this));
            row.addEventListener('mouseleave', this.unhighlightRow.bind(this));
        });
        
        // Scroll detection for visual indicators
        const gridContainer = this.container.querySelector('.hybrid-status-grid');
        if (gridContainer) {
            this.setupScrollIndicators(gridContainer);
        }
    }
    
    /**
     * Setup scroll indicators for visual feedback
     */
    setupScrollIndicators(container) {
        const checkScrollable = () => {
            // Check horizontal scrollability
            if (container.scrollWidth > container.clientWidth) {
                container.classList.add('scrollable-x');
            } else {
                container.classList.remove('scrollable-x');
            }
            
            // Check vertical scrollability
            if (container.scrollHeight > container.clientHeight) {
                container.classList.add('scrollable-y');
            } else {
                container.classList.remove('scrollable-y');
            }
        };
        
        // Initial check
        setTimeout(checkScrollable, 100);
        
        // Check on scroll
        container.addEventListener('scroll', checkScrollable);
        
        // Check on resize
        window.addEventListener('resize', checkScrollable);
    }
    
    /**
     * Handle status indicator click
     */
    handleStatusClick(event) {
        const cell = event.target.closest('td');
        if (!cell) return;
        
        const quarter = cell.getAttribute('data-quarter');
        const target = cell.getAttribute('data-target');
        
        console.log(`Status clicked: Quarter ${quarter}, Target ${target}`);
        
        // You can implement additional functionality here
        // such as opening a modal with detailed status information
    }
    
    /**
     * Highlight row on hover
     */
    highlightRow(event) {
        const row = event.target.closest('tr');
        if (row) {
            row.style.backgroundColor = '#f8f9fa';
        }
    }
    
    /**
     * Remove row highlight
     */
    unhighlightRow(event) {
        const row = event.target.closest('tr');
        if (row) {
            row.style.backgroundColor = '';
        }
    }
    
    /**
     * Show tooltip on hover
     */
    showTooltip(event) {
        const element = event.target;
        const title = element.getAttribute('title');
        
        if (!title) return;
        
        // Create tooltip element
        const tooltip = document.createElement('div');
        tooltip.className = 'status-tooltip position-absolute bg-dark text-white p-2 rounded';
        tooltip.style.cssText = `
            z-index: 1000;
            font-size: 0.75rem;
            white-space: nowrap;
            pointer-events: none;
        `;
        tooltip.textContent = title;
        document.body.appendChild(tooltip);
        
        // Position tooltip
        const rect = element.getBoundingClientRect();
        tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
        tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
        
        // Store reference for cleanup
        element._tooltip = tooltip;
        
        // Remove title to prevent native tooltip
        element._originalTitle = title;
        element.removeAttribute('title');
    }
    
    /**
     * Hide tooltip
     */
    hideTooltip(event) {
        const element = event.target;
        
        if (element._tooltip) {
            document.body.removeChild(element._tooltip);
            delete element._tooltip;
        }
        
        // Restore original title
        if (element._originalTitle) {
            element.setAttribute('title', element._originalTitle);
            delete element._originalTitle;
        }
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (typeof text !== 'string') return text;
        
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Refresh the grid with new data
     */
    async refresh() {
        await this.init();
    }
    
    /**
     * Destroy the grid and clean up
     */
    destroy() {
        if (this.container) {
            this.container.innerHTML = '';
        }
        
        // Clean up any remaining tooltips
        document.querySelectorAll('.status-tooltip').forEach(tooltip => {
            tooltip.remove();
        });
    }
}

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = StatusGrid;
} else if (typeof window !== 'undefined') {
    window.StatusGrid = StatusGrid;
}
