/**
 * Toast Manager
 * Handles displaying toast notifications
 */
function ToastManager() {
    /**
     * Show a toast notification
     * @param {string} title - Toast title
     * @param {string} message - Toast message
     * @param {string} type - Notification type (success, warning, danger, info)
     * @param {number} duration - Duration in milliseconds (default: 5000)
     */
    function show(title, message, type = 'info', duration = 5000) {
        // Set the toast container
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(container);
        }
        
        // Create toast element
        const toastId = 'toast-' + Date.now();
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.id = toastId;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        
        // Set toast content
        toast.innerHTML = `
            <div class="toast-header bg-${type} text-white">
                <strong class="me-auto">${title}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        `;
        
        // Add the toast to the container
        container.appendChild(toast);
        
        // Show the toast
        if (typeof bootstrap !== 'undefined' && typeof bootstrap.Toast !== 'undefined') {
            const bsToast = new bootstrap.Toast(toast, {
                autohide: true,
                delay: duration
            });
            bsToast.show();
        } else {
            // Fallback if Bootstrap isn't available
            toast.style.display = 'block';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, duration);
        }
        
        // Return the toast element
        return toast;
    }
    
    // Public API
    return {
        show
    };
}

// Make the toast manager globally available
window.ToastManager = ToastManager;

// Create a global showToast function for backward compatibility
window.showToast = function(title, message, type = 'info', duration = 5000) {
    const toastManager = ToastManager();
    return toastManager.show(title, message, type, duration);
};
