<?php
/**
 * User Management Functions
 * 
 * Contains functions for managing users (add, update, delete)
 */

require_once dirname(__DIR__) . '/utilities.php';
require_once 'core.php';

/**
 * Get all agency groups.
 *
 * @param mysqli $conn The database connection.
 * @return array An array of agency groups with sector_id included.
 */
function get_all_agency_groups(mysqli $conn): array {
    $agency_groups = [];
    // Include sector_id for proper filtering by sector
    $sql = "SELECT `agency_group_id`, `group_name`, `sector_id` FROM `agency_group` ORDER BY `group_name` ASC";
    $result = $conn->query($sql);
    if ($result === false) {
        error_log("Error fetching agency groups: " . $conn->error);
        return [];
    }
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $agency_groups[] = $row;
        }
    }
    return $agency_groups;
}

/**
 * Get all users in the system
 * 
 * @return array List of all users with their details
 */
function get_all_users() {
    global $conn;
    
    $query = "SELECT u.*, s.sector_name, ag.group_name 
              FROM users u 
              LEFT JOIN sectors s ON u.sector_id = s.sector_id
              LEFT JOIN agency_group ag ON u.agency_group_id = ag.agency_group_id
              ORDER BY u.username ASC";
              
    $result = $conn->query($query);
    
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    
    return $users;
}

/**
 * Add a new user to the system
 * 
 * @param array $data Post data from add user form
 * @return array Result of the operation
 */
function add_user($data) {
    global $conn;
    
    // Validate required fields
    $required_fields = ['username', 'role', 'password', 'confirm_password'];
    
    // Add agency-specific required fields
    if (isset($data['role']) && $data['role'] === 'agency') {
        $required_fields[] = 'agency_name';
        $required_fields[] = 'sector_id';
        $required_fields[] = 'agency_group_id';
    }
    
    // Check for missing required fields
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            return ['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required'];
        }
    }
    
    // Validate username uniqueness
    $username = trim($data['username']);
    $check_query = "SELECT user_id FROM users WHERE username = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['error' => "Username '$username' already exists"];
    }
    
    // Validate password
    $password = $data['password'];
    $confirm_password = $data['confirm_password'];
    
    if (strlen($password) < 8) {
        return ['error' => 'Password must be at least 8 characters long'];
    }
    
    if ($password !== $confirm_password) {
        return ['error' => 'Passwords do not match'];
    }
    
    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Prepare basic user data
        $role = $data['role'];
        $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;
        
        // Set agency-specific fields
        $agency_name = null;
        $sector_id = null;
        $agency_group_id = null;
        
        if ($role === 'agency') {
            $agency_name = trim($data['agency_name']);
            $sector_id = intval($data['sector_id']);
            $agency_group_id = intval($data['agency_group_id']);
            
            // Verify sector exists
            $sector_check = "SELECT sector_id FROM sectors WHERE sector_id = ?";
            $stmt = $conn->prepare($sector_check);
            $stmt->bind_param("i", $sector_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $conn->rollback();
                return ['error' => 'Invalid sector selected'];
            }
              // Verify agency group exists
            $group_check = "SELECT agency_group_id FROM agency_group WHERE agency_group_id = ?";
            $stmt = $conn->prepare($group_check);
            $stmt->bind_param("i", $agency_group_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $conn->rollback();
                return ['error' => 'Invalid agency group selected'];
            }
        }
        
        // Insert user
        $query = "INSERT INTO users (username, password, agency_name, role, sector_id, agency_group_id, is_active, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssssiis", $username, $hashed_password, $agency_name, $role, $sector_id, $agency_group_id, $is_active);
        
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        return [
            'success' => true,
            'user_id' => $stmt->insert_id
        ];
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Update an existing user
 * 
 * @param array $data Post data from edit user form
 * @return array Result of the operation
 */
function update_user($data) {
    global $conn;
    
    // Validate required fields
    if (!isset($data['user_id']) || !intval($data['user_id'])) {
        return ['error' => 'Invalid user ID'];
    }
    
    $user_id = intval($data['user_id']);
    
    // Validate required fields (only if they are present in the data)
    // This allows for partial updates (e.g. just updating is_active status)
    if (isset($data['username']) && isset($data['role'])) {
        $required_fields = ['username', 'role'];
        
        // Add agency-specific required fields
        if (isset($data['role']) && $data['role'] === 'agency') {
            $required_fields[] = 'agency_name';
            $required_fields[] = 'sector_id';
            $required_fields[] = 'agency_group_id';
        }
        
        // Check for missing required fields
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                return ['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required'];
            }
        }
    }
    
    // Check if user exists
    $user_check = "SELECT * FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($user_check);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['error' => 'User not found'];
    }
    
    $existing_user = $result->fetch_assoc();
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Prepare data for update - only update fields that are provided
        $update_fields = [];
        $bind_params = [];
        $param_types = "";
        
        // Handle username if provided
        if (isset($data['username'])) {
            $username = trim($data['username']);
            
            // Validate username uniqueness (only if username changed)
            if ($username !== $existing_user['username']) {
                $check_query = "SELECT user_id FROM users WHERE username = ? AND user_id != ?";
                $stmt = $conn->prepare($check_query);
                $stmt->bind_param("si", $username, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $conn->rollback();
                    return ['error' => "Username '$username' already exists"];
                }
            }
            
            $update_fields[] = "username = ?";
            $bind_params[] = $username;
            $param_types .= "s";
        }
        
        // Handle role if provided
        if (isset($data['role'])) {
            $role = $data['role'];
            $update_fields[] = "role = ?";
            $bind_params[] = $role;
            $param_types .= "s";
        }
        
        // Handle password if provided
        if (isset($data['password']) && !empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                $conn->rollback();
                return ['error' => 'Password must be at least 8 characters long'];
            }
            
            if (!isset($data['confirm_password']) || $data['password'] !== $data['confirm_password']) {
                $conn->rollback();
                return ['error' => 'Passwords do not match'];
            }
            
            $update_fields[] = "password = ?";
            $bind_params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            $param_types .= "s";
        }
        
        // Handle agency_name if provided
        if (isset($data['agency_name'])) {
            $agency_name = trim($data['agency_name']);
            $update_fields[] = "agency_name = ?";
            $bind_params[] = $agency_name;
            $param_types .= "s";
        } else {
            // Reset agency_name if role is not agency
            if (isset($data['role']) && $data['role'] !== 'agency') {
                $update_fields[] = "agency_name = NULL";
            }
        }
        
        // Handle sector_id if provided
        if (isset($data['sector_id'])) {
            $sector_id = !empty($data['sector_id']) ? intval($data['sector_id']) : null;
            $update_fields[] = "sector_id = ?";
            $bind_params[] = $sector_id;
            $param_types .= "i";
            
            // Verify sector exists if provided
            if ($sector_id) {
                $sector_check = "SELECT sector_id FROM sectors WHERE sector_id = ?";
                $stmt = $conn->prepare($sector_check);
                $stmt->bind_param("i", $sector_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) {
                    $conn->rollback();
                    return ['error' => 'Invalid sector selected'];
                }
            }
        }
        
        // Handle agency_group_id if provided
        if (isset($data['agency_group_id'])) {
            $agency_group_id = !empty($data['agency_group_id']) ? intval($data['agency_group_id']) : null;
            $update_fields[] = "agency_group_id = ?";
            $bind_params[] = $agency_group_id;
            $param_types .= "i";
              // Verify agency group exists if provided
            if ($agency_group_id) {
                $group_check = "SELECT agency_group_id FROM agency_group WHERE agency_group_id = ?";
                $stmt = $conn->prepare($group_check);
                $stmt->bind_param("i", $agency_group_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) {
                    $conn->rollback();
                    return ['error' => 'Invalid agency group selected'];
                }
            }
        }
        
        // Handle is_active if provided
        if (isset($data['is_active'])) {
            $update_fields[] = "is_active = ?";
            $bind_params[] = intval($data['is_active']);
            $param_types .= "i";
        }
        
        // Add updated_at timestamp
        $update_fields[] = "updated_at = NOW()";
        
        // If there are fields to update
        if (!empty($update_fields)) {
            $query = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE user_id = ?";
            $stmt = $conn->prepare($query);
            
            // Add user_id to parameters
            $bind_params[] = $user_id;
            $param_types .= "i";
            
            // Bind parameters
            $stmt->bind_param($param_types, ...$bind_params);
            
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Delete a user
 * 
 * @param int $user_id User ID to delete
 * @return array Result of the operation
 */
function delete_user($user_id) {
    global $conn;
    
    $user_id = intval($user_id);
    
    // Verify user exists
    $check_query = "SELECT username, role FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['error' => 'User not found'];
    }
    
    $user = $result->fetch_assoc();
    
    // Check if user has any programs
    $program_check = "SELECT COUNT(*) as count FROM programs WHERE owner_agency_id = ?";
    $stmt = $conn->prepare($program_check);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $program_result = $stmt->get_result();
    $program_count = $program_result->fetch_assoc()['count'];
    
    if ($program_count > 0) {
        return [
            'error' => "Cannot delete user '{$user['username']}' because they own $program_count program(s). Reassign these programs first."
        ];
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Delete the user
        $delete_query = "DELETE FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("i", $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        return [
            'success' => true,
            'message' => "User '{$user['username']}' successfully deleted"
        ];
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get a single user by ID.
 *
 * @param mysqli $conn
 * @param integer $user_id
 * @return array|null
 */
function get_user_by_id(mysqli $conn, int $user_id): ?array {
    $sql = "SELECT u.*, s.sector_name, ag.group_name 
            FROM users u 
            LEFT JOIN sectors s ON u.sector_id = s.sector_id
            LEFT JOIN agency_group ag ON u.agency_group_id = ag.agency_group_id
            WHERE u.user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        return null;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}
?>