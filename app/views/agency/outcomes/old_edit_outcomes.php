<?php
/**
 * Edit Outcome for Agency
 * 
 * Agency page to edit an existing outcome with a table name, dynamic columns, and monthly data.
 */

// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/agency_functions.php';
require_once ROOT_PATH . 'app/lib/audit_log.php';

// Verify user is an agency user
if (!is_agency()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Initialize variables
$message = '';
$message_type = '';

$sector_id = $_SESSION['sector_id'] ?? 0; // Use agency user's sector_id
// Use outcome_id instead of metric_id
$outcome_id = isset($_GET['outcome_id']) ? intval($_GET['outcome_id']) : 0;

if ($outcome_id === 0) {
    $_SESSION['error_message'] = 'Invalid outcome ID.';
    header('Location: submit_outcomes.php');
    exit;
}

// Load existing outcome data - don't filter by is_draft to allow editing both draft and submitted outcomes
$query = "SELECT table_name, data_json, is_draft FROM sector_outcomes_data WHERE metric_id = ? AND sector_id = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $outcome_id, $sector_id);
$stmt->execute();
$result = $stmt->get_result();

$table_name = '';
$is_outcome_draft = 1; // Default to draft
$data_array = [
    'columns' => [],
    'data' => []
];

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $table_name = $row['table_name'];
    $is_outcome_draft = $row['is_draft']; // Store the current draft status
    $data_array = json_decode($row['data_json'], true);
    if (!is_array($data_array)) {
        $data_array = ['columns' => [], 'data' => []];
    }
} else {
    // No existing data found, initialize empty structure
    $data_array = ['columns' => [], 'data' => []];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_table_name = trim($_POST['table_name'] ?? '');
    $post_data_json = $_POST['data_json'] ?? '';
    $is_draft = isset($_POST['is_draft']) ? intval($_POST['is_draft']) : 0;

    if ($post_table_name === '' || $post_data_json === '') {
        $message = 'Table name and data are required.';
        $message_type = 'danger';
    } else {
        $post_data_array = json_decode($post_data_json, true);
        if ($post_data_array === null) {
            $message = 'Invalid JSON data.';
            $message_type = 'danger';
        } else {
            // Update existing record in sector_outcomes_data
            $update_query = "UPDATE sector_outcomes_data SET table_name = ?, data_json = ?, is_draft = ? WHERE metric_id = ? AND sector_id = ?";
            $stmt_update = $conn->prepare($update_query);
            $data_json_str = json_encode($post_data_array);
            $stmt_update->bind_param("ssiii", $post_table_name, $data_json_str, $is_draft, $outcome_id, $sector_id);
            
            if ($stmt_update->execute()) {
                // Log successful outcome edit
                log_audit_action(
                    'outcome_updated',
                    "Updated outcome '{$post_table_name}' (Metric ID: {$outcome_id}) for sector {$sector_id}" . ($is_draft ? ' as draft' : ''),
                    'success',
                    $_SESSION['user_id']
                );
                
                // Redirect to view outcome details after successful save
                header('Location: view_outcome.php?outcome_id=' . $outcome_id . '&saved=1');
                exit;
            } else {
                $message = 'Error updating outcome: ' . $conn->error;
                $message_type = 'danger';
                
                // Log outcome update failure
                log_audit_action(
                    'outcome_update_failed',
                    "Failed to update outcome '{$post_table_name}' (Metric ID: {$outcome_id}) for sector {$sector_id}: " . $conn->error,
                    'failure',
                    $_SESSION['user_id']
                );
            }
        }
    }
}

// Add CSS references
$additionalStyles = [
    APP_URL . '/assets/css/custom/metric-create.css'
];
$additionalScripts = [
    // Removed conflicting metric-editor.js - using embedded JavaScript instead
];

// Include header and agency navigation
require_once '../../layouts/header.php';

// Configure modern page header
$header_config = [
    'title' => 'Edit Outcome',
    'subtitle' => 'Edit an existing outcome with monthly data' . ($is_outcome_draft ? ' (Draft)' : ' (Submitted)'),
    'variant' => 'white',
    'actions' => [
        [
            'url' => 'submit_outcomes.php',
            'text' => 'Back to Submit Outcomes',
            'icon' => 'fas fa-arrow-left',
            'class' => 'btn-outline-primary'
        ],
        [
            'html' => '<span class="badge ' . ($is_outcome_draft ? 'bg-warning text-dark' : 'bg-success') . '"><i class="fas ' . ($is_outcome_draft ? 'fa-edit' : 'fa-check') . ' me-1"></i>' . ($is_outcome_draft ? 'Draft' : 'Submitted') . '</span>'
        ]
    ]
];

// Include modern page header
require_once '../../layouts/page_header.php';
?>

<div class="container-fluid px-4 py-4">
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title m-0">Edit Outcome</h5>
        </div>
        <div class="card-body">
            <form id="editOutcomeForm" method="post" action="">
                <div class="mb-3">
                    <label for="tableNameInput" class="form-label">Table Name</label>
                    <input type="text" class="form-control" id="tableNameInput" name="table_name" required value="<?= htmlspecialchars($table_name) ?>" />
                </div>

                <div class="mb-3">
                    <button type="button" class="btn btn-primary" id="addColumnBtn">
                        <i class="fas fa-plus me-1"></i> Add Column
                    </button>
                    <button type="button" class="btn btn-primary ms-2" id="addRowBtn">
                        <i class="fas fa-plus me-1"></i> Add Row
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover metrics-table">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 150px;">Row</th>
                                <!-- Dynamic columns will be added here -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get row labels from existing data or use default if no data exists
                            $row_labels = [];
                            if (!empty($data_array['data']) && is_array($data_array['data'])) {
                                $row_labels = array_keys($data_array['data']);
                            }
                            
                            // If no existing data, provide a default structure that can be modified
                            if (empty($row_labels)) {
                                $row_labels = ['Row 1', 'Row 2', 'Row 3']; // Default starting rows
                            }
                            
                            foreach ($row_labels as $row_label): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="row-badge editable-hint" contenteditable="true" data-row="<?= htmlspecialchars($row_label) ?>"><?= htmlspecialchars($row_label) ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-danger delete-row-btn ms-2" data-row="<?= htmlspecialchars($row_label) ?>" title="Delete row">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <!-- Dynamic cells will be added here -->
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <input type="hidden" name="data_json" id="dataJsonInput" />

                <div class="mt-3">
                    <input type="hidden" name="is_draft" id="isDraftInput" value="0" />
                    <button type="submit" class="btn btn-success" id="saveBtn">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                    <a href="submit_outcomes.php" class="btn btn-secondary ms-2">
                        <i class="fas fa-times me-1"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="unsavedChangesAlert" class="alert alert-warning alert-dismissible fade show" role="alert" style="display:none;">
        <strong>Warning:</strong> You have unsaved changes. Please click <b>Save Changes</b> to apply your edits.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>

<script>
    // JavaScript to handle dynamic columns and data collection

    // Remove: const rowLabels = ...
    // let rowLabels = ...
    // Instead, always use Object.keys(data)
    let columns = <?= json_encode($data_array['columns'] ?? []) ?>;
    let data = <?= json_encode($data_array['data'] ?? []) ?>;
    
    // Helper to get current row labels
    function getRowLabels() {
        return Object.keys(data);
    }
    
    // Initialize table with loaded data
    
    function addRow() {
        const rowName = prompt('Enter row name:');
        if (rowName && rowName.trim() !== '') {
            const trimmedName = rowName.trim();
            if (data[trimmedName] !== undefined) {
                alert('Row already exists!');
                return;
            }
            // Initialize data for this row with all existing columns
            data[trimmedName] = {};
            columns.forEach(col => {
                data[trimmedName][col] = 0;
            });
            renderTable();
            showUnsavedAlert();
        }
    }

    function removeRow(rowName) {
        if (getRowLabels().length <= 1) {
            alert('Cannot delete the last row. At least one row is required.');
            return;
        }
        if (data[rowName] !== undefined) {
            delete data[rowName];
            renderTable(true);
            showUnsavedAlert();
        }
    }

    function addColumn() {
        // Show enhanced input modal instead of basic prompt
        const columnName = prompt('Enter column title:', 'New Column');
        if (!columnName || columnName.trim() === '') return;
        
        const trimmedName = columnName.trim();
        
        if (columns.includes(trimmedName)) {
            alert('Column title already exists. Please choose a different name.');
            return;
        }
        
        // Show loading state
        const addBtn = document.getElementById('addColumnBtn');
        const originalText = addBtn.innerHTML;
        addBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Adding...';
        addBtn.disabled = true;
        
        // Collect current data from DOM before adding column
        collectCurrentData();
        
        // Add column to array
        columns.push(trimmedName);
        // Add new column to all rows
        getRowLabels().forEach(rowLabel => {
            if (!data[rowLabel]) data[rowLabel] = {};
            data[rowLabel][trimmedName] = 0;
        });
        // Re-render table with new structure (preserve existing data)
        renderTable();
        
        // Reset button state
        setTimeout(() => {
            addBtn.innerHTML = originalText;
            addBtn.disabled = false;
        }, 500);
        
        // Column added successfully
        showUnsavedAlert();
    }

    function removeColumn(columnName) {
        // Show loading state on table
        const table = document.querySelector('.metrics-table');
        table.classList.add('table-loading');
        // Remove column from array
        columns = columns.filter(c => c !== columnName);
        // Remove data for the deleted column from all rows
        getRowLabels().forEach(rowLabel => {
            if (data[rowLabel]) {
                delete data[rowLabel][columnName];
            }
        });
        renderTable(true); // Skip collectCurrentData to avoid restoring deleted column
        setTimeout(() => {
            table.classList.remove('table-loading');
        }, 300);
        showUnsavedAlert();
    }

    function collectCurrentData() {
        // Collect data from table DOM elements
        const rowElements = document.querySelectorAll('.metrics-table tbody tr');
        const currentData = {};
        
        rowElements.forEach(row => {
            const rowBadge = row.querySelector('.row-badge');
            if (rowBadge) {
                const rowLabel = rowBadge.textContent.trim();
                currentData[rowLabel] = {};
                
                columns.forEach(col => {
                    const cell = row.querySelector(`.metric-cell[data-row="${rowLabel}"][data-column="${col}"]`);
                    if (cell) {
                        let val = parseFloat(cell.textContent.trim());
                        if (isNaN(val)) val = 0;
                        currentData[rowLabel][col] = val;
                    }
                });
            }
        });
        
        // Update the global data object
        data = currentData;
    }

    function renderTable(skipDataCollection = false) {
        // Only collect current data if this is not the initial render
        if (!skipDataCollection) {
            collectCurrentData();
        }
        
        const theadRow = document.querySelector('.metrics-table thead tr');
        // Remove all columns except the first (Month)
        while (theadRow.children.length > 1) {
            theadRow.removeChild(theadRow.lastChild);
        }
        
        // Add column headers with enhanced styling and edit functionality
        columns.forEach(col => {
            const th = document.createElement('th');
            th.classList.add('position-relative');
            th.innerHTML = `
                <div class="metric-header">
                    <div class="metric-title editable-hint" contenteditable="true" data-column="${col}">${col}</div>
                    <div class="metric-actions">
                        <button type="button" class="btn btn-sm btn-danger delete-column-btn" data-column="${col}" title="Delete column">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>`;
            theadRow.appendChild(th);
        });

        // Rebuild table body with preserved data
        const tbody = document.querySelector('.metrics-table tbody');
        tbody.innerHTML = ''; // Clear all rows
        
        // Create rows dynamically from data object
        getRowLabels().forEach(rowLabel => {
            const tr = document.createElement('tr');
            
            // Create row header cell with editable name and delete button
            const rowHeaderTd = document.createElement('td');
            rowHeaderTd.innerHTML = `
                <div class="d-flex align-items-center justify-content-between">
                    <span class="row-badge editable-hint" contenteditable="true" data-row="${rowLabel}">${rowLabel}</span>
                    <button type="button" class="btn btn-sm btn-outline-danger delete-row-btn ms-2" data-row="${rowLabel}" title="Delete row">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>`;
            tr.appendChild(rowHeaderTd);
            
            // Create data cells for each column
            columns.forEach(col => {
                const cellValue = (data[rowLabel] && data[rowLabel][col] !== undefined) ? data[rowLabel][col] : '';
                const td = document.createElement('td');
                td.innerHTML = `<div class="metric-cell editable-hint" contenteditable="true" data-column="${col}" data-row="${rowLabel}">${cellValue}</div>`;
                tr.appendChild(td);
            });
            
            tbody.appendChild(tr);
        });

        // Reattach all event handlers
        attachEventHandlers();
    }

    function attachEventHandlers() {
        // Delete row button handlers
        document.querySelectorAll('.delete-row-btn').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const row = btn.getAttribute('data-row');
                console.log('Delete row clicked:', row); // Debug output
                if (confirm(`Delete row "${row}"? This action cannot be undone.`)) {
                    removeRow(row);
                }
            };
        });

        // Row title edit handlers
        document.querySelectorAll('.row-badge').forEach(el => {
            // Remove existing listeners first
            el.removeEventListener('input', handleRowTitleEdit);
            el.removeEventListener('blur', handleRowTitleBlur);
            el.removeEventListener('keydown', handleRowTitleKeydown);
            
            // Add new listeners
            el.addEventListener('input', handleRowTitleEdit);
            el.addEventListener('blur', handleRowTitleBlur);
            el.addEventListener('keydown', handleRowTitleKeydown);
        });

        // Delete column button handlers
        document.querySelectorAll('.delete-column-btn').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const col = btn.getAttribute('data-column');
                console.log('Delete column clicked:', col); // Debug output
                if (confirm(`Delete column "${col}"? This action cannot be undone.`)) {
                    removeColumn(col);
                }
            };
        });

        // Column title edit handlers
        document.querySelectorAll('.metric-title').forEach(el => {
            // Remove existing listeners first
            el.removeEventListener('input', handleColumnTitleEdit);
            el.removeEventListener('blur', handleColumnTitleBlur);
            el.removeEventListener('keydown', handleColumnTitleKeydown);
            
            // Add new listeners
            el.addEventListener('input', handleColumnTitleEdit);
            el.addEventListener('blur', handleColumnTitleBlur);
            el.addEventListener('keydown', handleColumnTitleKeydown);
        });

        // Data cell edit handlers
        document.querySelectorAll('.metric-cell').forEach(cell => {
            // Remove existing listeners first
            cell.removeEventListener('input', handleDataCellEdit);
            cell.removeEventListener('blur', handleDataCellBlur);
            
            // Add new listeners
            cell.addEventListener('input', handleDataCellEdit);
            cell.addEventListener('blur', handleDataCellBlur);
        });

        // Make header cells clickable (excluding delete button area)
        document.querySelectorAll('.metrics-table thead th').forEach(th => {
            th.style.cursor = 'text';
            th.addEventListener('click', (e) => {
                if (e.target.closest('.delete-column-btn')) return;
                const editableDiv = th.querySelector('.metric-title');
                if (editableDiv) {
                    editableDiv.focus();
                    selectAllText(editableDiv);
                }
            });
        });

        // Make data cells clickable
        document.querySelectorAll('.metrics-table tbody td').forEach(td => {
            td.style.cursor = 'text';
            td.addEventListener('click', (e) => {
                if (e.target.classList.contains('metric-cell')) return;
                const editableDiv = td.querySelector('.metric-cell');
                if (editableDiv) {
                    editableDiv.focus();
                    selectAllText(editableDiv);
                }
            });
        });
    }

    function handleRowTitleEdit() {
        const oldRow = this.getAttribute('data-row');
        const newRow = this.textContent.trim();
        
        if (newRow !== oldRow && newRow !== '') {
            if (data[newRow] !== undefined) {
                alert('Row name already exists!');
                this.textContent = oldRow;
                return;
            }
            
            // Update data object keys
            if (data[oldRow] !== undefined) {
                data[newRow] = data[oldRow];
                delete data[oldRow];
                
                // Update the data attribute
                this.setAttribute('data-row', newRow);
                
                // Update all related DOM elements
                const row = this.closest('tr');
                const deleteBtn = row.querySelector('.delete-row-btn');
                if (deleteBtn) {
                    deleteBtn.setAttribute('data-row', newRow);
                }
                
                // Update all metric cells in this row
                const metricCells = row.querySelectorAll('.metric-cell');
                metricCells.forEach(cell => {
                    cell.setAttribute('data-row', newRow);
                });
            }
        }
    }

    function handleRowTitleBlur() {
        // Validate row name
        const rowName = this.textContent.trim();
        if (rowName === '') {
            const oldRow = this.getAttribute('data-row');
            this.textContent = oldRow;
        }
    }

    function handleRowTitleKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            this.blur();
        }
    }

    function handleColumnTitleEdit() {
        const oldCol = this.getAttribute('data-column');
        const newCol = this.textContent.trim();
        
        if (newCol && newCol !== oldCol) {
            if (columns.includes(newCol)) {
                alert('Column title already exists.');
                this.textContent = oldCol;
                return;
            }
            
            // Update column name in array
            const index = columns.indexOf(oldCol);
            if (index !== -1) {
                columns[index] = newCol;
                this.setAttribute('data-column', newCol);
                
                // Update data object with new column name
                getRowLabels().forEach(rowLabel => {
                    if (data[rowLabel] && data[rowLabel][oldCol] !== undefined) {
                        data[rowLabel][newCol] = data[rowLabel][oldCol];
                        delete data[rowLabel][oldCol];
                    }
                });
                
                // Update all cells data-column attribute
                document.querySelectorAll(`[data-column="${oldCol}"]`).forEach(cell => {
                    cell.setAttribute('data-column', newCol);
                });
                
                // Update delete button
                const deleteBtn = this.closest('th').querySelector('.delete-column-btn');
                if (deleteBtn) {
                    deleteBtn.setAttribute('data-column', newCol);
                }
                
                // Column renamed successfully
            }
        }
    }

    function handleColumnTitleBlur() {
        // Ensure the column name is not empty
        if (!this.textContent.trim()) {
            this.textContent = this.getAttribute('data-column');
        }
    }

    function handleColumnTitleKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            this.blur();
        }
        if (e.key === 'Escape') {
            this.textContent = this.getAttribute('data-column');
            this.blur();
        }
    }

    function handleDataCellEdit() {
        const rowLabel = this.getAttribute('data-row');
        const column = this.getAttribute('data-column');
        
        if (rowLabel && column) {
            if (!data[rowLabel]) {
                data[rowLabel] = {};
            }
            let val = parseFloat(this.textContent.trim());
            if (isNaN(val)) val = 0;
            data[rowLabel][column] = val;
        }
        showUnsavedAlert();
    }

    function handleDataCellBlur() {
        // Format the number for display
        const value = parseFloat(this.textContent.trim());
        if (!isNaN(value)) {
            this.textContent = value.toString();
        } else {
            this.textContent = '0';
        }
        
        // Trigger data update
        handleDataCellEdit.call(this);
    }

    function selectAllText(element) {
        const range = document.createRange();
        range.selectNodeContents(element);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    // Initialize event handlers for add column and add row buttons
    document.getElementById('addColumnBtn').addEventListener('click', addColumn);
    document.getElementById('addRowBtn').addEventListener('click', addRow);

    // Handle button clicks to set draft status
    document.getElementById('saveBtn').addEventListener('click', function(e) {
        document.getElementById('isDraftInput').value = '0';
        // Save as final outcome clicked
    });

    // Handle form submission
    document.getElementById('editOutcomeForm').addEventListener('submit', function(e) {
        // Form submission started
        
        // Collect any final changes from DOM before submission
        collectCurrentData();
        
        // Use the maintained data object
        const collectedData = {
            columns: columns,
            data: data
        };
        
        // Data collected for submission
        document.getElementById('dataJsonInput').value = JSON.stringify(collectedData);
        
        // Basic validation
        const tableName = document.getElementById('tableNameInput').value.trim();
        if (!tableName) {
            e.preventDefault();
            alert('Please enter a table name.');
            return false;
        }
        
        if (columns.length === 0) {
            e.preventDefault();
            alert('Please add at least one column.');
            return false;
        }
        
        if (Object.keys(data).length === 0) {
            e.preventDefault();
            alert('Please add at least one row.');
            return false;
        }
        
        hideUnsavedAlert();
        // Form validation passed, submitting
    });

    function showUnsavedAlert() {
        const alertDiv = document.getElementById('unsavedChangesAlert');
        if (alertDiv) alertDiv.style.display = '';
    }
    function hideUnsavedAlert() {
        const alertDiv = document.getElementById('unsavedChangesAlert');
        if (alertDiv) alertDiv.style.display = 'none';
    }

    // Initial render when page loads
    document.addEventListener('DOMContentLoaded', () => {
        // Prevent conflicting edit-outcome.js from interfering
        window.editOutcomeJsDisabled = true;
        
        // Initial render - don't collect data from empty DOM, use loaded data
        renderTable(true);
        
        // Remove any duplicate save buttons that might be auto-generated
        const duplicateSaveBtn = document.getElementById('saveOutcomeBtn');
        if (duplicateSaveBtn) {
            // Remove duplicate save button from header
            duplicateSaveBtn.remove();
        }
    });
</script>

<?php
// Include footer
require_once '../../layouts/footer.php';
?>
