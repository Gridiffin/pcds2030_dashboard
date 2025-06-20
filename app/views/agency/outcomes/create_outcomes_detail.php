<?php
/**
 * Create Metric Detail
 * 
 * Interface for agency users to create metric details.
 */

// Define project root path for consistent file references
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', rtrim(dirname(dirname(dirname(dirname(__DIR__)))), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

ob_start();
session_start();
require_once PROJECT_ROOT_PATH . 'app/config/config.php';
require_once PROJECT_ROOT_PATH . 'app/lib/db_connect.php';
require_once PROJECT_ROOT_PATH . 'app/lib/session.php';
require_once PROJECT_ROOT_PATH . 'app/lib/functions.php';
require_once PROJECT_ROOT_PATH . 'app/lib/agencies/index.php';
require_once PROJECT_ROOT_PATH . 'app/views/layouts/header.php';

// Add cache control headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$additionalScripts = [
    APP_URL . '/assets/js/period_selector.js',
    APP_URL . '/assets/js/agency/dashboard.js'
];

// Handle JSON POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $title = trim($input['title'] ?? '');
    $items = $input['items'] ?? [];
    $detail_id = isset($input['detail_id']) ? (int)$input['detail_id'] : null;

    $errors = [];

    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    
    if (empty($items)) {
        $errors[] = 'At least one value-description pair is required.';
    } else {
        foreach ($items as $index => $item) {
            $value = trim($item['value'] ?? '');
            $description = trim($item['description'] ?? '');
            
            if ($value === '') {
                $errors[] = "Value #" . ($index + 1) . " is required.";
            }
            if ($description === '') {
                $errors[] = "Description #" . ($index + 1) . " is required.";
            }
        }
    }

    if (!empty($errors)) {
        header('Content-Type: application/json');
        ob_end_clean();
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }    // Get the selected layout type
    $layout_type = trim($input['layout_type'] ?? 'simple');
    
    // Prepare data for insertion or update using the new format
    $detail_name = $title;
    $detail_json = json_encode([
        'layout_type' => $layout_type,
        'items' => $items
    ]);    if ($detail_id === null) {        // Insert new outcome detail
        // First check if an outcome detail with the same name already exists
        $check_stmt = $conn->prepare("SELECT detail_id FROM outcomes_details WHERE detail_name = ?");
        if (!$check_stmt) {
            $errors[] = 'Database error: ' . $conn->error;
        } else {
            $check_stmt->bind_param('s', $detail_name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors[] = 'An outcome detail with this name already exists. Please use a different name.';
                $check_stmt->close();
            } else {
                $check_stmt->close();
                
                // No duplicates found, proceed with insertion
                $stmt = $conn->prepare("INSERT INTO outcomes_details (detail_name, detail_json, is_draft) VALUES (?, ?, 0)");
                if ($stmt) {
                    $stmt->bind_param('ss', $detail_name, $detail_json);
                    if ($stmt->execute()) {
                        header('Content-Type: application/json');
                        ob_end_clean();
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Outcome detail created successfully.',
                            'action' => 'create',
                            'new_id' => $stmt->insert_id,
                            'title' => $detail_name,
                            'items' => $items
                        ]);
                        exit;
                    } else {
                        $errors[] = 'Database error: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'Database error: ' . $conn->error;
                }
            }
        }
    } else {        // Update existing outcome detail
        $stmt = $conn->prepare("UPDATE outcomes_details SET detail_name = ?, detail_json = ? WHERE detail_id = ?");
        if ($stmt) {
            $stmt->bind_param('ssi', $detail_name, $detail_json, $detail_id);
            if ($stmt->execute()) {
                header('Content-Type: application/json');
                ob_end_clean();
                echo json_encode([
                    'success' => true, 
                    'message' => 'Outcome detail updated successfully.',
                    'action' => 'update',
                    'updated_id' => $detail_id,
                    'title' => $detail_name,
                    'items' => $items
                ]);
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors[] = 'Database error: ' . $conn->error;
        }
    }

    header('Content-Type: application/json');
    ob_end_clean();
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Fetch existing outcome details for display
$result = $conn->query("SELECT detail_id, detail_name, detail_json FROM outcomes_details WHERE is_draft = 0 ORDER BY created_at DESC");
$detailsArray = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $jsonData = json_decode($row['detail_json'], true);
        
        // Handle both new and legacy format
        $items = [];
        $layout_type = 'simple'; // Default
        
        if (isset($jsonData['layout_type']) && isset($jsonData['items'])) {
            // New format
            $layout_type = $jsonData['layout_type'];
            $items = $jsonData['items'];
        } elseif (isset($jsonData['value']) && isset($jsonData['description'])) {
            // Legacy format - convert to new format
            $values = explode(';', $jsonData['value']);
            $descriptions = explode(';', $jsonData['description']);
            
            for ($i = 0; $i < count($values); $i++) {
                $items[] = [
                    'value' => $values[$i],
                    'description' => $descriptions[$i] ?? ''
                ];
            }
        }
        
        $detailsArray[] = [
            'id' => $row['detail_id'],
            'title' => $row['detail_name'],
            'layout_type' => $layout_type,
            'items' => $items,
            // Keep these fields for backward compatibility in the UI
            'value' => isset($jsonData['value']) ? $jsonData['value'] : implode(';', array_column($items, 'value')),
            'description' => isset($jsonData['description']) ? $jsonData['description'] : implode(';', array_column($items, 'description'))
        ];
    }
}
?>

<?php
// Header configuration
$header_config = [
    'title' => 'Create Outcome Detail',
    'subtitle' => 'Design and manage detailed KPI structures for outcome reporting',
    'variant' => 'white',    'actions' => [
        [
            'text' => 'Back to Outcomes',
            'url' => APP_URL . '/app/views/agency/outcomes/submit_outcomes.php',
            'class' => 'btn-outline-primary',
            'icon' => 'fas fa-arrow-left'
        ]
    ]
];
require_once '../../layouts/page_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Outcome Detail</title>
    <link href="<?php echo APP_URL; ?>/assets/css/main.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>/assets/css/components/forms.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>/assets/css/components/buttons.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>/assets/css/layout/navigation.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>/assets/css/layout/dashboard.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>/assets/css/custom/agency.css" rel="stylesheet">
</head>
<body>
    <div class="main-content">        <div class="container-fluid p-4">
            <!-- Alert Messages -->
            <div id="errorContainer" class="alert alert-danger" style="display: none;"></div>
            <div id="successContainer" class="alert alert-success" style="display: none;"></div>

            <!-- Form Section -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Outcome Detail Configuration</h5>
                </div>
                <div class="card-body">                <div class="card-body">
                    <form id="metricDetailForm" method="post" action="<?php echo view_url('$ViewType', '$currentFileName'); ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="title" class="form-label">Title</label>
                                    <input type="text" class="form-control" id="title" name="title" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="layout_type" class="form-label">Layout Type</label>
                                    <select class="form-control" id="layout_type" name="layout_type" required>
                                        <option value="simple">Simple (Default)</option>
                                        <option value="detailed_list">Detailed List</option>
                                        <option value="comparison">Comparison</option>
                                    </select>
                                    <small class="text-muted">Select the visual presentation style for this KPI.</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <h6 class="mb-2">KPI Values and Descriptions</h6>
                            <p class="text-muted small">Add the values and descriptions for this KPI. For simple layout, only the first item will be used. For detailed list, each item represents a row. For comparison layout, items are shown side by side.</p>
                        </div>
                        
                        <div id="itemsContainer">
                            <div class="item-container border rounded p-3 mb-3 position-relative" data-index="0">
                                <span class="remove-item position-absolute top-0 end-0 bg-danger text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 25px; height: 25px; cursor: pointer; margin: 5px;" onclick="removeItem(this)">×</span>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="value_0" class="form-label">Value</label>
                                            <input type="text" class="form-control" id="value_0" name="value_0" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="description_0" class="form-label">Description</label>
                                            <textarea class="form-control" id="description_0" name="description_0" rows="3" required></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-outline-secondary" onclick="addItem()">
                                <i class="fas fa-plus me-1"></i> Add Another Value
                            </button>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save me-1"></i> Create
                            </button>
                            <a href="<?php echo APP_URL; ?>/app/views/agency/outcomes/create_outcomes_detail.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Existing Details Section -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Created Outcome Details</h5>
                    <small class="text-muted">Manage your existing outcome detail configurations</small>
                </div>
                <div class="card-body">
                    <div id="metricDetailsContainer">                    <div id="metricDetailsContainer">
                        <?php if (empty($detailsArray)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-list-alt fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No outcome details found.</p>
                                <p class="small text-muted">Create your first outcome detail using the form above.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($detailsArray as $detail): ?>
                                    <div class="col-lg-6 col-xl-4 mb-4">
                                        <div class="card h-100">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <h6 class="card-title mb-0"><?= htmlspecialchars($detail['title']) ?></h6>
                                                <span class="badge bg-secondary"><?= ucfirst($detail['layout_type'] ?? 'simple') ?></span>
                                            </div>
                                            <div class="card-body">
                                                <?php
                                                $values = explode(';', $detail['value']);
                                                $descriptions = explode(';', $detail['description']);
                                                
                                                if (count($values) === 1): ?>
                                                    <div class="d-flex align-items-center gap-3">
                                                        <div class="text-primary fw-bold fs-3"><?= htmlspecialchars($values[0]) ?></div>
                                                        <div class="text-muted small"><?= htmlspecialchars($descriptions[0] ?? '') ?></div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="row">
                                                        <?php foreach ($values as $index => $val): ?>
                                                            <div class="col-6 mb-2">
                                                                <div class="text-primary fw-bold"><?= htmlspecialchars($val) ?></div>
                                                                <div class="text-muted small"><?= htmlspecialchars($descriptions[$index] ?? '') ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-footer bg-transparent">
                                                <div class="d-flex gap-2">
                                                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editMetricDetail(<?= $detail['id'] ?>)">
                                                        <i class="fas fa-edit me-1"></i> Edit
                                                    </button>
                                                    <?php if (function_exists('is_focal') && is_focal()): ?>
                                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteMetricDetail(<?= $detail['id'] ?>)">
                                                        <i class="fas fa-trash me-1"></i> Delete
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>    <script>
        // Embed detailsArray as JS object for edit lookup
        const metricDetails = <?= json_encode($detailsArray) ?>;
        let editingDetailId = null;

        // Helper function to escape HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }// Function to add a new item
        function addItem() {
            const container = document.getElementById('itemsContainer');
            const itemCount = container.children.length;
            const newIndex = itemCount;
            
            const newItem = document.createElement('div');
            newItem.className = 'item-container border rounded p-3 mb-3 position-relative';
            newItem.dataset.index = newIndex;
            
            newItem.innerHTML = `
                <span class="remove-item position-absolute top-0 end-0 bg-danger text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 25px; height: 25px; cursor: pointer; margin: 5px;" onclick="removeItem(this)">×</span>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="value_${newIndex}" class="form-label">Value</label>
                            <input type="text" class="form-control" id="value_${newIndex}" name="value_${newIndex}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="description_${newIndex}" class="form-label">Description</label>
                            <textarea class="form-control" id="description_${newIndex}" name="description_${newIndex}" rows="3" required></textarea>
                        </div>
                    </div>
                </div>
            `;
            
            container.appendChild(newItem);
        }
        
        // Function to remove an item
        function removeItem(element) {
            const container = document.getElementById('itemsContainer');
            if (container.children.length > 1) {
                element.parentElement.remove();
                // Reindex remaining items
                Array.from(container.children).forEach((child, index) => {
                    child.dataset.index = index;
                    const valueInput = child.querySelector('input[type="text"]');
                    const descriptionInput = child.querySelector('textarea');
                    valueInput.id = `value_${index}`;
                    valueInput.name = `value_${index}`;
                    descriptionInput.id = `description_${index}`;
                    descriptionInput.name = `description_${index}`;
                });
            } else {
                alert('You need at least one value-description pair.');
            }
        }        // Function to delete metric detail
        function deleteMetricDetail(id) {
            if (!confirm('Are you sure you want to delete this outcome detail? This action cannot be undone.')) {
                return;
            }
            
            const deleteBtn = document.querySelector(`button[onclick="deleteMetricDetail(${id})"]`);
            if (!deleteBtn) {
                console.error('Delete button not found');
                showAlert('Error finding delete button', 'error');
                return;
            }

            const originalText = deleteBtn.textContent;
            deleteBtn.textContent = 'Deleting...';
            deleteBtn.disabled = true;
            
            fetch(`delete_metric_detail.php?detail_id=${id}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Cache-Control': 'no-cache'
                }
            })
            .then(async response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Invalid response:', text);
                    throw new TypeError("Expected JSON response but received: " + contentType);
                }
                if (!response.ok) {
                    const text = await response.text();
                    console.error('Server response:', text);
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Remove the deleted item from the UI
                    const itemToRemove = deleteBtn.closest('li');
                    if (itemToRemove) {
                        itemToRemove.remove();
                    }
                    // Show success message
                    showAlert(data.message || 'Metric detail deleted successfully', 'success');
                    // Update UI if no items left
                    if (document.querySelectorAll('#metricDetailsContainer li').length === 0) {
                        document.getElementById('metricDetailsContainer').innerHTML = '<p>No metric details found.</p>';
                    }
                    // Also update the metricDetails array
                    const index = metricDetails.findIndex(d => d.id === id);
                    if (index !== -1) {
                        metricDetails.splice(index, 1);
                    }
                } else {
                    showAlert(data.message || 'Failed to delete metric detail', 'error');
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                showAlert('Failed to delete metric detail. Please try again.', 'error');
            })
            .finally(() => {
                deleteBtn.textContent = originalText;
                deleteBtn.disabled = false;
            });
        }

        // Function to load metric detail into form for editing
        function editMetricDetail(id) {
            const detail = metricDetails.find(d => d.id == id);
            if (!detail) {
                showAlert('Metric detail not found.', 'error');
                return;
            }
            editingDetailId = id;

            // Set title
            document.getElementById('title').value = detail.title;
            
            // Set layout type
            const layoutSelect = document.getElementById('layout_type');
            if (layoutSelect) {
                layoutSelect.value = detail.layout_type || 'simple';
            }

            // Clear existing items
            const container = document.getElementById('itemsContainer');
            container.innerHTML = '';            // Handle both legacy format and new format
            if (detail.items && Array.isArray(detail.items)) {
                // New format with items array
                detail.items.forEach((item, i) => {
                    const newItem = document.createElement('div');
                    newItem.className = 'item-container border rounded p-3 mb-3 position-relative';
                    newItem.dataset.index = i;
                    newItem.innerHTML = `
                        <span class="remove-item position-absolute top-0 end-0 bg-danger text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 25px; height: 25px; cursor: pointer; margin: 5px;" onclick="removeItem(this)">×</span>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="value_${i}" class="form-label">Value</label>
                                    <input type="text" class="form-control" id="value_${i}" name="value_${i}" required value="${escapeHtml(item.value)}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="description_${i}" class="form-label">Description</label>
                                    <textarea class="form-control" id="description_${i}" name="description_${i}" rows="3" required>${escapeHtml(item.description)}</textarea>
                                </div>
                            </div>
                        </div>
                    `;
                    container.appendChild(newItem);
                });
            } else {
                // Legacy format with value and description as semicolon-separated strings
                const values = detail.value.split(';');
                const descriptions = detail.description.split(';');

                for (let i = 0; i < values.length; i++) {
                    const newItem = document.createElement('div');
                    newItem.className = 'item-container border rounded p-3 mb-3 position-relative';
                    newItem.dataset.index = i;
                    newItem.innerHTML = `
                        <span class="remove-item position-absolute top-0 end-0 bg-danger text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 25px; height: 25px; cursor: pointer; margin: 5px;" onclick="removeItem(this)">×</span>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="value_${i}" class="form-label">Value</label>
                                    <input type="text" class="form-control" id="value_${i}" name="value_${i}" required value="${escapeHtml(values[i])}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="description_${i}" class="form-label">Description</label>
                                    <textarea class="form-control" id="description_${i}" name="description_${i}" rows="3" required>${escapeHtml(descriptions[i] || '')}</textarea>
                                </div>
                            </div>
                        </div>
                    `;
                    container.appendChild(newItem);
                }
            }            // Change submit button text and icon to Update
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-save me-1"></i> Update';
            
            // Scroll to the form
            document.getElementById('metricDetailForm').scrollIntoView({ behavior: 'smooth' });
        }        // Function to update metric detail in UI without reloading
        function updateMetricDetailInUI(id, title, items) {
            // Find the existing item in the UI
            const itemElement = document.querySelector(`button[onclick="editMetricDetail(${id})"]`)?.closest('.col-lg-6');
            
            if (!itemElement) return;
            
            // Update the title
            const titleElement = itemElement.querySelector('.card-title');
            if (titleElement) titleElement.textContent = title;
            
            // Update the card body content
            const cardBody = itemElement.querySelector('.card-body');
            if (cardBody) {
                if (items.length === 1) {
                    cardBody.innerHTML = `
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-primary fw-bold fs-3">${escapeHtml(items[0].value)}</div>
                            <div class="text-muted small">${escapeHtml(items[0].description)}</div>
                        </div>
                    `;
                } else {
                    let itemsHTML = '';
                    items.forEach(item => {
                        itemsHTML += `
                            <div class="col-6 mb-2">
                                <div class="text-primary fw-bold">${escapeHtml(item.value)}</div>
                                <div class="text-muted small">${escapeHtml(item.description)}</div>
                            </div>
                        `;
                    });
                    
                    cardBody.innerHTML = `<div class="row">${itemsHTML}</div>`;
                }
            }
            
            // Update the metricDetails array for future edits
            const detailIndex = metricDetails.findIndex(d => d.id == id);
            if (detailIndex !== -1) {
                metricDetails[detailIndex] = {
                    id: id,
                    title: title,
                    items: items,
                    value: items.map(i => i.value).join(';'),
                    description: items.map(i => i.description).join(';')
                };
            }
        }

        // Function to show alert messages
        function showAlert(message, type) {
            const container = document.getElementById(`${type}Container`);
            if (container) {
                container.textContent = message;
                container.style.display = 'block';
                setTimeout(() => {
                    container.style.display = 'none';
                }, 5000);
            } else {
                console.error(`Alert container not found for type: ${type}`);
            }
        }

        // Handle form submission
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('metricDetailForm');
            const errorContainer = document.getElementById('errorContainer');
            const successContainer = document.getElementById('successContainer');
            const submitBtn = document.getElementById('submitBtn');

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                errorContainer.style.display = 'none';
                successContainer.style.display = 'none';

                const title = form.title.value.trim();
                const items = [];
                
                // Collect all items
                const itemContainers = document.querySelectorAll('.item-container');
                itemContainers.forEach(container => {
                    const index = container.dataset.index;
                    const value = document.getElementById(`value_${index}`).value.trim();
                    const description = document.getElementById(`description_${index}`).value.trim();
                    
                    items.push({
                        value: value,
                        description: description
                    });
                });

                const data = {
                    title: title,
                    items: items
                };

                // Only include detail_id if we're editing
                if (editingDetailId !== null) {
                    data.detail_id = editingDetailId;
                }

                // Disable the submit button to prevent duplicate submissions
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';

                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
.then(result => {
    if (result.success) {
        showAlert(result.message, 'success');
        
        // Reset editingDetailId before reload
        editingDetailId = null;

        // Reload the page after create or update action
        window.location.href = window.location.href;
    } else {
        if (result.errors && result.errors.length > 0) {
            const ul = document.createElement('ul');
            result.errors.forEach(function (error) {
                const li = document.createElement('li');
                li.textContent = error;
                ul.appendChild(li);
            });
            errorContainer.innerHTML = '';
            errorContainer.appendChild(ul);
            errorContainer.style.display = 'block';
        } else {
            showAlert('An unknown error occurred.', 'error');
        }
    }
})
                .catch((error) => {
                    console.error('Fetch error:', error);
                    showAlert('Failed to submit data. Please try again.', 'error');
                })                .finally(() => {
                    // Re-enable the submit button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = editingDetailId ? '<i class="fas fa-save me-1"></i> Update' : '<i class="fas fa-save me-1"></i> Create';
                });
            });
        });
    </script>
<?php require_once PROJECT_ROOT_PATH . 'app/views/layouts/footer.php'; ?>



