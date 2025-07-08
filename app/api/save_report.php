<?php
/**
 * Save Report API Endpoint
 * 
 * Saves a generated PPTX report file to the server and records it in the database.
 * This endpoint receives the generated report from the client-side JavaScript.
 * 
 * Only admin users are allowed to access this endpoint.
 */

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

// Suppress any output that might break JSON
ob_start();

// Include necessary files
require_once dirname(__DIR__) . '/config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admins/index.php';
require_once ROOT_PATH . 'app/lib/audit_log.php';

// Verify user is admin
if (!is_admin()) {
    ob_clean(); // Clear any output
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Permission denied. Only admin users can save reports.']);
    exit;
}

// Define upload directory - create if it doesn't exist
$upload_dir = ROOT_PATH . 'app/reports/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Define subdirectories for each report type
$pptx_dir = $upload_dir . 'pptx/';
$pdf_dir = $upload_dir . 'pdf/';

// Create subdirectories if they don't exist
if (!is_dir($pptx_dir)) {
    mkdir($pptx_dir, 0755, true);
}
if (!is_dir($pdf_dir)) {
    mkdir($pdf_dir, 0755, true);
}

// Get POST parameters
$period_id = isset($_POST['period_id']) ? intval($_POST['period_id']) : 0;
$sector_id = isset($_POST['sector_id']) ? intval($_POST['sector_id']) : 0;
$report_name = isset($_POST['report_name']) ? $_POST['report_name'] : '';
$description = isset($_POST['description']) ? $_POST['description'] : '';
$is_public = isset($_POST['is_public']) ? intval($_POST['is_public']) : 0;

// Validate required parameters
if ($period_id <= 0 || $sector_id <= 0 || empty($report_name)) {
    // Log failed report save attempt due to invalid parameters
    log_audit_action('save_report', "Failed to save report due to invalid parameters. Period ID: {$period_id}, Sector ID: {$sector_id}, Report Name: '{$report_name}'", 'failure');
    
    ob_clean(); // Clear any output
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

// Get period and sector details for validation
$period = get_reporting_period($period_id);
if (!$period) {
    ob_clean(); // Clear any output
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid reporting period']);
    exit;
}

$sector_query = "SELECT sector_name FROM sectors WHERE sector_id = ?";
$stmt = $conn->prepare($sector_query);
$stmt->bind_param("i", $sector_id);
$stmt->execute();
$sector_result = $stmt->get_result();

if ($sector_result->num_rows === 0) {
    ob_clean(); // Clear any output
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid sector']);
    exit;
}

$sector = $sector_result->fetch_assoc();

// Check if file was uploaded
if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] != UPLOAD_ERR_OK) {
    // Log failed upload attempt
    $upload_error = isset($_FILES['report_file']) ? $_FILES['report_file']['error'] : 'No file';
    log_audit_action('save_report', "Failed to save report '{$report_name}' for {$sector['sector_name']} - Upload error: {$upload_error}", 'failure');
    
    ob_clean(); // Clear any output
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

// Process the uploaded file
$uploaded_file = $_FILES['report_file'];
$file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));

// Validate file type
if ($file_ext !== 'pptx') {
    // Log invalid file type attempt
    log_audit_action('save_report', "Failed to save report '{$report_name}' for {$sector['sector_name']} - Invalid file type: {$file_ext}", 'failure');
    
    ob_clean(); // Clear any output
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid file type. Only PPTX files are allowed.']);
    exit;
}

// Generate unique filename
$timestamp = date('YmdHis');
$quarter = 'Q' . $period['quarter'] . '-' . $period['year'];
$sanitized_sector = preg_replace('/[^a-zA-Z0-9]/', '_', $sector['sector_name']);
$filename = $sanitized_sector . '_' . $quarter . '_' . $timestamp . '.pptx';
$filepath = $pptx_dir . $filename;

// Move uploaded file to destination
if (!move_uploaded_file($uploaded_file['tmp_name'], $filepath)) {
    // Log file save failure
    log_audit_action('save_report', "Failed to save report '{$report_name}' for {$sector['sector_name']} - File system error during upload", 'failure');
    
    ob_clean(); // Clear any output
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to save the file']);
    exit;
}

// Record in database
$user_id = $_SESSION['user_id'] ?? 0;
$pptx_path = 'app/reports/pptx/' . $filename; // Relative path from project root for database storage

// Prepare SQL to insert record
$insert_query = "INSERT INTO reports (period_id, report_name, description, pdf_path, pptx_path, generated_by, is_public) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($insert_query);
$pdf_path = ''; // Initialize empty pdf_path since we're only saving PPTX for now
$stmt->bind_param("issssii", $period_id, $report_name, $description, $pdf_path, $pptx_path, $user_id, $is_public);

// Execute query
if (!$stmt->execute()) {
    // If database insert fails, delete the uploaded file
    unlink($filepath);
    
    // Log database error
    log_audit_action('save_report', "Failed to save report '{$report_name}' for {$sector['sector_name']} - Database error: " . $stmt->error, 'failure');
    
    ob_clean(); // Clear any output
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to record report in database: ' . $stmt->error]);
    exit;
}

// Get the newly created report id
$report_id = $conn->insert_id;

// Log successful report save
$file_size = filesize($filepath);
$quarter_year = 'Q' . $period['quarter'] . ' ' . $period['year'];
$report_details = "Report: '{$report_name}' for {$sector['sector_name']} - {$quarter_year} (ID: {$report_id}, File: {$filename}, Size: " . number_format($file_size) . " bytes)";
log_audit_action('save_report', "Successfully saved {$report_details}", 'success');

// Return success response
$response = [
    'success' => true,
    'message' => 'Report saved successfully',
    'report_id' => $report_id,
    'pptx_path' => $pptx_path,
    'filename' => $filename
];

ob_clean(); // Clear any output that might have been generated
header('Content-Type: application/json');
echo json_encode($response);
exit;
?>