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
$selected_targets_raw = isset($_GET['selected_targets']) ? $_GET['selected_targets'] : null;

// Handle half-yearly period logic
$period_ids = [$period_id];

// Get period details to check if it's half-yearly
$period_query = "SELECT period_id, quarter, year FROM reporting_periods WHERE period_id = ?";
$stmt = $conn->prepare($period_query);
$stmt->bind_param("i", $period_id);
$stmt->execute();
$period_result = $stmt->get_result();
$period_data = $period_result->fetch_assoc();

// Check if this is a half-yearly period based on quarter value
if ($period_data && isset($period_data['quarter'])) {
    $quarter = (int)$period_data['quarter'];
    $year = $period_data['year'];
    
    if ($quarter == 5) { // Half Yearly 1 includes Q1 and Q2
        // Find all Q1 and Q2 periods for the same year
        $q1q2_query = "SELECT period_id FROM reporting_periods WHERE year = ? AND quarter IN (1, 2)";
        $stmt = $conn->prepare($q1q2_query);
        $stmt->bind_param("i", $year);
        $stmt->execute();
        $q1q2_result = $stmt->get_result();
        
        $period_ids = [$period_id]; // Always include the original period
        while ($row = $q1q2_result->fetch_assoc()) {
            $period_ids[] = $row['period_id'];
        }
        
        error_log("Half Yearly 1 ($year) selected: Including period_ids " . implode(", ", $period_ids));
    } elseif ($quarter == 6) { // Half Yearly 2 includes Q3 and Q4
        // Find all Q3 and Q4 periods for the same year
        $q3q4_query = "SELECT period_id FROM reporting_periods WHERE year = ? AND quarter IN (3, 4)";
        $stmt = $conn->prepare($q3q4_query);
        $stmt->bind_param("i", $year);
        $stmt->execute();
        $q3q4_result = $stmt->get_result();
        
        $period_ids = [$period_id]; // Always include the original period
        while ($row = $q3q4_result->fetch_assoc()) {
            $period_ids[] = $row['period_id'];
        }
        
        error_log("Half Yearly 2 ($year) selected: Including period_ids " . implode(", ", $period_ids));
    }
}

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

// Parse selected targets if provided
$selected_targets = [];
if ($selected_targets_raw) {
    try {
        $selected_targets = json_decode($selected_targets_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Error parsing selected_targets JSON: " . json_last_error_msg());
            $selected_targets = [];
        }
    } catch (Exception $e) {
        error_log("Exception parsing selected_targets: " . $e->getMessage());
        $selected_targets = [];
    }
}

// Add debug logging for parameters
$period_ids_str = implode(',', $period_ids);
error_log("API parameters - period_ids: {$period_ids_str}, sector_id: {$sector_id}");
error_log("Fixed duplicate submission query - using MAX(submission_id) for tie-breaking");
if (!empty($program_orders)) {
    error_log("Program orders provided: " . json_encode($program_orders));
}
if (!empty($selected_targets)) {
    error_log("Selected targets provided: " . json_encode($selected_targets));
}

// If no period_id provided, use current reporting period
if (!$period_id) {
    $current_period = get_current_reporting_period();
    $period_id = isset($current_period['period_id']) ? $current_period['period_id'] : null;
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

// Format the quarter/year string (e.g., "Q4 2024", "Half Yearly 1 2025")
$quarter = get_period_display_name($period);

// --- 1. Get Sector Leads (agencies with this sector, including focal agencies) ---
$sector_leads_query = "SELECT GROUP_CONCAT(agency_name SEPARATOR '; ') as sector_leads 
                      FROM users 
                      WHERE sector_id = ? AND role IN ('agency', 'focal') AND is_active = 1";
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

// Create the period IN clause for the two places where period_id is used
$period_in_clause = implode(',', array_fill(0, count($period_ids), '?'));

// Prepare the parameters for the query
$program_params = array_merge($period_ids, $period_ids); // period_ids appear twice in subquery
$program_params[] = $sector_id; // Add sector_id 
$program_param_types = str_repeat('i', count($period_ids) * 2) . 'i'; // period_ids (twice) + sector_id

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
        
        // Reset params and add period_ids twice (for both IN clauses in the subquery), then all program IDs
        $program_params = array_merge($period_ids, $period_ids); // period_ids appear twice in subquery
        $program_param_types = str_repeat('i', count($period_ids) * 2);
        
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
                    GROUP_CONCAT(ps.content_json ORDER BY rp.quarter ASC SEPARATOR '|||') as all_content_json,
                    GROUP_CONCAT(CONCAT(rp.quarter, ':', ps.period_id) ORDER BY rp.quarter ASC SEPARATOR ',') as period_info,
                    i.initiative_id, i.initiative_name
                  FROM programs p
                  LEFT JOIN (
                      SELECT ps1.*
                      FROM program_submissions ps1
                      INNER JOIN (
                          SELECT program_id, period_id, MAX(submission_date) as latest_date, MAX(submission_id) as latest_submission_id
                          FROM program_submissions
                          WHERE period_id IN ($period_in_clause) AND is_draft = 0
                          GROUP BY program_id, period_id
                      ) ps2 ON ps1.program_id = ps2.program_id 
                           AND ps1.period_id = ps2.period_id
                           AND ps1.submission_date = ps2.latest_date 
                           AND ps1.submission_id = ps2.latest_submission_id
                      WHERE ps1.period_id IN ($period_in_clause) AND ps1.is_draft = 0
                  ) ps ON p.program_id = ps.program_id
                  LEFT JOIN reporting_periods rp ON ps.period_id = rp.period_id
                  LEFT JOIN initiatives i ON p.initiative_id = i.initiative_id
                  WHERE $program_filter_condition
                  GROUP BY p.program_id, p.program_name, i.initiative_id, i.initiative_name
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
while ($program = $programs_result->fetch_assoc()) {
    // Handle aggregated content from multiple periods
    $all_content_jsons = $program['all_content_json'] ? explode('|||', $program['all_content_json']) : [];
    $period_info = $program['period_info'] ? explode(',', $program['period_info']) : [];
    
    // Initialize arrays to collect targets and statuses from all periods
    $all_target_texts = [];
    $all_status_texts = [];
    $latest_rating = 'not-reported'; // Default rating
    
    // Process each period's content
    foreach ($all_content_jsons as $index => $content_json) {
        if (empty(trim($content_json))) continue;
        
        $content = json_decode($content_json, true);
        if (!$content) continue;
        
        // Extract period info for logging
        $period_quarter = isset($period_info[$index]) ? explode(':', $period_info[$index])[0] : 'unknown';
        
        // Update rating to the latest one (from last period processed)
        if (isset($content['rating'])) {
            $latest_rating = $content['rating'];
        } elseif (isset($content['status'])) {
            $latest_rating = $content['status'];
        }
        
        // Process targets from this period
        if (isset($content['targets']) && is_array($content['targets']) && !empty($content['targets'])) {
            // New format with targets array
            foreach ($content['targets'] as $target_index => $t) {
                $target_text = isset($t['target_text']) ? $t['target_text'] : (isset($t['text']) ? $t['text'] : 'No target set');
                $status_desc = isset($t['status_description']) ? $t['status_description'] : 'No status update available';
                
                // Calculate target_id using the same logic as get_program_targets.php
                // This is a sequential counter across all periods for this program
                $target_id = count($all_target_texts) + 1;
                
                // Check if target filtering is enabled and if this target is selected
                $program_id_str = strval($program['program_id']);
                $should_include_target = true;
                
                if (!empty($selected_targets) && isset($selected_targets[$program_id_str])) {
                    $selected_target_ids = array_map('intval', $selected_targets[$program_id_str]);
                    $should_include_target = in_array($target_id, $selected_target_ids);
                }
                
                if ($should_include_target) {
                    // Clean up newlines
                    $target_text = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $target_text);
                    $status_desc = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $status_desc);
                    
                    $all_target_texts[] = $target_text;
                    $all_status_texts[] = $status_desc;
                }
            }
        } elseif (isset($content['target'])) {
            // Old format with direct target property
            $target_text = isset($content['target']) ? $content['target'] : 'No target set';
            $status_description = isset($content['status_text']) ? $content['status_text'] : 'No status update available';
            
            // Check if targets are semicolon-separated
            if (strpos($target_text, ';') !== false) {
                $target_parts = array_map('trim', explode(';', $target_text));
                $status_parts = array_map('trim', explode(';', $status_description));
                
                foreach ($target_parts as $idx => $target_part) {
                    if (!empty($target_part)) {
                        // Calculate target_id for this target part
                        $target_id = count($all_target_texts) + 1;
                        
                        // Check if target filtering is enabled and if this target is selected
                        $program_id_str = strval($program['program_id']);
                        $should_include_target = true;
                        
                        if (!empty($selected_targets) && isset($selected_targets[$program_id_str])) {
                            $selected_target_ids = array_map('intval', $selected_targets[$program_id_str]);
                            $should_include_target = in_array($target_id, $selected_target_ids);
                        }
                        
                        if ($should_include_target) {
                            $all_target_texts[] = $target_part;
                            $all_status_texts[] = isset($status_parts[$idx]) ? $status_parts[$idx] : 'No status update available';
                        }
                    }
                }
            } else {
                // Single target
                $target_id = count($all_target_texts) + 1;
                
                // Check if target filtering is enabled and if this target is selected
                $program_id_str = strval($program['program_id']);
                $should_include_target = true;
                
                if (!empty($selected_targets) && isset($selected_targets[$program_id_str])) {
                    $selected_target_ids = array_map('intval', $selected_targets[$program_id_str]);
                    $should_include_target = in_array($target_id, $selected_target_ids);
                }
                
                if ($should_include_target) {
                    $target_text = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $target_text);
                    $status_description = str_replace(['\\n', '\\r', '\\r\\n'], "\n", $status_description);
                    
                    $all_target_texts[] = $target_text;
                    $all_status_texts[] = $status_description;
                }
            }
        }
        
        error_log("Program {$program['program_id']}: Processed Q{$period_quarter} with " . 
                 (isset($content['targets']) ? count($content['targets']) : '1') . " targets");
    }
    
    // Combine all targets and statuses from all periods
    $target = !empty($all_target_texts) ? implode("\n", $all_target_texts) : 'No target set';
    $status_text = !empty($all_status_texts) ? implode("\n", $all_status_texts) : 'No status update available';
    
    // Final cleanup of newlines
    $target = preg_replace('/\\\\[rn]/', "\n", $target);
    $status_text = preg_replace('/\\\\[rn]/', "\n", $status_text);
    
    // Map status to color (green, yellow, red, grey)
    $status_color = 'grey'; // Default for not reported
    
    // Get the rating from the latest period's content
    $rating = $latest_rating;
    
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
    ];      $programs[] = [
        'name' => $program['program_name'],
        'target' => $target,
        'rating' => $status_color,
        'rating_value' => $rating, // Include the actual rating value for debugging
        'status' => $status_text,
        'text_metrics' => $text_metrics,
        'initiative_id' => $program['initiative_id'],
        'initiative_name' => isset($program['initiative_name']) ? $program['initiative_name'] : 'No Initiative'
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

// Calculate the latest 3 years dynamically
$degraded_area_years = [
    strval($year_before_previous), // 2023
    strval($previous_year),        // 2024
    strval($current_year)          // 2025
];

// Initialize degraded area data for the latest 3 years dynamically
$degraded_area_data = [];
foreach ($degraded_area_years as $year) {
    $degraded_area_data[$year] = array_fill(0, 12, 0);
}
$degraded_area_units = '';

// Query to find Total Degraded Area records
$degraded_area_query = "SELECT m.data_json, m.table_name, m.row_config, m.column_config
                        FROM sector_outcomes_data m 
                        WHERE m.sector_id = ? 
                        AND m.table_name = 'TOTAL DEGRADED AREA'
                        AND m.is_draft = 0
                        ORDER BY m.updated_at DESC LIMIT 1"; // Assuming one relevant record

$stmt_degraded = $conn->prepare($degraded_area_query);
if ($stmt_degraded) {
    $stmt_degraded->bind_param("i", $sector_id);
    $stmt_degraded->execute();
    $degraded_result = $stmt_degraded->get_result();

    if ($degraded_result->num_rows > 0) {
        $row_degraded = $degraded_result->fetch_assoc();
        $data_json_degraded = json_decode($row_degraded['data_json'], true);
        $row_config = json_decode($row_degraded['row_config'], true);
        $column_config = json_decode($row_degraded['column_config'], true);

        // Check the actual data structure to determine the correct parsing approach
        if (isset($data_json_degraded['data']) && isset($data_json_degraded['columns'])) {
            // Standard format: {columns: [years], data: {month: {year: value}}}
            $year_columns = $data_json_degraded['columns'];
            $monthly_data_rows = $data_json_degraded['data'];
        } else {
            // No valid data structure found
            $year_columns = [];
            $monthly_data_rows = [];
        }

        if (!empty($year_columns) && !empty($monthly_data_rows)) {

            foreach ($monthly_labels as $month_index => $month_name_short) {
                // Find the full month name key used in data_json (e.g., 'January', 'February')
                $full_month_name = '';
                foreach (array_keys($monthly_data_rows) as $json_month_key) {
                    if (strtoupper(substr($json_month_key, 0, 3)) === $month_name_short) {
                        $full_month_name = $json_month_key;
                        break;
                    }
                }                if ($full_month_name && isset($monthly_data_rows[$full_month_name])) {
                    $month_values = $monthly_data_rows[$full_month_name];
                    foreach ($degraded_area_years as $year) {
                        // Standard format - use year as string key
                        if (in_array($year, $year_columns) && isset($month_values[$year]) && is_numeric($month_values[$year])) {
                            $degraded_area_data[$year][$month_index] = floatval($month_values[$year]);
                        }
                    }
                }
            }

            // Extract units - check column_config first, then data_json, then default
            $degraded_area_units = 'Ha'; // Default
            
            if (!empty($column_config) && isset($column_config['columns'])) {
                // Try to get units from column config
                foreach ($column_config['columns'] as $col) {
                    if (!empty($col['unit'])) {
                        $degraded_area_units = $col['unit'];
                        break;
                    }
                }
            } elseif (isset($data_json_degraded['units'])) {
                // Try to get units from data_json
                if (is_array($data_json_degraded['units'])) {
                    $current_year_str = (string)$current_year;
                    $previous_year_str = (string)$previous_year;
                    
                    if (isset($data_json_degraded['units'][$current_year_str])) {
                        $degraded_area_units = $data_json_degraded['units'][$current_year_str];
                    } elseif (isset($data_json_degraded['units'][$previous_year_str])) {
                        $degraded_area_units = $data_json_degraded['units'][$previous_year_str];
                    } elseif (!empty($data_json_degraded['units'])) {
                        // Get first key
                        $unit_keys = array_keys($data_json_degraded['units']);
                        $first_key = reset($unit_keys);
                        $degraded_area_units = $data_json_degraded['units'][$first_key];
                    } else {
                        $degraded_area_units = 'Ha';
                    }
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
$timber_query = "SELECT m.data_json, m.table_name, m.row_config, m.column_config
                FROM sector_outcomes_data m 
                WHERE m.sector_id = ? 
                AND m.table_name = 'TIMBER EXPORT VALUE'
                AND m.is_draft = 0
                ORDER BY m.updated_at DESC LIMIT 1";
                
$stmt = $conn->prepare($timber_query);
$stmt->bind_param("i", $sector_id);
$stmt->execute();
$timber_result = $stmt->get_result();

if ($timber_result->num_rows > 0) {
    // Process timber export metrics data
    while ($row = $timber_result->fetch_assoc()) {
        $data = json_decode($row['data_json'], true);
        $row_config = json_decode($row['row_config'], true);
        $column_config = json_decode($row['column_config'], true);
        
        $debug_info = array(
            'has_columns' => isset($data['columns']),
            'has_data' => isset($data['data'])
        );
        
        if (isset($data['columns'])) {
            $debug_info['columns'] = $data['columns'];
        } else {
            $debug_info['columns'] = null;
        }
        
        if (isset($data['data'])) {
            $debug_info['data_keys'] = array_keys($data['data']);
        } else {
            $debug_info['data_keys'] = null;
        }
        
        error_log("Processing TIMBER EXPORT data structure: " . json_encode($debug_info));
        
        // Process the standard format: {columns: [years], data: {month: {year: value}}}
        if (isset($data['columns']) && isset($data['data'])) {
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
                            error_log("Set timber data for {$current_year} month {$month_index}: " . $values[$current_year_str]);
                        }
                        // Store values for previous year if available
                        if (isset($values[$previous_year_str]) && is_numeric($values[$previous_year_str])) {
                            $timber_export_data[$previous_year][$month_index] = floatval($values[$previous_year_str]);
                            error_log("Set timber data for {$previous_year} month {$month_index}: " . $values[$previous_year_str]);
                        }
                    }
                }
                // We found the data, no need to look further
                break;
            }
        }
        
        // Alternative structure checks (keep existing logic as fallback)
        if (isset($data['columns']) && isset($data['data'])) {
            // Legacy format fallback
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
                    // Patch: Ensure $column is a string before using stripos
                    if (is_array($column)) {
                        // Convert array to string for search, or skip if not meaningful
                        $column_str = implode(' ', $column);
                    } else {
                        $column_str = $column;
                    }
                    if (stripos($column_str, 'timber export') !== false || stripos($column_str, 'export value') !== false) {
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

// Add degraded area chart data using dynamic years
$degraded_area_chart_data_prepared = [
    'labels' => $monthly_labels,
    'years' => $degraded_area_years, // Add years array for frontend reference
    'units' => $degraded_area_units ?: 'Ha' // Default to 'Ha' if not found
];

// Add data and totals for each year dynamically
foreach ($degraded_area_years as $year) {
    $degraded_area_chart_data_prepared['data' . $year] = $degraded_area_data[$year];
    $degraded_area_chart_data_prepared['total' . $year] = array_sum($degraded_area_data[$year]);
}

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

// Add a shutdown handler to always return JSON on fatal error
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Fatal error: ' . $error['message']]);
        exit;
    }
});
exit;
?>