<?php
/**
 * Edit User Page
 * 
 * Admin interface for editing existing user accounts.
 */

// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admins/index.php';

// Verify user is admin
if (!is_admin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Get user ID from URL
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$user_id) {
    $_SESSION['message'] = 'Invalid user ID.';
    $_SESSION['message_type'] = 'danger';
    header('Location: manage_users.php');
    exit;
}

// Get user data
$user = get_user_by_id($conn, $user_id); // Use the updated function

if (!$user) {
    $_SESSION['message'] = 'User not found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: manage_users.php');
    exit;
}

// Process form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add user_id to the POST data
    $_POST['user_id'] = $user_id;
    
    // Process the form submission
    $result = update_user($_POST);
    
    if (isset($result['success'])) {
        $_SESSION['message'] = 'User updated successfully.';
        $_SESSION['message_type'] = 'success';
        header('Location: manage_users.php');
        exit;
    } else {
        $message = $result['error'] ?? 'Failed to update user.';
        $message_type = 'danger';
    }
}

// Get all sectors for dropdown
$sectors = get_all_sectors();

// Get all agency groups for dropdown
$agency_groups = get_all_agency_groups($conn);

// Set page title
$pageTitle = 'Edit User';

// Additional scripts
$additionalScripts = []; // Set to empty array if no other scripts are needed here

// Include header
require_once '../../layouts/header.php';

// Configure the modern page header
$header_config = [
    'title' => 'Edit User',
    'subtitle' => 'Update user account information',
    'variant' => 'white',
    'actions' => [
        [
            'text' => 'Back to Users',
            'url' => APP_URL . '/app/views/admin/users/manage_users.php',
            'class' => 'btn-outline-primary',
            'icon' => 'fas fa-arrow-left'
        ]
    ]
];

// Include the modern page header
require_once '../../layouts/page_header.php';
?>

<main class="flex-fill">
<script>
    // Make sure APP_URL is defined for JavaScript
    window.APP_URL = '<?php echo APP_URL; ?>';
</script>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
            <div><?php echo $message; ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
<?php endif; ?>

<!-- Edit User Form -->
<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h5 class="card-title m-0">Edit User: <?php echo htmlspecialchars($user['username']); ?></h5>
    </div>    <div class="card-body">
        <form action="" method="post" id="editUserForm">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
            
            <!-- Basic Information -->
            <div class="mb-4">
                <h6 class="fw-bold mb-3">Account Information</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="role" class="form-label">User Role *</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            <option value="agency" <?php echo $user['role'] === 'agency' ? 'selected' : ''; ?>>Agency User</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password (leave blank to keep current)</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password">
                            <button class="btn btn-outline-secondary toggle-password" type="button">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text password-strength">Password should be at least 8 characters.</div>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                            <button class="btn btn-outline-secondary toggle-password" type="button">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Agency Information (shown only for agency role) -->
            <div id="agencyFields" class="mb-4" style="display: <?php echo ($user['role'] === 'agency') ? 'block' : 'none'; ?>;">
                <h6 class="fw-bold mb-3">Agency Information <span class="text-danger">(Required for Agency Users)</span></h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="agency_name" class="form-label">Agency Name *</label>
                        <input type="text" class="form-control" id="agency_name" name="agency_name" value="<?php echo htmlspecialchars($user['agency_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="col-md-6">
                        <label for="sector_id" class="form-label">Sector *</label>
                        <select class="form-select" id="sector_id" name="sector_id">
                            <option value="">Select Sector</option>
                            <?php foreach($sectors as $sector): ?>
                                <option value="<?php echo $sector['sector_id']; ?>" <?php echo isset($user['sector_id']) && $user['sector_id'] == $sector['sector_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sector['sector_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="agency_group_id" class="form-label">Agency Group</label>
                        <select class="form-select" id="agency_group_id" name="agency_group_id">
                            <option value="">Select Agency Group (Optional)</option>
                            <?php foreach($agency_groups as $group): ?>
                                <option value="<?php echo $group['agency_group_id']; ?>" <?php echo isset($user['agency_group_id']) && $user['agency_group_id'] == $group['agency_group_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group['group_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select which group this agency belongs to. This is optional.</div>
                    </div>
                </div>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Agency users can only submit data related to their assigned sector.
                </div>
            </div>
            
            <!-- Account Status -->
            <div class="mb-4">
                <h6 class="fw-bold mb-3">Account Status</h6>
                <?php if ($user['user_id'] == $_SESSION['user_id']): ?>
                    <!-- For own account, show fixed status instead of toggle -->
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Active Account</strong> - You cannot modify your own account status.
                    </div>
                    <input type="hidden" name="is_active" value="1">
                <?php else: ?>
                    <!-- For other accounts, show the toggle switch -->
                    <div class="form-check form-switch">
                        <!-- Add hidden field to ensure is_active is always submitted even when checkbox is unchecked -->
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">
                            <span class="status-indicator <?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </label>
                    </div>
                    <div class="form-text">Inactive users cannot log in to the system.</div>
                <?php endif; ?>
            </div>
            
            <!-- Action Buttons -->
            <div class="d-flex justify-content-end mt-4">
                <a href="<?php echo APP_URL; ?>/app/views/admin/manage_users.php" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Additional JavaScript for field toggling and validation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle agency fields visibility based on role selection
    const roleSelect = document.getElementById('role');
    const agencyFields = document.getElementById('agencyFields');
    const agencyName = document.getElementById('agency_name');
    const sectorId = document.getElementById('sector_id');
    const agencyGroupId = document.getElementById('agency_group_id'); // Add agency_group_id

    if (roleSelect && agencyFields) {
        // Function to update required status based on role
        const updateRequiredFields = function() {
            if (roleSelect.value === 'agency') {
                agencyFields.style.display = 'block';
                agencyName.setAttribute('required', '');
                sectorId.setAttribute('required', '');
                // agency_group_id is optional, so no required attribute here
            } else {
                agencyFields.style.display = 'none';
                agencyName.removeAttribute('required');
                sectorId.removeAttribute('required');
                // agency_group_id remains optional
            }
        };
        
        // Initial check
        updateRequiredFields();
        
        // Listen for changes
        roleSelect.addEventListener('change', updateRequiredFields);
    }
    
    // Password toggle functionality
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.closest('.input-group').querySelector('input');
            
            // Toggle password visibility
            if (input.type === 'password') {
                input.type = 'text';
                this.innerHTML = '<i class="far fa-eye-slash"></i>';
            } else {
                input.type = 'password';
                this.innerHTML = '<i class="far fa-eye"></i>';
            }
        });
    });
    
    // Password validation only if password is changed
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthIndicator = document.querySelector('.password-strength');
    
    if (passwordInput && confirmInput) {
        // Helper function to manage feedback messages
        function setInputFeedback(inputElement, isValid, message = '') {
            // Remove any existing feedback first
            const existingFeedback = inputElement.parentNode.querySelector('.invalid-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            // Update input validation state
            if (!isValid) {
                inputElement.classList.add('is-invalid');
                
                // Create new feedback element if we have a message
                if (message) {
                    const feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback';
                    feedback.textContent = message;
                    inputElement.parentNode.appendChild(feedback);
                }
            } else {
                inputElement.classList.remove('is-invalid');
            }
        }
        
        // Form validation
        const form = document.getElementById('editUserForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Only validate password if it's not empty (user is changing it)
                if (passwordInput.value) {
                    // Check password length
                    if (passwordInput.value.length < 8) {
                        isValid = false;
                        setInputFeedback(passwordInput, false, 'Password must be at least 8 characters');
                    } else {
                        setInputFeedback(passwordInput, true);
                    }
                    
                    // Check if passwords match
                    if (passwordInput.value !== confirmInput.value) {
                        isValid = false;
                        setInputFeedback(confirmInput, false, 'Passwords do not match');
                    } else {
                        setInputFeedback(confirmInput, true);
                    }
                }
                
                // Additional validation for agency role
                if (roleSelect.value === 'agency') {
                    if (!agencyName.value.trim()) {
                        isValid = false;
                        setInputFeedback(agencyName, false, 'Agency name is required');
                    } else {
                        setInputFeedback(agencyName, true);
                    }
                    
                    if (!sectorId.value) {
                        isValid = false;
                        setInputFeedback(sectorId, false, 'Please select a sector');
                    } else {
                        setInputFeedback(sectorId, true);
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                }
            });
        }
    }
});
</script>
</main>

<?php
// Include footer
require_once '../../layouts/footer.php';
?>




