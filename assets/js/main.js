/**
 * Main JavaScript
 * Contains application-wide functionality used across multiple pages
 */

document.addEventListener('DOMContentLoaded', function() {
    // Handle preloader
    handlePreloader();
    
    // Initialize interactive elements
    initInteractiveElements();
    
    // Initialize tooltips if Bootstrap is available
    initTooltips();
    
    // Add listener for dynamic content updates
    document.addEventListener('contentUpdated', initInteractiveElements);
});

/**
 * Hide the preloader after page load
 */
function handlePreloader() {
    const preloader = document.getElementById('preloader');
    if (preloader) {
        window.addEventListener('load', function() {
            preloader.classList.add('preloader-hide');
            setTimeout(() => {
                preloader.style.display = 'none';
                document.body.classList.add('page-loaded');
            }, 300);
        });
        
        // Fallback if load event doesn't fire
        setTimeout(() => {
            preloader.classList.add('preloader-hide');
            preloader.style.display = 'none';
            document.body.classList.add('page-loaded');
        }, 2000);
    }
}

/**
 * Initialize Bootstrap tooltips if available
 */
function initTooltips() {
    // Bootstrap's data-API (data-bs-toggle="tooltip") should handle tooltip initialization automatically.
    // This function can be left empty or removed if no custom tooltip logic is needed beyond
    // what Bootstrap provides by default.
    // If there was specific custom logic here previously, ensure it's not conflicting
    // with the automatic data-API initialization.
    // For now, we'll ensure it doesn't try to re-initialize.
    if (typeof bootstrap !== 'undefined' && typeof bootstrap.Tooltip !== 'undefined') {
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(tooltipTriggerEl => {
            // We are relying on Bootstrap's data-API to initialize tooltips.
            // This function will not create new bootstrap.Tooltip instances
            // to avoid the "multiple instances" error.
            // If you need to interact with a tooltip instance, use:
            // bootstrap.Tooltip.getInstance(tooltipTriggerEl)
            // Example: To manually show a tooltip (though usually not needed with data-API)
            // const tooltip = bootstrap.Tooltip.getInstance(tooltipTriggerEl);
            // if (tooltip) { tooltip.show(); }
        });
    }
}

/**
 * Initialize interactive elements like password toggles and character counters
 */
function initInteractiveElements() {
    // Initialize password toggles
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.closest('.input-group').querySelector('input');
            
            if (input) {
                // Toggle password visibility
                if (input.type === 'password') {
                    input.type = 'text';
                    this.innerHTML = '<i class="far fa-eye-slash"></i>';
                } else {
                    input.type = 'password';
                    this.innerHTML = '<i class="far fa-eye"></i>';
                }
            }
        });
    });
    
    // Initialize character counters
    document.querySelectorAll('[data-max-length]').forEach(field => {
        const maxLength = parseInt(field.getAttribute('data-max-length'), 10);
        
        if (!isNaN(maxLength)) {
            // Create counter element if it doesn't exist
            let counter = field.parentNode.querySelector('.character-counter');
            
            if (!counter) {
                counter = document.createElement('small');
                counter.className = 'text-muted float-end character-counter';
                field.parentNode.appendChild(counter);
            }
            
            // Initial count
            counter.textContent = `${field.value.length}/${maxLength} characters`;
            
            // Update counter on input
            field.addEventListener('input', function() {
                const currentLength = this.value.length;
                counter.textContent = `${currentLength}/${maxLength} characters`;
                
                if (currentLength > maxLength) {
                    counter.classList.add('text-danger');
                } else {
                    counter.classList.remove('text-danger');
                }
            });
        }
    });
    
    // Initialize datepickers if jQuery UI is available
    if (typeof $ !== 'undefined' && typeof $.fn.datepicker !== 'undefined') {
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true
        });
    }
    
    // Add dismiss functionality to alerts
    document.querySelectorAll('.alert .btn-close').forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => {
                    alert.remove();
                }, 150);
            }
        });
    });
}

/**
 * Show a toast notification
 * @param {string} title - Toast title
 * @param {string} message - Toast message
 * @param {string} type - Notification type (success, warning, danger, info)
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showToast(title, message, type = 'info', duration = 5000) {
    // Create toast container if it doesn't exist
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        document.body.appendChild(toastContainer);
    }
    
    // Create a unique ID for this toast
    const toastId = 'toast-' + Date.now();
    
    // Create toast element
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
    // Toast content
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <strong>${title}</strong>: ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;
    
    // Add to container
    toastContainer.appendChild(toast);
    
    // Initialize and show using Bootstrap if available
    if (typeof bootstrap !== 'undefined' && typeof bootstrap.Toast !== 'undefined') {
        const bsToast = new bootstrap.Toast(toast, {
            autohide: true,
            delay: duration
        });
        bsToast.show();
    } else {
        // Fallback if Bootstrap isn't available
        toast.style.opacity = '1';
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 500);
        }, duration);
    }
}

/**
 * Create a modal dialog
 * @param {object} options - Modal options
 * @param {string} options.title - Modal title
 * @param {string} options.content - Modal content (HTML)
 * @param {boolean} options.isDanger - Whether this is a danger modal (red header)
 * @param {array} options.buttons - Array of button configs {text, type, handler}
 * @returns {object} Modal control object with show() and hide() methods
 */
function createModal(options) {
    // Set default options
    const defaults = {
        title: 'Notification',
        content: '',
        isDanger: false,
        buttons: [
            { text: 'Close', type: 'secondary', handler: null }
        ]
    };
    
    const settings = Object.assign({}, defaults, options);
    
    // Create modal elements
    const modalId = 'modal-' + Date.now();
    const modal = document.createElement('div');
    modal.id = modalId;
    modal.className = 'form-overlay';
    modal.style.display = 'none';
    
    // Modal content
    const modalContent = document.createElement('div');
    modalContent.className = 'form-wrapper';
    
    // Modal header
    const modalHeader = document.createElement('div');
    modalHeader.className = `form-header ${settings.isDanger ? 'form-header-danger' : ''}`;
    modalHeader.innerHTML = `
        <h3>${settings.title}</h3>
        <button type="button" class="close-form">&times;</button>
    `;
    
    // Modal body
    const modalBody = document.createElement('div');
    modalBody.className = 'form-body p-3';
    modalBody.innerHTML = settings.content;
    
    // Modal footer
    const modalFooter = document.createElement('div');
    modalFooter.className = 'd-flex justify-content-end p-3 border-top';
    
    // Add buttons
    settings.buttons.forEach(button => {
        const btnElement = document.createElement('button');
        btnElement.type = 'button';
        btnElement.className = `btn btn-${button.type || 'secondary'} ms-2`;
        btnElement.textContent = button.text;
        
        if (button.handler) {
            btnElement.addEventListener('click', button.handler);
        } else {
            // Default close behavior
            btnElement.addEventListener('click', () => {
                hideModal();
            });
        }
        
        modalFooter.appendChild(btnElement);
    });
    
    // Assemble modal
    modalContent.appendChild(modalHeader);
    modalContent.appendChild(modalBody);
    modalContent.appendChild(modalFooter);
    modal.appendChild(modalContent);
    
    // Add modal to document
    document.body.appendChild(modal);
    
    // Close button functionality
    const closeButton = modal.querySelector('.close-form');
    closeButton.addEventListener('click', () => {
        hideModal();
    });
    
    // Close on click outside modal content
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            hideModal();
        }
    });
    
    // ESC key to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            hideModal();
        }
    });
    
    // Modal control functions
    function showModal() {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Prevent scrolling
    }
    
    function hideModal() {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
        
        // Remove modal from DOM after animation
        setTimeout(() => {
            if (document.body.contains(modal)) {
                document.body.removeChild(modal);
            }
        }, 300);
    }
    
    // Return control object
    return {
        show: showModal,
        hide: hideModal,
        id: modalId
    };
}
