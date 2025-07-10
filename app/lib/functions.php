<?php
/**
 * Common functions used throughout the application
 */

// Include rating helpers
require_once 'rating_helpers.php';
// Include status helpers
require_once 'status_helpers.php';
// Include asset helpers
require_once 'asset_helpers.php';


/**
 * Get a display name for a reporting period.
 * Example: Q1-2023, H1-2023
 * @param array $period Associative array of the period (must contain 'quarter' and 'year')
 * @return string Formatted period name
 */
function get_period_display_name($period) {
    if (!isset($period['quarter']) || !isset($period['year'])) {
        return 'Invalid Period';
    }
    $year = $period['year'];
    $quarter = $period['quarter'];

    if ($quarter >= 1 && $quarter <= 4) {
        return "Q{$quarter}-{$year}";
    } elseif ($quarter == 5) {
        return "Half Year 1 {$year}"; // Updated from H1-{$year}
    } elseif ($quarter == 6) {
        return "Half Year 2 {$year}"; // Updated from H2-{$year}
    } else {
        return "Unknown-{$year}";
    }
}

/**
 * Get all metrics
 */
function get_all_metrics(){
    global $conn;
    
    $query = "SELECT * FROM sector_metrics_data";
    $result = $conn->query($query);
    
    if (!$result) {
        // Log error or handle it as needed
        error_log("Database query failed in get_all_metrics: " . $conn->error);
        return [];
    }
    
    $metrics = [];
    while ($row = $result->fetch_assoc()) {
        $metrics[] = $row;
    }
    
    return $metrics;

}
/**
 * Sanitize user input
 * @param string $data Input to sanitize
 * @return string Sanitized input
 */
function sanitize_input($data) {
    // Sanitization logic would go here
    return htmlspecialchars(trim($data));
}

/**
 * Enhanced sanitization for copy-pasted content
 * Handles Unicode normalization, special characters, and formatting issues
 * @param string $data Input to sanitize
 * @param bool $preserve_newlines Whether to preserve line breaks
 * @return string Cleaned input
 */
function sanitize_copy_paste_content($data, $preserve_newlines = true) {
    if (empty($data) || !is_string($data)) {
        return '';
    }

    // Step 1: Normalize Unicode characters (if available)
    if (class_exists('Normalizer')) {
        $data = Normalizer::normalize($data, Normalizer::FORM_C);
    }

    // Step 2: Replace smart quotes and similar characters
    $replacements = [
        // Smart quotes (using hex codes to avoid syntax issues)
        "\xe2\x80\x9c" => '"',  // Left double quotation mark
        "\xe2\x80\x9d" => '"',  // Right double quotation mark
        "\xe2\x80\x98" => "'",  // Left single quotation mark
        "\xe2\x80\x99" => "'",  // Right single quotation mark
        
        // Em/en dashes
        "\xe2\x80\x94" => '-',  // Em dash
        "\xe2\x80\x93" => '-',  // En dash
        
        // Various spaces and non-breaking spaces
        "\xc2\xa0" => ' ',      // Non-breaking space
        "\xe2\x80\x82" => ' ',  // En space
        "\xe2\x80\x83" => ' ',  // Em space
        "\xe2\x80\x89" => ' ',  // Thin space
        "\xe2\x80\x8b" => '',   // Zero-width space
        "\xe2\x80\x8c" => '',   // Zero-width non-joiner
        "\xe2\x80\x8d" => '',   // Zero-width joiner
        
        // Other problematic characters
        "\xe2\x80\xa6" => '...', // Horizontal ellipsis
        "\xc2\xae" => '(R)',     // Registered trademark
        "\xc2\xa9" => '(C)',     // Copyright
        "\xe2\x84\xa2" => '(TM)', // Trademark
    ];
    
    $data = str_replace(array_keys($replacements), array_values($replacements), $data);

    // Step 3: Normalize line breaks
    if ($preserve_newlines) {
        // Convert all line break variations to \n
        $data = preg_replace('/\r\n|\r|\n/', "\n", $data);
        // Remove excessive line breaks (more than 2 consecutive)
        $data = preg_replace('/\n{3,}/', "\n\n", $data);
    } else {
        // Remove all line breaks
        $data = preg_replace('/\r\n|\r|\n/', ' ', $data);
    }

    // Step 4: Clean up whitespace
    // Remove leading/trailing whitespace from each line
    if ($preserve_newlines) {
        $lines = explode("\n", $data);
        $lines = array_map('trim', $lines);
        $data = implode("\n", $lines);
    }
    
    // Normalize multiple spaces to single space
    $data = preg_replace('/[ \t]+/', ' ', $data);
    
    // Step 5: Remove control characters except tabs and newlines
    $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);
    
    // Step 6: Final trim
    $data = trim($data);

    return $data;
}

/**
 * Sanitize program data array with enhanced copy-paste handling
 * @param array $program_data Program data to sanitize
 * @return array Sanitized program data
 */
function sanitize_program_data($program_data) {
    if (!is_array($program_data)) {
        return [];
    }

    $sanitized = [];
    
    // Fields that should preserve newlines
    $multiline_fields = ['brief_description', 'remarks', 'target_text', 'status_description'];
    
    foreach ($program_data as $key => $value) {
        if (is_string($value)) {
            $preserve_newlines = in_array($key, $multiline_fields);
            $sanitized[$key] = sanitize_copy_paste_content($value, $preserve_newlines);
        } elseif (is_array($value)) {
            // Handle nested arrays (like targets)
            $sanitized[$key] = sanitize_program_data($value);
        } else {
            $sanitized[$key] = $value;
        }
    }
    
    return $sanitized;
}

/**
 * Generate random string
 * @param int $length Length of string
 * @return string Random string
 */
function generate_random_string($length = 10) {
    // Random string generation logic would go here
    return ''; // Placeholder
}

/**
 * Format date for display
 * @param string $date Date string
 * @param string $format Output format
 * @return string Formatted date
 */
function format_date($date, $format = 'Y-m-d') {
    // Date formatting logic would go here
    return ''; // Placeholder
}

/**
 * Auto-manage reporting periods
 * 
 * 1. Creates missing periods for current year
 * 2. Sets correct open/closed status based on current date or admin selection
 * 
 * @param bool $respect_admin_open Respect manually opened periods by admin
 * @return bool True if successfully managed periods
 */
function auto_manage_reporting_periods($respect_admin_open = true) {
    global $conn;
    
    // First check if updated_at column exists, if not add it
    $check_column = "SHOW COLUMNS FROM reporting_periods LIKE 'updated_at'";
    $column_result = $conn->query($check_column);
    
    if ($column_result->num_rows === 0) {
        // Column doesn't exist, add it
        $alter_query = "ALTER TABLE reporting_periods 
                        ADD COLUMN updated_at TIMESTAMP NOT NULL 
                        DEFAULT CURRENT_TIMESTAMP 
                        ON UPDATE CURRENT_TIMESTAMP";
        $conn->query($alter_query);
    }
    
    // Define the quarters with their start and end dates
    $current_year = date('Y');
    $periods_definition = [
        // Quarters
        ['type' => 'quarterly', 'q_num' => 1, 'start_month' => 1, 'start_day' => 1, 'end_month' => 3, 'end_day' => 31],
        ['type' => 'quarterly', 'q_num' => 2, 'start_month' => 4, 'start_day' => 1, 'end_month' => 6, 'end_day' => 30],
        ['type' => 'quarterly', 'q_num' => 3, 'start_month' => 7, 'start_day' => 1, 'end_month' => 9, 'end_day' => 30],
        ['type' => 'quarterly', 'q_num' => 4, 'start_month' => 10, 'start_day' => 1, 'end_month' => 12, 'end_day' => 31],
        // Half-yearly
        ['type' => 'half_yearly', 'q_num' => 5, 'start_month' => 1, 'start_day' => 1, 'end_month' => 6, 'end_day' => 30], // H1
        ['type' => 'half_yearly', 'q_num' => 6, 'start_month' => 7, 'start_day' => 1, 'end_month' => 12, 'end_day' => 31], // H2
    ];
    
    $managed_periods = []; // To store details for creation/update

    // Current Year Periods
    foreach ($periods_definition as $def) {
        $managed_periods[] = [
            'year' => $current_year,
            'quarter' => $def['q_num'],
            'start' => sprintf("%d-%02d-%02d", $current_year, $def['start_month'], $def['start_day']),
            'end' => sprintf("%d-%02d-%02d", $current_year, $def['end_month'], $def['end_day']),
            'type' => $def['type']
        ];
    }

    // Create next year's Q1 if we're in Q4 (October onwards)
    $current_month = date('n');
    if ($current_month >= 10) {
        $next_year = $current_year + 1;
        $managed_periods[] = [
            'year' => $next_year,
            'quarter' => 1, // Q1 of next year
            'start' => "$next_year-01-01",
            'end' => "$next_year-03-31",
            'type' => 'quarterly'
        ];
    }
    
    // Get current date for comparison
    $today = date('Y-m-d');
    $current_quarter = ceil($current_month / 3);
    
    // Check if admin has manually set a period to open
    $admin_open_period = null;
    if ($respect_admin_open) {
        // Use safe query that doesn't rely on updated_at column ordering
        $manual_query = "SELECT period_id, year, quarter FROM reporting_periods 
                        WHERE status = 'open' LIMIT 1";
        $manual_result = $conn->query($manual_query);
        
        if ($manual_result && $manual_result->num_rows > 0) {
            $admin_open_period = $manual_result->fetch_assoc();
        }
    }
    
    // Check and create missing periods
    foreach ($managed_periods as $p_data) {
        $y = $p_data['year'];
        $q = $p_data['quarter'];
        $start = $p_data['start'];
        $end = $p_data['end'];
        
        // Check if this period exists
        $query = "SELECT period_id, status FROM reporting_periods WHERE year = ? AND quarter = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $y, $q);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // Period doesn't exist, create it
            // Determine if this should be open
            // For quarterly:
            $is_current_q_for_year = ($q == $current_quarter && $y == $current_year);
            // For half-yearly H1 (q=5): open if current month is Jan-Jun
            $is_current_h1_for_year = ($q == 5 && $y == $current_year && $current_month >= 1 && $current_month <= 6);
            // For half-yearly H2 (q=6): open if current month is Jul-Dec
            $is_current_h2_for_year = ($q == 6 && $y == $current_year && $current_month >= 7 && $current_month <= 12);

            $status = ($is_current_q_for_year || $is_current_h1_for_year || $is_current_h2_for_year) ? 'open' : 'closed';
            
            // Create the period
            $insert = "INSERT INTO reporting_periods (year, quarter, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insert);
            $stmt->bind_param("iisss", $y, $q, $start, $end, $status);
            $stmt->execute();
        } else {
            // Period exists, update its status based on current date or admin selection
            $period = $result->fetch_assoc();
            $period_id = $period['period_id'];
            
            // If admin has manually set an open period, respect that
            if ($respect_admin_open && $admin_open_period && 
                $admin_open_period['period_id'] == $period_id) {
                // Skip automatic status change for admin-selected open period
                continue;
            }
            
            // Current quarter/half should be open, others closed
            $is_current_q_for_year = ($q == $current_quarter && $y == $current_year && $p_data['type'] == 'quarterly');
            $is_current_h1_for_year = ($q == 5 && $y == $current_year && $current_month >= 1 && $current_month <= 6 && $p_data['type'] == 'half_yearly');
            $is_current_h2_for_year = ($q == 6 && $y == $current_year && $current_month >= 7 && $current_month <= 12 && $p_data['type'] == 'half_yearly');
            
            $should_be_open = ($is_current_q_for_year || $is_current_h1_for_year || $is_current_h2_for_year);
            
            // Only auto-update if no admin selection or this isn't the admin-selected period
            $current_status = $period['status'];
            
            if (($should_be_open && $current_status != 'open') || (!$should_be_open && $current_status != 'closed')) {
                $new_status = $should_be_open ? 'open' : 'closed';
                $update = "UPDATE reporting_periods SET status = ? WHERE period_id = ?";
                $stmt = $conn->prepare($update);
                $stmt->bind_param("si", $new_status, $period_id);
                $stmt->execute();
            }
        }
    }
    
    return true;
}

/**
 * Get current reporting period
 * @return array|null Current active reporting period or null if none
 */
function get_current_reporting_period() {
    global $conn;
    
    // Prefer open quarterly period first if available and current
    $current_month = date('n');
    $current_year = date('Y');
    $current_q_val = ceil($current_month / 3);

    $query = "SELECT * FROM reporting_periods WHERE status = 'open' AND year = ? AND quarter = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $current_year, $current_q_val);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    // Then prefer open half-yearly period if available and current
    $current_h_val = ($current_month <= 6) ? 5 : 6;
    $query_h = "SELECT * FROM reporting_periods WHERE status = 'open' AND year = ? AND quarter = ? LIMIT 1";
    $stmt_h = $conn->prepare($query_h);
    $stmt_h->bind_param("ii", $current_year, $current_h_val);
    $stmt_h->execute();
    $result_h = $stmt_h->get_result();

    if ($result_h->num_rows > 0) {
        return $result_h->fetch_assoc();
    }
    
    // Fallback: any other open period (could be manually opened by admin)
    $query_any_open = "SELECT * FROM reporting_periods WHERE status = 'open' ORDER BY year DESC, quarter DESC LIMIT 1";
    $result_any_open = $conn->query($query_any_open);
    
    if ($result_any_open->num_rows > 0) {
        return $result_any_open->fetch_assoc();
    }
    
    // If no open period, get the next upcoming one (quarterly or half-yearly)
    $query = "SELECT * FROM reporting_periods 
              WHERE start_date > NOW() 
              ORDER BY start_date ASC 
              LIMIT 1";
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    // If no upcoming period, get the most recent one (quarterly or half-yearly)
    $query = "SELECT * FROM reporting_periods 
              ORDER BY year DESC, quarter DESC 
              LIMIT 1";
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get all reporting periods
 * @return array List of all reporting periods
 */
function get_all_reporting_periods() {
    global $conn;
    
    $query = "SELECT * FROM reporting_periods ORDER BY year DESC, quarter DESC";
    $result = $conn->query($query);
    
    $periods = [];
    while ($row = $result->fetch_assoc()) {
        $periods[] = $row;
    }
    
    return $periods;
}

/**
 * Get available reporting periods for the period dropdown in forms
 * This function retrieves all reporting periods suitable for dropdown selection in forms
 * 
 * @param bool $include_inactive Whether to include inactive (closed) periods in the result
 * @return array List of reporting periods with essential fields
 */
function get_reporting_periods_for_dropdown($include_inactive = false) {
    global $conn;
    
    $where_clause = $include_inactive ? "" : "WHERE status = 'open'";
    $query = "SELECT period_id, year, quarter, status FROM reporting_periods 
              $where_clause
              ORDER BY year DESC, quarter DESC";
    
    $result = $conn->query($query);
    
    if (!$result) {
        error_log("Error fetching reporting periods: " . $conn->error);
        return [];
    }
    
    $periods = [];
    while ($row = $result->fetch_assoc()) {
        // Format the period name for display in the dropdown
        $row['display_name'] = get_period_display_name($row);
        $periods[] = $row;
    }
    
    return $periods;
}

/**
 * Get a specific reporting period by ID
 * 
 * @param int $period_id The ID of the reporting period to retrieve
 * @return array|null The reporting period data or null if not found
 */
function get_reporting_period($period_id) {
    global $conn;
    
    // Check if connection is valid, if not reconnect
    if (!$conn || $conn->connect_error) {
        // Make sure db_connect.php path is correct if this file is moved
        $db_connect_path = dirname(__FILE__) . '/db_connect.php'; // Assumes db_connect.php is in the same directory
        if (!file_exists($db_connect_path)) {
             // Try one level up if functions.php is in includes/ and db_connect.php is in root
            $db_connect_path = dirname(__DIR__) . '/db_connect.php';
        }
        require_once $db_connect_path;
    }
    
    $query = "SELECT * FROM reporting_periods WHERE period_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Validate user login credentials
 * @param string $username The username
 * @param string $password The password
 * @return array Result of login validation
 */
function validate_login($username, $password) {
    global $conn;
    
    // Include audit log functions
    require_once ROOT_PATH . 'app/lib/audit_log.php';
    
    // Sanitize inputs
    $username = $conn->real_escape_string(trim($username));
    
    // First check if username exists
    $query = "SELECT * FROM users WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Log failed login attempt - user not found
        log_login_attempt($username, false, 'User not found');
        return ['error' => 'Invalid username or password'];
    }
    
    $user = $result->fetch_assoc();
    
    // Check if account is active
    if (isset($user['is_active']) && $user['is_active'] == 0) {
        // Log failed login attempt - account deactivated
        log_login_attempt($username, false, 'Account deactivated', $user['user_id']);
        return ['error' => 'Your account has been deactivated. Please contact an administrator.'];
    }
    
    // Verify password
    if (password_verify($password, $user['password'])) {
        // Store user data in session, but don't include password
        unset($user['password']);
        $_SESSION = $user;
        
        // Log successful login
        log_login_attempt($username, true, '', $user['user_id']);
        
        return ['success' => true, 'user' => $user];
    } else {
        // Log failed login attempt - wrong password
        log_login_attempt($username, false, 'Invalid password', $user['user_id']);
        return ['error' => 'Invalid username or password'];
    }
}

/**
 * Get current period ID
 * @param object $conn Database connection
 * @return int|null Current period ID or null if none
 */
function getCurrentPeriodId($conn) {
    $query = "SELECT period_id FROM reporting_periods WHERE status = 'open' ORDER BY end_date DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['period_id'];
    }
    
    return null;
}

/**
 * Get reporting period status color
 * @param string $status The status of the reporting period (e.g., 'open', 'closed')
 * @return string CSS class or color code representing the status
 */
function get_reporting_period_status_color($status) {
    switch ($status) {
        case 'open':
            return 'success'; // Or any color you prefer for open status
        case 'closed':
            return 'danger'; // Or any color you prefer for closed status
        default:
            return 'secondary'; // Default color
    }
}

/**
 * Log activity to audit log
 * 
 * @param int $user_id The ID of the user performing the action
 * @param string $action Description of the action performed
 * @return bool True on success, false on failure
 */
function log_activity($user_id, $action) {
    // TODO: Implement audit logging when audit_log table is created
    // For now, just log to error log for debugging
    error_log("Activity Log: User $user_id - $action");
    return true;
    
    /* 
    // Commented out until audit_log table is created
    global $conn;
    
    // Get username from session or database
    $username = $_SESSION['username'] ?? 'Unknown';
    
    // If username not in session, try to get it from database
    if ($username === 'Unknown' && $user_id > 0) {
        $stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $username = $row['username'];
            }
            $stmt->close();
        }
    }
    
    // Insert into audit log
    $log_query = "INSERT INTO audit_log (user_id, username, action, action_date) VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($log_query);
    
    if (!$stmt) {
        error_log("Failed to prepare audit log statement: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("iss", $user_id, $username, $action);
    $success = $stmt->execute();
    
    if (!$success) {
        error_log("Failed to log activity: " . $stmt->error);
    }
    
    $stmt->close();
    return $success;
    */
}
?>
