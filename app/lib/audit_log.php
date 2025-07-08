<?php
/**
 * Audit Log Functions
 * 
 * Centralized audit logging system for tracking user activities and system events.
 * This module provides secure, parameterized logging with proper error handling.
 */

/**
 * Log an audit action to the database
 * 
 * @param string $action The action being performed (e.g., 'login', 'create_program', 'delete_user')
 * @param string $details Additional details about the action (optional)
 * @param string $status The status of the action ('success' or 'failure')
 * @param int $user_id Override user ID (optional, defaults to current session user)
 * @return bool True if logged successfully, false otherwise
 */
function log_audit_action($action, $details = '', $status = 'success', $user_id = null) {
    global $conn;

    // Validate inputs
    if (empty($action)) {
        error_log("Audit log error: Action cannot be empty");
        return false;
    }

    if (!in_array($status, ['success', 'failure'])) {
        error_log("Audit log error: Invalid status '$status'");
        return false;
    }

    // Get user ID from session if not provided
    if ($user_id === null) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }

    // Get client information
    $ip_address = get_client_ip();

    // Prepare the SQL statement
    $sql = "INSERT INTO audit_logs (user_id, action, details, ip_address, status, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Audit log error: Failed to prepare statement - " . $conn->error);
            return false;
        }

        $stmt->bind_param('issss', $user_id, $action, $details, $ip_address, $status);
        $result = $stmt->execute();

        if (!$result) {
            error_log("Audit log error: Failed to execute statement - " . $stmt->error);
        }

        $stmt->close();
        return $result;

    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Get audit logs with filtering and pagination
 * 
 * @param array $filters Associative array of filters
 * @param int $limit Number of records to return (default: 50)
 * @param int $offset Offset for pagination (default: 0)
 * @return array Array containing logs and pagination info
 */
function get_audit_logs($filters = [], $limit = 50, $offset = 0) {
    global $conn;

    $conditions = [];
    $params = [];
    $param_types = "";

    // Build WHERE conditions based on filters
    if (!empty($filters['date_from'])) {
        $conditions[] = "al.created_at >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
        $param_types .= 's';
    }

    if (!empty($filters['date_to'])) {
        $conditions[] = "al.created_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
        $param_types .= 's';
    }

    if (!empty($filters['action_type'])) {
        $conditions[] = "al.action = ?";
        $params[] = $filters['action_type'];
        $param_types .= 's';
    }    if (!empty($filters['user'])) {
        $conditions[] = "(u.agency_name LIKE ? OR u.username LIKE ?)";
        $search_term = '%' . $filters['user'] . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $param_types .= 'ss';
        error_log("Searching audit logs for user: " . $filters['user']);
    }

    if (!empty($filters['status'])) {
        $conditions[] = "al.status = ?";
        $params[] = $filters['status'];
        $param_types .= 's';
    }

    if (!empty($filters['user_id'])) {
        $conditions[] = "al.user_id = ?";
        $params[] = $filters['user_id'];
        $param_types .= 'i';
    }

    // Build the WHERE clause
    $where_clause = '';
    if (!empty($conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $conditions);
    }

    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as total 
                  FROM audit_logs al 
                  LEFT JOIN users u ON al.user_id = u.user_id 
                  $where_clause";

    $total_records = 0;
    try {
        if (!empty($params)) {
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->bind_param($param_types, ...$params);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $total_records = $count_result->fetch_assoc()['total'];
            $count_stmt->close();
        } else {
            $count_result = $conn->query($count_sql);
            $total_records = $count_result->fetch_assoc()['total'];
        }
    } catch (Exception $e) {
        error_log("Error fetching total count for audit logs: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [
            'logs' => [],
            'total' => 0,
            'error' => 'Failed to fetch audit logs'
        ];
    }

    // Main query with joins
    $sql = "SELECT 
                al.id,
                al.user_id,
                al.action,
                al.details,
                al.ip_address,
                al.status,
                al.created_at,
                COALESCE(u.agency_name, u.username, 'System') as user_name,
                u.role
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            $where_clause
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?";

    // Add limit and offset to params
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= 'ii';

    $logs = [];

    try {
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        $stmt->close();

    } catch (Exception $e) {
        error_log("Error fetching audit logs: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [
            'logs' => [],
            'total' => 0,
            'error' => 'Failed to fetch audit logs'
        ];
    }

    return [
        'logs' => $logs,
        'total' => $total_records,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Get client IP address
 * Handles various proxy scenarios
 * 
 * @return string Client IP address
 */
function get_client_ip() {
    $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            if (!empty($ip)) {
                // Handle comma-separated list (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Helper function to log login attempts
 * 
 * @param string $username User username
 * @param bool $success Whether login was successful
 * @param string $reason Failure reason (if applicable)
 * @param int $user_id User ID (if login successful)
 */
function log_login_attempt($username, $success = true, $reason = '', $user_id = null) {
    $action = $success ? 'login_success' : 'login_failure';
    $status = $success ? 'success' : 'failure';
    $details = "Username: $username" . ($reason ? " | Reason: $reason" : '');
    
    log_audit_action($action, $details, $status, $user_id);
}

/**
 * Helper function to log logout
 * 
 * @param int $user_id User ID
 */
function log_logout($user_id = null) {
    log_audit_action('logout', 'User logged out', 'success', $user_id);
}

/**
 * Helper function to log data operations
 * 
 * @param string $operation Operation type (create, update, delete)
 * @param string $entity Entity type (program, user, outcome, etc.)
 * @param int $entity_id ID of the affected entity
 * @param array $changes Array of changes made (optional)
 * @param int $user_id User performing the action
 */
function log_data_operation($operation, $entity, $entity_id, $changes = [], $user_id = null) {
    $action = "{$operation}_{$entity}";
    $details = "Entity ID: $entity_id";
    
    if (!empty($changes)) {
        $details .= " | Changes: " . json_encode($changes);
    }
    
    log_audit_action($action, $details, 'success', $user_id);
}

/**
 * Helper function to log export operations
 * 
 * @param string $export_type Type of export (csv, pdf, excel, etc.)
 * @param string $entity Entity being exported
 * @param array $filters Applied filters
 * @param int $user_id User performing the export
 */
function log_export_operation($export_type, $entity, $filters = [], $user_id = null) {
    $action = "export_{$entity}";
    $details = "Export type: $export_type";
    
    if (!empty($filters)) {
        $details .= " | Filters: " . json_encode($filters);
    }
    
    log_audit_action($action, $details, 'success', $user_id);
}

/**
 * Clean up old audit logs (for maintenance)
 * 
 * @param int $days_to_keep Number of days to keep logs (default: 90)
 * @return int Number of records deleted
 */
function cleanup_audit_logs($days_to_keep = 90) {
    global $conn;
    
    if (!is_admin()) {
        error_log("Audit log cleanup: Unauthorized access attempt");
        return 0;
    }
    
    $sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $days_to_keep);
        $stmt->execute();
        $deleted_count = $stmt->affected_rows;
        $stmt->close();
        
        // Log the cleanup operation
        log_audit_action('cleanup_audit_logs', "Deleted $deleted_count old records (older than $days_to_keep days)", 'success');
        
        return $deleted_count;
        
    } catch (Exception $e) {
        error_log("Audit log cleanup error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Log successful user deletion
 * 
 * @param int $deleted_user_id The ID of the user that was deleted
 * @param string $deleted_username The username of the user that was deleted
 * @param string $deleted_user_role The role of the user that was deleted
 * @param int $admin_user_id The ID of the admin who performed the deletion
 * @return bool True if logged successfully, false otherwise
 */
function log_user_deletion_success($deleted_user_id, $deleted_username, $deleted_user_role, $admin_user_id) {
    $details = json_encode([
        'deleted_user_id' => $deleted_user_id,
        'deleted_username' => $deleted_username,
        'deleted_user_role' => $deleted_user_role,
        'action' => 'User account deleted'
    ], JSON_UNESCAPED_SLASHES);
    
    return log_audit_action('user_deletion', $details, 'success', $admin_user_id);
}

/**
 * Log failed user deletion attempt
 * 
 * @param int $target_user_id The ID of the user that deletion was attempted on
 * @param string $error_reason The reason why deletion failed
 * @param int $admin_user_id The ID of the admin who attempted the deletion
 * @return bool True if logged successfully, false otherwise
 */
function log_user_deletion_failed($target_user_id, $error_reason, $admin_user_id) {
    $details = json_encode([
        'target_user_id' => $target_user_id,
        'error_reason' => $error_reason,
        'action' => 'User deletion attempt failed'
    ], JSON_UNESCAPED_SLASHES);

    return log_audit_action('user_deletion', $details, 'failure', $admin_user_id);
}

/**
 * Log general user action (deactivation, reactivation, etc.)
 * 
 * @param int $target_user_id The ID of the user being acted upon
 * @param string $action_type The type of action (e.g., 'deactivated', 'reactivated')
 * @param string $details Additional details about the action
 * @param int $admin_user_id The ID of the admin performing the action
 * @return bool True if logged successfully, false otherwise
 */
function log_user_action($target_user_id, $action_type, $details, $admin_user_id) {
    $audit_details = json_encode([
        'target_user_id' => $target_user_id,
        'action_type' => $action_type,
        'details' => $details
    ], JSON_UNESCAPED_SLASHES);

    return log_audit_action("user_$action_type", $audit_details, 'success', $admin_user_id);
}
?>
