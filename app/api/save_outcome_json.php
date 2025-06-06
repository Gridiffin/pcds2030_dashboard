<?php
/**
 * API Endpoint: Save Outcome JSON Data
 * 
 * Handles saving the JSON structure for outcome data
 * Used by the outcome editor to initialize or update the JSON structure
 */

// Include necessary files
require_once '../config/config.php';
require_once '../lib/db_connect.php';
require_once '../lib/session.php';
require_once '../lib/functions.php';
require_once '../lib/admins/outcomes.php'; // Added for record_outcome_history function

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Get JSON data from request body
$input = json_decode(file_get_contents('php://input'), true);

// Validate required parameters
if (empty($input['metric_id']) || empty($input['sector_id']) || empty($input['data_json'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$metric_id = intval($input['metric_id']);
$sector_id = intval($input['sector_id']);
$table_name = $input['table_name'] ?? 'Table_' . $metric_id;
$data_json = json_encode($input['data_json']);
$is_draft = isset($input['is_draft']) ? intval($input['is_draft']) : 1;
$user_id = $_SESSION['user_id']; // Get current user ID for history tracking

try {
    // Check if the record already exists
    $check_query = "SELECT id FROM sector_outcomes_data 
                   WHERE metric_id = ? AND sector_id = ? 
                   LIMIT 1";
    
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ii", $metric_id, $sector_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Record exists, update it
        $outcome_record_id = $row['id'];
        $query = "UPDATE sector_outcomes_data 
                 SET table_name = ?, data_json = ?, updated_at = NOW() 
                 WHERE metric_id = ? AND sector_id = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssii", $table_name, $data_json, $metric_id, $sector_id);
        
        if ($stmt->execute()) {
            // Record history for the edit action
            $action_type = 'edit';
            $status = $is_draft ? 'draft' : 'submitted';
            $description = 'Updated outcome data structure';
            record_outcome_history($outcome_record_id, $metric_id, $data_json, $action_type, $status, $user_id, $description);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Outcome data saved successfully'
            ]);
        } else {
            throw new Exception($conn->error);
        }
    } else {
        // Create new record
        $query = "INSERT INTO sector_outcomes_data 
                 (metric_id, sector_id, table_name, data_json, is_draft) 
                 VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iissi", $metric_id, $sector_id, $table_name, $data_json, $is_draft);
        
        if ($stmt->execute()) {
            // Get the inserted record ID for history tracking
            $outcome_record_id = $conn->insert_id;
            
            // Record history for the create action
            $action_type = 'create';
            $status = $is_draft ? 'draft' : 'submitted';
            $description = 'Created new outcome data structure';
            record_outcome_history($outcome_record_id, $metric_id, $data_json, $action_type, $status, $user_id, $description);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Outcome data saved successfully'
            ]);
        } else {
            throw new Exception($conn->error);
        }
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
