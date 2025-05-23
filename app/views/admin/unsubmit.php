<?php
/**
 * Unsubmit Program Submission
 * 
 * Sets the is_draft flag to 1 for a specific program submission within a reporting period.
 */

// Define project root path for consistent file references
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', rtrim(dirname(dirname(dirname(__DIR__))), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

// Include necessary files
require_once PROJECT_ROOT_PATH . 'app/config/config.php';
require_once PROJECT_ROOT_PATH . 'app/lib/db_connect.php';
require_once PROJECT_ROOT_PATH . 'app/lib/session.php';
require_once PROJECT_ROOT_PATH . 'app/lib/functions.php'; // General functions
require_once PROJECT_ROOT_PATH . 'app/lib/admin_functions.php'; // Admin-specific functions
// require_once PROJECT_ROOT_PATH . 'app/lib/admin_functions.php'; // May not be needed if only basic DB ops

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

// Prepare and execute update query to set is_draft = 1 for the specific program submission
// Optionally, you might also want to reset the status, e.g., status = 'not-started' or 'draft'
$sql = "UPDATE program_submissions SET is_draft = 1, status = 'not-started' WHERE program_id = ? AND period_id = ?";
$stmt = $conn->prepare($sql);

$success = false;
if ($stmt) {
    $stmt->bind_param('ii', $program_id, $period_id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Program submission has been successfully un-submitted and marked as draft.";
            $success = true;
        } else {
            // This can happen if the submission didn't exist or was already a draft with status 'not-started'
            // Check if a submission record actually exists for this program_id and period_id
            $check_sql = "SELECT submission_id FROM program_submissions WHERE program_id = ? AND period_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $program_id, $period_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows === 0) {
                $_SESSION['error_message'] = "No submission found for this program in the specified period. Nothing to un-submit.";
            } else {
                 // Record existed, but maybe no change was needed (e.g., already draft and not-started)
                $_SESSION['info_message'] = "Program submission was already in the desired state or no changes were made.";
            }
            $check_stmt->close();
        }
    } else {
        $_SESSION['error_message'] = "Failed to un-submit the program. Database error: " . $stmt->error;
    }
    $stmt->close();
} else {
    $_SESSION['error_message'] = "Failed to prepare the database statement for un-submitting.";
}

// Construct redirect URL to go back to the programs page, maintaining the period context
// And potentially other filters if they were passed or stored in session
$redirect_url = 'programs.php?period_id=' . $period_id;

// You could enhance this by re-adding other filters if your programs.php supports them in GET
// For example: if (isset($_GET['rating'])) $redirect_url .= '&rating='.urlencode($_GET['rating']);
// For simplicity, just redirecting with period_id for now.

header('Location: ' . $redirect_url);
exit;
?>


