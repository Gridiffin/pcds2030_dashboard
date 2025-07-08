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
 * Generate Report API Endpoint
 * 
 * Handles complete report generation including target selection.
 * This endpoint processes form submissions from the admin generate reports page.
 * 
 * Only admin users are allowed to access this endpoint.
 */

// Prevent any output before headers
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
    ob_end_clean();
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Permission denied. Only admin users can generate reports.']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('HTTP/1.1 405 Method Not Allowed');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Only POST requests are allowed.']);
    exit;
}

try {
    // Get form data
    $period_id = isset($_POST['period_id']) ? intval($_POST['period_id']) : null;
    $sector_id = isset($_POST['sector_id']) ? intval($_POST['sector_id']) : null;
    $selected_programs = isset($_POST['selected_programs']) ? $_POST['selected_programs'] : [];
    $selected_targets = isset($_POST['selected_targets']) ? $_POST['selected_targets'] : [];

    // Validate required fields
    if (!$period_id || !$sector_id) {
        throw new Exception('Period and sector are required.');
    }

    if (empty($selected_programs)) {
        throw new Exception('At least one program must be selected.');
    }

    // Process selected programs
    $program_ids = [];
    $program_orders = [];
    
    foreach ($selected_programs as $program) {
        if (isset($program['program_id']) && isset($program['order'])) {
            $program_id = intval($program['program_id']);
            $order = intval($program['order']);
            
            if ($program_id > 0 && $order > 0) {
                $program_ids[] = $program_id;
                $program_orders[$program_id] = $order;
            }
        }
    }

    if (empty($program_ids)) {
        throw new Exception('No valid programs selected.');
    }

    // Log the target selection for debugging
    error_log("Report generation with target selection:");
    error_log("Period ID: $period_id");
    error_log("Sector ID: $sector_id");
    error_log("Program IDs: " . implode(',', $program_ids));
    error_log("Selected targets: " . json_encode($selected_targets));

    // Build URL for report_data.php endpoint
    $report_data_url = APP_URL . '/app/api/report_data.php?' . http_build_query([
        'period_id' => $period_id,
        'sector_id' => $sector_id,
        'selected_program_ids' => implode(',', $program_ids),
        'program_orders' => json_encode($program_orders)
    ]);

    // If targets are selected, add them as a parameter
    if (!empty($selected_targets)) {
        $report_data_url .= '&selected_targets=' . urlencode(json_encode($selected_targets));
    }

    // Fetch report data
    $report_data = @file_get_contents($report_data_url);
    
    if ($report_data === false) {
        throw new Exception('Failed to fetch report data from internal API.');
    }

    $data = json_decode($report_data, true);
    
    if (!$data || !isset($data['success']) || !$data['success']) {
        throw new Exception('Report data API returned an error: ' . ($data['error'] ?? 'Unknown error'));
    }

    // If target filtering is enabled, modify the report data to include only selected targets
    if (!empty($selected_targets) && isset($data['data']['programs'])) {
        $data['data']['programs'] = filterProgramTargets($data['data']['programs'], $selected_targets);
    }

    // Log audit trail
    log_audit_action(
        $_SESSION['user_id'] ?? 0,
        'report_generated',
        'reports',
        null,
        "Generated report for period $period_id, sector $sector_id with " . count($program_ids) . " programs"
    );

    // Return success response with report data
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Report data generated successfully.',
        'data' => $data['data']
    ]);

} catch (Exception $e) {
    error_log("Report generation error: " . $e->getMessage());
    
    ob_end_clean();
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Filter program targets based on user selection
 * 
 * @param array $programs Array of program data
 * @param array $selected_targets Array of selected target IDs per program
 * @return array Filtered program data
 */
function filterProgramTargets($programs, $selected_targets) {
    foreach ($programs as &$program) {
        $program_id = $program['program_id'];
        
        // If this program has target selections, filter the targets
        if (isset($selected_targets[$program_id]) && !empty($selected_targets[$program_id])) {
            $selected_target_ids = array_map('intval', $selected_targets[$program_id]);
            
            // Filter targets to only include selected ones
            if (isset($program['targets'])) {
                $program['targets'] = array_filter($program['targets'], function($target) use ($selected_target_ids) {
                    return in_array(intval($target['target_id']), $selected_target_ids);
                });
                
                // Re-index the array
                $program['targets'] = array_values($program['targets']);
            }
            
            // Also filter target_text and status_description if they exist
            // This may need adjustment based on how the data is structured
            if (isset($program['target_text']) && isset($program['status_description'])) {
                // For aggregated text fields, we might need to parse and filter
                // This would require more complex logic depending on how the text is formatted
                // For now, we'll leave the aggregated text as-is
            }
        }
    }
    
    return $programs;
}
?>
