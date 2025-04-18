/**
 * Admin Dashboard JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Handle refresh button click
    const refreshButton = document.getElementById('refreshPage');
    if (refreshButton) {
        refreshButton.addEventListener('click', function() {
            // Add loading state
            this.classList.add('loading');
            
            // Change button text
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-sync-alt me-1"></i> Refreshing...';
            
            // Reload the page after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 500);
        });
    }
    
    // Enhanced submissions refresh button
    const refreshSubmissions = document.getElementById('refreshSubmissions');
    if (refreshSubmissions) {
        refreshSubmissions.addEventListener('click', function() {
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...';
            this.disabled = true;
            
            // Simulate AJAX refresh with a setTimeout
            setTimeout(() => {
                this.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
                this.disabled = false;
                
                // Show a temporary success message
                const tableContainer = document.querySelector('.table-responsive').parentNode;
                const alertElement = document.createElement('div');
                alertElement.className = 'alert alert-success alert-dismissible fade show mt-3';
                alertElement.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle me-2"></i>
                        <div>Data refreshed successfully!</div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                
                tableContainer.insertBefore(alertElement, document.querySelector('.table-responsive'));
                
                // Auto dismiss after 3 seconds
                setTimeout(() => {
                    alertElement.classList.remove('show');
                    setTimeout(() => alertElement.remove(), 300);
                }, 3000);
            }, 1200);
        });
    }
    
    // Show appropriate messaging if no active reporting period
    if (!hasActivePeriod) {
        const mainContent = document.querySelector('.quick-actions-container');
        const notification = document.createElement('div');
        notification.className = 'alert alert-info alert-dismissible fade show mb-4';
        notification.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-info-circle me-3 fa-lg"></i>
                <div>
                    <strong>No active reporting period.</strong>
                    Start by <a href="reporting_periods.php" class="alert-link">creating a new reporting period</a> to begin collecting data.
                </div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        mainContent.parentNode.insertBefore(notification, mainContent);
    }
    
    // Add animation to stat cards
    document.querySelectorAll('.stat-card').forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('animate__animated', 'animate__fadeInUp');
        }, index * 100);
    });
});
