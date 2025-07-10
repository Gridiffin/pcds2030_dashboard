<?php
/**
 * Admin Edit Program - Rewritten to match agency pattern with admin functions
 * 
 * Allows admin users to edit program details with proper date handling
 * Based on agency update_program.php pattern with admin-specific features
 */

// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admin_functions.php';
require_once ROOT_PATH . 'app/lib/rating_helpers.php';
require_once ROOT_PATH . 'app/lib/agencies/programs.php';
require_once ROOT_PATH . 'app/lib/audit_log.php';
require_once ROOT_PATH . 'app/lib/agencies/program_attachments.php';
require_once ROOT_PATH . 'app/lib/numbering_helpers.php';
require_once ROOT_PATH . 'app/lib/initiative_functions.php';
require_once ROOT_PATH . 'app/lib/admins/statistics.php'; // Added for admin program functions

// Verify user is admin
if (!is_admin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Get program ID from query parameter
$program_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($program_id <= 0) {
    $_SESSION['message'] = 'Invalid program ID.';
    $_SESSION['message_type'] = 'danger';
    header('Location: manage_programs.php');
    exit;
}

// Initialize result variable for AJAX responses
$result = null;

// Admin users can edit any program (cross-agency access)
$program = get_admin_program_details($program_id);

if (!$program) {
    $_SESSION['message'] = 'Program not found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: programs.php');
    exit;
}

// Get program edit history with pagination
$history_page = isset($_GET['history_page']) ? max(1, intval($_GET['history_page'])) : 1;
$program_history = get_program_edit_history_paginated($program_id, $history_page, 5); // 5 entries per page

// Get active initiatives for dropdown
$active_initiatives = get_initiatives_for_select(true);

// Load existing attachments for this program
$existing_attachments = get_program_attachments($program_id);

// Get current reporting period for submissions
$current_period = get_current_reporting_period();

// If no current period, use the latest period
if (!$current_period) {
    $latest_period_query = "SELECT * FROM reporting_periods ORDER BY year DESC, quarter DESC LIMIT 1";
    $latest_result = $conn->query($latest_period_query);
    if ($latest_result && $latest_result->num_rows > 0) {
        $current_period = $latest_result->fetch_assoc();
    }
}

// Admin function: check if field is editable (admins can edit most fields)
function is_admin_editable($field) {
    // Admins can edit most fields, with some restrictions for finalized programs
    return true;
}

// Get selected period from query or default to current
$selected_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : null;
$all_periods = [];
$selected_period = null;

// Fetch all periods for selector
if ($conn) {
    $periods_result = $conn->query("SELECT * FROM reporting_periods ORDER BY year DESC, quarter DESC");
    if ($periods_result) {
        while ($row = $periods_result->fetch_assoc()) {
            $all_periods[] = $row;
            if ($selected_period_id && $row['period_id'] == $selected_period_id) {
                $selected_period = $row;
            }
        }
    }
}

if (!$selected_period && $current_period) {
    $selected_period = $current_period;
    $selected_period_id = $current_period['period_id'];
}

if (!$selected_period && !empty($all_periods)) {
    $selected_period = $all_periods[0];
    $selected_period_id = $selected_period['period_id'];
}

// Find the correct submission for the selected period
$submission_id = null;
$current_submission = null;
if (isset($program['submissions']) && is_array($program['submissions'])) {
    foreach ($program['submissions'] as $submission) {
        if (isset($submission['period_id']) && $submission['period_id'] == $selected_period_id) {
            $current_submission = $submission;
            $submission_id = $submission['submission_id'] ?? null;
            break;
        }
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure no output has been sent before processing
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Determine submission type and redirection preference
    $is_draft = isset($_POST['save_draft']);
    $save_and_continue = isset($_POST['save_and_continue']);
    $save_and_exit = isset($_POST['save_and_exit']);
    $finalize_draft = isset($_POST['finalize_draft']);
    
    // Determine if this should be a final submission
    $is_final_submission = $save_and_continue || $save_and_exit || $finalize_draft;
    
    // Determine redirection behavior
    $should_redirect_to_list = $save_and_exit;
    
    if ($finalize_draft && $submission_id) {
        // Handle finalize draft (admin specific)
        if ($selected_period) {
            $content_check = $conn->prepare("SELECT content_json FROM program_submissions WHERE submission_id = ? AND program_id = ? AND period_id = ?");
            $content_check->bind_param("iii", $submission_id, $program_id, $selected_period['period_id']);
            $content_check->execute();
            $content_result = $content_check->get_result();
            
            if ($content_result->num_rows > 0) {
                $content_row = $content_result->fetch_assoc();
                $content_json = $content_row['content_json'];
                
                // Validate content
                if (empty($content_json) || $content_json === 'null') {
                    $result = ['error' => 'Cannot finalize submission without content. Please add targets and rating first.'];
                    log_audit_action('admin_program_finalization_failed', "Admin failed to finalize program (ID: {$program_id}) - no content", 'failure', $_SESSION['user_id']);
                } else {
                    $content_data = json_decode($content_json, true);
                    if (!$content_data || (empty($content_data['targets']) && empty($content_data['target'])) || empty($content_data['rating'])) {
                        $result = ['error' => 'Cannot finalize submission without targets and rating. Please complete the program details first.'];
                        log_audit_action('admin_program_finalization_failed', "Admin failed to finalize program (ID: {$program_id}) - missing targets or rating", 'failure', $_SESSION['user_id']);
                    } else {
                        // Content is valid, proceed with finalization
                        $stmt = $conn->prepare("UPDATE program_submissions SET is_draft = 0, submission_date = NOW() WHERE submission_id = ? AND program_id = ? AND period_id = ?");
                        $stmt->bind_param("iii", $submission_id, $program_id, $selected_period['period_id']);
                        
                        if ($stmt->execute() && $stmt->affected_rows > 0) {
                            $result = ['success' => true, 'message' => 'Draft has been finalized successfully. The program is now marked as final.'];
                            log_audit_action('admin_program_finalized', "Admin finalized program '{$program['program_name']}' (ID: {$program_id}) for period {$selected_period['period_id']}", 'success', $_SESSION['user_id']);
                        } else {
                            $result = ['error' => 'Failed to finalize draft. Submission may not exist for selected period.'];
                            log_audit_action('admin_program_finalization_failed', "Admin failed to finalize program (ID: {$program_id}) - database error", 'failure', $_SESSION['user_id']);
                        }
                    }
                }
            } else {
                $result = ['error' => 'Submission not found.'];
            }
        } else {
            $result = ['error' => 'No reporting period selected.'];
        }
    } else {
        // Handle save/update program data (admin version with enhanced permissions)
        try {
            $conn->begin_transaction();
            
            // STEP 1: Capture current state before making any changes
            $before_state = get_current_program_state($program_id);
            
            // Get form data with proper sanitization and copy-paste handling
            $program_name = sanitize_copy_paste_content($_POST['program_name'] ?? '', false);
            $program_number = sanitize_copy_paste_content($_POST['program_number'] ?? '', false);
            $brief_description = sanitize_copy_paste_content($_POST['brief_description'] ?? '', true);
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $initiative_id = !empty($_POST['initiative_id']) ? intval($_POST['initiative_id']) : null;
            $owner_agency_id = intval($_POST['owner_agency_id'] ?? 0);
            $sector_id = intval($_POST['sector_id'] ?? 0);
            $is_assigned = isset($_POST['is_assigned']) ? 1 : 0;
            $rating = $_POST['rating'] ?? 'not-started';
            $remarks = sanitize_copy_paste_content($_POST['remarks'] ?? '', true);
            $period_id = intval($_POST['period_id'] ?? $selected_period_id);
            $submission_id = intval($_POST['submission_id'] ?? 0);
            $current_user_id = $_SESSION['user_id'];
            
            // Admin-specific: Handle edit permissions
            $edit_permissions = isset($_POST['edit_permissions']) ? $_POST['edit_permissions'] : [];
            $program_settings = ['edit_permissions' => $edit_permissions];
            $edit_permissions_json = json_encode($program_settings);
            
            // Validate required fields
            if (empty($program_name)) {
                throw new Exception('Program name is required.');
            }
            if ($owner_agency_id <= 0) {
                throw new Exception('Valid owner agency is required.');
            }
            if ($sector_id <= 0) {
                throw new Exception('Valid sector is required.');
            }
            
            // Validate date formats if provided
            if ($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
                throw new Exception('Invalid start date format. Please use YYYY-MM-DD format.');
            }
            if ($end_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                throw new Exception('Invalid end date format. Please use YYYY-MM-DD format.');
            }
            
            // Validate that dates are actual valid dates
            if ($start_date && !strtotime($start_date)) {
                throw new Exception('Invalid start date. Please enter a valid date.');
            }
            if ($end_date && !strtotime($end_date)) {
                throw new Exception('Invalid end date. Please enter a valid date.');
            }
            
            // Validate program_number format if provided
            if (!empty($program_number) && !is_valid_program_number_format($program_number, false)) {
                throw new Exception(get_program_number_format_error(false));
            }
            
            // Additional validation for hierarchical format if initiative is linked
            if ($program_number && $initiative_id) {
                $format_validation = validate_program_number_format($program_number, $initiative_id);
                if (!$format_validation['valid']) {
                    throw new Exception($format_validation['message']);
                }
                
                // Check if number is already in use (excluding current program)
                if (!is_program_number_available($program_number, $program_id)) {
                    throw new Exception('Program number is already in use.');
                }
            }
            
            // Process targets array with enhanced structure
            $targets = [];
            if (isset($_POST['target_text']) && is_array($_POST['target_text'])) {
                $target_texts = $_POST['target_text'];
                $target_status_descriptions = $_POST['target_status_description'] ?? [];
                $target_numbers = $_POST['target_number'] ?? [];
                $target_statuses = $_POST['target_status'] ?? [];
                $target_start_dates = $_POST['target_start_date'] ?? [];
                $target_end_dates = $_POST['target_end_date'] ?? [];
                
                for ($i = 0; $i < count($target_texts); $i++) {
                    $target_text = sanitize_copy_paste_content($target_texts[$i], true);
                    if (!empty($target_text)) {
                        $target_number = sanitize_copy_paste_content($target_numbers[$i] ?? '', false);
                        
                        // Validate target number format if provided
                        if (!empty($target_number)) {
                            if (!is_valid_target_number_format($target_number, $program_number)) {
                                $_SESSION['message'] = get_target_number_format_error($program_number);
                                $_SESSION['message_type'] = 'danger';
                                header('Location: edit_program.php?id=' . $program_id);
                                exit;
                            }
                            
                            // Check target number hierarchy
                            $hierarchy_validation = validate_target_number_hierarchy($target_number, $program_number);
                            if (!$hierarchy_validation['valid']) {
                                $_SESSION['message'] = $hierarchy_validation['message'];
                                $_SESSION['message_type'] = 'danger';
                                header('Location: edit_program.php?id=' . $program_id);
                                exit;
                            }
                        }
                        
                        // Validate date range if both dates provided
                        $start_date = !empty($target_start_dates[$i]) ? trim($target_start_dates[$i]) : null;
                        $end_date = !empty($target_end_dates[$i]) ? trim($target_end_dates[$i]) : null;
                        
                        if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                            $_SESSION['message'] = "Target " . ($i + 1) . ": End date cannot be before start date.";
                            $_SESSION['message_type'] = 'danger';
                            header('Location: edit_program.php?id=' . $program_id);
                            exit;
                        }
                        
                        $targets[] = [
                            'target_number' => $target_number,
                            'target_text' => $target_text,
                            'status_description' => sanitize_copy_paste_content($target_status_descriptions[$i] ?? '', true),
                            'target_status' => trim($target_statuses[$i] ?? 'not-started'),
                            'start_date' => $start_date,
                            'end_date' => $end_date
                        ];
                    }
                }
            }
            
            // 1. Update program basic information (admin can edit all fields)
            $program_query = "UPDATE programs SET 
                             program_name = ?, 
                             program_number = ?,
                             initiative_id = ?,
                             owner_agency_id = ?, 
                             sector_id = ?,
                             start_date = ?, 
                             end_date = ?,
                             is_assigned = ?,
                             edit_permissions = ?,
                             updated_at = NOW()
                             WHERE program_id = ?";
                             
            $program_stmt = $conn->prepare($program_query);
            // FIXED: Correct parameter binding types: s,s,i,i,i,s,s,i,s,i
            $program_stmt->bind_param('ssiisssisi', 
                $program_name, 
                $program_number,
                $initiative_id,
                $owner_agency_id,
                $sector_id,
                $start_date,
                $end_date,
                $is_assigned,
                $edit_permissions_json,
                $program_id
            );
            
            if (!$program_stmt->execute()) {
                throw new Exception('Failed to update program: ' . $program_stmt->error);
            }
            
            // STEP 2: Handle Program-Outcome Links
            // Get new outcome IDs from form
            $new_outcome_ids = isset($_POST['outcome_id']) ? array_filter($_POST['outcome_id']) : [];
            
            // First, delete existing links for this program
            $delete_links_query = $conn->prepare("DELETE FROM program_outcome_links WHERE program_id = ?");
            $delete_links_query->bind_param("i", $program_id);
            if (!$delete_links_query->execute()) {
                throw new Exception('Failed to remove existing outcome links: ' . $delete_links_query->error);
            }
            
            // Then, insert new links
            if (!empty($new_outcome_ids)) {
                $insert_link_query = $conn->prepare("INSERT INTO program_outcome_links (program_id, outcome_id, created_by, created_at) VALUES (?, ?, ?, NOW())");
                foreach ($new_outcome_ids as $outcome_id) {
                    if (!empty($outcome_id) && is_numeric($outcome_id)) {
                        $insert_link_query->bind_param("iii", $program_id, $outcome_id, $current_user_id);
                        if (!$insert_link_query->execute()) {
                            throw new Exception('Failed to create outcome link: ' . $insert_link_query->error);
                        }
                    }
                }
            }

            // STEP 3: Build new state and generate changes
            // Get agency and sector names for comparison
            $agency_name = '';
            $sector_name = '';
            if ($owner_agency_id) {
                $agency_query = $conn->prepare("SELECT agency_name FROM users WHERE user_id = ?");
                $agency_query->bind_param("i", $owner_agency_id);
                $agency_query->execute();
                $agency_result = $agency_query->get_result();
                if ($agency_row = $agency_result->fetch_assoc()) {
                    $agency_name = $agency_row['agency_name'];
                }
            }
            if ($sector_id) {
                $sector_query = $conn->prepare("SELECT sector_name FROM sectors WHERE sector_id = ?");
                $sector_query->bind_param("i", $sector_id);
                $sector_query->execute();
                $sector_result = $sector_query->get_result();
                if ($sector_row = $sector_result->fetch_assoc()) {
                    $sector_name = $sector_row['sector_name'];
                }
            }
            
            // Build after state
            $after_state = [
                'program_name' => $program_name,
                'program_number' => $program_number,
                'brief_description' => $brief_description,
                'owner_agency_name' => $agency_name,
                'sector_name' => $sector_name,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'is_assigned' => $is_assigned,
                'rating' => $rating,
                'remarks' => $remarks,
                'targets' => $targets,
                'edit_permissions' => $edit_permissions_json,
                'linked_outcomes' => $new_outcome_ids
            ];
            
            // Generate changes made during this save session
            $changes_made = generate_field_changes($before_state, $after_state);
            
            // 2. Handle program submission data
            $content_data = [
                'rating' => $rating,
                'targets' => $targets,
                'remarks' => $remarks,
                'brief_description' => $brief_description,
                'program_name' => $program_name,
                'program_number' => $program_number,
                'changes_made' => $changes_made  // Add the before/after changes
            ];
            $content_json = json_encode($content_data);
            
            // Create submission record for history tracking
            if ($period_id > 0) {
                $submission_query = "INSERT INTO program_submissions 
                                   (program_id, period_id, submitted_by, content_json, is_draft, submission_date, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                $submission_stmt = $conn->prepare($submission_query);
                $is_draft_value = $is_draft ? 1 : 0;  // Draft only if specifically save_draft
                $submission_stmt->bind_param("iiisi", $program_id, $period_id, $current_user_id, $content_json, $is_draft_value);
                
                if (!$submission_stmt->execute()) {
                    throw new Exception('Failed to create submission record: ' . $submission_stmt->error);
                }
            }
            
            $conn->commit();
            
            // Create appropriate success message based on action
            if ($is_draft) {
                $result = ['success' => true, 'message' => 'Program saved as draft successfully. You can continue editing anytime.'];
            } elseif ($save_and_continue) {
                $result = ['success' => true, 'message' => 'Program saved as final version successfully. You can continue editing if needed.'];
            } elseif ($save_and_exit) {
                $result = ['success' => true, 'message' => 'Program saved as final version successfully.'];
            } else {
                $result = ['success' => true, 'message' => 'Program updated successfully.'];
            }
            
            // Log successful update
            log_audit_action(
                'admin_program_edited',
                "Admin edited program '{$program_name}' (ID: {$program_id}) - Owner: Agency {$owner_agency_id}, Sector: {$sector_id}",
                'success',
                $_SESSION['user_id']
            );
            
        } catch (Exception $e) {
            $conn->rollback();
            $result = ['error' => 'Operation failed: ' . $e->getMessage()];
            
            // Log failure
            log_audit_action(
                'admin_program_edit_failed',
                "Admin failed to edit program (ID: {$program_id}): " . $e->getMessage(),
                'failure',
                $_SESSION['user_id']
            );
        }
    }
    
    // Handle AJAX responses
    if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    
    // Handle regular form responses - set session messages
    if ($result) {
        if (isset($result['success'])) {
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $result['error'];
            $_SESSION['message_type'] = 'danger';
        }
    }
    
    // Force redirection based on user choice
    if ($should_redirect_to_list && isset($result['success'])) {
        // Redirect to programs list for "Save & Exit"
        $full_redirect_url = APP_URL . '/app/views/admin/programs/programs.php';
    } else {
        // Stay on edit page for drafts and "Save & Continue"
        $redirect_url = 'edit_program.php?id=' . $program_id;
        if ($selected_period_id) {
            $redirect_url .= '&period_id=' . $selected_period_id;
        }
        $full_redirect_url = APP_URL . '/app/views/admin/programs/' . $redirect_url;
    }
    
    // Check if headers can be sent
    if (!headers_sent()) {
        header('Location: ' . $full_redirect_url);
        exit;
    } else {
        // Fallback: JavaScript redirect if headers already sent
        echo '<script>window.location.href = "' . htmlspecialchars($full_redirect_url) . '";</script>';
        exit;
    }
}

// Get agencies and sectors for dropdowns (including focal users)
$agencies = [];
$agencies_result = $conn->query("SELECT user_id as agency_id, agency_name FROM users WHERE role IN ('agency', 'focal') AND is_active = 1 ORDER BY agency_name");
if ($agencies_result) {
    while ($row = $agencies_result->fetch_assoc()) {
        $agencies[] = $row;
    }
}

$sectors = [];
$sectors_result = $conn->query("SELECT sector_id, sector_name FROM sectors ORDER BY sector_name");
if ($sectors_result) {
    while ($row = $sectors_result->fetch_assoc()) {
        $sectors[] = $row;
    }
}

// Extract edit permissions for admin interface
$edit_permissions = [];
if (!empty($program['edit_permissions'])) {
    $permissions_data = json_decode($program['edit_permissions'], true);
    if (isset($permissions_data['edit_permissions']) && is_array($permissions_data['edit_permissions'])) {
        $edit_permissions = $permissions_data['edit_permissions'];
    }
}

// Get current submission content for form population
$current_rating = 'not-started';
$current_targets = [];
$current_remarks = '';
$current_brief_description = $program['brief_description'] ?? '';

if ($current_submission && !empty($current_submission['content_json'])) {
    $content_data = json_decode($current_submission['content_json'], true);
    if ($content_data) {
        $current_rating = $content_data['rating'] ?? 'not-started';
        $current_remarks = $content_data['remarks'] ?? '';
        if (isset($content_data['brief_description'])) {
            $current_brief_description = $content_data['brief_description'];
        }
        
        // Handle both new and legacy target structures
        if (isset($content_data['targets']) && is_array($content_data['targets'])) {
            // New enhanced structure
            $current_targets = [];
            foreach ($content_data['targets'] as $target_data) {
                $current_targets[] = [
                    'target_number' => $target_data['target_number'] ?? '',
                    'target_text' => $target_data['target_text'] ?? '',
                    'status_description' => $target_data['status_description'] ?? '',
                    'target_status' => $target_data['target_status'] ?? 'not-started',
                    'start_date' => $target_data['start_date'] ?? null,
                    'end_date' => $target_data['end_date'] ?? null
                ];
            }
        } else {
            // Legacy structure fallback - convert to new structure
            $current_targets = [[
                'target_number' => '',
                'target_text' => $content_data['target'] ?? '',
                'status_description' => $content_data['status_text'] ?? '',
                'target_status' => 'not-started',
                'start_date' => null,
                'end_date' => null
            ]];
        }
    }
}

// Ensure at least one empty target for new programs
if (empty($current_targets)) {
    $current_targets = [[
        'target_number' => '',
        'target_text' => '',
        'status_description' => '',
        'target_status' => 'not-started',
        'start_date' => null,
        'end_date' => null
    ]];
}

// Get available outcomes and existing program-outcome links
$available_outcomes = [];
$outcomes_result = $conn->query("SELECT detail_id, detail_name FROM outcomes_details ORDER BY detail_name");
if ($outcomes_result) {
    while ($row = $outcomes_result->fetch_assoc()) {
        $available_outcomes[] = $row;
    }
}

// Get currently linked outcomes for this program
$linked_outcomes = [];
$linked_query = $conn->prepare("SELECT pol.outcome_id, od.detail_name 
                               FROM program_outcome_links pol
                               JOIN outcomes_details od ON pol.outcome_id = od.detail_id
                               WHERE pol.program_id = ?");
$linked_query->bind_param("i", $program_id);
$linked_query->execute();
$linked_result = $linked_query->get_result();
while ($row = $linked_result->fetch_assoc()) {
    $linked_outcomes[] = $row['outcome_id'];
}

// Page title and breadcrumbs
$page_title = 'Edit Program - ' . htmlspecialchars($program['program_name']);

// Include header
require_once '../../layouts/header.php';

// Configure the modern page header
$header_config = [
    'title' => 'Edit Program',
    'subtitle' => 'Administrative program editing with enhanced permissions',
    'variant' => 'blue',
    'actions' => [
        [
            'text' => 'Back to Programs',
            'url' => 'programs.php',
            'class' => 'btn-light',
            'icon' => 'fas fa-arrow-left'
        ]
    ]
];

// Include the modern page header
require_once '../../layouts/page_header.php';
?>

<style>
/* Override the circular target number styling for admin edit form */
#edit-program-form .target-number {
    display: block !important;
    width: auto !important;
    height: auto !important;
    background: none !important;
    color: var(--bs-dark) !important;
    border-radius: 0 !important;
    font-size: 1rem !important;
    font-weight: 600 !important;
    padding: 0 !important;
    margin: 0 !important;
}
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible alert-permanent show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        Program Details
                        <span class="badge bg-primary ms-2">Admin Edit Mode</span>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="edit-program-form">
                        <input type="hidden" name="period_id" value="<?php echo $selected_period_id; ?>">
                        <input type="hidden" name="submission_id" value="<?php echo $submission_id; ?>">
                        
                        <!-- Period Selector for Admin -->
                        <?php if (!empty($all_periods)): ?>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="period_selector" class="form-label">
                                    <i class="fas fa-calendar-alt me-1"></i> Reporting Period
                                </label>
                                <select class="form-select" id="period_selector" onchange="changePeriod()">
                                    <?php foreach ($all_periods as $period): ?>
                                        <option value="<?php echo $period['period_id']; ?>" 
                                                <?php echo ($period['period_id'] == $selected_period_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($period['year'] . ' Q' . $period['quarter']); ?>
                                            <?php if (isset($period['status']) && $period['status'] === 'active'): ?>
                                                <span class="text-success">(Active)</span>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Basic Program Information -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="program_name" class="form-label">Program Name *</label>
                                    <input type="text" class="form-control" id="program_name" name="program_name" 
                                           value="<?php echo htmlspecialchars($program['program_name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="program_number" class="form-label">Program Number</label>
                                    <input type="text" class="form-control" id="program_number" name="program_number" 
                                           value="<?php echo htmlspecialchars($program['program_number'] ?? ''); ?>"
                                           pattern="[\w.]+"
                                           title="Program number can contain letters, numbers, and dots"
                                           placeholder="e.g., 31.1, 31.2A, 31.25.6, 31.2A.3B">
                                    <div class="form-text">Optional. Flexible format supporting letters, numbers, and dots.</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="initiative_id" class="form-label">Linked Initiative</label>
                                    <select class="form-select" id="initiative_id" name="initiative_id">
                                        <option value="">Select Initiative (Optional)</option>
                                        <?php foreach ($active_initiatives as $initiative): ?>
                                            <option value="<?php echo $initiative['initiative_id']; ?>" 
                                                    <?php echo ($program['initiative_id'] == $initiative['initiative_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($initiative['initiative_number'] . ' - ' . $initiative['initiative_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="owner_agency_id" class="form-label">Owner Agency *</label>
                                    <select class="form-select" id="owner_agency_id" name="owner_agency_id" required>
                                        <option value="">Select Agency</option>
                                        <?php foreach ($agencies as $agency): ?>
                                            <option value="<?php echo $agency['agency_id']; ?>" 
                                                    <?php echo ($program['owner_agency_id'] == $agency['agency_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($agency['agency_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="sector_id" class="form-label">Sector *</label>
                                    <select class="form-select" id="sector_id" name="sector_id" required>
                                        <option value="">Select Sector</option>
                                        <?php foreach ($sectors as $sector): ?>
                                            <option value="<?php echo $sector['sector_id']; ?>" 
                                                    <?php echo ($program['sector_id'] == $sector['sector_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sector['sector_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" 
                                           value="<?php echo $program['start_date']; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" 
                                           value="<?php echo $program['end_date']; ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Admin-specific settings -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_assigned" name="is_assigned" 
                                               <?php echo $program['is_assigned'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_assigned">
                                            Assigned Program
                                        </label>
                                    </div>
                                    <div class="form-text">Assigned programs have restricted edit permissions for agencies.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Agency Edit Permissions</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="edit_permissions[]" value="program_name" 
                                               <?php echo in_array('program_name', $edit_permissions) ? 'checked' : ''; ?>>
                                        <label class="form-check-label">Program Name</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="edit_permissions[]" value="start_date" 
                                               <?php echo in_array('start_date', $edit_permissions) ? 'checked' : ''; ?>>
                                        <label class="form-check-label">Start Date</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="edit_permissions[]" value="end_date" 
                                               <?php echo in_array('end_date', $edit_permissions) ? 'checked' : ''; ?>>
                                        <label class="form-check-label">End Date</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Program Content -->
                        <div class="mb-3">
                            <label for="brief_description" class="form-label">Brief Description</label>
                            <textarea class="form-control" id="brief_description" name="brief_description" rows="3"><?php echo htmlspecialchars($current_brief_description); ?></textarea>
                        </div>

                        <!-- Targets Section -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title m-0">Program Targets</h5>
                                <span id="target-counter" class="badge bg-primary fs-6">
                                    <i class="fas fa-bullseye me-1"></i>
                                    <span id="target-count"><?php echo count($current_targets); ?></span> 
                                    <?php echo count($current_targets) === 1 ? 'target' : 'targets'; ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <p class="text-muted mb-3">
                                    Define one or more targets for this program, each with its own status and timeline.
                                </p>
                                
                                <div id="targets-container">
                                    <?php foreach ($current_targets as $index => $target): 
                                        $target_number = $target['target_number'] ?? '';
                                        $target_text = $target['target_text'] ?? '';
                                        $status_description = $target['status_description'] ?? '';
                                        $target_status = $target['target_status'] ?? 'not-started';
                                        $start_date = $target['start_date'] ?? null;
                                        $end_date = $target['end_date'] ?? null;
                                        $canDelete = $index > 0; // Only allow deleting additional targets
                                    ?>
                                    <div class="target-entry">
                                        <?php if ($canDelete): ?>
                                        <button type="button" class="btn-close remove-target" aria-label="Remove target"></button>
                                        <?php endif; ?>
                                        
                                        <!-- Target Counter -->
                                        <div class="target-counter-header mb-2">
                                            <h6 class="text-primary fw-bold mb-0">
                                                <i class="fas fa-bullseye me-1"></i>Target #<?php echo $index + 1; ?>
                                            </h6>
                                        </div>
                                        
                                        <!-- Target Number and Status Row -->
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Target Number (Optional)</label>
                                                <input type="text" class="form-control target-number-input" name="target_number[]" 
                                                        value="<?php echo htmlspecialchars($target_number); ?>" 
                                                        placeholder="e.g., <?php echo htmlspecialchars($program['program_number'] ?? '30.1A'); ?>.1">
                                                <div class="form-text">Format: {program_number}.{target_counter}</div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Target Status</label>
                                                <select class="form-select target-status-select" name="target_status[]">
                                                    <option value="not-started" <?php echo ($target_status === 'not-started') ? 'selected' : ''; ?>>Not Started</option>
                                                    <option value="in-progress" <?php echo ($target_status === 'in-progress') ? 'selected' : ''; ?>>In Progress</option>
                                                    <option value="completed" <?php echo ($target_status === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                                    <option value="delayed" <?php echo ($target_status === 'delayed') ? 'selected' : ''; ?>>Delayed</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <!-- Target Text -->
                                        <div class="mb-3">
                                            <label class="form-label target-text-label">Target *</label>
                                            <textarea class="form-control target-input" name="target_text[]" 
                                                    rows="3"
                                                    placeholder="Define a measurable target (e.g., 'Plant 100 trees')"><?php echo htmlspecialchars($target_text); ?></textarea>
                                        </div>
                                        
                                        <!-- Timeline Row -->
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Start Date (Optional)</label>
                                                <input type="date" class="form-control target-start-date" name="target_start_date[]" 
                                                        value="<?php echo $start_date ? date('Y-m-d', strtotime($start_date)) : ''; ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">End Date (Optional)</label>
                                                <input type="date" class="form-control target-end-date" name="target_end_date[]" 
                                                        value="<?php echo $end_date ? date('Y-m-d', strtotime($end_date)) : ''; ?>">
                                            </div>
                                        </div>
                                        
                                        <!-- Status Description -->
                                        <div class="mb-2">
                                            <label class="form-label">Status Description</label>
                                            <textarea class="form-control status-description" name="target_status_description[]" rows="2" 
                                                        placeholder="Describe the current status or progress toward this target"><?php echo htmlspecialchars($status_description); ?></textarea>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <button type="button" id="add-target-btn" class="btn btn-outline-secondary add-target-btn">
                                    <i class="fas fa-plus-circle me-1"></i> Add Another Target
                                </button>
                            </div>
                        </div>

                        <!-- Rating -->
                        <div class="mb-3">
                            <label for="rating" class="form-label">Program Status/Rating</label>
                            <select class="form-select" id="rating" name="rating">
                                <option value="not-started" <?php echo ($current_rating === 'not-started') ? 'selected' : ''; ?>>Not Started</option>
                                <option value="on-track" <?php echo ($current_rating === 'on-track') ? 'selected' : ''; ?>>On Track</option>
                                <option value="delayed" <?php echo ($current_rating === 'delayed') ? 'selected' : ''; ?>>Delayed</option>
                                <option value="completed" <?php echo ($current_rating === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo ($current_rating === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>

                        <!-- Remarks -->
                        <div class="mb-4">
                            <label for="remarks" class="form-label">Remarks</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="3"><?php echo htmlspecialchars($current_remarks); ?></textarea>
                        </div>

                        <!-- Outcomes Section -->
                        <div class="mb-4">
                            <label class="form-label">Program Outcomes</label>
                            <div id="outcomes-container">
                                <?php if (!empty($linked_outcomes)): ?>
                                    <?php foreach ($linked_outcomes as $index => $outcome_id): ?>
                                        <div class="mb-4">
                                            <h6 class="mb-2 fw-bold target-number text-primary">Outcome <?php echo ($index + 1); ?></h6>
                                            <div class="target-item border rounded p-3">
                                                <div class="row">
                                                    <div class="col-md-10">
                                                        <label class="form-label small text-muted">Outcome</label>
                                                        <select class="form-select" name="outcome_id[]">
                                                            <option value="">Select Outcome</option>
                                                            <?php foreach ($available_outcomes as $outcome): ?>
                                                                <option value="<?php echo $outcome['detail_id']; ?>" 
                                                                        <?php echo ($outcome['detail_id'] == $outcome_id) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($outcome['detail_name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2 d-flex align-items-end">
                                                        <button type="button" class="btn btn-outline-danger btn-sm remove-outcome">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary" id="add-outcome">
                                <i class="fas fa-plus me-1"></i> Add Outcome
                            </button>
                        </div>

                        <!-- Form Actions -->
                        <div class="alert alert-info alert-dismissible alert-permanent mb-3">
                            <h6 class="alert-heading"><i class="fas fa-info-circle me-1"></i> Save Options Explained</h6>
                            <small>
                                <strong>Save as Draft:</strong> Save your progress without finalizing. You can continue editing later.<br>
                                <strong>Save & Continue:</strong> Save as final version but stay on this page to make more changes.<br>
                                <strong>Save & Exit:</strong> Save as final version and return to the programs list.<br>
                                <strong>Finalize Draft:</strong> Convert an existing draft to final status.
                            </small>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" name="save_draft" class="btn btn-outline-primary" 
                                    title="Save progress without finalizing - can be edited later">
                                <i class="fas fa-save me-1"></i> Save as Draft
                            </button>
                            <button type="submit" name="save_and_continue" class="btn btn-primary" 
                                    title="Save as final version and continue editing">
                                <i class="fas fa-check me-1"></i> Save & Continue
                            </button>
                            <button type="submit" name="save_and_exit" class="btn btn-success" 
                                    title="Save as final version and return to programs list">
                                <i class="fas fa-check-circle me-1"></i> Save & Exit
                            </button>
                            <?php if ($submission_id && $current_submission && ($current_submission['is_draft'] ?? 1)): ?>
                                <button type="submit" name="finalize_draft" class="btn btn-warning" 
                                        title="Convert current draft to final version">
                                    <i class="fas fa-lock me-1"></i> Finalize This Draft
                                </button>
                            <?php endif; ?>
                            <a href="programs.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Program History (Admin View) -->
            <?php if (!empty($program_history['submissions'])): ?>
                <div class="card mt-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history me-1"></i> Edit History
                            <small class="text-muted">(<?php echo $program_history['pagination']['total']; ?> total entries)</small>
                        </h5>
                        <?php if ($program_history['pagination']['total'] > 5): ?>
                            <small class="text-muted">
                                Showing <?php echo $program_history['pagination']['start_entry']; ?>-<?php echo $program_history['pagination']['end_entry']; ?> 
                                of <?php echo $program_history['pagination']['total']; ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th width="15%">Date</th>
                                        <th width="12%">Period</th>
                                        <th width="15%">Submitted By</th>
                                        <th width="8%">Status</th>
                                        <th width="50%">Changes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($program_history['submissions'] as $submission): ?>
                                        <tr>
                                            <td>
                                                <small><?php echo $submission['formatted_date']; ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($submission['period_display']); ?></small>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo htmlspecialchars($submission['submitted_by_name'] ?? 'Unknown'); ?>
                                                    <?php if (!empty($submission['submitted_by_agency'])): ?>
                                                        <br><span class="text-muted"><?php echo htmlspecialchars($submission['submitted_by_agency']); ?></span>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo ($submission['is_draft'] ?? 0) ? 'bg-warning' : 'bg-success'; ?>">
                                                    <?php echo $submission['is_draft_label']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                // Check if this submission has the new changes_made format
                                                if (isset($submission['content_json']) && !empty($submission['content_json'])) {
                                                    $content = json_decode($submission['content_json'], true);
                                                    if (json_last_error() === JSON_ERROR_NONE && isset($content['changes_made'])) {
                                                        // Display new before/after format
                                                        echo display_before_after_changes($content['changes_made']);
                                                    } else {
                                                        // Fallback: show general submission info
                                                        $changes_summary = [];
                                                        if (isset($content['rating'])) $changes_summary[] = 'Rating: ' . htmlspecialchars($content['rating']);
                                                        if (isset($content['targets']) && is_array($content['targets'])) {
                                                            $changes_summary[] = 'Targets: ' . count($content['targets']) . ' target(s)';
                                                        }
                                                        if (isset($content['remarks']) && !empty($content['remarks'])) {
                                                            $changes_summary[] = 'Remarks updated';
                                                        }
                                                        echo !empty($changes_summary) ? implode('<br>', $changes_summary) : '<span class="text-muted">Legacy submission</span>';
                                                    }
                                                } else {
                                                    // No content available
                                                    echo '<span class="text-muted">No change details available</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination Controls -->
                        <?php if ($program_history['pagination']['pages'] > 1): ?>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div class="text-muted small">
                                    Page <?php echo $program_history['pagination']['current_page']; ?> of <?php echo $program_history['pagination']['pages']; ?>
                                </div>
                                <nav aria-label="Edit history pagination">
                                    <ul class="pagination pagination-sm mb-0">
                                        <!-- Previous Page -->
                                        <?php if ($program_history['pagination']['has_previous']): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?id=<?php echo $program_id; ?>&period_id=<?php echo $selected_period_id; ?>&history_page=<?php echo $program_history['pagination']['current_page'] - 1; ?>">
                                                    <i class="fas fa-chevron-left"></i> Previous
                                                </a>
                                            </li>
                                        <?php else: ?>
                                            <li class="page-item disabled">
                                                <span class="page-link"><i class="fas fa-chevron-left"></i> Previous</span>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <!-- Page Numbers -->
                                        <?php 
                                        $current_page = $program_history['pagination']['current_page'];
                                        $total_pages = $program_history['pagination']['pages'];
                                        
                                        // Calculate page range to display
                                        $start_page = max(1, $current_page - 2);
                                        $end_page = min($total_pages, $current_page + 2);
                                        
                                        // Show first page if not in range
                                        if ($start_page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?id=<?php echo $program_id; ?>&period_id=<?php echo $selected_period_id; ?>&history_page=1">1</a>
                                            </li>
                                            <?php if ($start_page > 2): ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <!-- Page range -->
                                        <?php for ($page = $start_page; $page <= $end_page; $page++): ?>
                                            <li class="page-item <?php echo ($page == $current_page) ? 'active' : ''; ?>">
                                                <a class="page-link" href="?id=<?php echo $program_id; ?>&period_id=<?php echo $selected_period_id; ?>&history_page=<?php echo $page; ?>">
                                                    <?php echo $page; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <!-- Show last page if not in range -->
                                        <?php if ($end_page < $total_pages): ?>
                                            <?php if ($end_page < $total_pages - 1): ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?id=<?php echo $program_id; ?>&period_id=<?php echo $selected_period_id; ?>&history_page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <!-- Next Page -->
                                        <?php if ($program_history['pagination']['has_next']): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?id=<?php echo $program_id; ?>&period_id=<?php echo $selected_period_id; ?>&history_page=<?php echo $program_history['pagination']['current_page'] + 1; ?>">
                                                    Next <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php else: ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">Next <i class="fas fa-chevron-right"></i></span>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif (isset($program_history)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history me-1"></i> Edit History
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            No edit history available for this program yet.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Period selector change handler
function changePeriod() {
    const periodId = document.getElementById('period_selector').value;
    window.location.href = `edit_program.php?id=<?php echo $program_id; ?>&period_id=${periodId}`;
}

// Target management
document.addEventListener('DOMContentLoaded', function() {
    // Add target functionality
    const addTargetBtn = document.getElementById('add-target-btn');
    if (addTargetBtn) {
        const targetsContainer = document.getElementById('targets-container');
        
        // Keep track of the highest target number
        let highestTargetNumber = document.querySelectorAll('.target-entry').length;
        
        // Initialize target counter on page load
        updateTargetCounter(highestTargetNumber);
        
        // Function to update target numbers sequentially and counter
        function updateTargetNumbers() {
            const targetEntries = document.querySelectorAll('.target-entry');
            const targetCount = targetEntries.length;
            
            // Update target counter headers only (not form labels)
            targetEntries.forEach((entry, index) => {
                const counterHeader = entry.querySelector('.target-counter-header h6');
                if (counterHeader) {
                    counterHeader.innerHTML = `<i class="fas fa-bullseye me-1"></i>Target #${index + 1}`;
                }
            });
            
            // Update target counter in header
            updateTargetCounter(targetCount);
        }
        
        // Function to update the target counter badge
        function updateTargetCounter(count) {
            const targetCountElement = document.getElementById('target-count');
            const targetCounter = document.getElementById('target-counter');
            
            if (targetCountElement && targetCounter) {
                targetCountElement.textContent = count;
                
                // Update the text (singular/plural)
                const targetText = count === 1 ? 'target' : 'targets';
                const badgeContent = `<i class="fas fa-bullseye me-1"></i><span id="target-count">${count}</span> ${targetText}`;
                targetCounter.innerHTML = badgeContent;
                
                // Update badge color based on count
                targetCounter.className = 'badge fs-6 ' + (count === 0 ? 'bg-secondary' : count === 1 ? 'bg-primary' : 'bg-success');
            }
        }
        
        addTargetBtn.addEventListener('click', function() {
            // Increment the highest target number
            highestTargetNumber++;
            
            const targetEntry = document.createElement('div');
            targetEntry.className = 'target-entry';
            
            const html = `
                <button type="button" class="btn-close remove-target" aria-label="Remove target"></button>
                
                <!-- Target Counter -->
                <div class="target-counter-header mb-2">
                    <h6 class="text-primary fw-bold mb-0">
                        <i class="fas fa-bullseye me-1"></i>Target #${highestTargetNumber}
                    </h6>
                </div>
                
                <!-- Target Number and Status Row -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Target Number (Optional)</label>
                        <input type="text" class="form-control target-number-input" name="target_number[]" 
                               placeholder="e.g., <?php echo htmlspecialchars($program['program_number'] ?? '30.1A'); ?>.${highestTargetNumber}">
                        <div class="form-text">Format: {program_number}.{target_counter}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Target Status</label>
                        <select class="form-select target-status-select" name="target_status[]">
                            <option value="not-started" selected>Not Started</option>
                            <option value="in-progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="delayed">Delayed</option>
                        </select>
                    </div>
                </div>
                
                <!-- Target Text -->
                <div class="mb-3">
                    <label class="form-label target-text-label">Target *</label>
                    <textarea class="form-control target-input" name="target_text[]" 
                             rows="3"
                             placeholder="Define a measurable target (e.g., 'Plant 100 trees')"></textarea>
                </div>
                
                <!-- Timeline Row -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Start Date (Optional)</label>
                        <input type="date" class="form-control target-start-date" name="target_start_date[]">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Date (Optional)</label>
                        <input type="date" class="form-control target-end-date" name="target_end_date[]">
                    </div>
                </div>
                
                <!-- Status Description -->
                <div class="mb-2">
                    <label class="form-label">Status Description</label>
                    <textarea class="form-control status-description" name="target_status_description[]" rows="2" 
                              placeholder="Describe the current status or progress toward this target"></textarea>
                </div>
            `;
            
            targetEntry.innerHTML = html;
            targetsContainer.appendChild(targetEntry);
            
            // Update target numbers and counter
            updateTargetNumbers();
            
            // Attach remove event listener to the new target
            const removeBtn = targetEntry.querySelector('.remove-target');
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    targetEntry.remove();
                    // Update target numbers after removing
                    updateTargetNumbers();
                });
            }
            
            // Attach date validation listeners
            const startDateInput = targetEntry.querySelector('.target-start-date');
            const endDateInput = targetEntry.querySelector('.target-end-date');
            
            if (startDateInput && endDateInput) {
                endDateInput.addEventListener('change', function() {
                    validateTargetDates(startDateInput, endDateInput);
                });
                
                startDateInput.addEventListener('change', function() {
                    validateTargetDates(startDateInput, endDateInput);
                });
            }
            
            // Attach target number validation
            const targetNumberInput = targetEntry.querySelector('.target-number-input');
            if (targetNumberInput) {
                targetNumberInput.addEventListener('blur', function() {
                    validateTargetNumber(this);
                });
            }
        });
    }
    
    // Initialize existing remove buttons and validation
    document.querySelectorAll('.remove-target').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.target-entry').remove();
            // Update target numbers after removing
            updateTargetNumbers();
        });
    });
    
    // Date validation function
    function validateTargetDates(startInput, endInput) {
        if (startInput.value && endInput.value) {
            const startDate = new Date(startInput.value);
            const endDate = new Date(endInput.value);
            
            if (endDate < startDate) {
                endInput.setCustomValidity('End date must be after start date');
                endInput.classList.add('is-invalid');
            } else {
                endInput.setCustomValidity('');
                endInput.classList.remove('is-invalid');
            }
        }
    }
    
    // Target number validation function
    function validateTargetNumber(input) {
        const value = input.value.trim();
        if (value) {
            // Basic format validation
            const programNumber = document.querySelector('input[name="program_number"]')?.value || '';
            if (programNumber && !value.startsWith(programNumber + '.')) {
                input.setCustomValidity(`Target number must start with ${programNumber}.`);
                input.classList.add('is-invalid');
            } else {
                input.setCustomValidity('');
                input.classList.remove('is-invalid');
            }
        } else {
            input.setCustomValidity('');
            input.classList.remove('is-invalid');
        }
    }
});

// Outcome management
document.addEventListener('DOMContentLoaded', function() {
    const outcomesContainer = document.getElementById('outcomes-container');
    const addOutcomeBtn = document.getElementById('add-outcome');
    
    // Function to update outcome numbers
    function updateOutcomeNumbers() {
        const outcomeContainers = outcomesContainer.children;
        Array.from(outcomeContainers).forEach((container, index) => {
            const numberElement = container.querySelector('.target-number');
            const outcomeNumber = index + 1;
            
            if (numberElement) {
                numberElement.textContent = `Outcome ${outcomeNumber}`;
            }
        });
    }
    
    addOutcomeBtn.addEventListener('click', function() {
        const outcomeCount = outcomesContainer.children.length + 1;
        const outcomeItem = document.createElement('div');
        outcomeItem.className = 'mb-4';
        outcomeItem.innerHTML = `
            <h6 class="mb-2 fw-bold target-number text-primary">Outcome ${outcomeCount}</h6>
            <div class="target-item border rounded p-3">
                <div class="row">
                    <div class="col-md-10">
                        <label class="form-label small text-muted">Outcome</label>
                        <select class="form-select" name="outcome_id[]">
                            <option value="">Select Outcome</option>
                            <?php foreach ($available_outcomes as $outcome): ?>
                                <option value="<?php echo $outcome['detail_id']; ?>">
                                    <?php echo htmlspecialchars($outcome['detail_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-outcome">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        outcomesContainer.appendChild(outcomeItem);
    });
    
    outcomesContainer.addEventListener('click', function(e) {
        if (e.target.closest('.remove-outcome')) {
            // Remove the entire container (mb-4 div) that contains both the counter and target-item
            const containerToRemove = e.target.closest('.mb-4') || e.target.closest('.target-item').parentElement;
            if (containerToRemove) {
                containerToRemove.remove();
                updateOutcomeNumbers(); // Renumber after removal
            }
        }
    });
});
</script>

<?php require_once '../../layouts/footer.php'; ?>
