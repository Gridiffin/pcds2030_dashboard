<?php
/**
 * Report Data API Endpoint
 * 
 * Retrieves and formats data needed for PPTX report generation.
 * This endpoint provides JSON data to the client-side JavaScript
 * which then generates the PowerPoint file using PptxGenJS.
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
require_once ROOT_PATH . 'app/lib/rating_helpers.php';

// Verify user is admin
if (!is_admin()) {
    ob_end_clean(); // Clear any buffered output
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Permission denied. Only admin users can generate reports.']);
    exit;
}

// Get parameters from request
$period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : null;
$sector_id = isset($_GET['sector_id']) ? intval($_GET['sector_id']) : 1; // Default to Forestry (sector_id 1)
$selected_program_ids_raw = isset($_GET['selected_program_ids']) ? $_GET['selected_program_ids'] : null;
$program_orders_raw = isset($_GET['program_orders']) ? $_GET['program_orders'] : null;

// Parse program orders if provided
$program_orders = [];
if ($program_orders_raw) {
    try {
        $program_orders = json_decode($program_orders_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Error parsing program_orders JSON: " . json_last_error_msg());
            $program_orders = [];
        }
    } catch (Exception $e) {
        error_log("Exception parsing program_orders: " . $e->getMessage());
        $program_orders = [];
    }
}

// Add debug logging for parameters
error_log("API parameters - period_id: {$period_id}, sector_id: {$sector_id}");
error_log("Fixed duplicate submission query - using MAX(submission_id) for tie-breaking");
if (!empty($program_orders)) {
    error_log("Program orders provided: " . json_encode($program_orders));
}

// If no period_id provided, use current reporting period
if (!$period_id) {
    $current_period = get_current_reporting_period();
    $period_id = $current_period['period_id'] ?? null;
}

// Validate period_id
if (!$period_id) {
    ob_end_clean(); // Clear any buffered output
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid or missing reporting period']);
    exit;
}

// Get reporting period information
$period = get_reporting_period($period_id);
if (!$period) {
    ob_end_clean(); // Clear any buffered output
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['error' => 'Reporting period not found']);
    exit;
}

// Get sector information
$sector_query = "SELECT * FROM sectors WHERE sector_id = ?";
$stmt = $conn->prepare($sector_query);
$stmt->bind_param("i", $sector_id);
$stmt->execute();
$sector_result = $stmt->get_result();

if ($sector_result->num_rows === 0) {
    ob_end_clean(); // Clear any buffered output
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['error' => 'Sector not found']);
    exit;
}

$sector = $sector_result->fetch_assoc();

// Format the quarter/year string (e.g., "Q4 2024")
$quarter = "Q" . $period['quarter'] . " " . $period['year'];

// --- 1. Get Sector Leads (agencies with this sector) ---
$sector_leads_query = "SELECT GROUP_CONCAT(agency_name SEPARATOR '; ') as sector_leads 
                      FROM users 
                      WHERE sector_id = ? AND role = 'agency' AND is_active = 1";
$stmt = $conn->prepare($sector_leads_query);
$stmt->bind_param("i", $sector_id);
$stmt->execute();
$sector_leads_result = $stmt->get_result();
$sector_leads_row = $sector_leads_result->fetch_assoc();
$sector_leads = $sector_leads_row['sector_leads'] ?: 'No assigned agencies';

// Add department name before sector leads (e.g., "MUDENR; Sector Leads: ...")
$dept_prefix = '';
if ($sector_id == 1) { // Forestry
    $dept_prefix = 'MUDENR; Sector Leads: ';
} elseif ($sector_id == 2) { // Land
    $dept_prefix = 'MIPD; Sector Leads: ';
} elseif ($sector_id == 3) { // Environment
    $dept_prefix = 'MUDENR; Sector Leads: ';
} elseif ($sector_id == 4) { // Natural Resources
    $dept_prefix = 'MNRD; Sector Leads: ';
} elseif ($sector_id == 5) { // Urban Development
    $dept_prefix = 'MIPD; Sector Leads: ';
}

$sector_leads = $dept_prefix . $sector_leads;

// --- 2. Get Programs for this Sector ---
// Process selected program IDs if provided
$selected_program_ids = [];
$program_filter_condition = "p.sector_id = ?"; // Default filter by sector only
$program_params = [$period_id, $sector_id]; // Default parameters
$program_param_types = "ii"; // Default parameter types

if (!empty($selected_program_ids_raw)) {
    error_log("Selected program IDs raw: {$selected_program_ids_raw}");
    
    if (is_string($selected_program_ids_raw)) {
        $selected_program_ids = array_map('intval', explode(',', $selected_program_ids_raw));
    } elseif (is_array($selected_program_ids_raw)) {
        $selected_program_ids = array_map('intval', $selected_program_ids_raw);
    }
    
    // Filter out any zero or negative IDs to prevent issues
    $selected_program_ids = array_filter($selected_program_ids, function($id) {
        return $id > 0;
    });
    
    // If we have valid program IDs, update the query
    if (!empty($selected_program_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_program_ids), '?'));
        $program_filter_condition = "p.program_id IN ($placeholders)";
        
        // Reset params and add period_id twice (for subquery and main query), then all program IDs
        $program_params = [$period_id, $period_id];
        $program_param_types = "ii";
        
        // Add each program_id to params
        foreach ($selected_program_ids as $prog_id) {
            $program_params[] = $prog_id;
            $program_param_types .= "i";
        }
        
        error_log("Filtering by " . count($selected_program_ids) . " selected programs");
    }
}

// Add debug logging
error_log("Fetching programs for sector_id: {$sector_id} and period_id: {$period_id}");

// Prepare custom order case statement if program orders are provided
$order_by_clause = "p.program_name"; // Default alphabetical order
if (!empty($program_orders) && !empty($selected_program_ids)) {
    $case_parts = [];
    $valid_orders = 0;
    
    // Validate program orders and create case statements
    foreach ($program_orders as $prog_id => $order) {
        $prog_id = (int)$prog_id;
        $order = (int)$order;
        
        if (in_array($prog_id, $selected_program_ids) && $prog_id > 0 && $order > 0) {
            $case_parts[] = "WHEN p.program_id = {$prog_id} THEN {$order}";
            $valid_orders++;
        }
    }
    
    if (!empty($case_parts)) {
        $order_by_clause = "CASE " . implode(" ", $case_parts) . " ELSE 999999 END, p.program_name";
        error_log("Using custom order by clause with {$valid_orders} valid program orders");
    } else {
        error_log("No valid program orders found, using default alphabetical order");
    }
} else {
    error_log("No program orders provided or no programs selected, using default alphabetical order");
}

$programs_query = "SELECT p.program_id, p.program_name, 
                    ps.content_json
                  FROM programs p
                  LEFT JOIN (
                      SELECT ps1.*
                      FROM program_submissions ps1
                      INNER JOIN (
                          SELECT program_id, MAX(submission_date) as latest_date, MAX(submission_id) as latest_submission_id
                          FROM program_submissions
                          WHERE period_id = ? AND is_draft = 0
                          GROUP BY program_id
                      ) ps2 ON ps1.program_id = ps2.program_id 
                           AND ps1.submission_date = ps2.latest_date 
                           AND ps1.submission_id = ps2.latest_submission_id
                      WHERE ps1.period_id = ? AND ps1.is_draft = 0
                  ) ps ON p.program_id = ps.program_id
                  WHERE $program_filter_condition
                  ORDER BY $order_by_clause";

$stmt = $conn->prepare($programs_query);
if (!$stmt) {
    error_log("Failed to prepare statement: " . $conn->error);
    ob_end_clean();
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error preparing statement']);
    exit;
}

// Bind parameters using the array
$stmt->bind_param($program_param_types, ...$program_params);
$stmt->execute();
$programs_result = $stmt->get_result();
$programs_count = $programs_result->num_rows;
error_log("Query returned {$programs_count} programs");

$programs = [];
while ($program = $programs_result->fetch_assoc()) {    // Extract target from content_json
    $content = json_decode($program['content_json'] ?? '{}', true);
    $target = 'No target set';
    $status_text = 'No status update available';
    
    // Check if we have the new format with targets array
    if (isset($content['targets']) && is_array($content['targets']) && !empty($content['targets'])) {
        $target_texts = [];
        $status_texts = [];
        
        // Process each target and ensure newline consistency
        foreach ($content['targets'] as $t) {
            // Get target text with several fallbacks
            $target_text = $t['target_text'] ?? $t['text'] ?? 'No target set';
            // Make sure there are no escaped newlines in the text
            $target_text = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $target_text);
            $target_texts[] = $target_text;
            
            // Get status with fallbacks
            $status_desc = $t['status_description'] ?? 'No status update available';
            // Make sure there are no escaped newlines in the status
            $status_desc = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $status_desc);
            $status_texts[] = $status_desc;
        }
          // Combine targets and statuses with literal newlines for bullet point separation
        // Use clean newlines to ensure consistent bullet point formatting in the frontend
        $target = implode("\n", $target_texts);
        $status_text = implode("\n", $status_texts);
        
        // Extra normalization to ensure no leftover escaped characters
        $target = preg_replace('/\\\\[rn]/', "\n", $target);
        $status_text = preg_replace('/\\\\[rn]/', "\n", $status_text);
        
        // Debug log the processed targets (can be removed in production)
        error_log("Program {$program['program_id']}: Processed " . count($target_texts) . " targets");
          } elseif (isset($content['target'])) { // Old format with direct target property
        $target_text = $content['target'] ?? 'No target set';
        $status_description = $content['status_text'] ?? 'No status update available';
        
        // Check if targets are semicolon-separated
        if (strpos($target_text, ';') !== false) {
            // Split semicolon-separated targets and status descriptions
            $target_parts = array_map('trim', explode(';', $target_text));
            $status_parts = array_map('trim', explode(';', $status_description));
            
            $target_texts = [];
            $status_texts = [];
            
            foreach ($target_parts as $index => $target_part) {
                if (!empty($target_part)) {
                    $target_texts[] = $target_part;
                    $status_texts[] = isset($status_parts[$index]) ? $status_parts[$index] : 'No status update available';
                }
            }
            
            // Combine targets and statuses with newlines for bullet point separation
            $target = implode("\n", $target_texts);
            $status_text = implode("\n", $status_texts);
        } else {
            // Single target
            $target = $target_text;
            $status_text = $status_description;
        }
        
        // Normalize newlines in the old format as well
        $target = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $target);
        $status_text = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $status_text);
    }
      // Map status to color (green, yellow, red, grey)
    $status_color = 'grey'; // Default for not reported
      // Get the rating from content_json instead of the removed status column
    $rating = $content['rating'] ?? $content['status'] ?? 'not-reported';
    
    // Use the same color mapping as the rating_helpers.php and program admin
    switch ($rating) {
        case 'target-achieved':
        case 'completed':
            $status_color = 'green';
            break;
        case 'on-track':
        case 'on-track-yearly':
            $status_color = 'yellow';  // Fixed: on-track-yearly should be yellow, not green
            break;
        case 'delayed':
        case 'severe-delay':
            $status_color = 'red';
            break;
        case 'not-started':
        default:
            $status_color = 'grey';
    }
    // Calculate text complexity metrics to help with frontend layout decisions
    // Handle different types of newlines that might appear in the data 
    // Note: We do this normalization again to be absolutely sure we have consistent newlines
    $normalized_target = str_replace(['\n', '\r', '\r\n', '\\n', '\\r', '\\r\\n'], "\n", $target);
    $normalized_status = str_replace(['\n', '\r', '\r\n', '\\n', '\\r', '\\r\\n'], "\n", $status_text);
    
    // Split by normalized newlines
    $target_lines = explode("\n", $normalized_target);
    $status_lines = explode("\n", $normalized_status);
    
    // Filter out empty lines (which could create phantom bullets)
    $target_lines = array_filter($target_lines, function($line) {
        return trim($line) !== '';
    });
    
    $status_lines = array_filter($status_lines, function($line) {
        return trim($line) !== '';
    });
    
    // Calculate average and max characters per line to better estimate space needs
    $target_chars = array_map('strlen', $target_lines);
    $status_chars = array_map('strlen', $status_lines);
      // Calculate wrapping metrics based on actual column widths
    $target_col_chars_per_line = 33; // Target column can fit 33 chars before wrapping (verified)
    $status_col_chars_per_line = 72; // Status column can fit ~72 chars before wrapping
    
    // Calculate how many wrapped lines would be needed for each bullet
    $target_wrapped_lines = 0;
    foreach ($target_chars as $char_count) {
        $target_wrapped_lines += max(1, ceil($char_count / $target_col_chars_per_line));
    }
    
    $status_wrapped_lines = 0;
    foreach ($status_chars as $char_count) {
        $status_wrapped_lines += max(1, ceil($char_count / $status_col_chars_per_line));
    }
    
    $text_metrics = [
        'target_bullet_count' => count($target_lines),
        'target_max_chars' => !empty($target_chars) ? max($target_chars) : 0,
        'target_total_chars' => array_sum($target_chars),
        'target_wrapped_lines' => $target_wrapped_lines, // New metric for more accurate height calculation
        'status_bullet_count' => count($status_lines),
        'status_max_chars' => !empty($status_chars) ? max($status_chars) : 0,
        'status_total_chars' => array_sum($status_chars),
        'status_wrapped_lines' => $status_wrapped_lines, // New metric for more accurate height calculation
        'name_length' => strlen($program['program_name'])
    ];
      $programs[] = [
        'name' => $program['program_name'],
        'target' => $target,
        'rating' => $status_color,
        'rating_value' => $rating, // Include the actual rating value for debugging
        'status' => $status_text,
        'text_metrics' => $text_metrics
    ];
    error_log('Program name in API response: ' . $program['program_name']);
}

// --- 3. Get Monthly Labels ---
$monthly_labels = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
$current_year = $period['year'];
$previous_year = $current_year - 1;
$year_before_previous = $current_year - 2;

// --- 4. Get Sector Outcomes from Database ---
// Fetch outcomes for this sector and period
$sector_outcomes = [];
$charts_data = [];

// Fetch outcomes_details data for KPI sections
$outcomes_details = []; // This will hold the KPI data to be sent to the client

// Process selected KPI IDs if provided - KPI selection functionality is removed.
// The following block that processed $selected_kpi_ids_raw and fetched specific KPIs
// is now entirely commented out or removed, so $outcomes_details remains empty here.

/*
if (!empty($selected_kpi_ids_raw)) {
    $selected_kpi_ids = [];
    if (is_string($selected_kpi_ids_raw)) {
        $selected_kpi_ids = array_map('intval', explode(',', $selected_kpi_ids_raw));
    } elseif (is_array($selected_kpi_ids_raw)) {
        $selected_kpi_ids = array_map('intval', $selected_kpi_ids_raw);
    }

    $selected_kpi_ids = array_filter($selected_kpi_ids, function($id) {
        return $id > 0;
    });

    if (!empty($selected_kpi_ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($selected_kpi_ids), '?'));
            $order_fields = implode(',', $selected_kpi_ids);              $kpi_query = "SELECT detail_id, detail_name, detail_json FROM outcomes_details 
                          WHERE is_draft = 0 AND detail_id IN ($placeholders)
                          ORDER BY FIELD(detail_id, $order_fields)";
            
            $stmt_kpi = $conn->prepare($kpi_query);
            if (!$stmt_kpi) {
                throw new Exception("Database query preparation failed: " . $conn->error);
            }
            
            $types = str_repeat('i', count($selected_kpi_ids));
            $bind_params = array_merge([$types], $selected_kpi_ids);
            
            $ref_params = [];
            foreach ($bind_params as $key => $value) {
                $ref_params[$key] = &$bind_params[$key];
            }
            call_user_func_array([$stmt_kpi, 'bind_param'], $ref_params);

            $stmt_kpi->execute();
            $result_kpi = $stmt_kpi->get_result();
              while ($row = $result_kpi->fetch_assoc()) {
                $outcomes_details[] = [
                    'id' => $row['detail_id'],
                    'name' => $row['detail_name'],
                    'detail_json' => $row['detail_json']
                ];
            }
            $stmt_kpi->close();
        } catch (Exception $e) {
            ob_end_clean();
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }
}
*/

// Fallback to old logic if no KPIs were selected or fetched (Now default behavior as $outcomes_details is empty)
if (empty($outcomes_details)) {// First, try to find a "TPA Protection" metric specifically
    $tpa_query = "SELECT detail_id, detail_name, detail_json FROM outcomes_details 
                  WHERE is_draft = 0 
                  AND (detail_name LIKE '%TPA%' OR detail_name LIKE '%Protection%' OR detail_name LIKE '%Biodiversity%') 
                  ORDER BY created_at DESC LIMIT 1";
    $tpa_result = $conn->query($tpa_query);    // Then get other metrics
    $other_metrics_query = "SELECT detail_id, detail_name, detail_json FROM outcomes_details 
                              WHERE is_draft = 0 
                              AND (detail_name NOT LIKE '%TPA%' AND detail_name NOT LIKE '%Protection%' AND detail_name NOT LIKE '%Biodiversity%')
                              ORDER BY created_at DESC LIMIT 2";
    $other_metrics_result = $conn->query($other_metrics_query);    if ($tpa_result && $tpa_result->num_rows > 0) {
        $row = $tpa_result->fetch_assoc();
        $outcomes_details[] = [
            'id' => $row['detail_id'],
            'name' => $row['detail_name'],
            'detail_json' => $row['detail_json']
        ];
    }    if ($other_metrics_result && $other_metrics_result->num_rows > 0) {
        while ($row = $other_metrics_result->fetch_assoc()) {
            // Ensure we don't add more than 3 KPIs in total for fallback
            if (count($outcomes_details) < 3) {
                $outcomes_details[] = [
                    'id' => $row['detail_id'],
                    'name' => $row['detail_name'],
                    'detail_json' => $row['detail_json']
                ];
            }
        }
    }
}

// Initialize degraded area data for 2022, 2023, 2024
$degraded_area_data = [
    '2022' => array_fill(0, 12, 0),
    '2023' => array_fill(0, 12, 0),
    '2024' => array_fill(0, 12, 0)
];
$degraded_area_units = '';

// Query to find Total Degraded Area records
$degraded_area_query = "SELECT m.data_json, m.table_name 
                        FROM sector_outcomes_data m 
                        WHERE m.sector_id = ? 
                        AND m.is_draft = 0 
                        AND m.table_name = 'TOTAL DEGRADED AREA'
                        ORDER BY m.updated_at DESC LIMIT 1"; // Assuming one relevant record

$stmt_degraded = $conn->prepare($degraded_area_query);
if ($stmt_degraded) {
    $stmt_degraded->bind_param("i", $sector_id);
    $stmt_degraded->execute();
    $degraded_result = $stmt_degraded->get_result();

    if ($degraded_result->num_rows > 0) {
        $row_degraded = $degraded_result->fetch_assoc();
        $data_json_degraded = json_decode($row_degraded['data_json'], true);

        if (isset($data_json_degraded['data']) && isset($data_json_degraded['columns'])) {
            $year_columns = $data_json_degraded['columns'];
            $monthly_data_rows = $data_json_degraded['data'];

            foreach ($monthly_labels as $month_index => $month_name_short) {
                // Find the full month name key used in data_json (e.g., 'January', 'February')
                $full_month_name = '';
                foreach (array_keys($monthly_data_rows) as $json_month_key) {
                    if (strtoupper(substr($json_month_key, 0, 3)) === $month_name_short) {
                        $full_month_name = $json_month_key;
                        break;
                    }
                }

                if ($full_month_name && isset($monthly_data_rows[$full_month_name])) {
                    $month_values = $monthly_data_rows[$full_month_name];
                    foreach (['2022', '2023', '2024'] as $year) {
                        if (in_array($year, $year_columns) && isset($month_values[$year]) && is_numeric($month_values[$year])) {
                            $degraded_area_data[$year][$month_index] = floatval($month_values[$year]);
                        }
                    }
                }
            }
            // Extract units. Assuming units are consistent or pick one if varied by year.
            if(isset($data_json_degraded['units'])) {
                if(is_array($data_json_degraded['units'])) {
                    // If units is an array, try to get for a specific year or the first one
                    $degraded_area_units = $data_json_degraded['units']['2022'] ?? $data_json_degraded['units'][array_key_first($data_json_degraded['units'])] ?? 'Ha';
                } else {
                    $degraded_area_units = $data_json_degraded['units'];
                }
            }
        }
    }
    $stmt_degraded->close();
} else {
    // Handle error in preparing statement if necessary
    error_log("Failed to prepare statement for degraded area data: " . $conn->error);
}

// Initialize timber export data for current year and previous year (instead of hardcoded 2022/2023)
$timber_export_data = [
    $current_year => array_fill(0, 12, 0), // Initialize with zeros for each month of current year
    $previous_year => array_fill(0, 12, 0)  // Initialize with zeros for each month of previous year
];

// Query to find Timber Export Value records
$timber_query = "SELECT m.data_json, m.table_name 
                FROM sector_outcomes_data m 
                WHERE m.sector_id = ? 
                AND m.is_draft = 0 
                AND (m.table_name LIKE '%Timber Export%' OR m.table_name LIKE '%Export Value%')
                ORDER BY m.updated_at DESC";
                
$stmt = $conn->prepare($timber_query);
$stmt->bind_param("i", $sector_id);
$stmt->execute();
$timber_result = $stmt->get_result();

if ($timber_result->num_rows > 0) {
    // Process timber export metrics data
    while ($row = $timber_result->fetch_assoc()) {
        $data = json_decode($row['data_json'], true);
        
        // Check if we have the structure with years as columns and months as rows
        if (isset($data['columns']) && isset($data['data'])) {
            // Try to find current year and previous year data
            $current_year_str = (string)$current_year;
            $previous_year_str = (string)$previous_year;
            
            // Check if the data follows the format where years are columns
            if (in_array($current_year_str, $data['columns']) || in_array($previous_year_str, $data['columns'])) {
                // Direct year-based structure with months as keys
                foreach ($data['data'] as $month => $values) {
                    // Get month index (0-based)
                    $month_index = array_search(strtoupper(substr($month, 0, 3)), array_map('strtoupper', $monthly_labels));
                    if ($month_index !== false) {
                        // Store values for current year if available
                        if (isset($values[$current_year_str]) && is_numeric($values[$current_year_str])) {
                            $timber_export_data[$current_year][$month_index] = floatval($values[$current_year_str]);
                        }
                        // Store values for previous year if available
                        if (isset($values[$previous_year_str]) && is_numeric($values[$previous_year_str])) {
                            $timber_export_data[$previous_year][$month_index] = floatval($values[$previous_year_str]);
                        }
                    }
                }
                // We found the data, no need to look further
                break;
            } else {
                // Try the alternative structure where column names contain "timber export"
                foreach ($data['columns'] as $column) {
                    if (stripos($column, 'timber export') !== false || stripos($column, 'export value') !== false) {
                        // This column contains timber export data
                        foreach ($data['data'] as $month => $values) {
                            if (isset($values[$column]) && is_numeric($values[$column])) {
                                // Get month index (0-based)
                                $month_index = array_search(strtoupper(substr($month, 0, 3)), array_map('strtoupper', $monthly_labels));
                                if ($month_index !== false) {
                                    // Check if year is specified in the data
                                    if (isset($data['year'])) {
                                        $year = (int)$data['year'];
                                        if ($year == $current_year) {
                                            $timber_export_data[$current_year][$month_index] = floatval($values[$column]);
                                        } else if ($year == $previous_year) {
                                            $timber_export_data[$previous_year][$month_index] = floatval($values[$column]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// Now query for other metrics data
$metrics_query = "SELECT * FROM sector_outcomes_data 
                  WHERE sector_id = ? AND period_id = ? AND is_draft = 0";
$stmt = $conn->prepare($metrics_query);
$stmt->bind_param("ii", $sector_id, $period_id);
$stmt->execute();
$metrics_result = $stmt->get_result();

// Prepare the main chart data with the timber export values we found
$main_chart_data = [
    'labels' => $monthly_labels,
    'data' . $previous_year => $timber_export_data[$previous_year],
    'data' . $current_year => $timber_export_data[$current_year],
    'total' . $previous_year => array_sum($timber_export_data[$previous_year]),
    'total' . $current_year => array_sum($timber_export_data[$current_year])
];

// Add degraded area chart data
$degraded_area_chart_data_prepared = [
    'labels' => $monthly_labels,
    'data2022' => $degraded_area_data['2022'],
    'data2023' => $degraded_area_data['2023'],
    'data2024' => $degraded_area_data['2024'],
    'total2022' => array_sum($degraded_area_data['2022']),
    'total2023' => array_sum($degraded_area_data['2023']),
    'total2024' => array_sum($degraded_area_data['2024']),
    'units' => $degraded_area_units ?: 'Ha' // Default to 'Ha' if not found
];

// Default secondary chart data - still using placeholder data
$secondary_chart_data = [
    'labels' => $monthly_labels,
    'data' . $year_before_previous => array_fill(0, 12, 0),
    'data' . $previous_year => array_fill(0, 12, 0),
    'data' . $current_year => array_fill(0, 12, 0),
    'total' . $current_year => "0"
];

if ($metrics_result->num_rows > 0) {
    // Process each metric
    while ($metric = $metrics_result->fetch_assoc()) {
        $data = json_decode($metric['data_json'], true);
        
        // Determine if it's a chart or a KPI based on the data structure
        if (isset($data['type'])) {
            if ($data['type'] === 'chart') {
                $charts_data[$data['key']] = $data;
            }
        }
    }
}

// Set the chart titles and values based on the sector
switch ($sector_id) {
    case 1: // Forestry
        $main_chart_title = "Timber Export Value (RM)";
        $secondary_chart_title = "Total Degraded Area Restored (Ha)";
        break;
            
    case 2: // Land
        $main_chart_title = "Land Development (Ha)";
        $secondary_chart_title = "Land Title Applications Processed";
        break;
            
    case 3: // Environment
        $main_chart_title = "Air Quality Index";
        $secondary_chart_title = "Waste Management (Tons)";
        break;
            
    case 4: // Natural Resources
        $main_chart_title = "Resource Extraction (Units)";
        $secondary_chart_title = "Sustainable Resource Management (%)";
        break;
            
    case 5: // Urban Development
        $main_chart_title = "Urban Growth (km²)";
        $secondary_chart_title = "Housing Development (Units)";
        break;
}

// Store default charts in the arrays
$charts_data['main_chart'] = [
    'type' => 'chart',
    'key' => 'main_chart',
    'title' => $main_chart_title,
    'data' => $main_chart_data
];

$charts_data['degraded_area_chart'] = [
    'type' => 'chart',
    'key' => 'degraded_area_chart',
    'title' => 'Total Degraded Area (' . ($degraded_area_units ?: 'Ha') . ')',
    'data' => $degraded_area_chart_data_prepared
];

$charts_data['secondary_chart'] = [
    'type' => 'chart',
    'key' => 'secondary_chart',
];

// --- 5. Generate Draft Date ---
$draft_date = 'DRAFT ' . date('j M Y');

// --- Assemble final data structure ---
$report_data = [
    'reportTitle' => strtoupper($sector['sector_name']),
    'sectorLeads' => $sector_leads,
    'quarter' => $quarter,
    'projects' => $programs,
    'programs' => $programs, // Add programs with the correct key name that the frontend expects
    'charts' => $charts_data,    'draftDate' => $draft_date,
    'sector_id' => $sector_id,  // Include sector_id for client-side use
    'outcomes_details' => $outcomes_details,  // This is the primary source for KPIs now
];

// Remove any undefined variables reference that could cause PHP warnings
// Clear all previous output
ob_end_clean();

// Set content type header and output JSON
header('Content-Type: application/json');
echo json_encode($report_data, JSON_PRETTY_PRINT);
exit;
?>