<?php
/**
 * Update Program
 * 
 * Interface for agency users to update program information.
 */

// DEBUG: Show all errors (remove after debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define the root path
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', rtrim(dirname(dirname(dirname(__DIR__))), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

// Include necessary files
require_once PROJECT_ROOT_PATH . 'config/config.php';
require_once PROJECT_ROOT_PATH . 'lib/db_connect.php';
require_once PROJECT_ROOT_PATH . 'lib/session.php';
require_once PROJECT_ROOT_PATH . 'lib/functions.php';
require_once PROJECT_ROOT_PATH . 'lib/agencies/index.php';
require_once PROJECT_ROOT_PATH . 'lib/rating_helpers.php';
require_once PROJECT_ROOT_PATH . 'lib/audit_log.php';
require_once PROJECT_ROOT_PATH . 'lib/agencies/program_attachments.php';
require_once PROJECT_ROOT_PATH . 'lib/initiative_functions.php';
require_once PROJECT_ROOT_PATH . 'lib/numbering_helpers.php'; // Added for program number validation

// Verify user is an agency or focal user
if (!is_agency() && !is_focal_user()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// --- BEGIN: Impersonate owner for editing if not owner ---
// Get program ID from URL
$program_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$program_id) {
    $_SESSION['message'] = 'Invalid program ID.';
    $_SESSION['message_type'] = 'danger';
    header('Location: view_programs.php');
    exit;
}

// Get program details with cross-agency access for focal users
// But first, impersonate owner if not owner
require_once PROJECT_ROOT_PATH . 'lib/session.php';
$temp_program = get_program_details($program_id, true); // always allow cross-agency for lookup
if ($temp_program && isset($temp_program['owner_agency_id']) && $_SESSION['user_id'] != $temp_program['owner_agency_id']) {
    if (!isset($_SESSION['original_user_id'])) {
        $_SESSION['original_user_id'] = $_SESSION['user_id'];
    }
    $_SESSION['user_id'] = $temp_program['owner_agency_id'];
}
// Now get the program details as the owner
$allow_cross_agency = is_focal_user();
$program = get_program_details($program_id, $allow_cross_agency);
// --- END: Impersonate owner for editing if not owner ---

if (!$program) {
    $_SESSION['message'] = 'Program not found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: view_programs.php');
    exit;
}

// Get program edit history
$program_history = get_program_edit_history($program_id);

// Get active initiatives for dropdown
$active_initiatives = get_initiatives_for_select(true);

// Load existing attachments for this program
$existing_attachments = get_program_attachments($program_id);

/**
 *  Note;
 *       this is commented because SPIE asked to make it posisble to "update"
 *       a program EVEN IF it is finalized for the current period.
 *       If this permission to be revoked, just remove the button @ view_program.php
 *       and uncomment this code block to prevent editing finalized programs.
 */ 

// Check if this program has a finalized (non-draft) submission for the current period
// If it does, redirect to the program details page, as editing is not allowed
// if (isset($program['submissions']) && !empty($program['submissions'])) {
//     $current_period = get_current_reporting_period();
//     foreach ($program['submissions'] as $submission) {
//         if (isset($submission['period_id']) && 
//             $current_period && 
//             $submission['period_id'] == $current_period['period_id'] && 
//             (!isset($submission['is_draft']) || $submission['is_draft'] == 0)) {
//             // Found a finalized submission for current period - redirect to details page
//             $_SESSION['message'] = 'This program has already been finalized for the current reporting period and cannot be edited.';
//             $_SESSION['message_type'] = 'info';
//             header('Location: program_details.php?id=' . $program_id);
//             exit;
//         }
//     }
// }

// Get current reporting period for submissions
$current_period = get_current_reporting_period();

// If no current period, redirect with error
if (!$current_period) {
    $_SESSION['message'] = 'No active reporting period found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: view_programs.php');
    exit;
}

// --- BEGIN: Impersonate owner for editing if not owner ---
if (isset($program['owner_agency_id']) && $_SESSION['user_id'] != $program['owner_agency_id']) {
    // Store original user id for restoration if needed
    if (!isset($_SESSION['original_user_id'])) {
        $_SESSION['original_user_id'] = $_SESSION['user_id'];
    }
    $_SESSION['user_id'] = $program['owner_agency_id'];
}
// --- END: Impersonate owner for editing if not owner ---

// Helper function to check if a field is editable for assigned programs
function is_editable($field) {
    // Allow all users to edit all fields
    return true;
}

// Helper function to get field value from POST, default, or content
function get_field_value($field, $default = '') {
    if (isset($_POST[$field])) {
        return $_POST[$field];
    }
    
    return $default;
}

/**
 * Render paginated field history with Load More functionality
 * 
 * @param array $history_data Complete field history data
 * @param string $field_name Name of the field
 * @param string $history_target_id HTML ID for the history container
 * @param string $button_text Text for the show/hide button
 * @param int $initial_limit Number of items to show initially
 */
function render_paginated_field_history($history_data, $field_name, $history_target_id, $button_text, $initial_limit = 3) {
    global $program_id, $selected_period_id;
    
    if (empty($history_data)) {
        return;
    }
    
    $total_count = count($history_data);
    $initial_items = array_slice($history_data, 0, $initial_limit);
    $has_more = $total_count > $initial_limit;
    
    echo '<div class="d-flex align-items-center mt-2">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary field-history-toggle" ';
    echo 'data-history-target="' . htmlspecialchars($history_target_id) . '">';
    echo '<i class="fas fa-history"></i> ' . htmlspecialchars($button_text);
    echo '</button>';
    echo '</div>';
    
    echo '<div id="' . htmlspecialchars($history_target_id) . '" class="history-complete" style="display: none;">';
    echo '<h6 class="small text-muted mb-2">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $field_name))) . ' History</h6>';
    echo '<ul class="history-list" data-field="' . htmlspecialchars($field_name) . '" data-program-id="' . htmlspecialchars($program_id) . '" data-period-id="' . htmlspecialchars($selected_period_id) . '">';
    
    // Render initial items
    foreach ($initial_items as $idx => $item) {
        render_history_item($item, $field_name);
    }
    
    echo '</ul>';
    
    // Add Load More button if there are more items
    if ($has_more) {
        $remaining_count = $total_count - $initial_limit;
        echo '<div class="load-more-container text-center mt-3">';
        echo '<button type="button" class="btn btn-sm btn-outline-primary load-more-history" ';
        echo 'data-field="' . htmlspecialchars($field_name) . '" ';
        echo 'data-program-id="' . htmlspecialchars($program_id) . '" ';
        echo 'data-period-id="' . htmlspecialchars($selected_period_id) . '" ';
        echo 'data-offset="' . $initial_limit . '" ';
        echo 'data-total="' . $total_count . '">';
        echo '<i class="fas fa-chevron-down me-1"></i>';
        echo 'Load More (' . $remaining_count . ' remaining)';
        echo '</button>';
        echo '<div class="load-more-spinner d-none mt-2">';
        echo '<div class="spinner-border spinner-border-sm" role="status">';
        echo '<span class="visually-hidden">Loading...</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
}

/**
 * Render a single history item
 * 
 * @param array $item History item data
 * @param string $field_name Field name for special formatting
 */
function render_history_item($item, $field_name) {
    echo '<li class="history-list-item">';
    echo '<div class="history-list-value">';
    
    if ($field_name === 'targets' && is_array($item['value'])) {
        foreach($item['value'] as $target_idx => $target) {
            echo '<strong>Target ' . ($target_idx + 1) . ':</strong> ' . 
                 htmlspecialchars($target['target_text'] ?? $target['text'] ?? '') . '<br>';
        }
    } else {
        echo htmlspecialchars($item['value']);
    }
    
    echo '</div>';
    echo '<div class="history-list-meta">';
    echo $item['timestamp'];
    
    if (isset($item['submission_id']) && $item['submission_id'] > 0) {
        $badge_class = ($item['is_draft'] ?? 0) ? 'history-draft-badge' : 'history-final-badge';
        $badge_text = ($item['is_draft'] ?? 0) ? 'Draft' : 'Final';
        echo '<span class="' . $badge_class . '">' . $badge_text . '</span>';
    }
    
    echo '</div>';
    echo '</li>';
}

// Get selected period from query or default to current
$selected_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : null;
$current_period = get_current_reporting_period();
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

// If no period was specified in URL, default to current period
if (!$selected_period_id && $current_period) {
    $selected_period = $current_period;
    $selected_period_id = $current_period['period_id'];
}

// If still no selected period, use the first available period
if (!$selected_period && !empty($all_periods)) {
    $selected_period = $all_periods[0];
    $selected_period_id = $selected_period['period_id'];
}

if (!$selected_period) {
    die('<div style="color:red">No reporting period found.</div>');
}

// Find the correct submission for the selected period
$submission_id = null;
$current_submission = null;
if (isset($program['submissions']) && is_array($program['submissions'])) {
    // Filter submissions for the selected period
    $period_submissions = array_filter($program['submissions'], function($submission) use ($selected_period_id) {
        return isset($submission['period_id']) && $submission['period_id'] == $selected_period_id;
    });
    
    // If there are submissions for this period, get the latest one
    if (!empty($period_submissions)) {
        // Sort by submission_id in descending order to get the latest submission
        usort($period_submissions, function($a, $b) {
            return $b['submission_id'] <=> $a['submission_id'];
        });
        
        $current_submission = reset($period_submissions);
        $submission_id = $current_submission['submission_id'] ?? null;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate that period_id from the form matches the URL parameter
    if (isset($_POST['period_id']) && intval($_POST['period_id']) != $selected_period_id) {
        $_SESSION['message'] = 'Period mismatch. Please try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: update_program.php?id=' . $program_id . '&period_id=' . $selected_period_id);
        exit;
    }
    
    // Determine submission type
    $is_draft = isset($_POST['save_draft']);
    $finalize_draft = isset($_POST['finalize_draft']);    if ($finalize_draft) {
        $submission_id = $_POST['submission_id'] ?? 0;
        // Use the selected period ID instead of only allowing the current period
        
        if ($submission_id && $selected_period_id) {
            global $conn;
            
            // First validate that the submission has content
            $content_check = $conn->prepare("SELECT content_json FROM program_submissions WHERE submission_id = ? AND program_id = ? AND period_id = ?");
            $content_check->bind_param("iii", $submission_id, $program_id, $selected_period_id);
            $content_check->execute();
            $content_result = $content_check->get_result();
            
            if ($content_result->num_rows > 0) {
                $content_row = $content_result->fetch_assoc();
                $content_json = $content_row['content_json'];
                  // Validate content
                if (empty($content_json) || $content_json === 'null') {
                    $result = ['error' => 'Cannot finalize submission without content. Please add targets and rating first.'];
                    
                    // Log validation failure
                    log_audit_action(
                        'program_submission_finalization_failed',
                        "Failed to finalize program '{$program['program_name']}' (ID: {$program_id}) - no content",
                        'failure',
                        $_SESSION['user_id']
                    );
                } else {
                    $content_data = json_decode($content_json, true);
                    if (!$content_data || (empty($content_data['targets']) && empty($content_data['target'])) || empty($content_data['rating'])) {
                        $result = ['error' => 'Cannot finalize submission without targets and rating. Please complete the program details first.'];
                        
                        // Log validation failure
                        log_audit_action(
                            'program_submission_finalization_failed',
                            "Failed to finalize program '{$program['program_name']}' (ID: {$program_id}) - missing targets or rating",
                            'failure',
                            $_SESSION['user_id']
                        );
                    } else {
                        // Content is valid, proceed with finalization
                        $stmt = $conn->prepare("UPDATE program_submissions SET is_draft = 0, submission_date = NOW() WHERE submission_id = ? AND program_id = ? AND period_id = ?");
                        $stmt->bind_param("iii", $submission_id, $program_id, $selected_period_id);                        if ($stmt->execute() && $stmt->affected_rows > 0) {
                            $result = ['success' => true, 'message' => 'Draft finalized successfully.'];
                            
                            // Log successful finalization
                            log_audit_action(
                                'program_submission_finalized',
                                "Program '{$program['program_name']}' (ID: {$program_id}) submission finalized for period {$selected_period_id}",
                                'success',
                                $_SESSION['user_id']
                            );
                        } else {
                            $result = ['error' => 'Failed to finalize draft. Submission may not exist for selected period.'];
                            
                            // Log finalization failure
                            log_audit_action(
                                'program_submission_finalization_failed',
                                "Failed to finalize program '{$program['program_name']}' (ID: {$program_id}) submission for period {$selected_period_id}",
                                'failure',
                                $_SESSION['user_id']
                            );
                        }
                    }
                }
                } else {
                    $result = ['error' => 'Submission not found.'];
                }
            }
        } else {
            // Handle save draft functionality - update both program basic info and submission content
            global $conn;
        try {
            $conn->begin_transaction();            // Get form data with copy-paste sanitization
            $program_name = sanitize_copy_paste_content($_POST['program_name'] ?? '', false);
            $program_number = sanitize_copy_paste_content($_POST['program_number'] ?? '', false);
            $brief_description = sanitize_copy_paste_content($_POST['brief_description'] ?? '', true);
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $initiative_id = !empty($_POST['initiative_id']) ? intval($_POST['initiative_id']) : null;
            $status_indicator = !empty($_POST['status_indicator']) ? $_POST['status_indicator'] : null;
            $hold_point_post = isset($_POST['hold_point']) ? json_encode(['is_on_hold' => true, 'reason' => sanitize_copy_paste_content($_POST['hold_reason'] ?? '', true), 'date_set' => date('Y-m-d H:i:s')]) : null;
            $rating = $_POST['rating'] ?? 'not-started';
            $remarks = sanitize_copy_paste_content($_POST['remarks'] ?? '', true);
            $period_id = intval($_POST['period_id'] ?? 0);
            $submission_id = isset($_POST['submission_id']) && !empty($_POST['submission_id']) ? intval($_POST['submission_id']) : null;
            $current_user_id = $_SESSION['user_id'];
            
            // Ensure we're updating the correct period
            if ($period_id != $selected_period_id) {
                $_SESSION['message'] = 'Selected period mismatch. Please try again.';
                $_SESSION['message_type'] = 'danger';
                header('Location: update_program.php?id=' . $program_id . '&period_id=' . $selected_period_id);
                exit;
            }
              // Validate program_number format if provided
            if (!empty($program_number) && !is_valid_program_number_format($program_number, false)) {
                $_SESSION['message'] = get_program_number_format_error(false);
                $_SESSION['message_type'] = 'danger';
                header('Location: update_program.php?id=' . $program_id);
                exit;
            }
            
            // Additional validation for hierarchical format if initiative is linked
            if ($program_number && $initiative_id) {
                $format_validation = validate_program_number_format($program_number, $initiative_id);
                if (!$format_validation['valid']) {
                    $_SESSION['message'] = $format_validation['message'];
                    $_SESSION['message_type'] = 'danger';
                    header('Location: update_program.php?id=' . $program_id);
                    exit;
                }
            }
            
            // Process targets array with enhanced structure
            $targets = [];
            $existing_target_numbers = []; // Track existing target numbers to avoid false duplicates
            
            // First, get existing target numbers from current submission to avoid validation errors
            if ($submission_id > 0) {
                $existing_query = "SELECT content_json FROM program_submissions WHERE submission_id = ?";
                $existing_stmt = $conn->prepare($existing_query);
                $existing_stmt->bind_param("i", $submission_id);
                $existing_stmt->execute();
                $existing_result = $existing_stmt->get_result();
                
                if ($existing_result->num_rows > 0) {
                    $existing_row = $existing_result->fetch_assoc();
                    $existing_content = json_decode($existing_row['content_json'], true);
                    
                    if (isset($existing_content['targets']) && is_array($existing_content['targets'])) {
                        $existing_targets = $existing_content['targets'];
                    }
                }
            }
            
            if (isset($_POST['target_text']) && is_array($_POST['target_text'])) {
                $target_texts = $_POST['target_text'];
                $target_status_descriptions = $_POST['target_status_description'] ?? [];
                $target_numbers = $_POST['target_number'] ?? [];
                $target_statuses = $_POST['target_status'] ?? [];
                $target_start_dates = $_POST['target_start_date'] ?? [];
                $target_end_dates = $_POST['target_end_date'] ?? [];
                
                // DEBUG: Log the submitted data to understand duplication
                error_log("=== TARGET DEBUG INFO ===");
                error_log("target_texts count: " . count($target_texts));
                error_log("target_numbers: " . print_r($target_numbers, true));
                error_log("target_texts: " . print_r($target_texts, true));
                error_log("========================");
                
                for ($i = 0; $i < count($target_texts); $i++) {
                    $target_text = sanitize_copy_paste_content($target_texts[$i], true);
                    if (!empty($target_text)) {
                        $target_number = sanitize_copy_paste_content($target_numbers[$i] ?? '', false);

                        // ... other validation ...

                        // Fallback for submission_id
                        if (!isset($submission_id) || empty($submission_id) || $submission_id == 0) {
                            if (isset($_POST['submission_id']) && intval($_POST['submission_id']) > 0) {
                                $submission_id = intval($_POST['submission_id']);
                            } elseif (isset($current_submission['submission_id']) && intval($current_submission['submission_id']) > 0) {
                                $submission_id = intval($current_submission['submission_id']);
                            } else {
                                // Try to get the latest submission for this program and period
                                $latest_submission_id = null;
                                $stmt = $conn->prepare("SELECT submission_id FROM program_submissions WHERE program_id = ? AND period_id = ? ORDER BY submission_id DESC LIMIT 1");
                                $stmt->bind_param("ii", $program_id, $period_id);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($row = $result->fetch_assoc()) {
                                    $latest_submission_id = $row['submission_id'];
                                }
                                $submission_id = $latest_submission_id;
                            }
                        }

                        // Debug log for target number check
                        error_log("[DEBUG] (Uniqueness check disabled) Skipping target number availability check: target_number={$target_number}, program_id={$program_id}, submission_id={$submission_id}, period_id={$period_id}");
                        // if (!is_target_number_available($target_number, $program_id, $submission_id, $period_id)) {
                        //     $_SESSION['message'] = "Target number '{$target_number}' is already in use in this program.";
                        //     $_SESSION['message_type'] = 'danger';
                        //     header('Location: update_program.php?id=' . $program_id);
                        //     exit;
                        // }

                        // Build the target array with sanitized data
                        $targets[] = [
                            'target_number' => $target_number,
                            'target_text' => $target_text,
                            'target_status' => trim($target_statuses[$i] ?? 'not-started'),
                            'status_description' => sanitize_copy_paste_content($target_status_descriptions[$i] ?? '', true),
                            'start_date' => !empty($target_start_dates[$i]) ? $target_start_dates[$i] : null,
                            'end_date' => !empty($target_end_dates[$i]) ? $target_end_dates[$i] : null
                        ];
                    }
                }
            }
            
            // Final validation: ensure we don't save completely empty targets
            if (empty($targets)) {
                // Add a default empty target structure to prevent data loss
                $targets = [[
                    'target_number' => '',
                    'target_text' => '',
                    'status_description' => '',
                    'target_status' => 'not-started',
                    'start_date' => null,
                    'end_date' => null
                ]];
            } else {
                // Validate that at least one target has content
                $has_content = false;
                foreach ($targets as $target) {
                    if (!empty(trim($target['target_text'] ?? ''))) {
                        $has_content = true;
                        break;
                    }
                }
                
                // If no targets have content, keep existing targets instead of overwriting with empty ones
                if (!$has_content && isset($current_submission) && !empty($current_submission['content_json'])) {
                    $existing_content = json_decode($current_submission['content_json'], true);
                    if (isset($existing_content['targets']) && !empty($existing_content['targets'])) {
                        $targets = $existing_content['targets'];
                        error_log("[DEBUG] Preserved existing targets to prevent data loss");
                    }
                }
            }
              // 1. Update program basic information (now always allowed)
            $update_fields = [];
            $update_params = [];
            $param_types = '';
            
            if (!empty($program_name)) {
                $update_fields[] = "program_name = ?";
                $update_params[] = $program_name;
                $param_types .= 's';
            }
            
            // Always allow editing program_number (it's optional)
            $update_fields[] = "program_number = ?";
            $update_params[] = $program_number;
            $param_types .= 's';
            
            $update_fields[] = "start_date = ?";
            $update_params[] = $start_date;
            $param_types .= 's';
            
            $update_fields[] = "end_date = ?";
            $update_params[] = $end_date;
            $param_types .= 's';
            
            // Always allow editing initiative_id
            $update_fields[] = "initiative_id = ?";
            $update_params[] = $initiative_id;
            $param_types .= 'i';
            
            // Add status_indicator field
            $update_fields[] = "status_indicator = ?";
            $update_params[] = $status_indicator;
            $param_types .= 's';
            
            // Add hold_point field
            $update_fields[] = "hold_point = ?";
            $update_params[] = $hold_point_post;
            $param_types .= 's';
            
            if (!empty($update_fields)) {
                $update_fields[] = "updated_at = NOW()";
                $update_params[] = $program_id;
                $param_types .= 'i';
                
                $program_query = "UPDATE programs SET " . implode(', ', $update_fields) . " WHERE program_id = ?";
                $program_stmt = $conn->prepare($program_query);
                $program_stmt->bind_param($param_types, ...$update_params);
                
                if (!$program_stmt->execute()) {
                    throw new Exception('Failed to update program: ' . $program_stmt->error);
                }
            }              // 2. Handle program submission data
            $content_data = [
                'rating' => $rating,
                'targets' => $targets,
                'remarks' => $remarks,
                'brief_description' => $brief_description,
                'program_name' => $program_name,
                'program_number' => $program_number
            ];
            $content_json = json_encode($content_data);
              if ($submission_id > 0) {
                // Whenever changes are made, we should insert a new record to preserve change history
                // This is particularly important for brief_description which needs a full change history
                
                // Create new submission instead of updating
                $submission_query = "INSERT INTO program_submissions 
                                   (program_id, period_id, submitted_by, content_json, is_draft, submission_date, updated_at) 
                                   VALUES (?, ?, ?, ?, 1, NOW(), NOW())";
                $submission_stmt = $conn->prepare($submission_query);
                $submission_stmt->bind_param("iiis", $program_id, $period_id, $current_user_id, $content_json);
                
                if (!$submission_stmt->execute()) {
                    throw new Exception('Failed to create submission record: ' . $submission_stmt->error);
                }
            } else {
                // Create new submission
                $submission_query = "INSERT INTO program_submissions 
                                   (program_id, period_id, submitted_by, content_json, is_draft, submission_date, updated_at) 
                                   VALUES (?, ?, ?, ?, 1, NOW(), NOW())";
                $submission_stmt = $conn->prepare($submission_query);
                $submission_stmt->bind_param("iiis", $program_id, $period_id, $current_user_id, $content_json);
                
                if (!$submission_stmt->execute()) {
                    throw new Exception('Failed to create submission: ' . $submission_stmt->error);
                }
            }            $conn->commit();
            $result = ['success' => true, 'message' => 'Program saved as draft successfully.'];
            
            // Log successful draft save
            log_audit_action(
                'program_draft_saved',
                "Program '{$program_name}' (ID: {$program_id}) draft saved for period {$period_id}",
                'success',
                $_SESSION['user_id']
            );
        } catch (Exception $e) {
            $conn->rollback();
            $result = ['error' => 'Failed to save draft: ' . $e->getMessage()];
            
            // Log draft save failure
            log_audit_action(
                'program_draft_save_failed',
                "Failed to save draft for program '{$program['program_name']}' (ID: {$program_id}): " . $e->getMessage(),
                'failure',
                $_SESSION['user_id']
            );
        }
    }
    
    if (isset($result['success'])) {
        // Set success message
        if ($finalize_draft) {
            $_SESSION['message'] = 'Draft finalized successfully.';
        } else if ($is_draft) {
            $_SESSION['message'] = 'Program saved as draft successfully.';
        } else {
            $_SESSION['message'] = 'Program updated successfully.';
        }
        $_SESSION['message_type'] = 'success';
        
        // Redirect back to edit page to show success message and updated content
        header('Location: update_program.php?id=' . $program_id . '&period_id=' . $period_id);
        exit;
    } else {
        $_SESSION['message'] = $result['error'] ?? 'An error occurred while updating the program.';
        $_SESSION['message_type'] = 'danger';
        
        // Redirect back to form to show error
        header('Location: update_program.php?id=' . $program_id);
        exit;
    }
}

// Check if the program has a draft submission for the selected period
$is_draft = false;
$rating = 'not-started';
$remarks = '';
$targets = [];
$brief_description = '';

// Use submission data only from the selected period (already filtered above)
if ($current_submission) {
    $is_draft = isset($current_submission['is_draft']) && $current_submission['is_draft'] == 1;
    
    // Process content_json if available
    if (isset($current_submission['content_json']) && is_string($current_submission['content_json'])) {
        $content = json_decode($current_submission['content_json'], true);
          // If we have the new structure with targets array, use it
        if (isset($content['targets']) && is_array($content['targets'])) {
            $targets = [];
            foreach ($content['targets'] as $target_data) {
                // Handle both new enhanced structure and legacy structure
                $targets[] = [
                    'target_number' => $target_data['target_number'] ?? '',
                    'target_text' => $target_data['target_text'] ?? '',
                    'status_description' => $target_data['status_description'] ?? '',
                    'target_status' => $target_data['target_status'] ?? 'not-started',
                    'start_date' => $target_data['start_date'] ?? null,
                    'end_date' => $target_data['end_date'] ?? null
                ];
            }
            $rating = $content['rating'] ?? 'not-started';
            $remarks = $content['remarks'] ?? '';
            $brief_description = $content['brief_description'] ?? '';} else {
            // Legacy data - handle semicolon-separated targets
            $target_text = $content['target'] ?? $current_submission['target'] ?? '';
            $status_description = $content['status_description'] ?? $content['status_text'] ?? $current_submission['status_text'] ?? '';
            
            // Check if targets are semicolon-separated
            if (strpos($target_text, ';') !== false) {
                // Split semicolon-separated targets and status descriptions
                $target_parts = array_map('trim', explode(';', $target_text));
                $status_parts = array_map('trim', explode(';', $status_description));
                
                $targets = [];
                foreach ($target_parts as $index => $target_part) {
                    if (!empty($target_part)) {
                        $targets[] = [
                            'target_number' => '', // Legacy data doesn't have target numbers
                            'target_text' => $target_part,
                            'status_description' => isset($status_parts[$index]) ? $status_parts[$index] : '',
                            'target_status' => 'not-started', // Default status for legacy data
                            'start_date' => null,
                            'end_date' => null
                        ];
                    }
                }
                
                // Ensure we have at least one target
                if (empty($targets)) {
                    $targets = [[
                        'target_number' => '',
                        'target_text' => '',
                        'status_description' => '',
                        'target_status' => 'not-started',
                        'start_date' => null,
                        'end_date' => null
                    ]];
                }
            } else {            // Single target - create a single target from old structure
            $targets = [
                [
                    'target_number' => '',
                    'target_text' => $target_text,
                    'status_description' => $status_description,
                    'target_status' => 'not-started',
                    'start_date' => null,
                    'end_date' => null
                ]
            ];
            }
            
            // Get other fields from content or submission
            $rating = $content['rating'] ?? $current_submission['rating'] ?? 'not-started';
            $remarks = $content['remarks'] ?? $current_submission['remarks'] ?? '';
            $brief_description = $content['brief_description'] ?? $current_submission['brief_description'] ?? '';
        }
    } else {
        // Old structure without content_json - handle semicolon-separated targets
        $target_text = $current_submission['target'] ?? '';
        $status_description = $current_submission['status_text'] ?? '';
        
        // Check if targets are semicolon-separated
        if (strpos($target_text, ';') !== false) {
            // Split semicolon-separated targets and status descriptions
            $target_parts = array_map('trim', explode(';', $target_text));
            $status_parts = array_map('trim', explode(';', $status_description));
            
            $targets = [];
            foreach ($target_parts as $index => $target_part) {
                if (!empty($target_part)) {
                    $targets[] = [
                        'target_number' => '',
                        'target_text' => $target_part,
                        'status_description' => isset($status_parts[$index]) ? $status_parts[$index] : '',
                        'target_status' => 'not-started',
                        'start_date' => null,
                        'end_date' => null
                    ];
                }
            }
            
            // Ensure we have at least one target
            if (empty($targets)) {
                $targets = [[
                    'target_number' => '',
                    'target_text' => '',
                    'status_description' => '',
                    'target_status' => 'not-started',
                    'start_date' => null,
                    'end_date' => null
                ]];
            }
        } else {
            // Single target
            $targets = [
                [
                    'target_number' => '',
                    'target_text' => $target_text,
                    'status_description' => $status_description,
                    'target_status' => 'not-started',
                    'start_date' => null,
                    'end_date' => null
                ]
            ];
        }
        
        $rating = $current_submission['status'] ?? 'not-started';
        $remarks = $current_submission['remarks'] ?? '';
        $brief_description = $current_submission['brief_description'] ?? '';
    }
} else {
    // No submission for this period: carry over incomplete targets from previous period
    $targets = [];
    $prev_submission = null;
    // Find the latest previous period with a submission
    if (isset($program['submissions']) && is_array($program['submissions'])) {
        // Sort submissions by period_id descending (latest first, but less than selected_period_id)
        $prev_subs = array_filter($program['submissions'], function($sub) use ($selected_period_id) {
            return isset($sub['period_id']) && $sub['period_id'] < $selected_period_id;
        });
        usort($prev_subs, function($a, $b) {
            return $b['period_id'] <=> $a['period_id'];
        });
        if (!empty($prev_subs)) {
            $prev_submission = $prev_subs[0];
        }
    }
    if ($prev_submission && isset($prev_submission['content_json'])) {
        $prev_content = json_decode($prev_submission['content_json'], true);
        if (isset($prev_content['targets']) && is_array($prev_content['targets'])) {
            foreach ($prev_content['targets'] as $target) {
                if (!isset($target['target_status']) || strtolower($target['target_status']) !== 'completed') {
                    $targets[] = $target;
                }
            }
        }
    }
}

// Ensure $brief_description is always defined as a string to prevent warnings and deprecated notices
if (!isset($brief_description) || $brief_description === null) {
    $brief_description = '';
}

// Fallback: If brief_description is still empty, use the value from the program's content_json if not present in the latest submission, since that's where the data is stored.
if (empty($brief_description)) {
    // Try to extract from program['content_json'] if available
    if (!empty($program['content_json'])) {
        $program_content = json_decode($program['content_json'], true);
        if (is_array($program_content) && !empty($program_content['brief_description'])) {
            $brief_description = $program_content['brief_description'];
        }
    }
    // Legacy fallback (if any other fields are used in the future)
    elseif (!empty($program['brief_description'])) {
        $brief_description = $program['brief_description'];
    } elseif (!empty($program['description'])) {
        $brief_description = $program['description'];
    }
}

// Determine if current user is focal
$is_focal_user = is_focal_user();

// Set page title
$pageTitle = 'Update Program';

// Additional scripts
$additionalScripts = [
    APP_URL . '/assets/js/agency/program_management.js',
    APP_URL . '/assets/js/utilities/rating_utils.js',
    APP_URL . '/assets/js/utilities/program-history.js'
];

// Additional styles
$additionalStyles = '
<link rel="stylesheet" href="' . APP_URL . '/assets/css/components/program-history.css">
';

// Include header (which contains the DOCTYPE declaration)
require_once dirname(__DIR__, 2) . '/layouts/header.php';

// Configure modern page header
$header_config = [
    'title' => 'Update Program',
    'subtitle' => htmlspecialchars($program['program_name']) . " - " . 
                htmlspecialchars($current_period['name'] ?? '') . 
                " (" . date('M j, Y', strtotime($current_period['start_date'])) . " - " . 
                date('M j, Y', strtotime($current_period['end_date'])) . ")",
    'variant' => 'white',
    'actions' => [
        [
            'url' => 'view_programs.php',
            'text' => 'Back to Programs',
            'icon' => 'fa-arrow-left',
            'class' => 'btn-outline-secondary'
        ]
    ]
];

// Include modern page header
require_once dirname(__DIR__, 2) . '/layouts/page_header.php';

// Set the period selector variables for the component
$viewing_period_id = $selected_period_id;
$viewing_period = $selected_period;
// Render the period selector UI before the form
require_once PROJECT_ROOT_PATH . 'lib/period_selector_edit.php';
?>

<?php
// Display error/success messages from session
if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type'] ?? 'info'; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($_SESSION['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php 
    // Clear the message after displaying
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
    ?>
<?php endif; ?>

<?php
// Include any draft notification banner if this is a draft
                if ($is_draft): ?>
                <div class="draft-banner mb-4">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Draft Mode:</strong>  This program submission is currently saved as a draft. You can continue editing or submit the final version.
                </div>
                <?php endif; ?>

                <?php if (isset($program_history['submissions']) && count($program_history['submissions']) > 1): ?>
                <!-- Program History Panel -->
                <div class="mb-4">
                    <div class="history-panel-title">
                        <h6 class="fw-bold"><i class="fas fa-history me-2"></i> Program Edit History</h6>
                        <button type="button" class="history-toggle-btn" data-target="programHistoryPanel">
                            <i class="fas fa-history"></i> Show History
                        </button>
                    </div>
                    
                    <div id="programHistoryPanel" class="history-panel" style="display: none;">
                        <?php foreach($program_history['submissions'] as $idx => $submission): ?>
                        <div class="history-version">
                            <div class="history-version-info">
                                <strong><?php echo $submission['formatted_date']; ?></strong>
                                <span class="history-version-label"><?php echo $submission['is_draft_label']; ?></span>
                            </div>
                            <?php if ($idx === 0): ?>
                                <div><em>Current version</em></div>
                            <?php else: ?>
                                <div class="small text-muted mb-1">
                                    <?php echo isset($submission['submission_date']) ? 
                                        date('M j, Y g:i A', strtotime($submission['submission_date'])) : 
                                        $submission['formatted_date']; ?>
                            </div>
                            <?php if (isset($submission['period_name'])): ?>
                            <div>Period: <?php echo htmlspecialchars($submission['period_name']); ?></div>
                            <?php endif; ?>
                            
                            <?php if (isset($submission['target'])): ?>
                            <div>Target: <?php echo htmlspecialchars($submission['target']); ?></div>
                            <?php endif; ?>
                            
                            <?php if (isset($submission['achievement'])): ?>
                            <div>Achievement: <?php echo htmlspecialchars($submission['achievement']); ?></div>
                            <?php endif; ?>
                            
                            <?php if (isset($submission['status'])): ?>
                            <div>Status: <?php echo ucfirst($submission['status']); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Program Update Form -->
                <form id="updateProgramForm" method="post">
                    <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">
                    <input type="hidden" name="period_id" value="<?php echo $selected_period_id; ?>">
                    <?php if ($submission_id): ?>
                    <input type="hidden" name="submission_id" value="<?php echo $submission_id; ?>">
                    <?php endif; ?>
                    <?php if ($is_focal_user): ?>
                    <!-- Rating Section -->
                    <div class="rating-section mb-4">
                        <h6 class="fw-bold mb-3">Program Rating</h6>
                        <p class="text-muted mb-3">
                            How would you rate the overall progress of this program?
                        </p>
                        
                        <input type="hidden" id="rating" name="rating" value="<?php echo $rating; ?>">
                        
                        <div class="rating-pills">
                            <div class="rating-pill target-achieved <?php echo ($rating == 'target-achieved') ? 'active' : ''; ?>" data-rating="target-achieved">
                                <i class="fas fa-check-circle me-2"></i> Monthly Target Achieved
                            </div>
                            <div class="rating-pill on-track-yearly <?php echo ($rating == 'on-track-yearly') ? 'active' : ''; ?>" data-rating="on-track-yearly">
                                <i class="fas fa-calendar-check me-2"></i> On Track for Year
                            </div>
                            <div class="rating-pill severe-delay <?php echo ($rating == 'severe-delay') ? 'active' : ''; ?>" data-rating="severe-delay">
                                <i class="fas fa-exclamation-triangle me-2"></i> Severe Delays
                            </div>
                            <div class="rating-pill not-started <?php echo ($rating == 'not-started' || !$rating) ? 'active' : ''; ?>" data-rating="not-started">
                                <i class="fas fa-clock me-2"></i> Not Started
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- 1. Basic Information Card -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header">
                            <h5 class="card-title m-0">Basic Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="program_name" class="form-label">Program Name *</label>
                                <input type="text" class="form-control" id="program_name" name="program_name" required
                                        value="<?php echo htmlspecialchars($program['program_name']); ?>">
                                
                                <?php if (isset($program_history['submissions']) && count($program_history['submissions']) > 1): ?>
                                    <?php
                                    // Get complete history of program name changes
                                    $name_history = get_field_edit_history($program_history['submissions'], 'program_name');
                                    ?>
                                    <?php
                                    // Show history panel if there is at least one entry in $name_history
                                    $name_history = get_field_edit_history($program_history['submissions'], 'program_name');
                                    if (!empty($name_history)):
                                        render_paginated_field_history($name_history, 'program_name', 'programNameHistory', 'Show Name History');
                                    endif; ?>
                                <?php endif; ?>                            
                            </div>
                                <div class="mb-3">
                                <label for="program_number" class="form-label">Program Number</label>
                                <input type="text" class="form-control" id="program_number" name="program_number" 
                                        value="<?php echo htmlspecialchars($program['program_number'] ?? ''); ?>"
                                        pattern="[\w.]+" 
                                        title="Program number can contain letters, numbers, and dots"
                                        placeholder="e.g., 31.1, 31.2A, 31.25.6, 31.2A.3B">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Optional program identifier with flexible format (letters, numbers, dots)
                                </div>
                            </div>

                            <!-- Initiative Selection -->
                            <div class="mb-3">
                                <label for="initiative_id" class="form-label">
                                    <i class="fas fa-link me-1"></i>
                                    Link to Initiative
                                </label>
                                <select class="form-select" id="initiative_id" name="initiative_id">
                                    <option value="">Select an initiative (optional)</option>
                                    <?php foreach ($active_initiatives as $initiative): ?>
                                        <option value="<?php echo $initiative['initiative_id']; ?>"
                                                <?php echo (isset($program['initiative_id']) && $program['initiative_id'] == $initiative['initiative_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($initiative['initiative_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Link this program to a larger initiative for better organization and reporting
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="brief_description" class="form-label">Brief Description</label>
                                <textarea class="form-control" id="brief_description" name="brief_description" rows="3" 
                                        placeholder="Provide a short summary of the program"><?php echo htmlspecialchars($brief_description); ?></textarea>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    A brief overview to help identify this program
                                </div>
                                
                                <?php if (isset($program_history['submissions']) && count($program_history['submissions']) > 1): ?>
                                    <?php
                                    // Get complete history of brief description changes
                                    $description_history = get_field_edit_history($program_history['submissions'], 'brief_description');
                                    
                                    if (!empty($description_history)):
                                        render_paginated_field_history($description_history, 'brief_description', 'briefDescriptionHistory', 'Show Description History');
                                    endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" 
                                            value="<?php echo get_field_value('start_date', $program['start_date'] ? date('Y-m-d', strtotime($program['start_date'])) : ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" 
                                            value="<?php echo get_field_value('end_date', $program['end_date'] ? date('Y-m-d', strtotime($program['end_date'])) : ''); ?>">
                                </div>
                            </div>
                            
                            <!-- Program Status Controls -->
                            <div class="mt-4 pt-3 border-top">
                                <h6 class="fw-bold mb-3">Program Status</h6>
                                
                                <!-- Status Indicator Selection -->
                                <div class="mb-4">
                                    <label for="status_indicator" class="form-label fw-medium">
                                        <i class="fas fa-flag me-2"></i>
                                        Overall Program Status
                                    </label>
                                    <select class="form-select" id="status_indicator" name="status_indicator">
                                        <option value="">Select Status...</option>
                                        <option value="not-started" <?php echo (isset($program['status_indicator']) && $program['status_indicator'] === 'not-started') ? 'selected' : ''; ?>>
                                            Not Started
                                        </option>
                                        <option value="in-progress" <?php echo (isset($program['status_indicator']) && $program['status_indicator'] === 'in-progress') ? 'selected' : ''; ?>>
                                            In Progress
                                        </option>
                                        <option value="completed" <?php echo (isset($program['status_indicator']) && $program['status_indicator'] === 'completed') ? 'selected' : ''; ?>>
                                            Completed
                                        </option>
                                    </select>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Set the overall status to track the program's progress at a high level.
                                    </div>
                                </div>
                                
                                <div class="form-check form-switch">
                                    <?php 
                                    $hold_point_data = null;
                                    $is_on_hold = false;
                                    $hold_reason = '';
                                    
                                    if (isset($program['hold_point']) && !empty($program['hold_point'])) {
                                        $hold_point_data = json_decode($program['hold_point'], true);
                                        $is_on_hold = isset($hold_point_data['is_on_hold']) && $hold_point_data['is_on_hold'];
                                        $hold_reason = $hold_point_data['reason'] ?? '';
                                    }
                                    ?>
                                    <input class="form-check-input" type="checkbox" id="hold_point" name="hold_point" value="1" 
                                           <?php echo $is_on_hold ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-medium" for="hold_point">
                                        <i class="fas fa-pause-circle me-2 text-warning"></i>
                                        Put Program on Hold
                                    </label>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Mark this program as on hold to pause its execution temporarily. This will help track programs that are temporarily suspended.
                                    </div>
                                </div>
                                
                                <!-- Hold Reason (shown when hold is checked) -->
                                <div id="hold-reason-container" class="mt-3" style="<?php echo $is_on_hold ? '' : 'display: none;'; ?>">
                                    <label for="hold_reason" class="form-label">Reason for Hold (Optional)</label>
                                    <textarea class="form-control" id="hold_reason" name="hold_reason" rows="2" 
                                              placeholder="Briefly explain why this program is being put on hold..."><?php echo htmlspecialchars($hold_reason); ?></textarea>
                                    <div class="form-text">
                                        Provide context for why this program is on hold to help with future reference.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                        <!-- 2. Program Targets Card -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title m-0">Program Targets</h5>
                            <span id="target-counter" class="badge bg-primary fs-6">
                                <i class="fas fa-bullseye me-1"></i>
                                <span id="target-count"><?php echo count($targets); ?></span> 
                                <?php echo count($targets) === 1 ? 'target' : 'targets'; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">
                                Define one or more targets for this program, each with its own status description.
                            </p>
                            
                            <?php if (isset($program_history['submissions']) && count($program_history['submissions']) > 1): ?>
                                <?php
                                // Get complete history of program targets changes
                                $targets_history = get_field_edit_history($program_history['submissions'], 'targets');
                                
                                if (!empty($targets_history)):
                                    render_paginated_field_history($targets_history, 'targets', 'targetsHistory', 'Show Targets History');
                                endif; ?>
                            <?php endif; ?>
                            
                            <div id="targets-container">
                                <?php 
                                // All users can now edit targets
                                $canEditTargets = true;
                                
                                foreach ($targets as $index => $target): 
                                    $target_number = $target['target_number'] ?? '';
                                    $target_text = $target['target_text'] ?? '';
                                    $status_description = $target['status_description'] ?? '';
                                    $target_status = $target['target_status'] ?? 'not-started';
                                    $start_date = $target['start_date'] ?? null;
                                    $end_date = $target['end_date'] ?? null;
                                    $canDelete = $index > 0; // Only allow deleting additional targets
                                ?>
                                <div class="target-entry">
                                    <?php if ($canDelete && $canEditTargets): ?>
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
                    </div>                    <!-- 3. Remarks and Comments Card -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header">
                            <h5 class="card-title m-0">Remarks and Comments</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Additional Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="4" 
                                          placeholder="Add any additional remarks, challenges, or observations about this program..."><?php echo htmlspecialchars($remarks); ?></textarea>
                                
                                <?php if (isset($program_history['submissions']) && count($program_history['submissions']) > 1): ?>
                                    <?php
                                    // Get complete history of remarks changes
                                    $remarks_history = get_field_edit_history($program_history['submissions'], 'remarks');
                                    
                                    if (!empty($remarks_history)):
                                        render_paginated_field_history($remarks_history, 'remarks', 'remarksHistory', 'Show Remarks History');
                                    endif;
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Program Attachments Card -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header">
                            <h5 class="card-title m-0">Program Attachments</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">
                                Upload relevant documents, images, or other files related to this program.
                            </p>
                            
                            <!-- Existing Attachments -->
                            <?php if (!empty($existing_attachments)): ?>
                                <div class="mb-4">
                                    <h6 class="fw-bold mb-3">Current Attachments</h6>
                                    <div id="existing-attachments-list">
                                        <?php foreach ($existing_attachments as $attachment): ?>
                                            <div class="attachment-item d-flex align-items-center justify-content-between p-3 border rounded mb-2" data-attachment-id="<?php echo $attachment['attachment_id']; ?>">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas <?php echo get_file_icon($attachment['mime_type']); ?> me-2 text-primary"></i>
                                                    <div>
                                                        <div class="fw-medium"><?php echo htmlspecialchars($attachment['original_filename']); ?></div>
                                                        <small class="text-muted">
                                                            <?php echo format_file_size($attachment['file_size']); ?> • 
                                                            Uploaded <?php echo date('M j, Y g:i A', strtotime($attachment['upload_date'])); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                <div class="attachment-actions">
                                                    <a href="<?php echo APP_URL; ?>/app/ajax/download_program_attachment.php?id=<?php echo $attachment['attachment_id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary me-2" target="_blank">
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-danger delete-attachment-btn" 
                                                            data-attachment-id="<?php echo $attachment['attachment_id']; ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                              <!-- File Upload Section -->
                                <div class="upload-section mb-4">
                                    <h6 class="fw-bold mb-3">
                                        <i class="fas fa-paperclip me-2"></i>
                                        Add New Attachments
                                    </h6>
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        You can upload supporting documents such as PDFs, Word documents, Excel files, or images to provide additional context for your program.
                                    </div>
                                    
                                    <!-- Drag and Drop Area -->
                                    <div id="attachment-dropzone" class="upload-dropzone">
                                        <div class="upload-dropzone-content">
                                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                            <h6 class="text-muted">Drag and drop files here</h6>
                                            <p class="text-muted mb-3">or</p>
                                            <button type="button" class="btn btn-outline-primary" id="browse-files-btn">
                                                <i class="fas fa-folder-open me-2"></i>
                                                Select Files
                                            </button>
                                        </div>
                                        <div class="upload-info mt-3">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Allowed file types: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT<br>
                                                Maximum file size: 10MB per file, 50MB total
                                            </small>
                                        </div>
                                        <input type="file" id="attachment-file-input" multiple style="display: none;" 
                                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.txt,.csv">
                                    </div>
                                    
                                    <!-- Upload Progress -->
                                    <div id="upload-progress" class="mt-3" style="display: none;">
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-3">
                                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                            </div>
                                            <span class="upload-status">Uploading...</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Upload Instructions -->
                                    <div class="upload-instructions mt-3">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Supported formats: PDF, Word documents, Excel files, PowerPoint, images (JPG, PNG, GIF), text files, CSV.
                                            Maximum file size: 10MB per file.
                                        </small>
                                    </div>
                                </div>
                        </div>
                    </div>
                              <!-- Form Actions -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <?php if ($is_draft): ?>
                                <button type="submit" name="save_draft" class="btn btn-secondary me-2">
                                    <i class="fas fa-save me-1"></i> Save Draft
                                </button>
                            <?php else: ?>
                                <!-- <button type="submit" name="save_draft" class="btn btn-secondary me-2">
                                    <i class="fas fa-save me-1"></i> Save as Draft
                                </button> -->
                                <button type="submit" name="submit_program" class="btn btn-primary">
                                    <i class="fas fa-check-circle me-1"></i> Update Program
                                </button>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="view_programs.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                        </div>                    </div>
                </form>

<script>
function showToast(title, message, type = 'info', duration = 5000) {
    // Create a simple toast notification
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'success' ? 'success' : type === 'error' || type === 'danger' ? 'danger' : 'info'} alert-dismissible fade show position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; max-width: 500px;';
    
    toast.innerHTML = `
        <strong>${title}</strong><br>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(toast);
    
    // Auto-remove after duration
    setTimeout(() => {
        if (toast.parentNode) {
            toast.remove();
        }
    }, duration);
}

document.addEventListener('DOMContentLoaded', function() {
    // Rating pills selection
    const ratingPills = document.querySelectorAll('.rating-pill:not(.disabled)');
    const ratingInput = document.getElementById('rating');
    
    ratingPills.forEach(pill => {
        pill.addEventListener('click', function() {
            // Remove active class from all pills
            ratingPills.forEach(p => p.classList.remove('active'));
            
            // Add active class to clicked pill
            this.classList.add('active');
            
            // Update hidden input
            ratingInput.value = this.getAttribute('data-rating');
        });
    });
    
    // Hold Point toggle functionality
    const holdPointCheckbox = document.getElementById('hold_point');
    const holdReasonContainer = document.getElementById('hold-reason-container');
    
    if (holdPointCheckbox && holdReasonContainer) {
        holdPointCheckbox.addEventListener('change', function() {
            if (this.checked) {
                holdReasonContainer.style.display = 'block';
                // Add smooth animation
                holdReasonContainer.style.opacity = '0';
                setTimeout(() => {
                    holdReasonContainer.style.transition = 'opacity 0.3s ease';
                    holdReasonContainer.style.opacity = '1';
                }, 10);
            } else {
                holdReasonContainer.style.transition = 'opacity 0.3s ease';
                holdReasonContainer.style.opacity = '0';
                setTimeout(() => {
                    holdReasonContainer.style.display = 'none';
                }, 300);
                // Clear the reason field when unchecked
                const holdReasonField = document.getElementById('hold_reason');
                if (holdReasonField) {
                    holdReasonField.value = '';
                }
            }
        });
    }
      // Attachment Management
    const dropzone = document.getElementById('attachment-dropzone');
    const fileInput = document.getElementById('attachment-file-input');
    const browseBtn = document.getElementById('browse-files-btn');
    const uploadProgress = document.getElementById('upload-progress');
    const progressBar = document.querySelector('.progress-bar');
    const uploadStatus = document.querySelector('.upload-status');    // Only initialize attachment functionality if elements exist
    if (dropzone && fileInput && browseBtn) {
        console.log('Initializing attachment functionality...');
          // Drag and drop functionality
        dropzone.addEventListener('dragenter', function(e) {
            e.preventDefault();
            console.log('Dragenter event');
            dropzone.classList.add('dragover');
        });
        
        dropzone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            console.log('Dragover event');
            dropzone.classList.add('dragover');
        });
        
        dropzone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            console.log('Dragleave event');
            // Only remove dragover if we're actually leaving the drop zone
            if (!dropzone.contains(e.relatedTarget)) {
                dropzone.classList.remove('dragover');
            }
        });
        
        dropzone.addEventListener('drop', function(e) {
            e.preventDefault();
            console.log('Drop event with', e.dataTransfer.files.length, 'files');
            dropzone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            handleFileUpload(files);
        });
        
        // Click to browse
        if (browseBtn) {
            browseBtn.addEventListener('click', function() {
                fileInput.click();
            });
        }
        
        dropzone.addEventListener('click', function(e) {
            if (e.target === dropzone || e.target.closest('.upload-dropzone-content')) {
                fileInput.click();
            }
        });
        
        // File input change
        fileInput.addEventListener('change', function() {
            handleFileUpload(this.files);
        });          // Delete attachment functionality
        document.addEventListener('click', function(e) {
            // Check if click is on a delete button or its child elements
            const deleteBtn = e.target.closest('.delete-attachment-btn');
            if (deleteBtn) {
                e.preventDefault();
                e.stopPropagation();
                
                const attachmentId = deleteBtn.getAttribute('data-attachment-id');
                deleteAttachment(attachmentId);
            }
        });
    } else {
        console.log('Attachment elements not found:', {
            dropzone: !!dropzone,
            fileInput: !!fileInput, 
            browseBtn: !!browseBtn
        });
    }
      function handleFileUpload(files) {
        if (files.length === 0) return;
        
        // Upload files one by one like create_program.php does
        for (let i = 0; i < files.length; i++) {
            uploadSingleFile(files[i]);
        }
    }
    
    function uploadSingleFile(file) {
        const formData = new FormData();
        formData.append('program_id', <?php echo $program_id; ?>);
        formData.append('attachment_file', file); // Match backend expectation
        formData.append('description', ''); // Optional description
        
        // Show progress
        uploadProgress.style.display = 'block';
        progressBar.style.width = '0%';
        uploadStatus.textContent = `Uploading ${file.name}...`;
        
        // Simulate progress (since we don't have real progress tracking)
        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += Math.random() * 30;
            if (progress > 90) progress = 90;
            progressBar.style.width = progress + '%';
        }, 200);
        
        fetch('<?php echo APP_URL; ?>/app/ajax/upload_program_attachment.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            clearInterval(progressInterval);
            progressBar.style.width = '100%';
            
            if (data.success) {
                uploadStatus.textContent = 'Upload complete!';
                
                // Add uploaded file to the list
                if (data.attachment) {
                    addAttachmentToList(data.attachment);
                }
                  showToast('Success', `File "${file.name}" uploaded successfully`, 'success');
                
                // Clear file input for reuse
                if (fileInput) {
                    fileInput.value = '';
                }
                
                // Hide progress after delay
                setTimeout(() => {
                    uploadProgress.style.display = 'none';
                }, 2000);
            } else {                uploadStatus.textContent = 'Upload failed';
                showToast('Error', data.error || `Failed to upload ${file.name}`, 'danger');
                
                setTimeout(() => {
                    uploadProgress.style.display = 'none';
                }, 3000);
            }
        })
        .catch(error => {
            clearInterval(progressInterval);
            console.error('Upload error:', error);
            uploadStatus.textContent = 'Upload failed';
            showToast('Error', `Failed to upload ${file.name}`, 'danger');
            
            setTimeout(() => {
                uploadProgress.style.display = 'none';
            }, 3000);
        });
    }
      function deleteAttachment(attachmentId) {
        if (!confirm('Are you sure you want to delete this attachment?')) {
            return;
        }
        
        const attachmentItem = document.querySelector(`[data-attachment-id="${attachmentId}"]`);
        if (!attachmentItem) return;
        
        // Disable the delete button
        const deleteBtn = attachmentItem.querySelector('.delete-attachment-btn');
        if (deleteBtn) {
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        }
        
        fetch('<?php echo APP_URL; ?>/app/ajax/delete_program_attachment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `attachment_id=${attachmentId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the attachment item from the list
                attachmentItem.remove();
                showToast('Success', 'Attachment deleted successfully', 'success');
                
                // Check if no attachments remain
                const remainingAttachments = document.querySelectorAll('#existing-attachments-list .attachment-item');
                if (remainingAttachments.length === 0) {
                    const existingSection = document.querySelector('#existing-attachments-list').closest('.mb-4');
                    if (existingSection) {
                        existingSection.style.display = 'none';
                    }
                }
            } else {
                showToast('Error', data.error || 'Failed to delete attachment', 'danger');
                
                // Re-enable the delete button
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                }
            }
        })
        .catch(error => {
            console.error('Delete error:', error);
            showToast('Error', 'Failed to delete attachment', 'danger');
            
            // Re-enable the delete button
            if (deleteBtn) {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
            }
        });
    }
    
    function addAttachmentToList(attachment) {
        let attachmentsList = document.getElementById('existing-attachments-list');
        
        // If no existing attachments list, create it
        if (!attachmentsList) {
            const attachmentsCard = document.querySelector('.card-body:has(#attachment-dropzone)');
            const uploadSection = document.querySelector('.upload-section');
            
            const listSection = document.createElement('div');
            listSection.className = 'mb-4';
            listSection.innerHTML = `
                <h6 class="fw-bold mb-3">Current Attachments</h6>
                <div id="existing-attachments-list"></div>
            `;
            
            attachmentsCard.insertBefore(listSection, uploadSection);
            attachmentsList = document.getElementById('existing-attachments-list');
        } else {
            // Show the section if it was hidden
            const existingSection = attachmentsList.closest('.mb-4');
            if (existingSection) {
                existingSection.style.display = 'block';
            }
        }
        
        // Get file icon
        const fileIcon = getFileIcon(attachment.mime_type);
        
        const attachmentItem = document.createElement('div');
        attachmentItem.className = 'attachment-item d-flex align-items-center justify-content-between p-3 border rounded mb-2';
        attachmentItem.setAttribute('data-attachment-id', attachment.attachment_id);
        
        attachmentItem.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas ${fileIcon} me-2 text-primary"></i>
                <div>
                    <div class="fw-medium">${escapeHtml(attachment.original_filename)}</div>
                    <small class="text-muted">
                        ${formatFileSize(attachment.file_size)} • 
                        Uploaded ${formatUploadDate(attachment.upload_date)}
                    </small>
                </div>
            </div>
            <div class="attachment-actions">
                <a href="<?php echo APP_URL; ?>/app/ajax/download_program_attachment.php?id=${attachment.attachment_id}" 
                   class="btn btn-sm btn-outline-primary me-2" target="_blank">
                    <i class="fas fa-download"></i> Download
                </a>
                <button type="button" class="btn btn-sm btn-outline-danger delete-attachment-btn" 
                        data-attachment-id="${attachment.attachment_id}">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        `;
        
        attachmentsList.appendChild(attachmentItem);
    }
    
    function getFileIcon(mimeType) {
        const icons = {
            'application/pdf': 'fa-file-pdf',
            'application/msword': 'fa-file-word',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'fa-file-word',
            'application/vnd.ms-excel': 'fa-file-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'fa-file-excel',
            'application/vnd.ms-powerpoint': 'fa-file-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'fa-file-powerpoint',
            'text/plain': 'fa-file-alt',
            'text/csv': 'fa-file-csv',
            'image/jpeg': 'fa-file-image',
            'image/jpg': 'fa-file-image',
            'image/png': 'fa-file-image',
            'image/gif': 'fa-file-image'
        };
        
        return icons[mimeType] || 'fa-file';
    }
    
    function formatFileSize(bytes) {
        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(1) + ' MB';
        } else if (bytes >= 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        } else {
            return bytes + ' bytes';
        }
    }
    
    function formatUploadDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
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
    
    // Initialize date validation for existing targets
    document.querySelectorAll('.target-entry').forEach(targetEntry => {
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
        
        // Initialize target number validation
        const targetNumberInput = targetEntry.querySelector('.target-number-input');
        if (targetNumberInput) {
            targetNumberInput.addEventListener('blur', function() {
                validateTargetNumber(this);
            });
        }
    });
    
    // Validation functions
    function validateTargetDates(startInput, endInput) {
        if (startInput.value && endInput.value) {
            const startDate = new Date(startInput.value);
            const endDate = new Date(endInput.value);
            
            if (startDate > endDate) {
                endInput.classList.add('is-invalid');
                showValidationError('target_dates', 'End date cannot be before start date');
                return false;
            } else {
                endInput.classList.remove('is-invalid');
                endInput.classList.add('is-valid');
                return true;
            }
        }
        
        // Clear validation if dates are empty
        endInput.classList.remove('is-invalid', 'is-valid');
        return true;
    }
    
    function validateTargetNumber(input) {
        const targetNumber = input.value.trim();
        const programNumber = document.getElementById('program_number').value.trim();
        
        if (!targetNumber) {
            input.classList.remove('is-invalid', 'is-valid');
            return true; // Empty is allowed
        }
        
        // Basic format validation
        const formatPattern = /^\d+\.[\w\.]+$/;
        if (!formatPattern.test(targetNumber)) {
            input.classList.add('is-invalid');
            input.classList.remove('is-valid');
            showValidationError('target_number', 'Target number format is invalid');
            return false;
        }
        
        // Check if it starts with program number
        if (programNumber && !targetNumber.startsWith(programNumber + '.')) {
            input.classList.add('is-invalid');
            input.classList.remove('is-valid');
            showValidationError('target_number', `Target number must start with ${programNumber}.`);
            return false;
        }
        
        // Check for duplicates within the current form
        const allTargetNumbers = Array.from(document.querySelectorAll('.target-number-input'))
            .map(inp => inp.value.trim().toLowerCase())
            .filter(num => num !== '');
        
        const duplicates = allTargetNumbers.filter(num => num === targetNumber.toLowerCase());
        if (duplicates.length > 1) {
            input.classList.add('is-invalid');
            input.classList.remove('is-valid');
            showValidationError('target_number', 'Target number is already used in this form');
            return false;
        }
        
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        return true;
    }
    
    // Form validation
    document.getElementById('updateProgramForm').addEventListener('submit', function(e) {
        const programName = document.getElementById('program_name').value;
        const targetInputs = document.querySelectorAll('.target-input');
        const targetNumberInputs = document.querySelectorAll('.target-number-input');
        let hasFilledTarget = false;
        let validationErrors = [];
        
        // Validate program name
        if (!programName.trim()) {
            validationErrors.push('Please enter a program name.');
            e.preventDefault();
        }
        
        // Validate target numbers
        let validTargetNumbers = true;
        targetNumberInputs.forEach(input => {
            if (!validateTargetNumber(input)) {
                validTargetNumbers = false;
            }
        });
        
        if (!validTargetNumbers) {
            validationErrors.push('Please fix target number format errors.');
            e.preventDefault();
        }
        
        // Validate target dates
        let validDates = true;
        document.querySelectorAll('.target-entry').forEach(targetEntry => {
            const startInput = targetEntry.querySelector('.target-start-date');
            const endInput = targetEntry.querySelector('.target-end-date');
            
            if (startInput && endInput && !validateTargetDates(startInput, endInput)) {
                validDates = false;
            }
        });
        
        if (!validDates) {
            validationErrors.push('Please fix target date errors.');
            e.preventDefault();
        }
        
        // For finalize/submit actions, validate at least one target
        if (e.submitter && (e.submitter.name === 'submit_program' || e.submitter.name === 'finalize_draft')) {
            targetInputs.forEach(input => {
                if (input.value.trim()) {
                    hasFilledTarget = true;
                }
            });
            
            if (!hasFilledTarget) {
                validationErrors.push('Please add at least one target for this program.');
                e.preventDefault();
            }
        }
        
        // Show consolidated error message
        if (validationErrors.length > 0) {
            showToast('Validation Error', validationErrors.join('<br>'), 'danger');
            return false;
        }
        
        return true;
    });
    
        // Listen for program period data load event to update form fields
        document.addEventListener('ProgramPeriodDataLoaded', function(e) {
            const data = e.detail;
            
            // Update program name
            const programNameInput = document.getElementById('program_name');
            if (programNameInput) programNameInput.value = data.program_name || '';
            
            // Update program number
            const programNumberInput = document.getElementById('program_number');
            if (programNumberInput) programNumberInput.value = data.program_number || '';
            
            // Update brief description
            const briefDescInput = document.getElementById('brief_description');
            if (briefDescInput) briefDescInput.value = data.brief_description || '';
            
            // Update remarks
            const remarksInput = document.getElementById('remarks');
            if (remarksInput) remarksInput.value = data.remarks || '';
            
            // Update rating
            const ratingInput = document.getElementById('rating');
            if (ratingInput) ratingInput.value = data.rating || 'not-started';
            
            // Update submission_id hidden field
            const submissionIdInput = document.querySelector('input[name="submission_id"]');
            if (submissionIdInput) {
                submissionIdInput.value = data.submission_id || '';
            }
            
            // Update period_id hidden field
            const periodIdInput = document.querySelector('input[name="period_id"]');
            if (periodIdInput) {
                periodIdInput.value = data.period_id || '';
            }
            // Update rating pills UI
            const ratingPills = document.querySelectorAll('.rating-pill');
            ratingPills.forEach(pill => {
                pill.classList.remove('active');
                if (pill.getAttribute('data-rating') === data.rating) {
                    pill.classList.add('active');
                }
            });
            // Update targets
            const targetsContainer = document.getElementById('targets-container');
            if (targetsContainer) {
                targetsContainer.innerHTML = '';
                // Show all targets including completed ones to keep them displayed when navigating back
                const filteredTargets = (data.targets || [{target_text:'',status_description:'', target_number:'', target_status:'not-started', start_date:null, end_date:null}]);
                filteredTargets.forEach((target, idx) => {
                    const targetEntry = document.createElement('div');
                    targetEntry.className = 'target-entry';
                    targetEntry.innerHTML = `
                        <button type="button" class="btn-close remove-target" aria-label="Remove target"></button>
                        <!-- Target Counter -->
                        <div class="target-counter-header mb-2">
                            <h6 class="text-primary fw-bold mb-0">
                                <i class="fas fa-bullseye me-1"></i>Target #${idx + 1}
                            </h6>
                        </div>
                        <!-- Target Number and Status Row -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Target Number (Optional)</label>
                                <input type="text" class="form-control target-number-input" name="target_number[]" 
                                       value="${target.target_number || ''}" 
                                       placeholder="e.g., ${data.program_number || '30.1A'}.${idx + 1}">
                                <div class="form-text">Format: {program_number}.{target_counter}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Target Status</label>
                                <select class="form-select target-status-select" name="target_status[]">
                                    <option value="not-started" ${target.target_status === 'not-started' ? 'selected' : ''}>Not Started</option>
                                    <option value="in-progress" ${target.target_status === 'in-progress' ? 'selected' : ''}>In Progress</option>
                                    <option value="completed" ${target.target_status === 'completed' ? 'selected' : ''}>Completed</option>
                                    <option value="delayed" ${target.target_status === 'delayed' ? 'selected' : ''}>Delayed</option>
                                </select>
                            </div>
                        </div>
                        <!-- Target Text -->
                        <div class="mb-3">
                            <label class="form-label target-text-label">Target *</label>
                            <textarea class="form-control target-input" name="target_text[]" 
                                      rows="3"
                                      placeholder="Define a measurable target (e.g., 'Plant 100 trees')">${target.target_text || ''}</textarea>
                        </div>
                        <!-- Timeline Row -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date (Optional)</label>
                                <input type="date" class="form-control target-start-date" name="target_start_date[]" 
                                       value="${target.start_date ? new Date(target.start_date).toISOString().split('T')[0] : ''}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date (Optional)</label>
                                <input type="date" class="form-control target-end-date" name="target_end_date[]" 
                                       value="${target.end_date ? new Date(target.end_date).toISOString().split('T')[0] : ''}">
                            </div>
                        </div>
                        <!-- Status Description -->
                        <div class="mb-2">
                            <label class="form-label">Status Description</label>
                            <textarea class="form-control status-description" name="target_status_description[]" rows="2" 
                                      placeholder="Describe the current status or progress toward this target">${target.status_description || ''}</textarea>
                            <div class="form-text">Describe the current status or achievement toward this target.</div>
                        </div>
                    `;
                    targetsContainer.appendChild(targetEntry);
                    // Attach remove event
                    targetEntry.querySelector('.remove-target').addEventListener('click', function() {
                        targetEntry.remove();
                    });
                });
            }
        });
});
</script>

<?php
// Include footer
require_once dirname(__DIR__, 2) . '/layouts/footer.php';
