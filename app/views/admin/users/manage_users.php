<?php
/**
 * Manage Users Page
 * 
 * Admin interface for managing user accounts.
 * Using standard Bootstrap modals with fixes.
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

// AJAX Table Request Handler
if (isset($_GET['ajax_table']) && $_GET['ajax_table'] == '1') {
    // Get all users and separate them by role (include inactive users)
    $all_users = get_all_users(true); // Include inactive users for display
    $admin_users = array_filter($all_users, function($user) {
        return $user['role'] === 'admin';
    });
    $agency_users = array_filter($all_users, function($user) {
        return $user['role'] === 'agency';
    });

    // Output only the table HTML and exit
    // Admin Users Table Card (Simplified for AJAX)
    ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title m-0">Admin Users</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive w-100">
                <table class="table table-hover table-custom user-table mb-0">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th class="text-center" style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($admin_users as $user): ?>
                            <tr class="<?php echo !$user['is_active'] ? 'inactive-user' : ''; ?>">
                                <td>
                                    <div class="fw-medium"><?php echo htmlspecialchars($user['username']); ?></div>
                                    <?php if ($user['user_id'] == $_SESSION['user_id']): ?>
                                        <small class="text-muted">(You)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                        <span class="user-status active">Active</span>
                                    <?php else: ?>
                                        <span class="user-status inactive">Inactive</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <button class="btn btn-sm ms-2 toggle-active-btn" 
                                            data-user-id="<?php echo $user['user_id']; ?>"
                                            data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                            data-status="<?php echo $user['is_active']; ?>"
                                            title="<?php echo $user['is_active'] ? 'Deactivate User' : 'Activate User'; ?>">
                                            <i class="fas fa-toggle-<?php echo $user['is_active'] ? 'on text-success' : 'off text-secondary'; ?>"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm d-inline-flex align-items-center justify-content-center">
                                        <a href="<?php echo APP_URL; ?>/app/views/admin/users/edit_user.php?id=<?php echo $user['user_id']; ?>" class="btn btn-forest-light" title="Edit User">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <a href="#" class="btn btn-forest-light text-danger delete-user-btn" 
                                                title="Delete User"
                                                data-user-id="<?php echo $user['user_id']; ?>"
                                                data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($admin_users)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No admin users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Agency Users Table Card (Simplified for AJAX) -->
    <div class="card admin-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title m-0">Agency Users</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive w-100">
                <table class="table table-forest mb-0">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Agency</th>
                            <th>Sector</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th class="text-center" style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($agency_users as $user): ?>
                            <tr class="<?php echo !$user['is_active'] ? 'user-inactive' : ''; ?>">
                                <td>
                                    <div class="fw-medium text-forest"><?php echo htmlspecialchars($user['username']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($user['agency_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($user['sector_name'] ?? '-'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                    
                                    <button class="btn btn-sm ms-2 toggle-active-btn" 
                                        data-user-id="<?php echo $user['user_id']; ?>"
                                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                        data-status="<?php echo $user['is_active']; ?>"
                                        title="<?php echo $user['is_active'] ? 'Deactivate User' : 'Activate User'; ?>">
                                        <i class="fas fa-toggle-<?php echo $user['is_active'] ? 'on text-success' : 'off text-secondary'; ?>"></i>
                                    </button>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm d-inline-flex align-items-center justify-content-center">
                                        <a href="<?php echo APP_URL; ?>/app/views/admin/users/edit_user.php?id=<?php echo $user['user_id']; ?>" class="btn btn-outline-primary" title="Edit User">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="#" class="btn btn-outline-danger delete-user-btn" 
                                            title="Delete User"
                                            data-user-id="<?php echo $user['user_id']; ?>"
                                            data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($agency_users)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No agency users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    exit; // IMPORTANT: Stop script execution after sending table HTML
}
// END AJAX Table Request HANDLER

// Set page title
$pageTitle = 'Manage Users';

// Process form submissions
$message = '';
$message_type = '';

// Check if there's a message in the session and use it
if (isset($_SESSION['message']) && !empty($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    
    // If show_toast_only is set, we'll only show the toast notification
    $show_toast_only = isset($_SESSION['show_toast_only']) && $_SESSION['show_toast_only'];
    
    // Clear the message from session after using it
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
    if (isset($_SESSION['show_toast_only'])) {
        unset($_SESSION['show_toast_only']);
    }
}

// Handle user actions (add, edit, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Check if this is an AJAX request
        $is_ajax = isset($_POST['ajax_request']) && $_POST['ajax_request'] == '1';
        
        $result = [];
        
        switch ($_POST['action']) {
            case 'check_user_references':
                $references = check_user_references($_POST['user_id']);
                header('Content-Type: application/json');
                echo json_encode($references);
                exit;
                
            case 'add_user':
                $result = add_user($_POST);
                if (isset($result['success'])) {
                    $message = 'User added successfully.';
                    $message_type = 'success';
                    
                    // Store in session for redirect
                    $_SESSION['message'] = $message;
                    $_SESSION['message_type'] = $message_type;
                    
                    // Redirect to clear the form and prevent resubmission
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $message = $result['error'] ?? 'Failed to add user.';
                    $message_type = 'danger';
                }
                break;

            case 'edit_user':
                $result = update_user($_POST);
                if (isset($result['success'])) {
                    $message = 'User updated successfully.';
                    $message_type = 'success';
                } else {
                    $message = $result['error'] ?? 'Failed to update user.';
                    $message_type = 'danger';
                }
                break;            case 'delete_user':
                $force_soft_delete = isset($_POST['force_soft_delete']) && $_POST['force_soft_delete'] == '1';
                $result = delete_user($_POST['user_id'], $force_soft_delete);
                
                if (isset($result['success'])) {
                    $message = $result['message'];
                    $message_type = 'success';
                    
                    // Store in session for redirect if not AJAX
                    if (!$is_ajax) {
                        $_SESSION['message'] = $message;
                        $_SESSION['message_type'] = $message_type;
                        $_SESSION['show_toast_only'] = true; // Use toast for better UX
                        
                        // Redirect to clear the form and prevent resubmission
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit;
                    }
                } else {
                    $message = $result['error'] ?? 'Failed to delete user.';
                    $message_type = 'danger';
                    
                    // If it's a foreign key constraint issue, provide more context
                    if (isset($result['has_references']) && $result['has_references']) {
                        $result['suggest_alternatives'] = true;
                    }
                }
                break;
        }
        
        // If this was an AJAX request, return JSON response instead of setting message variables
        if ($is_ajax) {
            header('Content-Type: application/json');
            if ($message_type === 'success') {
                $response = ['success' => true, 'message' => $message];
                if (isset($result['action'])) {
                    $response['action'] = $result['action'];
                }
                echo json_encode($response);
            } else {
                $response = ['error' => $message];
                if (isset($result['has_references'])) {
                    $response['has_references'] = $result['has_references'];
                    $response['reference_details'] = $result['reference_details'] ?? [];
                    $response['suggest_soft_delete'] = $result['suggest_soft_delete'] ?? false;
                }
                echo json_encode($response);
            }
            exit;
        }
    }
}

// Get all users and separate them by role (include inactive users to show them differently)
$all_users = get_all_users(true); // Include inactive users
$admin_users = array_filter($all_users, function($user) {
    return $user['role'] === 'admin';
});
$agency_users = array_filter($all_users, function($user) {
    return $user['role'] === 'agency' || $user['role'] === 'focal';
});

// Get all sectors for dropdown
$sectors = get_all_sectors();

// Additional scripts
$additionalScripts = [
    APP_URL . '/assets/js/admin/toast_manager.js', 
    APP_URL . '/assets/js/admin/user_form_manager.js',
    APP_URL . '/assets/js/admin/user_table_manager.js',
    APP_URL . '/assets/js/admin/users.js'  // Make sure this file actually exists
];

// Pass sectors data directly to JavaScript
$sectorsJson = json_encode($sectors);

// Include header
require_once '../../layouts/header.php';

// Configure the modern page header
$header_config = [
    'title' => 'User Management',
    'subtitle' => 'Create and manage user accounts for the system',
    'variant' => 'green',
    'actions' => [
        [
            'text' => 'Add New User',
            'url' => APP_URL . '/app/views/admin/users/add_user.php',
            'class' => 'btn-light',
            'icon' => 'fas fa-user-plus'
        ]
    ]
];

// Include the modern page header
require_once '../../layouts/page_header.php';
?>

<!-- Make APP_URL and other data available to JavaScript -->
<script>
    // Define APP_URL for JavaScript to fix the "APP_URL is not defined" error
    window.APP_URL = '<?php echo APP_URL; ?>';
    
    // Make sectors data available to the users.js script
    window.sectorsData = <?php echo $sectorsJson; ?>;
    
    // Store any success/error messages for toast notifications - always use toast for AJAX responses
    window.pageMessages = {
        message: '<?php echo addslashes($message); ?>',
        type: '<?php echo $message_type; ?>',
        // Always use toast for ajax responses or when explicitly requested
        useToast: <?php echo (!empty($message) && (isset($show_toast_only) && $show_toast_only)) ? 'true' : 'false'; ?>
    };
</script>

<?php if (!empty($message) && empty($show_toast_only)): ?>
    <div class="alert alert-forest alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> alert-icon"></i>
            <div><?php echo $message; ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>    </div>
<?php endif; ?>

<!-- User Management Content -->
<main class="flex-fill">
    <div id="userTablesWrapper">
    <!-- Admin Users Table Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title m-0">Admin Users</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive w-100">
                <table class="table table-hover table-custom user-table mb-0">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th class="text-center" style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($admin_users as $user): ?>
                            <tr class="<?php echo !$user['is_active'] ? 'inactive-user' : ''; ?>">
                                <td>
                                    <div class="fw-medium"><?php echo $user['username']; ?></div>
                                    <?php if ($user['user_id'] == $_SESSION['user_id']): ?>
                                        <small class="text-muted">(You)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="user-status active">Active</span>
                                <?php else: ?>
                                    <span class="user-status inactive">Inactive</span>
                                <?php endif; ?>
                                
                                <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                    <button class="btn btn-sm ms-2 toggle-active-btn" 
                                        data-user-id="<?php echo $user['user_id']; ?>"
                                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                        data-status="<?php echo $user['is_active']; ?>"
                                        title="<?php echo $user['is_active'] ? 'Deactivate User' : 'Activate User'; ?>">
                                        <i class="fas fa-toggle-<?php echo $user['is_active'] ? 'on text-success' : 'off text-secondary'; ?>"></i>
                                    </button>
                                <?php endif; ?>
                            </td>                            <td class="text-center">
                                <div class="btn-group btn-group-sm d-inline-flex align-items-center justify-content-center">
                                    <a href="<?php echo APP_URL; ?>/app/views/admin/users/edit_user.php?id=<?php echo $user['user_id']; ?>" class="btn btn-forest-light" title="Edit User">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <a href="#" class="btn btn-forest-light text-danger delete-user-btn" 
                                            title="Delete User"
                                            data-user-id="<?php echo $user['user_id']; ?>"
                                            data-username="<?php echo $user['username']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Agency Users Table Card -->
<div class="card admin-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title m-0">Agency Users</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive w-100">
            <table class="table table-forest mb-0">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Agency</th>
                        <th>Sector</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th class="text-center" style="width: 100px;">Actions</th>
                    </tr>
                </thead>                <tbody>
                    <?php foreach($agency_users as $user): ?>
                        <tr class="<?php echo !$user['is_active'] ? 'user-inactive' : ''; ?>">
                            <td>
                                <div class="fw-medium text-forest"><?php echo $user['username']; ?></div>
                            </td>
                            <td><?php echo $user['agency_name'] ?? '-'; ?></td>
                            <td><?php echo $user['sector_name'] ?? '-'; ?></td>
                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                                
                                <button class="btn btn-sm ms-2 toggle-active-btn" 
                                    data-user-id="<?php echo $user['user_id']; ?>"
                                    data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                    data-status="<?php echo $user['is_active']; ?>"
                                    title="<?php echo $user['is_active'] ? 'Deactivate User' : 'Activate User'; ?>">
                                    <i class="fas fa-toggle-<?php echo $user['is_active'] ? 'on text-success' : 'off text-secondary'; ?>"></i>
                                </button>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm d-inline-flex align-items-center justify-content-center">
                                    <a href="<?php echo APP_URL; ?>/app/views/admin/users/edit_user.php?id=<?php echo $user['user_id']; ?>" class="btn btn-outline-primary" title="Edit User">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="#" class="btn btn-outline-danger delete-user-btn" 
                                        title="Delete User"
                                        data-user-id="<?php echo $user['user_id']; ?>"
                                        data-username="<?php echo $user['username']; ?>">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div> <!-- End of userTablesWrapper -->
</main>

<?php
// Include footer
require_once '../../layouts/footer.php';
?>



