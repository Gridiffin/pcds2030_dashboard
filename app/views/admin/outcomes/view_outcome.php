<?php
/**
 * View Submitted Outcome Details for Admin
 * 
 * Allows admin users to view the details of submitted outcomes from any sector.
 */

// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php'; // Contains legacy functions
require_once ROOT_PATH . 'app/lib/admins/outcomes.php'; // Contains updated outcome functions
require_once ROOT_PATH . 'app/lib/admins/index.php'; // Contains is_admin
require_once ROOT_PATH . 'app/lib/admins/users.php'; // Contains user information functions
require_once ROOT_PATH . 'app/lib/status_helpers.php'; // For display_submission_status_badge
require_once ROOT_PATH . 'app/lib/rating_helpers.php'; // For display_overall_rating_badge

// Verify user is an admin
if (!is_admin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Check if metric_id is provided (the system uses metric_id as primary identifier)
if (!isset($_GET['metric_id']) || !is_numeric($_GET['metric_id'])) {
    $_SESSION['error_message'] = 'Invalid outcome ID.';
    header('Location: manage_outcomes.php');
    exit;
}

$metric_id = (int) $_GET['metric_id'];

// Get outcome data using the updated function with improved error handling
$outcome_details = get_outcome_data_for_display($metric_id);

if (!$outcome_details) {
    $_SESSION['error_message'] = 'Outcome not found.';
    header('Location: manage_outcomes.php');
    exit;
}

$table_name = $outcome_details['table_name'];
$period_id = $outcome_details['period_id'];
$year = $outcome_details['year'] ?? 'N/A';
$quarter = $outcome_details['quarter'] ?? 'N/A';
$status = $outcome_details['status'] ?? 'submitted'; // Default to submitted if not present
$overall_rating = $outcome_details['overall_rating'] ?? null;

$created_at = new DateTime($outcome_details['created_at']);
$updated_at = new DateTime($outcome_details['updated_at']);

// Get flexible structure configuration (updated to match agency side format)
// All outcomes now use flexible format

// Parse the outcome data (new flexible format)
$outcome_data = json_decode($outcome_details['data_json'] ?? '{}', true) ?? [];

// Check if data is in new flexible format
$is_flexible = isset($outcome_data['columns']) && isset($outcome_data['data']);

if ($is_flexible) {
    // New flexible structure (same as agency side)
    $columns = $outcome_data['columns'] ?? [];
    $data = $outcome_data['data'] ?? [];
    
    // Get row labels from the data
    $row_labels = [];
    if (!empty($data) && is_array($data)) {
        $row_labels = array_keys($data);
    }
    
    // Convert to admin display format for compatibility
    $rows = array_map(function($row_label) {
        return ['id' => $row_label, 'label' => $row_label, 'type' => 'data'];
    }, $row_labels);
    
    $columns_formatted = array_map(function($col) {
        return ['id' => $col, 'label' => $col, 'type' => 'number', 'unit' => ''];
    }, $columns);
    
    // Organize data for display (compatible with existing admin template)
    $table_data = [];
    foreach ($row_labels as $row_label) {
        $row_data = ['row' => ['id' => $row_label, 'label' => $row_label, 'type' => 'data'], 'metrics' => []];
        
        // Add data for each column in this row
        if (isset($data[$row_label])) {
            $row_data['metrics'] = $data[$row_label];
        }
        
        $table_data[] = $row_data;
    }
} else {
    // Legacy fallback - shouldn't happen after migration
    $outcome_metrics_data = $outcome_details['parsed_data'] ?? [];
    if (empty($outcome_metrics_data) && !empty($outcome_details['data_json'])) {
        $outcome_metrics_data = json_decode($outcome_details['data_json'], true) ?? [];
    }
    
    $metric_names = $outcome_metrics_data['columns'] ?? [];
    
    // Create default monthly rows
    $month_names = ['January', 'February', 'March', 'April', 'May', 'June', 
                    'July', 'August', 'September', 'October', 'November', 'December'];
    $rows = array_map(function($month) {
        return ['id' => $month, 'label' => $month, 'type' => 'data'];
    }, $month_names);
    
    $columns_formatted = array_map(function($col) {
        return ['id' => $col, 'label' => $col, 'type' => 'number', 'unit' => ''];
    }, $metric_names);
    
    $table_data = [];
    // Legacy data organization would go here
}

// For backward compatibility with existing admin template
$columns = $columns_formatted ?? [];

// Add CSS references (if any specific to outcome viewing)
$additionalStyles = [
    // APP_URL . '/assets/css/custom/outcome-view.css' // Example
];

// Include header
require_once ROOT_PATH . 'app/views/layouts/header.php';

// Configure modern page header
$header_config = [
    'title' => 'View Outcome Details',
    'subtitle' => 'Review outcome data and metrics',
    'variant' => 'white',
    'actions' => [
        [
            'url' => 'manage_outcomes.php',
            'text' => 'Back to Manage Outcomes',
            'icon' => 'fas fa-arrow-left',
            'class' => 'btn-outline-primary'
        ],
        [
            'url' => 'outcome_history.php?metric_id=' . $metric_id,
            'text' => 'View History',
            'icon' => 'fas fa-history',
            'class' => 'btn-outline-info'
        ],
        [
            'url' => 'edit_outcome.php?metric_id=' . $metric_id,
            'text' => 'Edit Outcome',
            'icon' => 'fas fa-edit',
            'class' => 'btn-primary'
        ]
    ]
];

// Include modern page header
require_once '../../layouts/page_header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="card mb-4 admin-card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="card-title m-0">
                <i class="fas fa-bullseye me-2"></i><?= is_array($table_name) ? implode(', ', $table_name) : htmlspecialchars($table_name) ?>
            </h5>
        </div>        <!-- Tab Navigation -->
        <ul class="nav nav-tabs" id="outcomeDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="table-tab" data-bs-toggle="tab" data-bs-target="#table-view" 
                    type="button" role="tab" aria-controls="table-view" aria-selected="true">
                    <i class="fas fa-table me-1"></i> Table View
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="structure-tab" data-bs-toggle="tab" data-bs-target="#structure-view" 
                    type="button" role="tab" aria-controls="structure-view" aria-selected="false">
                    <i class="fas fa-cogs me-1"></i> Structure Info
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="chart-tab" data-bs-toggle="tab" data-bs-target="#chart-view" 
                    type="button" role="tab" aria-controls="chart-view" aria-selected="false">
                    <i class="fas fa-chart-line me-1"></i> Chart View
                </button>
            </li>
        </ul>
        
        <!-- Tab Content -->
        <div class="tab-content" id="outcomeTabsContent">
            <!-- Table View Tab -->
            <div class="tab-pane fade show active" id="table-view" role="tabpanel" aria-labelledby="table-tab">
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <p><strong>Outcome ID:</strong> <?= is_array($metric_id) ? implode(', ', $metric_id) : htmlspecialchars($metric_id) ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Created:</strong> <?= $created_at->format('F j, Y, g:i A') ?></p>
                            <p><strong>Last Updated:</strong> <?= $updated_at->format('F j, Y, g:i A') ?></p>
                            <?php 
                            // Only show submitted by if available
                            $submitted_by = $outcome_details['submitted_by_username'] ?? null;
                            if (!empty($submitted_by)): 
                            ?>
                                <p><strong>Submitted By:</strong> <?= is_array($submitted_by) ? implode(', ', $submitted_by) : htmlspecialchars($submitted_by) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($columns) && $is_flexible): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover data-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 150px;">Row</th>
                                    <?php foreach ($columns as $column): ?>
                                        <th class="text-center">
                                            <?= is_array($column['label']) ? implode(', ', $column['label']) : htmlspecialchars($column['label']) ?>
                                            <?php if (!empty($column['unit'])): ?>
                                                <span class="text-muted small">(<?= is_array($column['unit']) ? implode(', ', $column['unit']) : htmlspecialchars($column['unit']) ?>)</span>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($row_labels as $row_label): ?>
                                    <tr>
                                        <td>
                                            <span class="month-badge"><?= is_array($row_label) ? implode(', ', $row_label) : htmlspecialchars($row_label) ?></span>
                                        </td>
                                        <?php foreach ($columns as $column): ?>
                                            <td class="text-end">
                                                <?php 
                                                $value = $data[$row_label][$column['id']] ?? 0;
                                                // Handle empty strings and non-numeric values safely
                                                if (is_numeric($value) && $value !== '') {
                                                    echo number_format((float)$value, 2);
                                                } else {
                                                    echo '0.00';
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <!-- Total Row -->
                                <tr class="table-light fw-bold">
                                    <td><span class="total-badge">TOTAL</span></td>
                                    <?php foreach ($columns as $column): ?>
                                        <td class="text-end">
                                            <?php
                                            $total = 0;
                                            foreach ($row_labels as $row_label) {
                                                $cell_value = $data[$row_label][$column['id']] ?? 0;
                                                // Only add numeric values to total
                                                if (is_numeric($cell_value) && $cell_value !== '') {
                                                    $total += (float)$cell_value;
                                                }
                                            }
                                            echo number_format($total, 2);
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif (!empty($columns)): ?>
                    <!-- Legacy format display (fallback) -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover data-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 150px;">Month</th>
                                    <?php foreach ($columns as $column): ?>
                                        <th>
                                            <?= is_array($column['label']) ? implode(', ', $column['label']) : htmlspecialchars($column['label']) ?>
                                            <?php if (!empty($column['unit'])): ?>
                                                <span class="text-muted small">(<?= is_array($column['unit']) ? implode(', ', $column['unit']) : htmlspecialchars($column['unit']) ?>)</span>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($table_data as $row_data): ?>
                                    <tr>
                                        <td>
                                            <span class="month-badge"><?= is_array($row_data['row']['label']) ? implode(', ', $row_data['row']['label']) : htmlspecialchars($row_data['row']['label']) ?></span>
                                        </td>
                                        <?php foreach ($columns as $col_idx => $column): ?>
                                            <td class="text-end">
                                                <?= isset($row_data['metrics'][$col_idx]) && $row_data['metrics'][$col_idx] !== null && $row_data['metrics'][$col_idx] !== '' ? number_format((float)$row_data['metrics'][$col_idx], 2) : '—' ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <!-- Optional: Total Row (calculate if needed) -->
                                <tr class="table-light fw-bold">
                                    <td><span class="total-badge">TOTAL</span></td>
                                    <?php foreach ($columns as $col_idx => $column): 
                                        $total = 0;
                                        foreach ($table_data as $row_data) {
                                            if (isset($row_data['metrics'][$col_idx]) && is_numeric($row_data['metrics'][$col_idx])) {
                                                $total += (float)$row_data['metrics'][$col_idx];
                                            }
                                        }
                                    ?>
                                        <td class="text-end"><?= number_format($total, 2) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="alert alert-info">No metrics defined for this outcome structure.</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Structure Info Tab -->
            <div class="tab-pane fade" id="structure-view" role="tabpanel" aria-labelledby="structure-tab">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">
                                <i class="fas fa-list me-2"></i>Row Configuration
                            </h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Label</th>
                                            <th>Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?= is_array($row['label']) ? implode(', ', $row['label']) : htmlspecialchars($row['label']) ?></td>
                                            <td>
                                                <span class="badge bg-secondary"><?= is_array($row['type']) ? implode(', ', $row['type']) : htmlspecialchars($row['type']) ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-success">
                                <i class="fas fa-columns me-2"></i>Column Configuration
                            </h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Label</th>
                                            <th>Type</th>
                                            <th>Unit</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($columns as $column): ?>
                                        <tr>
                                            <td><?= is_array($column['label']) ? implode(', ', $column['label']) : htmlspecialchars($column['label']) ?></td>
                                            <td>
                                                <span class="badge bg-info"><?= is_array($column['type']) ? implode(', ', $column['type']) : htmlspecialchars($column['type']) ?></span>
                                            </td>
                                            <td><?= is_array($column['unit']) ? implode(', ', $column['unit']) : htmlspecialchars($column['unit'] ?? '') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart View Tab -->
            <div class="tab-pane fade" id="chart-view" role="tabpanel" aria-labelledby="chart-tab">
                <div class="card-body">
                    <!-- Chart options -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="chartTypeSelect" class="form-label">Chart Type</label>
                                <select class="form-select" id="chartTypeSelect">
                                    <option value="line">Line Chart</option>
                                    <option value="bar">Bar Chart</option>
                                    <option value="area">Area Chart</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="chartColumnSelect" class="form-label">Select Data Series</label>
                                <select class="form-select" id="chartColumnSelect" multiple>
                                    <?php foreach ($columns as $column): ?>
                                        <option value="<?= is_array($column['id']) ? implode(', ', $column['id']) : htmlspecialchars($column['id']) ?>" selected>
                                            <?= is_array($column['label']) ? implode(', ', $column['label']) : htmlspecialchars($column['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-center">
                            <div class="mt-4">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="cumulativeView">
                                    <label class="form-check-label" for="cumulativeView">
                                        Cumulative View
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-center justify-content-end">
                            <div class="btn-group mt-4" role="group">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="downloadChartImage" title="Download Chart">
                                    <i class="fas fa-image"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="downloadDataCSV" title="Download CSV">
                                    <i class="fas fa-download"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Chart Canvas - Simple Approach -->
                    <div style="width: 100%; height: 800px; margin: 20px 0;">
                        <canvas id="metricChart" style="width: 100%; height: 100%;"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pass data to JavaScript (same format as agency side) -->
<script>
// Prepare data for chart in a simple, compatible format
window.tableData = <?= json_encode($data ?? []) ?>;
window.tableColumns = <?= json_encode(array_column($columns, 'id')) ?>;
window.tableRows = <?= json_encode($row_labels ?? []) ?>;

// Additional data for context
const outcomeInfo = {
    id: <?= $metric_id ?>,
    tableName: <?= json_encode($table_name) ?>,
    hasData: <?= json_encode($is_flexible && !empty($columns) && !empty($row_labels)) ?>
};

// Initialize chart functionality if Chart.js is available (same as agency side)
function initializeChart() {
    if (typeof Chart !== 'undefined' && window.tableData && window.tableColumns && window.tableRows) {
        // Get the canvas element
        const canvas = document.getElementById('metricChart');
        
        if (canvas && Object.keys(window.tableData).length > 0) {
            // Clear any previous chart
            if (window.currentChart) {
                window.currentChart.destroy();
            }
            
            // Get chart settings from form inputs
            const chartType = document.getElementById('chartType') ? document.getElementById('chartType').value : 'bar';
            const cumulativeView = document.getElementById('cumulativeView') ? document.getElementById('cumulativeView').checked : false;
            
            // Create chart data - ensure all values are numeric
            const labels = window.tableRows;
            const datasets = window.tableColumns.map((column, index) => {
                let data = labels.map(row => {
                    const cellValue = window.tableData[row] ? window.tableData[row][column] : null;
                    // Convert to number, handle empty strings and null values
                    let numericValue = 0;
                    if (cellValue !== null && cellValue !== '' && cellValue !== undefined) {
                        numericValue = parseFloat(cellValue) || 0;
                    }
                    return numericValue;
                });
                
                // Apply cumulative calculation if enabled
                if (cumulativeView) {
                    const cumulativeData = [];
                    let runningTotal = 0;
                    for (let i = 0; i < data.length; i++) {
                        runningTotal += data[i];
                        cumulativeData.push(runningTotal);
                    }
                    data = cumulativeData;
                }
                
                const colors = [
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(255, 99, 132, 0.8)',
                    'rgba(75, 192, 192, 0.8)',
                    'rgba(255, 205, 86, 0.8)',
                    'rgba(153, 102, 255, 0.8)',
                    'rgba(255, 159, 64, 0.8)'
                ];
                
                return {
                    label: column + (cumulativeView ? ' (Cumulative)' : ''),
                    data: data,
                    backgroundColor: colors[index % colors.length],
                    borderColor: colors[index % colors.length].replace('0.8', '1'),
                    borderWidth: 2,
                    fill: chartType === 'area'
                };
            });
            
            // Create chart with basic configuration
            window.currentChart = new Chart(canvas, {
                type: chartType === 'area' ? 'line' : chartType,
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    devicePixelRatio: window.devicePixelRatio || 1,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: {
                                    size: 14,
                                    weight: '600'
                                },
                                // Format large numbers (e.g., financial data)
                                callback: function(value) {
                                    if (value >= 1000000000) {
                                        return 'RM ' + (value / 1000000000).toFixed(1) + 'B';
                                    } else if (value >= 1000000) {
                                        return 'RM ' + (value / 1000000).toFixed(1) + 'M';
                                    } else if (value >= 1000) {
                                        return 'RM ' + (value / 1000).toFixed(1) + 'K';
                                    } else {
                                        return 'RM ' + value.toFixed(2);
                                    }
                                }
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 14,
                                    weight: '600'
                                },
                                maxRotation: 45,
                                minRotation: 0
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Outcome Data Visualization' + (cumulativeView ? ' (Cumulative View)' : ''),
                            font: {
                                size: 20,
                                weight: 'bold',
                                family: "'Segoe UI', 'Helvetica', 'Arial', sans-serif"
                            }
                        },
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: {
                                    size: 16,
                                    weight: '600'
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    const formattedValue = new Intl.NumberFormat('en-MY', {
                                        style: 'currency',
                                        currency: 'MYR',
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }).format(value);
                                    return context.dataset.label + ': ' + formattedValue;
                                }
                            }
                        }
                    }
                }
            });
        }
    }
}

// Initialize everything when the page loads
document.addEventListener('DOMContentLoaded', function() {
    // Simple setup for chart initialization on tab switch
    const chartTab = document.getElementById('chart-tab');
    if (chartTab) {
        chartTab.addEventListener('shown.bs.tab', function() {
            console.log('Chart tab activated, initializing chart');
            setTimeout(initializeChart, 100);
        });
    }
    
    // Check if chart tab is initially active
    const chartView = document.getElementById('chart-view');
    if (chartView && chartView.classList.contains('active')) {
        console.log('Chart tab is initially active, initializing chart');
        setTimeout(initializeChart, 100);
    }
    
    // Chart type change handler
    const chartTypeSelect = document.getElementById('chartType');
    if (chartTypeSelect) {
        chartTypeSelect.addEventListener('change', function() {
            initializeChart();
        });
    }
    
    // Cumulative view toggle handler
    const cumulativeToggle = document.getElementById('cumulativeView');
    if (cumulativeToggle) {
        cumulativeToggle.addEventListener('change', function() {
            initializeChart();
        });
    }
    
    // Download chart image handler
    const downloadImageBtn = document.getElementById('downloadChartImage');
    if (downloadImageBtn) {
        downloadImageBtn.addEventListener('click', function() {
            if (window.currentChart) {
                const url = window.currentChart.toBase64Image();
                const link = document.createElement('a');
                link.download = 'outcome-chart.png';
                link.href = url;
                link.click();
            }
        });
    }

    // Download CSV handler
    const downloadBtn = document.getElementById('downloadDataCSV');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', function() {
            if (window.tableData && window.tableColumns && window.tableRows) {
                let csv = 'Row,' + window.tableColumns.join(',') + '\n';
                
                window.tableRows.forEach(row => {
                    const rowData = [row];
                    window.tableColumns.forEach(col => {
                        const value = window.tableData[row] && window.tableData[row][col] ? window.tableData[row][col] : 0;
                        rowData.push(value);
                    });
                    csv += rowData.join(',') + '\n';
                });
                
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.download = 'outcome-data.csv';
                link.href = url;
                link.click();
                window.URL.revokeObjectURL(url);
            }
        });
    }

    // Simple handler for window resize
    window.addEventListener('resize', function() {
        if (window.currentChart) {
            setTimeout(() => window.currentChart.resize(), 100);
        }
    });
});
</script>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>

<?php 
// Include footer
require_once ROOT_PATH . 'app/views/layouts/footer.php';
?>
