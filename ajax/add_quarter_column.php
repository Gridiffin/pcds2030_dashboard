<?php
/**
 * Utility script to add is_standard_dates column to reporting_periods table
 */

// Include necessary files
require_once '../config/config.php';
require_once '../includes/db_connect.php';

// Check if the column exists
$check_query = "SHOW COLUMNS FROM `reporting_periods` LIKE 'is_standard_dates'";
$result = $conn->query($check_query);

if ($result->num_rows === 0) {
    // Column doesn't exist, add it
    $alter_query = "ALTER TABLE `reporting_periods` ADD COLUMN `is_standard_dates` BOOLEAN DEFAULT 1";
    
    if ($conn->query($alter_query)) {
        echo "Column 'is_standard_dates' added successfully to reporting_periods table.";
        
        // Update existing periods to mark which ones follow standard quarter dates
        $update_query = "UPDATE reporting_periods SET is_standard_dates = 
        (
            CASE 
                WHEN (quarter = 1 AND start_date = CONCAT(year, '-01-01') AND end_date = CONCAT(year, '-03-31')) THEN 1
                WHEN (quarter = 2 AND start_date = CONCAT(year, '-04-01') AND end_date = CONCAT(year, '-06-30')) THEN 1
                WHEN (quarter = 3 AND start_date = CONCAT(year, '-07-01') AND end_date = CONCAT(year, '-09-30')) THEN 1
                WHEN (quarter = 4 AND start_date = CONCAT(year, '-10-01') AND end_date = CONCAT(year, '-12-31')) THEN 1
                ELSE 0
            END
        )";
        
        if ($conn->query($update_query)) {
            echo "<br>Existing periods updated successfully.";
        } else {
            echo "<br>Error updating existing periods: " . $conn->error;
        }
    } else {
        echo "Error adding column: " . $conn->error;
    }
} else {
    echo "Column 'is_standard_dates' already exists.";
}
?>
