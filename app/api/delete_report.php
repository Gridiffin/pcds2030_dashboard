<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => "$errstr in $errfile on line $errline"
    ]);
    exit;
});

/**
 * Delete Report API
 * 
 * This endpoint handles the deletion of generated reports.
 * Only admins can delete reports.
 */

// Include necessary files
require_once '../config/config.php';
require_once '../lib/db_connect.php';
require_once '../lib/session.php';
require_once '../lib/functions.php';
require_once '../lib/admins/core.php';  // Added this file which contains is_admin()
require_once '../lib/audit_log.php';

// Make sure no output has been sent before we set headers
if (ob_get_length()) ob_clean();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in and is an admin
if (!is_admin()) {
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized access'
    ]);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request method'
    ]);
    exit();
}

// Get report ID from JSON POST data or form POST data
$reportId = 0;

// Check if it's JSON data
$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($contentType, 'application/json') !== false) {
    // Handle JSON data
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON data'
        ]);
        exit();
    }
    
    $reportId = isset($data['report_id']) ? intval($data['report_id']) : 0;
} else {
    // Handle form data
    $reportId = isset($_POST['report_id']) ? intval($_POST['report_id']) : 0;
}

if ($reportId <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid report ID'
    ]);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Get the report details before deletion for audit logging
    $query = "SELECT r.report_id, r.report_name, r.pptx_path, r.generated_at,
                     u.username, u.agency_name,
                     rp.quarter, rp.year
              FROM reports r 
              LEFT JOIN users u ON r.generated_by = u.user_id 
              LEFT JOIN reporting_periods rp ON r.period_id = rp.period_id 
              WHERE r.report_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Log failed deletion attempt - report not found
        log_audit_action('delete_report', "Attempted to delete non-existent report (ID: {$reportId})", 'failure');
        
        throw new Exception('Report not found');
    }
    
    $report = $result->fetch_assoc();
    
    // Construct the file path - handle both old and new path formats
    $filePath = '';
    if (strpos($report['pptx_path'], 'app/reports/') === 0) {
        // New format: path includes app/reports/
        $filePath = ROOT_PATH . $report['pptx_path'];
    } else {
        // Old format: path starts from pptx/
        $filePath = ROOT_PATH . 'app/reports/' . $report['pptx_path'];
    }
    
    $report_info = "{$report['agency_name']} - Q{$report['quarter']} {$report['year']} (Generated: {$report['generated_at']})";
    
    // Delete from database
    $deleteQuery = "DELETE FROM reports WHERE report_id = ?";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->bind_param('i', $reportId);
    $deleteStmt->execute();
    
    if ($deleteStmt->affected_rows === 0) {
        // Log failed database deletion
        log_audit_action('delete_report', "Failed to delete report from database: {$report_info} (ID: {$reportId})", 'failure');
        
        throw new Exception('Failed to delete report from database');
    }
    
    // Track file deletion status for audit logging
    $file_deleted = true;
    $file_error = '';
    
    // Delete the physical file
    if (file_exists($filePath) && !unlink($filePath)) {
        // If file deletion fails, log but don't throw exception
        $file_deleted = false;
        $file_error = " (Warning: Physical file deletion failed)";
        error_log("Warning: Failed to delete file: $filePath");
    }
    
    // Commit transaction
    $conn->commit();
    
    // Log successful report deletion
    $audit_details = "Deleted report: {$report_info} (ID: {$reportId}, File: {$report['pptx_path']}){$file_error}";
    log_audit_action('delete_report', $audit_details, 'success');
    
    echo json_encode([
        'success' => true,
        'message' => 'Report deleted successfully'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    $conn->rollback();
    
    // Log failed deletion attempt
    log_audit_action('delete_report', "Failed to delete report (ID: {$reportId}). Error: " . $e->getMessage(), 'failure');
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} finally {
    // Close statements
    if (isset($stmt)) $stmt->close();
    if (isset($deleteStmt)) $deleteStmt->close();
}