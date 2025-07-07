<?php
/**
 * Admin Statistics Functions
 * 
 * Contains functions for retrieving statistics and data for admin dashboards
 */

require_once dirname(__DIR__) . '/utilities.php';
require_once 'core.php';

/**
 * Get dashboard statistics for admin overview
 * @return array Statistics for admin dashboard
 */
function get_admin_dashboard_stats() {
    global $conn;
    
    // Only admin should access this
    if (!is_admin()) {
        return ['error' => 'Permission denied'];
    }
    
    // Initialize stats array
    $stats = [
        'total_agencies' => 0,
        'total_programs' => 0,
        'submissions_complete' => 0,
        'submissions_pending' => 0,
        'program_status' => [],
        'sector_programs' => []
    ];
    
    // Get current period
    $current_period = get_current_reporting_period();
    $period_id = $current_period['period_id'] ?? null;
      // Get counts (including both regular agencies and focal agencies)
    $query = "SELECT 
                (SELECT COUNT(*) FROM users WHERE role IN ('agency', 'focal')) AS total_agencies,
                (SELECT COUNT(*) FROM programs) AS total_programs";
    
    $result = $conn->query($query);
    $counts = $result->fetch_assoc();
    
    $stats['total_agencies'] = $counts['total_agencies'];
    $stats['total_programs'] = $counts['total_programs'];
      // If we have an active period, get submission status
    if ($period_id) {
        // Get program submission counts
        $query = "SELECT 
                    u.user_id,
                    (SELECT COUNT(*) FROM programs p WHERE p.owner_agency_id = u.user_id) AS agency_programs,
                    (SELECT COUNT(*) FROM program_submissions ps 
                     JOIN programs p ON ps.program_id = p.program_id 
                     WHERE p.owner_agency_id = u.user_id AND ps.period_id = ?) AS submitted_programs
                  FROM users u
                  WHERE u.role IN ('agency', 'focal')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $completed = 0;
        $pending = 0;
        
        while ($row = $result->fetch_assoc()) {
            if ($row['agency_programs'] > 0) {
                if ($row['submitted_programs'] >= $row['agency_programs']) {
                    $completed++;
                } else {
                    $pending++;
                }
            }
        }
        
        $stats['submissions_complete'] = $completed;
        $stats['submissions_pending'] = $pending;
        
        // Get program status distribution
        $query = "SELECT ps.status, COUNT(*) as count
                  FROM program_submissions ps 
                  WHERE ps.period_id = ?
                  GROUP BY ps.status";
        
        // Removed status distribution query and processing as status column is deleted
        $stats['program_status'] = [
            'labels' => [],
            'data' => [],
            'backgroundColor' => []
        ];
    }
    
    // Get programs by sector
    $query = "SELECT s.sector_name, COUNT(p.program_id) as program_count
              FROM sectors s
              LEFT JOIN programs p ON s.sector_id = p.sector_id
              GROUP BY s.sector_id
              ORDER BY program_count DESC";
    
    $result = $conn->query($query);
    
    $sector_data = [
        'labels' => [],
        'data' => [],
        'backgroundColor' => [
            '#8591a4', // Primary color
            '#A49885', // Secondary color
            '#607b9b', // Variation of primary
            '#b3a996', // Variation of secondary
            '#4f616f'  // Another variation
        ]
    ];
    
    while ($row = $result->fetch_assoc()) {
        $sector_data['labels'][] = $row['sector_name'];
        $sector_data['data'][] = $row['program_count'];
    }
    
    $stats['sector_programs'] = $sector_data;
    
    return $stats;
}

/**
 * Get submission statistics for a specific reporting period
 * 
 * @param int $period_id - The ID of the reporting period
 * @return array - Array containing submission statistics
 */
function get_period_submission_stats($period_id) {
    global $conn;
    
    // Initialize stats array
    $stats = [
        'agencies_reported' => 0,
        'total_agencies' => 0,
        'on_track_programs' => 0,
        'delayed_programs' => 0,
        'total_programs' => 0,
        'completion_percentage' => 0
    ];
      // Get total agencies (including both regular agencies and focal agencies)
    $query = "SELECT COUNT(*) as total FROM users WHERE role IN ('agency', 'focal') AND is_active = 1";
    $result = $conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $stats['total_agencies'] = $row['total'];
    }
    
    // Get agencies that have reported
    $query = "SELECT COUNT(DISTINCT submitted_by) as reported 
              FROM program_submissions 
              WHERE period_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stats['agencies_reported'] = $row['reported'];
    }
      // Get program status counts - Fixed to count unique programs, not all submissions
    $query = "SELECT ps.is_draft, COUNT(DISTINCT ps.program_id) as count 
              FROM program_submissions ps 
              INNER JOIN (
                  SELECT program_id, MAX(submission_id) as max_submission_id
                  FROM program_submissions 
                  WHERE period_id = ?
                  GROUP BY program_id
              ) latest ON ps.program_id = latest.program_id AND ps.submission_id = latest.max_submission_id
              WHERE ps.period_id = ? 
              GROUP BY ps.is_draft";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $period_id, $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($result && $row = $result->fetch_assoc()) {
        if ($row['is_draft'] == 0) {
            // final submissions - count unique programs with finalized submissions
            $stats['on_track_programs'] += $row['count'];
        } else {
            // draft submissions - count unique programs with only draft submissions
            $stats['delayed_programs'] += $row['count'];
        }
    }
    
    // Get total programs
    $query = "SELECT COUNT(*) as total FROM programs";
    $result = $conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $stats['total_programs'] = $row['total'];
    }
    
    // Calculate completion percentage
    if ($stats['total_agencies'] > 0) {
        $stats['completion_percentage'] = round(($stats['agencies_reported'] / $stats['total_agencies']) * 100);
    }
    
    return $stats;
}

/**
 * Get all programs with detailed information for admin display
 * 
 * @param int $period_id Optional period ID to filter submissions
 * @param array $filters Optional filters (status, sector_id, agency_id, search)
 * @return array List of all programs with their details and status
 */
function get_admin_programs_list($period_id = null, $filters = []) {
    global $conn;

    // Handle cases where period_id is null
    if (!$period_id) {
        // Get current or latest reporting period
        $period_query = "SELECT period_id FROM reporting_periods WHERE status = 'active' ORDER BY end_date DESC LIMIT 1";
        $period_result = $conn->query($period_query);
        if ($period_result && $period_result->num_rows > 0) {
            $period_row = $period_result->fetch_assoc();
            $period_id = $period_row['period_id'];
        } else {
            // If no active period, get the latest period
            $period_query = "SELECT period_id FROM reporting_periods ORDER BY year DESC, quarter DESC LIMIT 1";
            $period_result = $conn->query($period_query);
            if ($period_result && $period_result->num_rows > 0) {
                $period_row = $period_result->fetch_assoc();
                $period_id = $period_row['period_id'];
            }
        }
    }

    // Construct the main query
    $sql = "SELECT 
                p.program_id, p.program_name, p.program_number, p.owner_agency_id, p.sector_id, p.created_at, p.is_assigned,
                p.initiative_id, i.initiative_name, i.initiative_number,
                s.sector_name, 
                u.agency_name,
                latest_sub.submission_id, latest_sub.is_draft, latest_sub.submission_date, latest_sub.updated_at, 
                latest_sub.period_id AS submission_period_id,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(latest_sub.content_json, '$.rating')), 'not-started') as rating
            FROM programs p
            JOIN sectors s ON p.sector_id = s.sector_id
            JOIN users u ON p.owner_agency_id = u.user_id
            LEFT JOIN initiatives i ON p.initiative_id = i.initiative_id
            LEFT JOIN (
                SELECT ps1.*
                FROM program_submissions ps1
                INNER JOIN (
                    SELECT program_id, MAX(submission_id) as max_submission_id
                    FROM program_submissions";
    
    // Add period filter to subquery if period_id is available
    if ($period_id) {
        $sql .= " WHERE period_id = ?";
    }
    
    $sql .= "        GROUP BY program_id
                ) ps2 ON ps1.program_id = ps2.program_id AND ps1.submission_id = ps2.max_submission_id
            ) latest_sub ON p.program_id = latest_sub.program_id";

    // Initialize parameters
    $params = [];
    $param_types = '';
    
    // Add period_id parameter if it exists
    if ($period_id) {
        $params[] = $period_id;
        $param_types .= 'i';
    }

    // Build WHERE clauses
    $where_clauses = [];
    
    // Apply filters
    if (isset($filters['status']) && $filters['status'] !== 'all' && $filters['status'] !== '') {
        $where_clauses[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(latest_sub.content_json, '$.rating')), 'not-started') = ?";
        $params[] = $filters['status'];
        $param_types .= "s";
    }
    
    if (isset($filters['sector_id']) && $filters['sector_id'] !== 'all' && $filters['sector_id'] !== 0 && $filters['sector_id'] !== '') {
        $where_clauses[] = "p.sector_id = ?";
        $params[] = $filters['sector_id'];
        $param_types .= "i";
    }
    
    if (isset($filters['agency_id']) && $filters['agency_id'] !== 'all' && $filters['agency_id'] !== 0 && $filters['agency_id'] !== '') {
        $where_clauses[] = "p.owner_agency_id = ?";
        $params[] = $filters['agency_id'];
        $param_types .= "i";
    }
    
    if (isset($filters['search']) && !empty($filters['search'])) {
        $search_term = '%' . $filters['search'] . '%';
        $where_clauses[] = "(p.program_name LIKE ?)";
        $params[] = $search_term;
        $param_types .= "s";
    }

    // Add is_assigned filter
    if (isset($filters['is_assigned'])) {
        $where_clauses[] = "p.is_assigned = ?";
        $params[] = $filters['is_assigned'] ? 1 : 0;
        $param_types .= "i";
    }
    
    // Add WHERE clause if we have conditions
    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    // ORDER BY clause
    $order_by_column = $filters['sort_by'] ?? 'p.program_name';
    $order_by_direction = $filters['sort_order'] ?? 'ASC';
    $sql .= " ORDER BY $order_by_column $order_by_direction";

    // LIMIT and OFFSET for pagination
    if (isset($filters['limit'])) {
        $sql .= " LIMIT ?";
        $params[] = $filters['limit'];
        $param_types .= 'i';
        if (isset($filters['offset'])) {
            $sql .= " OFFSET ?";
            $params[] = $filters['offset'];
            $param_types .= 'i';
        }
    }

    // Prepare and execute query
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $programs = [];
    while ($row = $result->fetch_assoc()) {
        $programs[] = $row;
    }
    
    return $programs;
}

/**
 * Get sector data for a specific reporting period
 *
 * @param int $period_id The reporting period ID
 * @return array Sector data including name, agency count, program count, and submission percentage
 */
function get_sector_data_for_period($period_id) {
    global $conn;
    
    $sector_data = array();
      $sql = "SELECT 
                s.sector_id,
                s.sector_name,
                COUNT(DISTINCT u.user_id) as agency_count,
                COUNT(DISTINCT p.program_id) as program_count,
                IFNULL(ROUND((COUNT(DISTINCT CASE WHEN ps.submission_id IS NOT NULL THEN ps.program_id END) / 
                    NULLIF(COUNT(DISTINCT p.program_id), 0)) * 100, 0), 0) as submission_pct            FROM 
                sectors s
                LEFT JOIN users u ON s.sector_id = u.sector_id AND u.role IN ('agency', 'focal')
                LEFT JOIN programs p ON u.user_id = p.owner_agency_id
                LEFT JOIN (
                    SELECT ps1.program_id, ps1.submission_id
                    FROM program_submissions ps1
                    INNER JOIN (
                        SELECT program_id, MAX(submission_id) as max_submission_id
                        FROM program_submissions 
                        WHERE period_id = ?
                        GROUP BY program_id
                    ) latest ON ps1.program_id = latest.program_id AND ps1.submission_id = latest.max_submission_id
                    WHERE ps1.period_id = ?
                ) ps ON p.program_id = ps.program_id
            GROUP BY 
                s.sector_id, s.sector_name
            ORDER BY 
                s.sector_name ASC";                
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $period_id, $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $sector_data[] = $row;
    }
    
    return $sector_data;
}

/**
 * Get recent submissions for the specified period
 * 
 * @param int|null $period_id The reporting period ID
 * @param int $limit Maximum number of submissions to return
 * @return array List of recent submissions
 */
function get_recent_submissions($period_id = null, $limit = 5) {
    global $conn;
    
    $query = "SELECT ps.*, 
              u.agency_name, 
              p.program_name 
              FROM program_submissions ps
              JOIN users u ON ps.submitted_by = u.user_id
              JOIN programs p ON ps.program_id = p.program_id
              WHERE 1=1";
    
    if ($period_id) {
        $query .= " AND ps.period_id = ?";
        $params = [$period_id];
    } else {
        $params = [];
    }
    
    $query .= " ORDER BY ps.submission_date DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return [];
    }
    
    if (!empty($params)) {
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $submissions = [];
    
    while ($row = $result->fetch_assoc()) {
        // Remove status field from each submission as status column is deleted
        if (isset($row['status'])) {
            unset($row['status']);
        }
        $submissions[] = $row;
    }
    
    return $submissions;
}

/**
 * Get all sectors
 * 
 * @return array List of all sectors
 */
function get_all_sectors() {
    global $conn;
    
    $query = "SELECT sector_id, sector_name FROM sectors ORDER BY sector_name";
    $result = $conn->query($query);
    
    $sectors = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $sectors[] = $row;
        }
    }
    
    return $sectors;
}

/**
 * Get detailed information about a specific program for admin view
 * 
 * @param int $program_id The ID of the program to retrieve
 * @return array|false Program details array or false if not found
 */
function get_admin_program_details($program_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT p.*, s.sector_name, u.agency_name, u.user_id as owner_agency_id,
                                  i.initiative_id, i.initiative_name, i.initiative_number, 
                                  i.initiative_description, i.start_date as initiative_start_date, 
                                  i.end_date as initiative_end_date
                          FROM programs p
                          LEFT JOIN sectors s ON p.sector_id = s.sector_id
                          LEFT JOIN users u ON p.owner_agency_id = u.user_id
                          LEFT JOIN initiatives i ON p.initiative_id = i.initiative_id
                          WHERE p.program_id = ?");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return false;
    }
    
    $program = $result->fetch_assoc();
    
    // Get submissions for this program with reporting period details
    $stmt = $conn->prepare("SELECT ps.*, rp.year, rp.quarter
                          FROM program_submissions ps 
                          JOIN reporting_periods rp ON ps.period_id = rp.period_id
                          WHERE ps.program_id = ? 
                          ORDER BY ps.submission_id DESC");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $submissions_result = $stmt->get_result();
    
    $program['submissions'] = [];
    
    if ($submissions_result->num_rows > 0) {
        while ($submission = $submissions_result->fetch_assoc()) {
            // Process content_json if applicable
            if (isset($submission['content_json']) && is_string($submission['content_json'])) {
                $content = json_decode($submission['content_json'], true);
                if ($content) {
                    // Extract fields from content JSON
                    $submission['target'] = $content['target'] ?? '';
                    $submission['achievement'] = $content['achievement'] ?? '';
                    $submission['remarks'] = $content['remarks'] ?? '';
                    $submission['status_date'] = $content['status_date'] ?? '';
                    $submission['status_text'] = $content['status_text'] ?? '';
                }
            }
            $program['submissions'][] = $submission;
        }
        
        // Set current submission (most recent)
        $program['current_submission'] = $program['submissions'][0];
    }
    
    return $program;
}

/**
 * Get sector info by ID
 *
 * @param int $sector_id The sector ID
 * @return array|null Associative array of sector data or null if not found
 */
function get_sector_by_id($sector_id) {
    global $conn;
    $sector_id = intval($sector_id);
    if (!$sector_id) {
        return null;
    }
    try {
        $query = "SELECT * FROM sectors WHERE sector_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $sector_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        } else {
            return null;
        }
    } catch (Exception $e) {
        error_log("Error in get_sector_by_id: " . $e->getMessage());
        return null;
    }
}

/**
 * Get a specific program submission by program ID and period ID
 * 
 * @param int $program_id The ID of the program
 * @param int $period_id The ID of the reporting period
 * @return array|false The submission data or false if not found
 */
function get_program_submission($program_id, $period_id) {
    global $conn;
    
    $program_id = intval($program_id);
    $period_id = intval($period_id);
    
    $sql = "SELECT * FROM program_submissions 
            WHERE program_id = ? AND period_id = ? 
            ORDER BY submission_id DESC LIMIT 1";
            
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Database error in get_program_submission: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param('ii', $program_id, $period_id);
    
    if (!$stmt->execute()) {
        error_log("Execution error in get_program_submission: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return false;
    }
    
    $submission = $result->fetch_assoc();
    $stmt->close();
    
    return $submission;
}

/**
 * Mark a program submission as draft (unsubmit)
 * 
 * @param int $program_id The ID of the program
 * @param int $period_id The ID of the reporting period
 * @return bool True on success, false on failure
 */
function unsubmit_program($program_id, $period_id) {
    global $conn;
    $program_id = intval($program_id);
    $period_id = intval($period_id);

    // Fetch the current content_json
    $sql_select = "SELECT submission_id, content_json FROM program_submissions WHERE program_id = ? AND period_id = ? ORDER BY submission_id DESC LIMIT 1";
    $stmt = $conn->prepare($sql_select);
    if (!$stmt) {
        error_log("Database error in unsubmit_program (select): " . $conn->error);
        return false;
    }
    $stmt->bind_param('ii', $program_id, $period_id);
    if (!$stmt->execute()) {
        error_log("Execution error in unsubmit_program (select): " . $stmt->error);
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        return false;
    }
    $row = $result->fetch_assoc();
    $submission_id = $row['submission_id'];
    $content_json = $row['content_json'];
    $stmt->close();

    // Update the rating/status in content_json
    $content = json_decode($content_json, true) ?: [];
    $content['rating'] = 'not-started';
    $new_content_json = json_encode($content);

    // Update is_draft and content_json
    $sql_update = "UPDATE program_submissions SET is_draft = 1, content_json = ? WHERE submission_id = ?";
    $stmt = $conn->prepare($sql_update);
    if (!$stmt) {
        error_log("Database error in unsubmit_program (update): " . $conn->error);
        return false;
    }
    $stmt->bind_param('si', $new_content_json, $submission_id);
    if (!$stmt->execute()) {
        error_log("Execution error in unsubmit_program (update): " . $stmt->error);
        $stmt->close();
        return false;
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected > 0;
}

/**
 * Enhanced unsubmit program with cascading logic awareness
 * This function reverts the latest submission to draft and handles multi-period scenarios
 * 
 * @param int $program_id The ID of the program
 * @param int $period_id The ID of the reporting period
 * @param bool $cascade_revert Whether to revert all submissions after this period to draft (default: false)
 * @return array Result array with success status and details
 */
function enhanced_unsubmit_program($program_id, $period_id, $cascade_revert = false) {
    global $conn;
    $program_id = intval($program_id);
    $period_id = intval($period_id);
    $affected_periods = [];
    
    // Start transaction for consistency
    $conn->begin_transaction();
    
    try {
        // First, get the submission for the specified period
        $sql_select = "SELECT submission_id, content_json, is_draft FROM program_submissions 
                      WHERE program_id = ? AND period_id = ? 
                      ORDER BY submission_id DESC LIMIT 1";
        $stmt = $conn->prepare($sql_select);
        if (!$stmt) {
            throw new Exception("Database error in enhanced_unsubmit_program (select): " . $conn->error);
        }
        
        $stmt->bind_param('ii', $program_id, $period_id);
        if (!$stmt->execute()) {
            throw new Exception("Execution error in enhanced_unsubmit_program (select): " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $stmt->close();
            throw new Exception("No submission found for Program ID: {$program_id}, Period ID: {$period_id}");
        }
        
        $row = $result->fetch_assoc();
        $submission_id = $row['submission_id'];
        $content_json = $row['content_json'];
        $is_already_draft = $row['is_draft'];
        $stmt->close();
        
        // If already draft, no need to proceed unless cascading
        if ($is_already_draft && !$cascade_revert) {
            $conn->rollback();
            return [
                'success' => true,
                'message' => 'Submission is already in draft status',
                'affected_periods' => []
            ];
        }
        
        // Update the rating/status in content_json to 'not-started'
        $content = json_decode($content_json, true) ?: [];
        $content['rating'] = 'not-started';
        $new_content_json = json_encode($content);
        
        // Revert the specified period to draft
        $sql_update = "UPDATE program_submissions 
                      SET is_draft = 1, content_json = ? 
                      WHERE submission_id = ?";
        $stmt = $conn->prepare($sql_update);
        if (!$stmt) {
            throw new Exception("Database error in enhanced_unsubmit_program (update): " . $conn->error);
        }
        
        $stmt->bind_param('si', $new_content_json, $submission_id);
        if (!$stmt->execute()) {
            throw new Exception("Execution error in enhanced_unsubmit_program (update): " . $stmt->error);
        }
        
        if ($stmt->affected_rows > 0) {
            $affected_periods[] = $period_id;
        }
        $stmt->close();
        
        // If cascade_revert is true, also revert all other submissions for this program
        if ($cascade_revert) {
            $cascade_sql = "UPDATE program_submissions 
                           SET is_draft = 1 
                           WHERE program_id = ? 
                           AND period_id != ? 
                           AND is_draft = 0";
            $cascade_stmt = $conn->prepare($cascade_sql);
            if (!$cascade_stmt) {
                throw new Exception("Database error in enhanced_unsubmit_program (cascade): " . $conn->error);
            }
            
            $cascade_stmt->bind_param('ii', $program_id, $period_id);
            if (!$cascade_stmt->execute()) {
                throw new Exception("Execution error in enhanced_unsubmit_program (cascade): " . $cascade_stmt->error);
            }
            
            // Get the periods that were affected by cascade
            if ($cascade_stmt->affected_rows > 0) {
                $affected_periods_sql = "SELECT DISTINCT period_id FROM program_submissions 
                                        WHERE program_id = ? AND period_id != ? AND is_draft = 1";
                $affected_stmt = $conn->prepare($affected_periods_sql);
                $affected_stmt->bind_param('ii', $program_id, $period_id);
                $affected_stmt->execute();
                $affected_result = $affected_stmt->get_result();
                
                while ($affected_row = $affected_result->fetch_assoc()) {
                    if (!in_array($affected_row['period_id'], $affected_periods)) {
                        $affected_periods[] = $affected_row['period_id'];
                    }
                }
                $affected_stmt->close();
            }
            $cascade_stmt->close();
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => count($affected_periods) > 1 ? 
                        'Program unsubmitted with cascading effect on ' . count($affected_periods) . ' periods' : 
                        'Program successfully unsubmitted',
            'affected_periods' => $affected_periods
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Enhanced unsubmit error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'affected_periods' => []
        ];
    }
}

/**
 * Log an admin action in the system
 * 
 * @param string $action The action being performed
 * @param string $details Additional details about the action
 * @param bool $success Whether the action was successful
 * @return bool True if log was created, false otherwise
 */
function log_action($action, $details, $success = true) {
    global $conn;
    
    // Only proceed if audit_logs table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if ($table_check->num_rows === 0) {
        return false;
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $status = $success ? 'success' : 'failure';
    
    $sql = "INSERT INTO audit_logs (user_id, action, details, ip_address, status, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
            
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Database error in log_action: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param('issss', $user_id, $action, $details, $ip_address, $status);
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}
?>
