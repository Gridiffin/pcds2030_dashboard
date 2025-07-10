<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

// Disable error output to prevent headers already sent issues
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../lib/audit_log.php';
/**
 * Resubmit Program Submission
 * 
 * Sets the is_draft flag to 0 for a specific program submission within a reporting period,
 * and records the current timestamp as submission_date/updated_at.
 */

// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admins/index.php';
require_once ROOT_PATH . 'app/lib/admins/statistics.php'; // Contains program functions and log_action
require_once ROOT_PATH . 'app/lib/audit_log.php';

// Verify user is an admin
if (!is_admin()) {
    // Set a message for the user
    $_SESSION['error_message'] = "Access denied. You must be an administrator to perform this action.";
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Check if program_id and period_id are provided and valid
if (!isset($_GET['program_id']) || !is_numeric($_GET['program_id']) || 
    !isset($_GET['period_id']) || !is_numeric($_GET['period_id'])) {
    
    $_SESSION['error_message'] = "Invalid request. Program ID or Period ID is missing or invalid.";
    // Redirect back to programs list, try to maintain original period if possible
    $redirect_url = 'programs.php';
    if (isset($_GET['period_id']) && is_numeric($_GET['period_id'])) {
        $redirect_url .= '?period_id=' . intval($_GET['period_id']);
    } elseif (isset($_SESSION['last_viewed_period_id'])) { // Fallback to a session stored period
        $redirect_url .= '?period_id=' . $_SESSION['last_viewed_period_id'];
    }
    header('Location: ' . $redirect_url);
    exit;
}

$program_id = intval($_GET['program_id']);
$period_id = intval($_GET['period_id']);

// First check if submission exists
$submission_data = get_program_submission($program_id, $period_id); // Using function from statistics.php

if (!$submission_data) {
    $_SESSION['error_message'] = "No submission found for this program in the specified period. Nothing to resubmit.";
    header('Location: programs.php?period_id=' . $period_id);
    exit;
}

// Prepare and execute update query to set is_draft = 0 and update timestamps.
// The status column is NOT changed here to avoid truncation.
$sql = "UPDATE program_submissions 
        SET is_draft = 0, submission_date = NOW(), updated_at = NOW() 
        WHERE program_id = ? AND period_id = ?";
$stmt = $conn->prepare($sql);

$success = false;
if ($stmt) {
    $stmt->bind_param('ii', $program_id, $period_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Program has been successfully re-submitted.";
            $success = true;
            
            // Log successful program resubmit
            $program_name = $submission_data['program_name'] ?? 'Unknown Program';
            log_audit_action('admin_resubmit_program', "Program: $program_name | Program ID: $program_id | Period ID: $period_id", 'success', $_SESSION['user_id']);
        } else {
            // If no rows affected, it might be because it was already not a draft
            if ($submission_data['is_draft'] == 0) {
                 $_SESSION['info_message'] = "Program submission was already in the submitted state (not a draft).";
                 // Consider $success = true here if this is not an error condition
            } else {
                $_SESSION['error_message'] = "Failed to resubmit the program. No rows were updated, though a record exists.";
                
                // Log failed program resubmit
                $program_name = $submission_data['program_name'] ?? 'Unknown Program';
                log_audit_action('admin_resubmit_program_failed', "Program: $program_name | Program ID: $program_id | Period ID: $period_id | Error: No rows updated", 'failure', $_SESSION['user_id']);
            }
        }
    } else {
        $_SESSION['error_message'] = "Failed to resubmit the program. Database error: " . $stmt->error;
        
        // Log failed program resubmit
        $program_name = $submission_data['program_name'] ?? 'Unknown Program';
        log_audit_action('admin_resubmit_program_failed', "Program: $program_name | Program ID: $program_id | Period ID: $period_id | Error: " . $stmt->error, 'failure', $_SESSION['user_id']);
    }
    $stmt->close();
} else {
    $_SESSION['error_message'] = "Failed to prepare the database statement for resubmitting.";
}

// Log the action
if (function_exists('log_action')) {
    log_action('resubmit_program', "Program ID: $program_id, Period ID: $period_id. Submission resubmitted.", $success);
}

// Construct redirect URL
$redirect_url = 'programs.php?period_id=' . $period_id;

// Clean any output buffer before redirect
ob_end_clean();

header('Location: ' . $redirect_url);
exit;