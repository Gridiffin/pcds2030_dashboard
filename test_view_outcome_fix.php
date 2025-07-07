<?php
/**
 * Test script to verify the view_outcome fix
 */

// Define project root path for consistent file references
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

// Include necessary files
require_once PROJECT_ROOT_PATH . 'app/config/config.php';
require_once PROJECT_ROOT_PATH . 'app/lib/db_connect.php';

// Test with metric_id 7 (TIMBER EXPORT VALUE)
$outcome_id = 7;
$sector_id = 1;

// Get outcome data (same query as view_outcome.php)
$query = "SELECT sod.*, u.username as submitted_by_username 
          FROM sector_outcomes_data sod 
          LEFT JOIN users u ON sod.submitted_by = u.user_id 
          WHERE sod.metric_id = ? AND sod.sector_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $outcome_id, $sector_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "No outcome found\n";
    exit;
}

$row = $result->fetch_assoc();
$table_name = $row['table_name'];
$outcome_data = json_decode($row['data_json'], true);

echo "Testing view outcome: {$table_name}\n";

// Apply the same logic as the fixed view_outcome.php
$table_structure_type = $row['table_structure_type'] ?? 'monthly';
$row_config = json_decode($row['row_config'] ?? '{}', true);
$column_config = json_decode($row['column_config'] ?? '{}', true);

// Determine if this is a flexible structure or legacy
$is_flexible = !empty($row_config) && !empty($column_config);

if ($is_flexible) {
    // New flexible structure
    $rows = $row_config['rows'] ?? [];
    $columns = $column_config['columns'] ?? [];
    
    // Ensure columns have proper IDs - fix any missing or incorrect IDs
    foreach ($columns as $index => &$column) {
        if (!isset($column['id'])) {
            $column['id'] = $column['label'] ?? $index;
        }
    }
    unset($column); // Break reference
} elseif (isset($outcome_data['columns'], $outcome_data['data']) && is_array($outcome_data['columns']) && is_array($outcome_data['data'])) {
    // New JSON structure: columns and data keys
    if (is_array($outcome_data['columns'][0] ?? null)) {
        // Array of column objects
        $columns = $outcome_data['columns'];
    } else {
        // Simple array of column names
        $columns = array_map(function($col) {
            return ['id' => $col, 'label' => $col, 'type' => 'number', 'unit' => ''];
        }, $outcome_data['columns']);
    }
    $rows = array_map(function($row_id) {
        return ['id' => $row_id, 'label' => $row_id, 'type' => 'data'];
    }, array_keys($outcome_data['data']));
}

// Organize data for display with proper column mapping
$table_data = [];
if (isset($outcome_data['data']) && is_array($outcome_data['data'])) {
    $row_labels = array_keys($outcome_data['data']);
    $rows = array_map(function($row_id) {
        return ['id' => $row_id, 'label' => $row_id, 'type' => 'data'];
    }, $row_labels);
    
    foreach ($rows as $row_def) {
        $row_data = ['row' => $row_def, 'metrics' => []];
        if (isset($outcome_data['data'][$row_def['id']]) && is_array($outcome_data['data'][$row_def['id']])) {
            $raw_metrics = $outcome_data['data'][$row_def['id']];
            // Map the raw data keys to column IDs using the column mapping
            foreach ($columns as $column) {
                $column_id = $column['id'];
                $column_label = $column['label'];
                
                // Try different key variations to find the actual data
                $value = 0;
                if (isset($raw_metrics[$column_label])) {
                    $value = $raw_metrics[$column_label];
                    echo "Found value {$value} using label '{$column_label}' for row {$row_def['id']}\n";
                } elseif (isset($raw_metrics[$column_id])) {
                    $value = $raw_metrics[$column_id];
                    echo "Found value {$value} using ID '{$column_id}' for row {$row_def['id']}\n";
                } elseif (isset($raw_metrics[(string)$column_id])) {
                    $value = $raw_metrics[(string)$column_id];
                    echo "Found value {$value} using string ID '{$column_id}' for row {$row_def['id']}\n";
                }
                
                $row_data['metrics'][$column_id] = $value;
            }
        }
        $table_data[] = $row_data;
        
        // Just test first few rows
        if (count($table_data) >= 3) break;
    }
}

echo "\nFirst few rows from view perspective:\n";
foreach ($table_data as $row_data) {
    echo "Row: {$row_data['row']['label']}\n";
    foreach ($columns as $column) {
        $value = $row_data['metrics'][$column['id']] ?? 0;
        echo "  {$column['label']}: {$value}\n";
    }
    echo "\n";
}

echo "View outcome test completed!\n";
?>
