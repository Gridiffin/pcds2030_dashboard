/**
 * User Form Manager
 * Handles dynamic user forms for add, edit, delete operations
 */
function UserFormManager() {
    // Private variables - moved inside function scope to prevent redeclarations
    const formContainer = document.getElementById('formContainer');
    
    // Flag for tracking initialization - moved inside the closure
    let initialized = false;

    /**
     * Initialize the form manager
     */
    function initialize() {
        if (initialized) return; // Prevent multiple initializations
        
        // Initial setup tasks
        console.log('User form manager initialized');
        initialized = true;
    }

    /**
     * Show modal to delete a user
     * @param {string} userId User ID
     * @param {string} username Username
     */
    function showDeleteForm(userId, username) {
        // First check if user has foreign key references
        checkUserReferences(userId, username);
    }

    /**
     * Check if user has foreign key references before deletion
     * @param {string} userId User ID
     * @param {string} username Username
     */
    function checkUserReferences(userId, username) {
        // Show loading state
        showToast('Info', 'Checking user references...', 'info');
        
        // Make AJAX call to check references
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                'action': 'check_user_references',
                'user_id': userId,
                'ajax_request': '1'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.has_references) {
                showDeleteOptionsModal(userId, username, data);
            } else {
                showSimpleDeleteModal(userId, username);
            }
        })
        .catch(error => {
            console.error('Error checking user references:', error);
            showToast('Error', 'Failed to check user references', 'error');
        });
    }

    /**
     * Show simple delete confirmation modal
     * @param {string} userId User ID
     * @param {string} username Username
     */
    function showSimpleDeleteModal(userId, username) {
        const modalId = 'deleteUserModal';
        let deleteModal = document.getElementById(modalId);
        
        if (!deleteModal) {
            deleteModal = createModal(modalId, 'Delete User', 'bg-danger text-white');
            document.body.appendChild(deleteModal);
        }
        
        deleteModal.querySelector('.modal-body').innerHTML = `
            <p>Are you sure you want to delete the user <strong>${username}</strong>?</p>
            <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</p>
            <form id="deleteUserForm" method="post">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="${userId}">
            </form>
        `;
        
        deleteModal.querySelector('.modal-footer').innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete User</button>
        `;
        
        // Add event listener
        document.getElementById('confirmDeleteBtn').onclick = function() {
            submitDeleteForm('deleteUserForm');
        };
        
        // Show the modal
        const modal = new bootstrap.Modal(deleteModal);
        modal.show();
    }

    /**
     * Show delete options modal when user has foreign key references
     * @param {string} userId User ID
     * @param {string} username Username
     * @param {object} referenceData Reference data from server
     */
    function showDeleteOptionsModal(userId, username, referenceData) {
        const modalId = 'deleteOptionsModal';
        let deleteModal = document.getElementById(modalId);
        
        if (!deleteModal) {
            deleteModal = createModal(modalId, 'User Deletion Options', 'bg-warning text-dark');
            document.body.appendChild(deleteModal);
        }
        
        let referenceDetails = '';
        if (referenceData.details && referenceData.details.length > 0) {
            referenceDetails = '<ul class="list-unstyled">';
            referenceData.details.forEach(ref => {
                referenceDetails += `<li><i class="fas fa-link text-warning"></i> ${ref.count} ${ref.message}</li>`;
            });
            referenceDetails += '</ul>';
        }
        
        deleteModal.querySelector('.modal-body').innerHTML = `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Cannot delete user "${username}"</strong> because it has foreign key references:
            </div>
            ${referenceDetails}
            <div class="mt-3">
                <h6>Options:</h6>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="deleteOption" id="deactivateOption" value="deactivate" checked>
                    <label class="form-check-label" for="deactivateOption">
                        <strong>Deactivate User</strong> (Recommended)
                        <br><small class="text-muted">User will be marked as inactive but data relationships are preserved</small>
                    </label>
                </div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="radio" name="deleteOption" id="forceDeleteOption" value="force_delete">
                    <label class="form-check-label" for="forceDeleteOption">
                        <strong>Force Delete</strong> (Advanced)
                        <br><small class="text-muted text-danger">This will perform a soft delete to maintain data integrity</small>
                    </label>
                </div>
            </div>
            <form id="deleteOptionsForm" method="post">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="${userId}">
                <input type="hidden" name="force_soft_delete" id="forceSoftDeleteInput" value="1">
            </form>
        `;
        
        deleteModal.querySelector('.modal-footer').innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-warning" id="confirmDeleteOptionsBtn">Proceed</button>
        `;
        
        // Add event listener
        document.getElementById('confirmDeleteOptionsBtn').onclick = function() {
            const selectedOption = document.querySelector('input[name="deleteOption"]:checked').value;
            if (selectedOption === 'deactivate' || selectedOption === 'force_delete') {
                document.getElementById('forceSoftDeleteInput').value = '1';
            }
            submitDeleteForm('deleteOptionsForm');
        };
        
        // Show the modal
        const modal = new bootstrap.Modal(deleteModal);
        modal.show();
    }

    /**
     * Create a basic modal structure
     * @param {string} id Modal ID
     * @param {string} title Modal title
     * @param {string} headerClass Header CSS class
     * @returns {HTMLElement} Modal element
     */
    function createModal(id, title, headerClass) {
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = id;
        modal.setAttribute('tabindex', '-1');
        modal.setAttribute('aria-labelledby', id + 'Label');
        modal.setAttribute('aria-hidden', 'true');
        
        modal.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header ${headerClass}">
                        <h5 class="modal-title" id="${id}Label">${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Content will be set dynamically -->
                    </div>
                    <div class="modal-footer">
                        <!-- Footer will be set dynamically -->
                    </div>
                </div>
            </div>
        `;
        
        return modal;
    }

    /**
     * Submit delete form with proper handling
     * @param {string} formId Form ID to submit
     */
    function submitDeleteForm(formId) {
        const form = document.getElementById(formId);
        if (!form) {
            showToast('Error', 'Form not found', 'error');
            return;
        }
        
        // Add AJAX flag
        const ajaxInput = document.createElement('input');
        ajaxInput.type = 'hidden';
        ajaxInput.name = 'ajax_request';
        ajaxInput.value = '1';
        form.appendChild(ajaxInput);
        
        // Submit via AJAX
        const formData = new FormData(form);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Close any open modals
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                }
            });
            
            if (data.success) {
                showToast('Success', data.message, 'success');
                
                // Reload table data
                if (window.UserTableManager) {
                    window.UserTableManager.refreshTable();
                }
            } else {
                showToast('Error', data.error || 'Delete operation failed', 'error');
            }
        })
        .catch(error => {
            console.error('Error deleting user:', error);
            showToast('Error', 'An error occurred while deleting the user', 'error');
        });
    }

    // Other form methods can go here...
    
    // Initialize on creation
    initialize();

    // Public API
    return {
        showDeleteForm
        // Other public methods...
    };
}

// Create a single global instance
window.UserFormManager = UserFormManager;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // This will be initialized later when needed, not automatically
    console.log('User form manager script loaded');
});
