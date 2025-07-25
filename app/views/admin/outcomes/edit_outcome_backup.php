<?php
/**
 * Edit/Create Sector Outcome
 * 
 * Admin interface to edit or create sector-specific outcomes.
 */


// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php'; // Contains legacy functions
require_once ROOT_PATH . 'app/lib/admins/outcomes.php'; // Contains updated outcome functions
require_once ROOT_PATH . 'app/lib/admins/index.php'; // Contains is_admin
require_once ROOT_PATH . 'app/lib/admins/statistics.php'; // For get_sector_by_id

// Verify user is an admin
if (!is_admin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Set page title
$pageTitle = 'Edit/Create Outcome';

// Function to log messages to browser console (optional, for debugging)
function console_log($message) {
    echo '<script>console.log(' . json_encode($message) . ');</script>';
}

// Initialize variables
$message = $_SESSION['success_message'] ?? '';
$message_type = 'success';
if (isset($_SESSION['error_message'])) {
    $message = $_SESSION['error_message'];
    $message_type = 'danger';
}
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Support both metric_id and outcome_id parameters for backward compatibility
$outcome_id = isset($_GET['metric_id']) ? intval($_GET['metric_id']) : 
             (isset($_GET['outcome_id']) ? intval($_GET['outcome_id']) : 
             (isset($_POST['outcome_id']) ? intval($_POST['outcome_id']) : 0));
$sector_id = isset($_GET['sector_id']) ? intval($_GET['sector_id']) : (isset($_POST['sector_id']) ? intval($_POST['sector_id']) : 0);
$period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : (isset($_POST['period_id']) ? intval($_POST['period_id']) : 0);

$outcome_data = null;
$table_name = '';
$data_json_structure = []; // For the metric-editor.js
$sector_name = '';
$reporting_periods = get_all_reporting_periods();
$current_reporting_period = get_current_reporting_period();

// If we have an outcome_id, get its data
if ($outcome_id > 0) {
    $outcome_data = get_outcome_data_for_display($outcome_id);
    if ($outcome_data) {
        $sector_id = $outcome_data['sector_id'];
        $period_id = $outcome_data['period_id'];
        $table_name = $outcome_data['table_name'];
        $sector_name = $outcome_data['sector_name']; // Assuming get_outcome_data returns this
        
        // Handle flexible structure data properly
        $raw_data_json = json_decode($outcome_data['data_json'], true) ?? [];
        $row_config = json_decode($outcome_data['row_config'] ?? '{}', true);
        $column_config = json_decode($outcome_data['column_config'] ?? '{}', true);
        
        // Check if this uses flexible structure
        $is_flexible = !empty($row_config) && !empty($column_config);
        
        if ($is_flexible) {
            // Convert flexible structure to legacy format for the editor
            $columns = $column_config['columns'] ?? [];
            $rows = $row_config['rows'] ?? [];
            
            // Create data structure expected by editor
            $data_json_structure = [
                'columns' => array_map(function($col) { return $col['label']; }, $columns),
                'data' => $raw_data_json,
                'units' => []
            ];
            
            // Extract units from columns
            foreach ($columns as $col) {
                if (!empty($col['unit'])) {
                    $data_json_structure['units'][$col['label']] = $col['unit'];
                }
            }
        } else {
            // Legacy structure
            $data_json_structure = $raw_data_json;
        }
        
        $pageTitle = 'Edit Outcome: ' . htmlspecialchars($table_name ?: 'ID ' . $outcome_id);
    } else {
        $_SESSION['error_message'] = "Outcome with ID {$outcome_id} not found.";
        header("Location: manage_outcomes.php");
        exit;
    }
} else {
    $pageTitle = 'Create New Outcome';
    // For new outcome, if sector_id is passed, get sector_name
    if ($sector_id > 0) {
        $sector_info = get_sector_by_id($sector_id); // Assuming this function exists
        if ($sector_info) {
            $sector_name = $sector_info['sector_name'];
        }
    }
    // Default to current reporting period if not set and creating new
    if (!$period_id && $current_reporting_period) {
        $period_id = $current_reporting_period['period_id'];
    }
}

// Handle form submission for creating/updating outcome metadata (table name, sector, period)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_outcome_metadata'])) {
    $new_table_name = trim($_POST['table_name']);
    $new_sector_id = intval($_POST['sector_id']);
    $new_period_id = intval($_POST['period_id']);

    if (empty($new_table_name)) {
        $message = "Outcome Name (Table Name) cannot be empty.";
        $message_type = "danger";
    } elseif ($new_sector_id <= 0) {
        $message = "Please select a valid Sector.";
        $message_type = "danger";
    } elseif ($new_period_id <= 0) {
        $message = "Please select a valid Reporting Period.";
        $message_type = "danger";
    } else {        if ($outcome_id > 0) { // Update existing outcome metadata
            // Get the record ID from sector_outcomes_data for history tracking
            $record_query = "SELECT id, data_json, is_draft FROM sector_outcomes_data WHERE metric_id = ? LIMIT 1";
            $record_stmt = $conn->prepare($record_query);
            $record_stmt->bind_param("i", $outcome_id);
            $record_stmt->execute();
            $record_result = $record_stmt->get_result();
            $record_row = $record_result->fetch_assoc();
            $outcome_record_id = $record_row['id'] ?? 0;
            $current_data_json = $record_row['data_json'] ?? '{}';
            $is_draft = $record_row['is_draft'] ?? 1;
            $record_stmt->close();
            
            $update_query = "UPDATE sector_outcomes_data SET table_name = ?, sector_id = ?, period_id = ?, updated_at = NOW() WHERE metric_id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("siii", $new_table_name, $new_sector_id, $new_period_id, $outcome_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Outcome metadata updated successfully.";
                
                // Record history for metadata change
                $user_id = $_SESSION['user_id'];
                $action_type = 'edit';
                $status = $is_draft ? 'draft' : 'submitted';
                $description = "Updated outcome metadata: table name, sector, or reporting period";
                record_outcome_history($outcome_record_id, $outcome_id, $current_data_json, $action_type, $status, $user_id, $description);
                
                // Refresh data
                $table_name = $new_table_name;
                $sector_id = $new_sector_id;
                $period_id = $new_period_id;
                $outcome_data = get_outcome_data_for_display($outcome_id); // Re-fetch to get fresh data
                if($outcome_data) {
                    // Handle flexible structure data properly (same logic as above)
                    $raw_data_json = json_decode($outcome_data['data_json'], true) ?? [];
                    $row_config = json_decode($outcome_data['row_config'] ?? '{}', true);
                    $column_config = json_decode($outcome_data['column_config'] ?? '{}', true);
                    
                    // Check if this uses flexible structure
                    $is_flexible = !empty($row_config) && !empty($column_config);
                    
                    if ($is_flexible) {
                        // Convert flexible structure to legacy format for the editor
                        $columns = $column_config['columns'] ?? [];
                        
                        // Create data structure expected by editor
                        $data_json_structure = [
                            'columns' => array_map(function($col) { return $col['label']; }, $columns),
                            'data' => $raw_data_json,
                            'units' => []
                        ];
                        
                        // Extract units from columns
                        foreach ($columns as $col) {
                            if (!empty($col['unit'])) {
                                $data_json_structure['units'][$col['label']] = $col['unit'];
                            }
                        }
                    } else {
                        // Legacy structure
                        $data_json_structure = $raw_data_json;
                    }
                }
            } else {
                $message = "Error updating outcome metadata: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        } else { // Create new outcome            // Check if structure data was provided
            $structure_data = isset($_POST['structure_data']) ? $_POST['structure_data'] : null;
            $data_json = null;
            
            if ($structure_data) {
                // Use the structure data provided from the form
                $data_json = $structure_data;
                console_log("Using structure data from form");
            } else {
                // Initialize empty data_json structure
                $data_json = json_encode(['columns' => [], 'units' => [], 'data' => []]);
                console_log("Using default empty structure");
            }
            
            // Get next available outcome_id (formerly metric_id)
            $max_query = "SELECT MAX(metric_id) AS max_id FROM sector_outcomes_data";
            $max_stmt = $conn->prepare($max_query);
            $max_stmt->execute();
            $max_result = $max_stmt->get_result();
            $next_outcome_id = 1;
            if ($max_result && $row = $max_result->fetch_assoc()) {
                if ($row['max_id'] !== null) {
                    $next_outcome_id = intval($row['max_id']) + 1;
                }
            }
            $max_stmt->close();            $insert_query = "INSERT INTO sector_outcomes_data (metric_id, table_name, sector_id, period_id, data_json, created_at, updated_at, is_draft) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1)";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("isiss", $next_outcome_id, $new_table_name, $new_sector_id, $new_period_id, $data_json);
            if ($stmt->execute()) {
                $outcome_record_id = $conn->insert_id;
                
                // Record history for outcome creation
                $user_id = $_SESSION['user_id'];
                $action_type = 'create';
                $status = 'draft'; // New outcomes start as drafts
                $description = "Created new outcome: " . $new_table_name;
                record_outcome_history($outcome_record_id, $next_outcome_id, $data_json, $action_type, $status, $user_id, $description);
                
                $_SESSION['success_message'] = "New outcome created successfully. You can now define its structure.";
                header("Location: edit_outcome.php?metric_id=" . $next_outcome_id);
                exit;
            } else {
                $message = "Error creating new outcome: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Handle form submission for saving the editable table data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_outcome_table']) && $outcome_id > 0) {
    $table_data = $_POST['table_data'] ?? [];
    $units = $_POST['units'] ?? [];
    // Rebuild data_json_structure
    if (isset($data_json_structure['columns'])) {
        $years = $data_json_structure['columns'];
        $months = array_keys($table_data);
        $data = [];
        foreach ($months as $month) {
            $row = [];
            foreach ($years as $year) {
                $cell = isset($table_data[$month][$year]) ? $table_data[$month][$year] : null;
                $row[$year] = ($cell === '' ? null : $cell);
            }
            $data[$month] = $row;
        }
        $data_json_structure['data'] = $data;
        // Save units for each year
        $units_clean = [];
        foreach ($years as $year) {
            $units_clean[$year] = isset($units[$year]) ? $units[$year] : '';
        }
        $data_json_structure['units'] = $units_clean;
        $new_data_json = json_encode($data_json_structure);
        // Save to DB
        $update_query = "UPDATE sector_outcomes_data SET data_json = ?, updated_at = NOW() WHERE metric_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("si", $new_data_json, $outcome_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Outcome table data updated successfully.";
            // Optionally record history here
        } else {
            $_SESSION['error_message'] = "Error updating outcome table data: " . $stmt->error;
        }
        $stmt->close();
        header("Location: edit_outcome.php?outcome_id=" . $outcome_id);
        exit;
    }
}

$sectors = get_all_sectors();

// Include header
require_once ROOT_PATH . 'app/views/layouts/header.php';

// Configure modern page header
$header_config = [
    'title' => $pageTitle,
    'subtitle' => 'Edit outcome data and table structure',
    'variant' => 'white',
    'actions' => [
        [
            'url' => 'manage_outcomes.php',
            'text' => 'Back to Manage Outcomes',
            'icon' => 'fas fa-arrow-left',
            'class' => 'btn-outline-primary'
        ]
    ]
];

// Include modern page header
require_once '../../layouts/page_header.php';
?>

<div class="container-fluid px-4 py-4">

    <!-- Placeholder for JavaScript-driven messages -->
    <div id="outcome-editor-messages" style="display: none;"></div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="edit_outcome.php<?php echo $outcome_id > 0 ? '?outcome_id='.$outcome_id : ''; ?>" class="mb-4">
        <input type="hidden" name="outcome_id" value="<?php echo $outcome_id; ?>">
        <div class="card admin-card">
            <div class="card-header">
                <h5 class="card-title m-0">Outcome Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="table_name" class="form-label">Outcome Name / Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="table_name" name="table_name" value="<?php echo htmlspecialchars($table_name); ?>" required>
                        <small class="form-text text-muted">This will be the title of the outcome table shown to users.</small>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="sector_id" class="form-label">Sector <span class="text-danger">*</span></label>
                        <select class="form-select" id="sector_id" name="sector_id" required <?php echo $outcome_id > 0 && isset($outcome_data['is_submitted']) && $outcome_data['is_submitted'] ? 'disabled' : '' ?>>
                            <option value="">Select Sector</option>
                            <?php foreach ($sectors as $s): ?>
                                <option value="<?php echo $s['sector_id']; ?>" <?php echo ($sector_id == $s['sector_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['sector_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                         <?php if ($outcome_id > 0 && isset($outcome_data['is_submitted']) && $outcome_data['is_submitted']): ?>
                            <small class="form-text text-warning">Sector cannot be changed for submitted outcomes.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="period_id" class="form-label">Reporting Period <span class="text-danger">*</span></label>
                        <select class="form-select" id="period_id" name="period_id" required <?php echo $outcome_id > 0 && isset($outcome_data['is_submitted']) && $outcome_data['is_submitted'] ? 'disabled' : '' ?>>                        <option value="">Select Period</option>
                            <?php foreach ($reporting_periods as $rp): ?>
                                <option value="<?php echo $rp['period_id']; ?>" <?php echo ($period_id == $rp['period_id']) ? 'selected' : ''; ?>>
                                    <?php echo get_period_display_name($rp); ?> (<?php echo $rp['status']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($outcome_id > 0 && isset($outcome_data['is_submitted']) && $outcome_data['is_submitted']): ?>
                            <small class="form-text text-warning">Period cannot be changed for submitted outcomes.</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" name="save_outcome_metadata" class="btn btn-forest">
                    <i class="fas fa-save me-1"></i> <?php echo $outcome_id > 0 ? 'Save Changes' : 'Create Outcome & Proceed'; ?>
                </button>
            </div>
        </div>
    </form>    <?php if ($outcome_id > 0 && $outcome_data): // Show structure editor for existing outcome ?>
    <div class="card admin-card mt-4">
        <div class="card-header">
            <h5 class="card-title m-0">Edit Outcome Data</h5>
        </div>
        <div class="card-body">
            <?php
            // Render editable table if data_json_structure is available and valid
            $years = isset($data_json_structure['columns']) ? $data_json_structure['columns'] : [];
            $months = isset($data_json_structure['data']) ? array_keys($data_json_structure['data']) : [];
            $data = isset($data_json_structure['data']) ? $data_json_structure['data'] : [];
            $units = isset($data_json_structure['units']) ? $data_json_structure['units'] : [];
            if ($years && $months): ?>
                <form method="POST" action="edit_outcome.php?outcome_id=<?php echo $outcome_id; ?>">
                    <input type="hidden" name="outcome_id" value="<?php echo $outcome_id; ?>">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th style="vertical-align: middle;">Month/Units</th>
                                <?php foreach ($years as $year): ?>
                                    <th>
                                        <div style="display: flex; flex-direction: column; align-items: center;">
                                            <span><?php echo htmlspecialchars($year); ?></span>
                                            <input type="text" name="units[<?php echo htmlspecialchars($year); ?>]" value="<?php echo isset($units[$year]) ? htmlspecialchars($units[$year]) : ''; ?>" class="form-control form-control-sm text-center" placeholder="Unit" style="width: 60px; font-size: 0.85em; margin-top: 2px; padding: 0.1rem 0.2rem; background: #e9f5ee; border: 1px solid #b7e4c7; display: inline-block;" title="Unit for <?php echo htmlspecialchars($year); ?>" />
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($months as $month): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($month); ?></td>
                                    <?php foreach ($years as $year): ?>
                                        <td>
                                            <input type="text" name="table_data[<?php echo htmlspecialchars($month); ?>][<?php echo htmlspecialchars($year); ?>]" value="<?php echo isset($data[$month][$year]) && $data[$month][$year] !== null ? htmlspecialchars((string)$data[$month][$year]) : ''; ?>" class="form-control form-control-sm" />
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-success fw-bold">
                                <td>Total</td>
                                <?php foreach ($years as $year): ?>
                                    <?php
                                    $total = 0;
                                    foreach ($months as $month) {
                                        $val = isset($data[$month][$year]) && is_numeric($data[$month][$year]) ? (float)$data[$month][$year] : 0;
                                        $total += $val;
                                    }
                                    ?>
                                    <td class="text-end">
                                        <?php echo number_format($total, 2); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                    <button type="submit" name="save_outcome_table" class="btn btn-forest">Save Table Data</button>
                </form>
            <?php else: ?>
                <div class="alert alert-warning">No outcome data available to display.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: // Show table preview for new outcome ?>
    <div class="card admin-card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title m-0">Outcome Structure Preview</h5>
            <div>
                <span class="badge bg-info">New Outcome</span>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted">Define the column structure for your new outcome. This will be the format for data collection.</p>
            
            <?php if ($sector_id > 0 && $period_id > 0): ?>
                <div class="table-responsive mb-3">
                    <table id="outcomeStructureTable" class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th colspan="12" class="text-center bg-light">
                                    <div class="py-2">
                                        <div class="mb-2">Sample Monthly Data Structure</div>
                                        <small class="text-muted">Add columns (metrics) to collect specific outcome data</small>
                                    </div>
                                </th>
                            </tr>
                            <tr>
                                <th>Month</th>
                                <!-- Preview columns will be added by JS -->
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>January</td>
                                <!-- Preview cells will be added by JS -->
                            </tr>
                            <tr>
                                <td>February</td>
                                <!-- Preview cells will be added by JS -->
                            </tr>
                            <tr class="bg-light text-muted">
                                <td colspan="12" class="text-center py-2">
                                    <i class="fas fa-ellipsis-h"></i> Remaining months omitted in preview
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div id="metricEditorContainer">
                    <!-- Will contain the structure editor -->
                </div>
                
                <div class="mt-3 text-end">
                    <button id="addColumnBtn" class="btn btn-outline-primary">
                        <i class="fas fa-plus-circle me-1"></i> Add Column
                    </button>
                </div>
                
                <div class="alert alert-warning mt-4">
                    <i class="fas fa-info-circle me-1"></i>
                    <strong>Important:</strong> After creating your outcome with the desired structure, click "Create Outcome & Proceed". 
                    The structure will be saved with the outcome.
                </div>
            <?php elseif (!$outcome_id && ($sector_id == 0 || $period_id == 0)) : ?>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-1"></i>
                    Please select a Sector and Reporting Period above, then you can define the outcome structure.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php 
// Pass data to JavaScript
$js_data = [
    'outcome_id' => $outcome_id,
    'table_name' => $table_name, // Current table name for the editor
    'data_json' => $data_json_structure, // Current structure
    'save_url' => APP_URL . '/app/api/save_outcome_json.php', // Specific API endpoint for saving outcome JSON
    'is_admin_view' => true
];
?>
<script>
    const initialMetricData = <?php echo json_encode($js_data); ?>;
</script>

<!-- Ensure outcome-editor.js is used if it's different from metric-editor.js -->
<script src="<?php echo APP_URL; ?>/assets/js/outcome-editor.js?v=<?php echo ASSET_VERSION; ?>"></script>

<?php 
require_once ROOT_PATH . 'app/views/layouts/footer.php'; 
?>
