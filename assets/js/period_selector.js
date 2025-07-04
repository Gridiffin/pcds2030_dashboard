/**
 * Period Selector JavaScript
 * 
 * Handles period selection and content updating based on selected period
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the period selector on load
    initPeriodSelector();
    // Initialize view mode toggle
    initViewModeToggle();
});

/**
 * Initialize the period selector
 */
function initPeriodSelector() {
    const periodSelector = document.getElementById('periodSelector');
    
    if (periodSelector) {
        // Handle period change
        periodSelector.addEventListener('change', function() {
            const selectedPeriodId = this.value;
            
            // Show loading indicator
            const periodSelectorCard = document.querySelector('.period-selector-card');
            if (periodSelectorCard) {
                periodSelectorCard.classList.add('loading');
            }
            
            // Update browser URL without refreshing
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('period_id', selectedPeriodId);
            const newUrl = window.location.pathname + '?' + urlParams.toString();
            window.history.pushState({ periodId: selectedPeriodId }, '', newUrl);
            
            // Load content via AJAX instead of refreshing
            updatePageContent(selectedPeriodId);
        });
        
        // Handle browser back/forward navigation
        window.addEventListener('popstate', function(event) {
            const urlParams = new URLSearchParams(window.location.search);
            const periodId = urlParams.get('period_id');
            
            if (periodId) {
                // Update the period selector dropdown value
                const periodSelector = document.getElementById('periodSelector');
                if (periodSelector) {
                    periodSelector.value = periodId;
                }
                
                // Update content
                updatePageContent(periodId);
            }
        });
    }
}

/**
 * Initialize view mode toggle functionality
 */
function initViewModeToggle() {
    const viewModeRadios = document.querySelectorAll('input[name="viewMode"]');
    
    viewModeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                handleViewModeChange(this.value);
            }
        });
    });
}

/**
 * Handle view mode change between half-yearly and quarterly
 * 
 * @param {string} viewMode - The selected view mode ('half-yearly' or 'quarterly')
 */
function handleViewModeChange(viewMode) {
    // Show loading indicator
    const periodSelectorCard = document.querySelector('.period-selector-card');
    if (periodSelectorCard) {
        periodSelectorCard.classList.add('loading');
    }
    
    // Update URL with new view mode and reload to rebuild the dropdown
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('view_mode', viewMode);
    // Remove period_id when switching view modes to prevent conflicts
    // but preserve all other URL parameters (like tab, sector_id, etc.)
    urlParams.delete('period_id');
    
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

/**
 * Update page content via AJAX based on the selected period
 * 
 * @param {string} periodId - The selected period ID (can be single ID or comma-separated IDs)
 */
function updatePageContent(periodId) {
    // Show loading indicators for all dynamic content sections
    const dynamicSections = document.querySelectorAll('[data-period-content]');
    dynamicSections.forEach(section => {
        section.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary" role="status"></div><div class="mt-3">Loading data...</div></div>';
    });
    
    // Get the current page path to determine which API endpoint to call
    const pagePath = window.location.pathname;
    
    // Define the endpoint based on page path
    let endpoint = '';    if (pagePath.includes('/agency/dashboard/dashboard.php')) {
        endpoint = 'ajax/agency_dashboard_data.php';
    } else if (pagePath.includes('/admin/dashboard/dashboard.php')) {
        endpoint = '../ajax/admin_dashboard_data.php';
    } else if (pagePath.includes('/agency/view_programs.php')) {
        endpoint = '../ajax/agency_programs_data.php';
    } else if (pagePath.includes('/admin/manage_programs.php')) {
        endpoint = '../ajax/admin_programs_data.php';
    } else if (pagePath.includes('/agency/reports.php')) {
        endpoint = '../ajax/agency_reports_data.php';
    } else if (pagePath.includes('/admin/reports.php')) {
        endpoint = '../ajax/admin_reports_data.php';
    } else if (pagePath.includes('/agency/sectors/view_all_sectors.php')) {
        // For view_all_sectors.php, always do a full page reload as filtering is client-side
        const currentParams = new URLSearchParams(window.location.search);
        
        // Set the period_id parameter
        currentParams.set('period_id', periodId);
        
        // Ensure view_mode parameter is preserved
        if (!currentParams.has('view_mode')) {
            // Default to the current view mode if not already in URL
            const viewModeRadios = document.querySelectorAll('input[name="viewMode"]:checked');
            if (viewModeRadios.length > 0) {
                currentParams.set('view_mode', viewModeRadios[0].value);
            } else {
                // Default to half-yearly if no radio is checked
                currentParams.set('view_mode', 'half-yearly');
            }
        }
        
        // Perform the page reload with all parameters
        window.location.href = window.location.pathname + '?' + currentParams.toString();
        return; // Exit early to avoid the AJAX logic below
    }
    
    // If endpoint is defined, fetch data
    if (endpoint) {
        fetch(`${endpoint}?period_id=${periodId}`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            // Update each dynamic section with its corresponding data
            dynamicSections.forEach(section => {
                const sectionId = section.getAttribute('data-period-content');
                if (data[sectionId]) {
                    section.innerHTML = data[sectionId];
                }
            });
            
            // Also update period selector info if present
            const periodSelectorInfo = document.querySelectorAll('.period-selector-info');
            if (periodSelectorInfo.length && data.period_info) {
                periodSelectorInfo.forEach(info => {
                    info.innerHTML = data.period_info;
                });
            }
            
            // Show success indicator briefly
            const periodSelectorCard = document.querySelector('.period-selector-card');
            if (periodSelectorCard) {
                periodSelectorCard.classList.remove('loading');
                periodSelectorCard.classList.add('updated');
                setTimeout(() => {
                    periodSelectorCard.classList.remove('updated');
                }, 1000);
            }
            
            // Reinitialize any components that need it
            reinitializeComponents();
        })
        .catch(error => {
            console.error('Error fetching period data:', error);
            dynamicSections.forEach(section => {
                section.innerHTML = '<div class="alert alert-danger">Failed to load data. <button class="btn btn-link p-0 reload-section">Try again</button></div>';
            });
            
            // Add click handler for retry buttons
            document.querySelectorAll('.reload-section').forEach(btn => {
                btn.addEventListener('click', () => updatePageContent(periodId));
            });
            
            // Remove loading state
            const periodSelectorCard = document.querySelector('.period-selector-card');
            if (periodSelectorCard) {
                periodSelectorCard.classList.remove('loading');
            }
        });
    } else {
    // Fallback to full page reload if no endpoint is defined
    // Preserve all existing URL parameters including view_mode
    const currentParams = new URLSearchParams(window.location.search);
    // Set the period_id parameter
    currentParams.set('period_id', periodId);
    // Ensure view_mode parameter is preserved
    if (!currentParams.has('view_mode')) {
        // Default to the current view mode if not already in URL
        const viewModeRadios = document.querySelectorAll('input[name="viewMode"]:checked');
        if (viewModeRadios.length > 0) {
            currentParams.set('view_mode', viewModeRadios[0].value);
        } else {
            // Default to half-yearly if no radio is checked
            currentParams.set('view_mode', 'half-yearly');
        }
    }
    // Perform the page reload with all parameters
    window.location.href = window.location.pathname + '?' + currentParams.toString();
    }
}

/**
 * Reinitialize JavaScript components after AJAX updates
 */
function reinitializeComponents() {
    // Re-initialize charts if they exist and if Chart.js is available
    if (typeof Chart !== 'undefined') {
        // Re-initialize dashboard charts
        if (typeof initDashboardCharts === 'function') {
            initDashboardCharts();
        }        // Re-initialize program rating chart if data exists
        if (typeof programRatingChartData !== 'undefined' && document.getElementById('programRatingChart')) {
            initProgramRatingChart();
        }
    }
    
    // Re-initialize datatables if they exist
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        $('.datatable').DataTable();
    }
    
    // Re-initialize status pills
    if (typeof initStatusPills === 'function') {
        initStatusPills();
    }
    
    // Re-attach event listeners for dynamic content
    document.dispatchEvent(new CustomEvent('contentUpdated'));
}
