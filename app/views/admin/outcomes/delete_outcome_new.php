<?php
/**
 * Delete Outcome Handler
 * 
 * Handles deletion of outcomes by admin users via AJAX.
 */

require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admins/outcomes.php';
require_once ROOT_PATH . 'app/lib/admins/index.php';
require_once ROOT_PATH . 'app/lib/audit_log.php';

// Set content type to JSON
header('Content-Type: application/json');

// Verify user is an admin
if (!is_admin()) {
    log_audit_action(
        'outcome_delete_denied',
        'Unauthorized attempt to delete outcome',
        'failure'
    );
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get outcome ID from POST data
$outcome_id = isset($_POST['metric_id']) ? (int)$_POST['metric_id'] : 0;

if ($outcome_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid outcome ID']);
    exit;
}

try {
    // Get outcome data for logging before deletion
    $outcome_data = get_outcome_data($outcome_id);
    
    if (!$outcome_data) {
        echo json_encode(['success' => false, 'message' => 'Outcome not found or already deleted']);
        exit;
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    // Delete from the main sector_outcomes_data table
    $query_main = "DELETE FROM sector_outcomes_data WHERE metric_id = ?";
    $stmt_main = $conn->prepare($query_main);
    
    if (!$stmt_main) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt_main->bind_param("i", $outcome_id);
    if (!$stmt_main->execute()) {
        throw new Exception("Delete failed: " . $stmt_main->error);
    }
    
    $affected_rows = $stmt_main->affected_rows;
    $stmt_main->close();
    
    if ($affected_rows === 0) {
        throw new Exception("No outcome found with ID: $outcome_id");
    }
    
    // TODO: If there are related tables or dynamic tables, delete from them as well
    // Example:
    // if (!empty($outcome_data['table_name'])) {
    //     $table_name = $outcome_data['table_name'];
    //     if (preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
    //         $conn->query("DROP TABLE IF EXISTS `$table_name`");
    //     }
    // }
    
    // Commit transaction
    $conn->commit();
    
    // Log successful deletion
    log_audit_action(
        'admin_outcome_deleted',
        "Admin deleted outcome '{$outcome_data['table_name']}' (Metric ID: {$outcome_id}) for sector {$outcome_data['sector_id']}",
        'success',
        $_SESSION['user_id']
    );
    
    echo json_encode([
        'success' => true, 
        'message' => 'Outcome deleted successfully'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    $conn->rollback();
    
    // Log failed deletion
    log_audit_action(
        'admin_outcome_deletion_failed',
        "Admin failed to delete outcome (Metric ID: {$outcome_id}): " . $e->getMessage(),
        'failure',
        $_SESSION['user_id']
    );
    
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to delete outcome: ' . $e->getMessage()
    ]);
}
?>
