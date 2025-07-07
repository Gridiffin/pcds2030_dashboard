<?php
/**
 * Delete Outcome Detail Handler
 * 
 * Handles deletion of outcome details (important metrics) by admin users.
 */

require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admins/index.php';
require_once ROOT_PATH . 'app/lib/audit_log.php';

// Set content type to JSON
header('Content-Type: application/json');

// Verify user is an admin
if (!is_admin()) {
    log_audit_action(
        'outcome_detail_delete_denied',
        'Unauthorized attempt to delete outcome detail',
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

// Get detail ID from POST data
$detail_id = isset($_POST['detail_id']) ? (int)$_POST['detail_id'] : 0;

if ($detail_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid detail ID']);
    exit;
}

try {
    // First, get the detail information for logging
    $get_detail_query = "SELECT detail_name, detail_json FROM outcomes_details WHERE detail_id = ?";
    $stmt = $conn->prepare($get_detail_query);
    $stmt->bind_param('i', $detail_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Outcome detail not found']);
        exit;
    }
    
    $detail = $result->fetch_assoc();
    $stmt->close();
    
    // Begin transaction
    $conn->begin_transaction();
    
    // Delete the outcome detail
    $delete_query = "DELETE FROM outcomes_details WHERE detail_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param('i', $detail_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to delete outcome detail: ' . $stmt->error);
    }
    
    $stmt->close();
    
    // Commit transaction
    $conn->commit();
    
    // Log successful deletion
    log_audit_action(
        'admin_outcome_detail_deleted',
        "Admin deleted outcome detail '{$detail['detail_name']}' (ID: {$detail_id})",
        'success',
        $_SESSION['user_id']
    );
    
    echo json_encode([
        'success' => true, 
        'message' => 'Outcome detail deleted successfully'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    $conn->rollback();
    
    // Log failed deletion
    log_audit_action(
        'admin_outcome_detail_deletion_failed',
        "Admin failed to delete outcome detail (ID: {$detail_id}): " . $e->getMessage(),
        'failure',
        $_SESSION['user_id']
    );
    
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to delete outcome detail: ' . $e->getMessage()
    ]);
}
?>
