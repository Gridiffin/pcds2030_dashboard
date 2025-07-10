<?php
/**
 * Create Program - Simplified Draft Only
 * 
 * Simple interface for agency users to create program drafts with basic information.
 */

// Define project root path for consistent file references
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', rtrim(dirname(dirname(dirname(__DIR__))), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

// Include necessary files
require_once PROJECT_ROOT_PATH . 'config/config.php';
require_once PROJECT_ROOT_PATH . 'lib/db_connect.php';
require_once PROJECT_ROOT_PATH . 'lib/session.php';
require_once PROJECT_ROOT_PATH . 'lib/functions.php';
require_once PROJECT_ROOT_PATH . 'lib/agencies/programs.php';
require_once PROJECT_ROOT_PATH . 'lib/initiative_functions.php';
require_once PROJECT_ROOT_PATH . 'lib/numbering_helpers.php';

// Verify user is an agency
if (!is_agency()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Get active initiatives for dropdown
$active_initiatives = get_initiatives_for_select(true);

// Get reporting periods for dropdown
$reporting_periods = get_reporting_periods_for_dropdown(true); // Include all periods (open and closed)

// Process form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an auto-save request
    if (isset($_POST['auto_save'])) {
        // Handle auto-save via AJAX
        header('Content-Type: application/json');
        
        // Handle targets array data - collect all targets (matching edit program logic)
        $targets = [];
        if (isset($_POST['target_text']) && is_array($_POST['target_text'])) {
            $target_texts = $_POST['target_text'];
            $target_numbers = $_POST['target_number'] ?? [];
            $target_statuses = $_POST['target_status'] ?? [];
            $target_status_descriptions = $_POST['target_status_description'] ?? [];
            $target_start_dates = $_POST['target_start_date'] ?? [];
            $target_end_dates = $_POST['target_end_date'] ?? [];
            
            for ($i = 0; $i < count($target_texts); $i++) {
                $target_text = trim($target_texts[$i] ?? '');
                if (!empty($target_text)) {
                    $targets[] = [
                        'target_number' => sanitize_copy_paste_content($target_numbers[$i] ?? '', false),
                        'target_text' => sanitize_copy_paste_content($target_text, true),
                        'target_status' => trim($target_statuses[$i] ?? 'not-started'),
                        'status_description' => sanitize_copy_paste_content($target_status_descriptions[$i] ?? '', true),
                        'start_date' => !empty($target_start_dates[$i]) ? $target_start_dates[$i] : null,
                        'end_date' => !empty($target_end_dates[$i]) ? $target_end_dates[$i] : null
                    ];
                }
            }
        }          $program_data = [
            'program_id' => $_POST['program_id'] ?? 0,
            'program_name' => sanitize_copy_paste_content($_POST['program_name'] ?? '', false),
            'program_number' => sanitize_copy_paste_content($_POST['program_number'] ?? '', false),
            'brief_description' => sanitize_copy_paste_content($_POST['brief_description'] ?? '', true),
            'start_date' => $_POST['start_date'] ?? '',
            'end_date' => $_POST['end_date'] ?? '',
            'targets' => $targets,
            'initiative_id' => !empty($_POST['initiative_id']) ? intval($_POST['initiative_id']) : null,
            'period_id' => !empty($_POST['period_id']) ? intval($_POST['period_id']) : null
        ];
        
        $result = auto_save_program_draft($program_data);
        echo json_encode($result);
        exit;
    }
      // Handle full form submission
    // Handle targets array data - collect all targets (matching edit program logic)
    $targets = [];
    if (isset($_POST['target_text']) && is_array($_POST['target_text'])) {
        $target_texts = $_POST['target_text'];
        $target_numbers = $_POST['target_number'] ?? [];
        $target_statuses = $_POST['target_status'] ?? [];
        $target_status_descriptions = $_POST['target_status_description'] ?? [];
        $target_start_dates = $_POST['target_start_date'] ?? [];
        $target_end_dates = $_POST['target_end_date'] ?? [];
        
        for ($i = 0; $i < count($target_texts); $i++) {
            $target_text = trim($target_texts[$i] ?? '');
            if (!empty($target_text)) {
                $targets[] = [
                    'target_number' => sanitize_copy_paste_content($target_numbers[$i] ?? '', false),
                    'target_text' => sanitize_copy_paste_content($target_text, true),
                    'target_status' => trim($target_statuses[$i] ?? 'not-started'),
                    'status_description' => sanitize_copy_paste_content($target_status_descriptions[$i] ?? '', true),
                    'start_date' => !empty($target_start_dates[$i]) ? $target_start_dates[$i] : null,
                    'end_date' => !empty($target_end_dates[$i]) ? $target_end_dates[$i] : null
                ];
            }
        }
    }        $program_data = [
            'program_name' => sanitize_copy_paste_content($_POST['program_name'] ?? '', false),
            'program_number' => sanitize_copy_paste_content($_POST['program_number'] ?? '', false),
            'brief_description' => sanitize_copy_paste_content($_POST['brief_description'] ?? '', true),
            'start_date' => $_POST['start_date'] ?? '',
            'end_date' => $_POST['end_date'] ?? '',
            'targets' => $targets,
            'initiative_id' => !empty($_POST['initiative_id']) ? intval($_POST['initiative_id']) : null,
            'period_id' => !empty($_POST['period_id']) ? intval($_POST['period_id']) : null
        ];
    
    // Check if this is an update to existing program or new creation
    $program_id = isset($_POST['program_id']) ? intval($_POST['program_id']) : 0;
    
    if ($program_id > 0) {
        // Update existing program draft
        $result = update_wizard_program_draft($program_id, $program_data);
    } else {
        // Create new comprehensive program draft using wizard function
        $result = create_wizard_program_draft($program_data);
    }
    
    if (isset($result['success']) && $result['success']) {
        // Set success message and redirect
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = 'success';
        
        // Redirect to programs list
        header('Location: view_programs.php');
        exit;
    } else {
        $message = $result['error'] ?? 'An error occurred while saving the program draft.';
        $messageType = 'danger';
    }
}

// Set page title
$pageTitle = 'Create New Program';

// Include header
require_once '../../layouts/header.php';

// Configure modern page header
$header_config = [
    'title' => 'Create New Program',
    'subtitle' => 'Create a new program draft with basic information',
    'variant' => 'white',
    'actions' => [
        [
            'url' => 'view_programs.php',
            'text' => 'Back to Programs',
            'icon' => 'fas fa-arrow-left',
            'class' => 'btn-outline-secondary'
        ]
    ]
];

// Include modern page header
require_once '../../layouts/page_header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">

            <!-- Error/Success Messages -->
            <?php if (!empty($message)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        showToast('<?= ucfirst($messageType) ?>', <?= json_encode($message) ?>, '<?= $messageType ?>');
    });
</script>
<?php endif; ?>            <!-- Program Creation Wizard -->
            <div class="card shadow-sm mb-4 w-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-plus-circle me-2"></i>
                        Create New Program
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Wizard Progress Indicator -->                    <div class="wizard-progress mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="step-indicator active" id="step-1-indicator">
                                <div class="step-number">1</div>
                                <div class="step-label">Basic Info</div>
                            </div>
                            <div class="step-line"></div>
                            <div class="step-indicator" id="step-2-indicator">
                                <div class="step-number">2</div>
                                <div class="step-label">Targets</div>
                            </div>
                            <div class="step-line"></div>
                            <div class="step-indicator" id="step-3-indicator">
                                <div class="step-number">3</div>
                                <div class="step-label">Review</div>
                            </div>
                        </div>
                        <div class="progress" style="height: 4px;">
                            <div class="progress-bar bg-primary" id="wizard-progress-bar" style="width: 25%"></div>
                        </div>
                    </div><!-- Wizard Form -->
                    <form id="createProgramWizard" method="post">
                        <!-- Hidden field to track program_id for auto-save -->
                        <input type="hidden" id="program_id" name="program_id" value="0">
                        <!-- Step 1: Basic Information -->
                        <div class="wizard-step active" id="step-1">
                            <div class="step-content">
                                <h6 class="fw-bold mb-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Basic Program Information
                                </h6>
                                  <!-- Program Name -->
                                <div class="mb-4">
                                    <label for="program_name" class="form-label">
                                        Program Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="program_name" 
                                           name="program_name" 
                                           required
                                           placeholder="Enter the program name"
                                           value="<?php echo htmlspecialchars($_POST['program_name'] ?? ''); ?>">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        This will be the main identifier for your program
                                    </div>                                </div>

                                <!-- Initiative Selection -->
                                <div class="mb-4">
                                    <label for="initiative_id" class="form-label">
                                        Link to Initiative
                                        <span class="badge bg-secondary ms-1">Optional</span>
                                    </label>
                                    <select class="form-select" id="initiative_id" name="initiative_id">
                                        <option value="">Select an initiative (optional)</option>
                                        <?php foreach ($active_initiatives as $initiative): ?>
                                            <option value="<?php echo $initiative['initiative_id']; ?>"
                                                    <?php echo (isset($_POST['initiative_id']) && $_POST['initiative_id'] == $initiative['initiative_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($initiative['initiative_name']); ?>
                                                <?php if ($initiative['initiative_number']): ?>
                                                    (<?php echo htmlspecialchars($initiative['initiative_number']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        <i class="fas fa-lightbulb me-1"></i>
                                        Link this program to a strategic initiative for better organization and reporting
                                    </div>
                                </div>
                                
                                <!-- Reporting Period Selection -->
                                <div class="mb-4">
                                    <label for="period_id" class="form-label">
                                        Reporting Quarter
                                        <span class="badge bg-danger ms-1">Required</span>
                                    </label>
                                    <select class="form-select" id="period_id" name="period_id" required>
                                        <option value="">Select a reporting quarter</option>
                                        <?php foreach ($reporting_periods as $period): ?>
                                            <?php if (!preg_match('/Q[1-4]\b/i', $period['display_name'])) continue; ?>
                                            <option value="<?php echo $period['period_id']; ?>"
                                                    <?php echo (isset($_POST['period_id']) && $_POST['period_id'] == $period['period_id']) ? 'selected' : ''; ?>
                                                    data-status="<?php echo $period['status']; ?>">
                                                <?php echo htmlspecialchars($period['display_name']); ?>
                                                <?php if ($period['status'] == 'open'): ?>
                                                    (Open)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        <i class="fas fa-calendar me-1"></i>
                                        Select the reporting quarter for this program submission. 
                                        <span class="text-success">Open</span> periods are currently accepting submissions.
                                    </div>
                                </div>
                                
                                <style>
                                    #period_id option[data-status="open"] {
                                        font-weight: bold;
                                        color: #28a745;
                                    }
                                </style>
                                
                                <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    const periodSelect = document.getElementById('period_id');
                                    
                                    // Highlight open periods
                                    Array.from(periodSelect.options).forEach(option => {
                                        if (option.dataset.status === 'open') {
                                            option.classList.add('text-success', 'fw-bold');
                                        }
                                    });
                                    
                                    // Select an open period by default if none selected
                                    if (!periodSelect.value) {
                                        const openOption = Array.from(periodSelect.options).find(
                                            option => option.dataset.status === 'open'
                                        );
                                        if (openOption) {
                                            openOption.selected = true;
                                        }
                                    }
                                });
                                </script>

                                <!-- Program Number -->
                                <div class="mb-4">
                                    <label for="program_number" class="form-label">
                                        Program Number
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="program_number" 
                                           name="program_number" 
                                           placeholder="Select initiative first"
                                           disabled
                                           pattern="[\w.]+"
                                           title="Program number can contain letters, numbers, and dots"
                                           value="<?php echo htmlspecialchars($_POST['program_number'] ?? ''); ?>">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <span id="number-help-text">Select an initiative to enable program numbering</span>
                                    </div>
                                    <div id="final-number-display" class="mt-1" style="display: none;">
                                        <small class="text-muted">Final number will be: <span id="final-number-preview"></span></small>
                                    </div>
                                    <div id="number-validation" class="mt-2" style="display: none;">
                                        <small id="validation-message"></small>
                                    </div>
                                </div>

                                <!-- Brief Description -->
                                <div class="mb-4">
                                    <label for="brief_description" class="form-label">Brief Description</label>
                                    <textarea class="form-control" 
                                              id="brief_description" 
                                              name="brief_description"
                                              rows="3"
                                              placeholder="Provide a short summary of the program"><?php echo htmlspecialchars($_POST['brief_description'] ?? ''); ?></textarea>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        A brief overview to help identify this program
                                    </div>
                                </div>                                <!-- Add missing start_date and end_date input elements -->
                                <div class="form-group mb-4">
                                    <label for="start_date" class="form-label">
                                        Start Date
                                    </label>
                                    <input type="date" 
                                           class="form-control" 
                                           id="start_date" 
                                           name="start_date">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Optional: Set a start date if the program has a specific timeline
                                    </div>
                                </div>
                                <div class="form-group mb-4">
                                    <label for="end_date" class="form-label">
                                        End Date
                                    </label>
                                    <input type="date" 
                                           class="form-control" 
                                           id="end_date" 
                                           name="end_date">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Optional: Set an end date if the program has a specific timeline
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Targets -->
                        <div class="wizard-step" id="step-2">
                            <div class="step-content">
                                <h6 class="fw-bold mb-3">
                                    <i class="fas fa-bullseye me-2"></i>
                                    Targets
                                </h6>
                                <div class="card shadow-sm mb-4">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="card-title m-0">Program Targets</h5>
                                        <span id="target-counter" class="badge bg-primary fs-6">
                                            <i class="fas fa-bullseye me-1"></i>
                                            <span id="target-count">0</span> targets
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-muted mb-3">
                                            Define one or more targets for this program, each with its own status and timeline.
                                        </p>
                                        <div id="targets-container">
                                            <!-- Targets will be added here by JavaScript -->
                                        </div>
                                        <button type="button" id="add-target-btn" class="btn btn-outline-secondary add-target-btn">
                                            <i class="fas fa-plus-circle me-1"></i> Add Another Target
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Review & Save -->
                        <div class="wizard-step" id="step-3">
                            <div class="step-content">
                                <h6 class="fw-bold mb-3">
                                    <i class="fas fa-eye me-2"></i>
                                    Review Program Information
                                </h6>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please review your program information below. You can go back to previous steps to make changes.
                                </div>                                <!-- Review Summary -->
                                <div class="review-summary">
                                    <div class="row">                                        <div class="col-md-6">
                                            <div class="review-section mb-3">
                                                <h6 class="text-muted mb-2">Program Name</h6>
                                                <p class="mb-0" id="review-program-name">-</p>
                                            </div>
                                            <div class="review-section mb-3">
                                                <h6 class="text-muted mb-2">Program Number</h6>
                                                <p class="mb-0" id="review-program-number">-</p>
                                            </div>
                                            <div class="review-section mb-3">
                                                <h6 class="text-muted mb-2">Reporting Quarter</h6>
                                                <p class="mb-0" id="review-period">-</p>
                                            </div>
                                            <div class="review-section mb-3">
                                                <h6 class="text-muted mb-2">Linked Initiative</h6>
                                                <p class="mb-0" id="review-initiative">-</p>
                                            </div>
                                            <div class="review-section mb-3">
                                                <h6 class="text-muted mb-2">Timeline</h6>
                                                <p class="mb-0" id="review-timeline">-</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="review-section mb-3">
                                                <h6 class="text-muted mb-2">Brief Description</h6>
                                                <p class="mb-0" id="review-description">-</p>
                                            </div>
                                        </div>
                                    </div>
                                      <!-- Targets Section -->
                                    <div class="review-section mt-4">
                                        <h6 class="text-muted mb-2">Targets</h6>
                                        <div id="review-targets">
                                            <table class="review-target-table">
                                                <thead>
                                                    <tr>
                                                        <th>Target #</th>
                                                        <th>Number</th>
                                                        <th>Description</th>
                                                        <th>Status</th>
                                                        <th>Status Description</th>
                                                        <th>Timeline</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <!-- rows injected by JS -->
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Save Note -->
                                <div class="mt-4 p-3 bg-light rounded">
                                    <small class="text-muted">
                                        <i class="fas fa-save me-1"></i>
                                        <strong>Note:</strong> This will save your program as a draft. You can edit and add more details anytime before submitting for review.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Wizard Navigation -->
                        <div class="wizard-navigation mt-4 pt-3 border-top">
                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" id="prevBtn" style="display: none;">
                                    <i class="fas fa-arrow-left me-2"></i>
                                    Previous
                                </button>
                                <div class="ms-auto">
                                    <button type="button" class="btn btn-primary" id="nextBtn">
                                        Next
                                        <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                    <button type="submit" class="btn btn-success" id="saveDraftBtn" style="display: none;">
                                        <i class="fas fa-save me-2"></i>
                                        Save Draft
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Auto-save Status -->
                        <div class="auto-save-status mt-2 text-center" id="autoSaveStatus" style="display: none;">
                            <small class="text-muted">
                            </small>
                        </div>
                    </form>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const targetsContainer = document.getElementById('targets-container');
                        const addTargetBtn = document.getElementById('add-target-btn');
                        const programNumberInput = document.getElementById('program_number');

                        const updateTargetUI = () => {
                            const targetEntries = targetsContainer.querySelectorAll('.target-entry');
                            const targetCount = targetEntries.length;

                            // Update counter
                            const targetCountElement = document.getElementById('target-count');
                            const targetCounter = document.getElementById('target-counter');
                            if (targetCountElement && targetCounter) {
                                targetCountElement.textContent = targetCount;
                                const targetText = targetCount === 1 ? 'target' : 'targets';
                                targetCounter.innerHTML = `<i class="fas fa-bullseye me-1"></i><span id="target-count">${targetCount}</span> ${targetText}`;
                                targetCounter.className = 'badge fs-6 ' + (targetCount === 0 ? 'bg-secondary' : targetCount === 1 ? 'bg-primary' : 'bg-success');
                            }

                            // Update numbering and remove buttons
                            targetEntries.forEach((entry, index) => {
                                // Update header
                                const counterHeader = entry.querySelector('.target-counter-header h6');
                                if (counterHeader) {
                                    counterHeader.innerHTML = `<i class="fas fa-bullseye me-1"></i>Target #${index + 1}`;
                                }

                                // Show/hide remove button
                                const removeBtn = entry.querySelector('.remove-target');
                                if (removeBtn) {
                                    removeBtn.style.display = targetCount > 1 ? 'block' : 'none';
                                }
                            });
                        };

                        const addNewTarget = () => {
                            const targetIndex = targetsContainer.querySelectorAll('.target-entry').length;
                            const targetEntry = document.createElement('div');
                            targetEntry.className = 'target-entry border rounded p-3 mb-3 position-relative';

                            const programNumber = programNumberInput.value || 'X.Y';

                            const html = `
                                <button type="button" class="btn-close remove-target" aria-label="Remove target" style="display: none;"></button>
                                <div class="target-counter-header mb-2">
                                    <h6 class="text-primary fw-bold mb-0"><i class="fas fa-bullseye me-1"></i>Target #${targetIndex + 1}</h6>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Target Number (Optional)</label>
                                        <input type="text" class="form-control target-number-input" name="target_number[]" placeholder="e.g., ${programNumber}.${targetIndex + 1}">
                                        <div class="form-text">Format: {program_number}.{target_counter}</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Target Status</label>
                                        <select class="form-select target-status-select" name="target_status[]">
                                            <option value="not-started" selected>Not Started</option>
                                            <option value="in-progress">In Progress</option>
                                            <option value="completed">Completed</option>
                                            <option value="delayed">Delayed</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label target-text-label">Target *</label>
                                    <textarea class="form-control target-input" name="target_text[]" rows="3" required placeholder="Define a measurable target (e.g., '''Plant 100 trees''')"></textarea>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Start Date (Optional)</label>
                                        <input type="date" class="form-control target-start-date" name="target_start_date[]">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">End Date (Optional)</label>
                                        <input type="date" class="form-control target-end-date" name="target_end_date[]">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Status Description</label>
                                    <textarea class="form-control status-description" name="target_status_description[]" rows="2" placeholder="Describe the current status or progress toward this target"></textarea>
                                </div>
                            `;
                            targetEntry.innerHTML = html;
                            targetsContainer.appendChild(targetEntry);

                            // Add event listener for the remove button
                            targetEntry.querySelector('.remove-target').addEventListener('click', function() {
                                targetEntry.remove();
                                updateTargetUI();
                            });

                            // Add date validation
                            const startDateInput = targetEntry.querySelector('.target-start-date');
                            const endDateInput = targetEntry.querySelector('.target-end-date');
                            const validateDates = () => {
                                if (startDateInput.value && endDateInput.value && new Date(endDateInput.value) < new Date(startDateInput.value)) {
                                    endDateInput.setCustomValidity('End date must be after start date.');
                                    endDateInput.reportValidity();
                                } else {
                                    endDateInput.setCustomValidity('');
                                }
                            };
                            startDateInput.addEventListener('change', validateDates);
                            endDateInput.addEventListener('change', validateDates);

                            // Add target number validation
                            const targetNumberInput = targetEntry.querySelector('.target-number-input');
                            if (targetNumberInput) {
                                targetNumberInput.addEventListener('blur', function() {
                                    validateTargetNumber(this);
                                });
                            }

                            updateTargetUI();
                        };

                        if (addTargetBtn) {
                            addTargetBtn.addEventListener('click', addNewTarget);
                        }

                        // Add the first target when the page loads
                        addNewTarget();

                        // Enable/disable program number input based on initiative selection
                        const initiativeSelect = document.getElementById('initiative_id');
                        const numberHelpText = document.getElementById('number-help-text');

                        if (initiativeSelect && programNumberInput && numberHelpText) {
                            const toggleProgramNumberInput = () => {
                                if (initiativeSelect.value) {
                                    programNumberInput.disabled = false;
                                    programNumberInput.placeholder = 'Enter program number (e.g., 31.1, 31.2A, 31.25.6)';
                                    numberHelpText.textContent = 'Flexible format supporting letters, numbers, and dots.';
                                } else {
                                    programNumberInput.disabled = true;
                                    programNumberInput.placeholder = 'Select initiative first';
                                    programNumberInput.value = '';
                                    numberHelpText.textContent = 'Select an initiative to enable program numbering.';
                                }
                            };

                            initiativeSelect.addEventListener('change', toggleProgramNumberInput);

                            // Run on page load to check initial state
                            toggleProgramNumberInput();
                        }

                        // Add program number validation
                        const validationMessage = document.getElementById('validation-message');
                        const numberValidationDiv = document.getElementById('number-validation');

                        if (programNumberInput && validationMessage && numberValidationDiv) {
                            programNumberInput.addEventListener('blur', function() {
                                const programNumber = this.value.trim();
                                const initiativeId = initiativeSelect.value;

                                if (programNumber === '') {
                                    numberValidationDiv.style.display = 'none';
                                    return;
                                }

                                // Basic format validation first
                                if (!/^[\w.]+$/.test(programNumber)) {
                                    validationMessage.textContent = 'Program number can only contain letters, numbers, and dots.';
                                    validationMessage.className = 'text-danger';
                                    numberValidationDiv.style.display = 'block';
                                    return;
                                }

                                // AJAX call to validation script
                                fetch('<?php echo APP_URL; ?>/app/ajax/program_numbering.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: `action=validate_number&program_number=${encodeURIComponent(programNumber)}&initiative_id=${encodeURIComponent(initiativeId)}`
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.valid) {
                                        validationMessage.textContent = data.message || 'Program number is valid and available.';
                                        validationMessage.className = 'text-success';
                                    } else {
                                        validationMessage.textContent = data.message || 'Program number is not valid.';
                                        validationMessage.className = 'text-danger';
                                    }
                                    numberValidationDiv.style.display = 'block';
                                })
                                .catch(error => {
                                    console.error('Error validating program number:', error);
                                    validationMessage.textContent = 'Unable to validate program number. Please try again.';
                                    validationMessage.className = 'text-warning';
                                    numberValidationDiv.style.display = 'block';
                                });                                });
                        }

                        // Target number validation function
                        function validateTargetNumber(input) {
                            const value = input.value.trim();
                            if (value) {
                                // Basic format validation
                                const programNumber = programNumberInput.value || '';
                                if (programNumber && !value.startsWith(programNumber + '.')) {
                                    input.setCustomValidity(`Target number must start with ${programNumber}.`);
                                    input.classList.add('is-invalid');
                                    
                                    // Show validation message below the input
                                    let validationDiv = input.parentElement.querySelector('.target-validation');
                                    if (!validationDiv) {
                                        validationDiv = document.createElement('div');
                                        validationDiv.className = 'target-validation mt-1';
                                        input.parentElement.appendChild(validationDiv);
                                    }
                                    validationDiv.innerHTML = `<small class="text-danger">Target number must start with ${programNumber}.</small>`;
                                } else if (value && !/^[\w.]+$/.test(value)) {
                                    input.setCustomValidity('Target number can only contain letters, numbers, and dots.');
                                    input.classList.add('is-invalid');
                                    
                                    // Show validation message below the input
                                    let validationDiv = input.parentElement.querySelector('.target-validation');
                                    if (!validationDiv) {
                                        validationDiv = document.createElement('div');
                                        validationDiv.className = 'target-validation mt-1';
                                        input.parentElement.appendChild(validationDiv);
                                    }
                                    validationDiv.innerHTML = '<small class="text-danger">Target number can only contain letters, numbers, and dots.</small>';
                                } else {
                                    input.setCustomValidity('');
                                    input.classList.remove('is-invalid');
                                    input.classList.add('is-valid');
                                    
                                    // Remove validation message
                                    const validationDiv = input.parentElement.querySelector('.target-validation');
                                    if (validationDiv) {
                                        validationDiv.remove();
                                    }
                                }
                            } else {
                                input.setCustomValidity('');
                                input.classList.remove('is-invalid', 'is-valid');
                                
                                // Remove validation message
                                const validationDiv = input.parentElement.querySelector('.target-validation');
                                if (validationDiv) {
                                    validationDiv.remove();
                                }
                            }
                        }

                        // Update target number placeholders when program number changes
                        programNumberInput.addEventListener('input', function() {
                            const programNumber = this.value || 'X.Y';
                            document.querySelectorAll('.target-number-input').forEach((input, index) => {
                                input.placeholder = `e.g., ${programNumber}.${index + 1}`;
                            });
                        });

                        // Function to update review section with target data
                        function updateReviewTargets() {
                            const reviewTargetsDiv = document.getElementById('review-targets');
                            const targetEntries = document.querySelectorAll('.target-entry');
                            
                            if (targetEntries.length === 0) {
                                reviewTargetsDiv.innerHTML = '<div class="text-muted">No targets defined</div>';
                                return;
                            }

                            let targetsHtml = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Target #</th><th>Number</th><th>Target Description</th><th>Status</th><th>Status Description</th><th>Timeline</th></tr></thead><tbody>';
                            
                            targetEntries.forEach((entry, index) => {
                                const targetNumber = entry.querySelector('.target-number-input')?.value || '-';
                                const targetText = entry.querySelector('.target-input')?.value || '-';
                                const targetStatus = entry.querySelector('.target-status-select')?.value || 'not-started';
                                const statusDescription = entry.querySelector('.target-status-description')?.value || '-';
                                const startDate = entry.querySelector('.target-start-date')?.value || '';
                                const endDate = entry.querySelector('.target-end-date')?.value || '';
                                
                                // Format status with badge
                                let statusBadge = '';
                                switch(targetStatus) {
                                    case 'completed':
                                        statusBadge = '<span class="badge bg-success">Completed</span>';
                                        break;
                                    case 'in-progress':
                                        statusBadge = '<span class="badge bg-warning">In Progress</span>';
                                        break;
                                    case 'delayed':
                                        statusBadge = '<span class="badge bg-danger">Delayed</span>';
                                        break;
                                    default:
                                        statusBadge = '<span class="badge bg-secondary">Not Started</span>';
                                }
                                
                                // Format timeline
                                let timeline = '-';
                                if (startDate && endDate) {
                                    timeline = `${startDate} to ${endDate}`;
                                } else if (startDate) {
                                    timeline = `From ${startDate}`;
                                } else if (endDate) {
                                    timeline = `Until ${endDate}`;
                                }
                                
                                targetsHtml += `
                                    <tr>
                                        <td><strong>#${index + 1}</strong></td>
                                        <td><code>${escapeHtml(targetNumber)}</code></td>
                                        <td>${escapeHtml(targetText)}</td>
                                        <td>${statusBadge}</td>
                                        <td><small class="text-muted">${escapeHtml(statusDescription)}</small></td>
                                        <td><small>${timeline}</small></td>
                                    </tr>
                                `;
                            });
                            
                            targetsHtml += '</tbody></table></div>';
                            reviewTargetsDiv.innerHTML = targetsHtml;
                        }

                        // Function to update all review data
                        function updateReviewData() {
                            // Update basic information
                            document.getElementById('review-program-name').textContent = document.getElementById('program_name').value || '-';
                            document.getElementById('review-program-number').textContent = document.getElementById('program_number').value || '-';
                            
                            // Update initiative
                            const initiativeSelect = document.getElementById('initiative_id');
                            const selectedInitiative = initiativeSelect.options[initiativeSelect.selectedIndex];
                            document.getElementById('review-initiative').textContent = selectedInitiative.value ? selectedInitiative.textContent : '-';
                            
                            // Update timeline
                            const startDate = document.getElementById('start_date').value;
                            const endDate = document.getElementById('end_date').value;
                            let timeline = '-';
                            if (startDate && endDate) {
                                timeline = `${startDate} to ${endDate}`;
                            } else if (startDate) {
                                timeline = `From ${startDate}`;
                            } else if (endDate) {
                                timeline = `Until ${endDate}`;
                            }
                            document.getElementById('review-timeline').textContent = timeline;
                            
                            // Update targets
                            updateReviewTargets();
                        }
                    });
                    </script>
                </div>
            </div>

<!-- Wizard CSS -->
<style>
.wizard-progress {
    margin-bottom: 2rem;
}

.step-indicator {
    text-align: center;
    position: relative;
    flex: 1;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: #e9ecef;
    color: #6c757d;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-bottom: 8px;
    transition: all 0.3s ease;
}

.step-indicator.active .step-number {
    background-color: #0d6efd;
    color: white;
}

.step-indicator.completed .step-number {
    background-color: #198754;
    color: white;
}

.step-label {
    font-size: 0.875rem;
    color: #6c757d;
    font-weight: 500;
}

.step-indicator.active .step-label {
    color: #0d6efd;
    font-weight: 600;
}

.step-indicator.completed .step-label {
    color: #198754;
    font-weight: 600;
}

.step-line {
    height: 2px;
    background-color: #e9ecef;
    flex: 1;
    margin: 0 15px;
    margin-top: 20px;
    transition: all 0.3s ease;
}

.step-line.completed {
    background-color: #198754;
}

.wizard-step {
    display: none;
    animation: fadeIn 0.3s ease-in-out;
}

.wizard-step.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateX(20px); }
    to { opacity: 1; transform: translateX(0); }
}

.review-section {
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 1rem;
}

.review-section:last-child {
    border-bottom: none;
}

.auto-save-indicator {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1050;
}

.targets-list .target-item {
    border-left: 3px solid #0d6efd;
    transition: all 0.2s ease;
}

.targets-list .target-item:hover {
    background-color: #f8f9fa !important;
    border-left-color: #198754;
}

.target-text {
    font-size: 0.95rem;
    line-height: 1.4;
}

.status-text small {
    font-style: italic;
}
</style>

<!-- Wizard JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {    // Wizard state
    let currentStep = 1;
    const totalSteps = 3;
    let formData = {};
    
    // Elements
    const wizard = document.getElementById('createProgramWizard');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const saveDraftBtn = document.getElementById('saveDraftBtn');
    const progressBar = document.getElementById('wizard-progress-bar');
    const autoSaveStatus = document.getElementById('autoSaveStatus');
    
    // Form inputs for auto-save
    const formInputs = wizard.querySelectorAll('input, select, textarea');
    
    // Initialize wizard
    initializeWizard();
    
    function initializeWizard() {
        showStep(currentStep);
        updateProgressBar();
        setupAutoSave();
        setupValidation();
    }
    
    function showStep(step) {
        // Hide all steps
        document.querySelectorAll('.wizard-step').forEach(stepEl => {
            stepEl.classList.remove('active');
        });
        
        // Show current step
        document.getElementById(`step-${step}`).classList.add('active');
        
        // Update step indicators
        updateStepIndicators(step);
        
        // Update navigation buttons
        updateNavigationButtons(step);
        
        // Update progress bar
        updateProgressBar();
        // Update review if on the last step
        if (step === totalSteps) {
            updateReviewSummary();
        }
    }
    
    function updateStepIndicators(step) {
        for (let i = 1; i <= totalSteps; i++) {
            const indicator = document.getElementById(`step-${i}-indicator`);
            const stepLine = indicator.nextElementSibling;
            
            indicator.classList.remove('active', 'completed');
            
            if (i < step) {
                indicator.classList.add('completed');
                if (stepLine && stepLine.classList.contains('step-line')) {
                    stepLine.classList.add('completed');
                }
            } else if (i === step) {
                indicator.classList.add('active');
            } else {
                if (stepLine && stepLine.classList.contains('step-line')) {
                    stepLine.classList.remove('completed');
                }
            }
        }
    }
    
    function updateNavigationButtons(step) {
        prevBtn.style.display = step > 1 ? 'inline-block' : 'none';
        nextBtn.style.display = step < totalSteps ? 'inline-block' : 'none';
        saveDraftBtn.style.display = step === totalSteps ? 'inline-block' : 'none';
    }
    
    function updateProgressBar() {
        const progressPercentage = (currentStep / totalSteps) * 100;
        progressBar.style.width = progressPercentage + '%';
    }
    function updateReviewSummary() {
        const data = collectFormData();        // Program Name
        document.getElementById('review-program-name').textContent = data.program_name || '-';

        // Program Number
        document.getElementById('review-program-number').textContent = data.program_number || 'Not specified';

        // Reporting Period
        let periodDisplay = 'No reporting period selected';
        if (data.period_id) {
            const periodSelect = document.getElementById('period_id');
            const selectedOption = periodSelect.options[periodSelect.selectedIndex];
            if (selectedOption && selectedOption.value) {
                periodDisplay = selectedOption.textContent.trim();
            }
        }
        document.getElementById('review-period').textContent = periodDisplay;

        // Linked Initiative
        let initiativeDisplay = 'No initiative linked';
        if (data.initiative_id) {
            const initiativeSelect = document.getElementById('initiative_id');
            const selectedOption = initiativeSelect.options[initiativeSelect.selectedIndex];
            if (selectedOption && selectedOption.value) {
                initiativeDisplay = selectedOption.textContent.trim();
            }
        }
        document.getElementById('review-initiative').textContent = initiativeDisplay;

        // Timeline
        let timeline = '-';
        if (data.start_date && data.end_date) {
            timeline = `${formatDate(data.start_date)} - ${formatDate(data.end_date)}`;
        } else if (data.start_date) {
            timeline = `Starts: ${formatDate(data.start_date)}`;
        } else if (data.end_date) {
            timeline = `Ends: ${formatDate(data.end_date)}`;
        }
        document.getElementById('review-timeline').textContent = timeline;

        // Brief Description
        document.getElementById('review-description').textContent =
            data.brief_description || 'No description provided';
        // Targets
        const reviewTargetsDiv = document.getElementById('review-targets');
        if (data.targets && data.targets.length > 0) {
            let targetsHtml = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Target #</th><th>Number</th><th>Target Description</th><th>Status</th><th>Status Description</th><th>Timeline</th></tr></thead><tbody>';
            
            data.targets.forEach((target, index) => {
                // Format status with badge
                let statusBadge = '';
                switch(target.target_status) {
                    case 'completed':
                        statusBadge = '<span class="badge bg-success">Completed</span>';
                        break;
                    case 'in-progress':
                        statusBadge = '<span class="badge bg-warning">In Progress</span>';
                        break;
                    case 'delayed':
                        statusBadge = '<span class="badge bg-danger">Delayed</span>';
                        break;
                    default:
                        statusBadge = '<span class="badge bg-secondary">Not Started</span>';
                }
                
                // Format timeline
                let timeline = '-';
                if (target.start_date && target.end_date) {
                    timeline = `${formatDate(target.start_date)} to ${formatDate(target.end_date)}`;
                } else if (target.start_date) {
                    timeline = `From ${formatDate(target.start_date)}`;
                } else if (target.end_date) {
                    timeline = `Until ${formatDate(target.end_date)}`;
                }
                
                targetsHtml += `
                    <tr>
                        <td><strong>#${index + 1}</strong></td>
                        <td><code>${escapeHtml(target.target_number || '-')}</code></td>
                        <td>${escapeHtml(target.target_text)}</td>
                        <td>${statusBadge}</td>
                        <td><small class="text-muted">${escapeHtml(target.status_description || '-')}</small></td>
                        <td><small>${timeline}</small></td>
                    </tr>
                `;
            });
            
            targetsHtml += '</tbody></table></div>';
            reviewTargetsDiv.innerHTML = targetsHtml;
        } else {
            reviewTargetsDiv.innerHTML = '<div class="text-muted">No targets defined</div>';
        }
    }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        function collectFormData() {
            const data = {};
            
            // Collect basic form inputs
            const basicInputs = ['program_id', 'program_name', 'program_number', 'initiative_id', 'period_id', 'brief_description', 'start_date', 'end_date'];
            basicInputs.forEach(inputName => {
                const input = document.getElementById(inputName);
                if (input) {
                    data[inputName] = input.value;
                }
            });
            
            // Collect targets data from our new structure
            const targetEntries = document.querySelectorAll('.target-entry');
            data.targets = [];
            
            targetEntries.forEach(entry => {
                const targetNumber = entry.querySelector('.target-number-input')?.value || '';
                const targetText = entry.querySelector('.target-input')?.value || '';
                const targetStatus = entry.querySelector('.target-status-select')?.value || 'not-started';
                const statusDescription = entry.querySelector('.status-description')?.value || '';
                const startDate = entry.querySelector('.target-start-date')?.value || '';
                const endDate = entry.querySelector('.target-end-date')?.value || '';
                
                // Only add target if there's actual content
                if (targetText.trim()) {
                    data.targets.push({
                        target_number: targetNumber,
                        target_text: targetText,
                        target_status: targetStatus,
                        status_description: statusDescription,
                        start_date: startDate,
                        end_date: endDate
                    });
                }
            });
            
            return data;
        }
        
    function validateStep(step) {
        const stepElement = document.getElementById(`step-${step}`);
        const requiredFields = stepElement.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                showFieldError(field, `${field.previousElementSibling.textContent.replace('*', '').trim()} is required`);
                isValid = false;
            } else {
                clearFieldError(field);
            }
        });
        
        // Step 1 specific validation
        if (step === 1) {
            const programName = document.getElementById('program_name');
            if (programName.value.trim() && programName.value.trim().length < 3) {
                showFieldError(programName, 'Program name must be at least 3 characters long');
                isValid = false;
            }
            
            // Validate reporting period
            const periodId = document.getElementById('period_id');
            if (!periodId.value.trim()) {
                showFieldError(periodId, 'Please select a reporting quarter');
                isValid = false;
            }
        }
        
        // Step 2 specific validation
        if (step === 2) {
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            
            if (startDate.value && endDate.value && new Date(startDate.value) > new Date(endDate.value)) {
                showFieldError(endDate, 'End date cannot be before start date');
                isValid = false;
            }
        }
        
        return isValid;
    }
      function setupAutoSave() {
        let autoSaveTimeout;
        
        // Setup auto-save for existing form inputs
        function addAutoSaveToInputs() {
            const currentInputs = wizard.querySelectorAll('input, select, textarea');
            currentInputs.forEach(input => {
                // Remove existing listeners to avoid duplicates
                input.removeEventListener('input', handleInputChange);
                input.addEventListener('input', handleInputChange);
            });
        }
          function handleInputChange() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                autoSaveFormData().catch(error => {
                    console.error('Auto-save failed from input change:', error);
                });
            }, 2000); // Auto-save after 2 seconds of inactivity
        }
        
        // Initial setup
        addAutoSaveToInputs();
        
        // Add specific listener for initiative dropdown to update review immediately
        const initiativeSelect = document.getElementById('initiative_id');
        if (initiativeSelect) {
            initiativeSelect.addEventListener('change', function() {
                // Update review if we're currently on step 4
                if (currentStep === 4) {
                    updateReviewSummary();
                }
            });
        }
        
        // Re-setup auto-save when new targets are added
        const addTargetButton = document.getElementById('add-target-button');
        if (addTargetButton) {
            const originalClick = addTargetButton.onclick;
            addTargetButton.onclick = function() {
                if (originalClick) originalClick.call(this);
                // Re-setup auto-save for new inputs after a short delay
                setTimeout(addAutoSaveToInputs, 100);
            };
        }
    }      function autoSaveFormData() {
        return new Promise((resolve, reject) => {
            const data = collectFormData();
            
            // Only auto-save if program name is provided
            if (!data.program_name || data.program_name.trim().length < 3) {
                reject(new Error('Program name is required'));
                return;
            }
            
            // Show saving indicator
            showAutoSaveStatus('Saving...', 'warning');
            
            // Prepare data for auto-save
            const formData = new FormData();
            
            // Add basic form fields
            Object.keys(data).forEach(key => {
                if (key !== 'targets' && data[key]) {
                    formData.append(key, data[key]);
                }
            });
            
            // Add targets array data (using new format that matches backend)
            if (data.targets && data.targets.length > 0) {
                data.targets.forEach((target, index) => {
                    if (target.target_text) {
                        formData.append('target_text[]', target.target_text);
                    }
                    if (target.target_number) {
                        formData.append('target_number[]', target.target_number);
                    }
                    if (target.target_status) {
                        formData.append('target_status[]', target.target_status);
                    }
                    if (target.status_description) {
                        formData.append('target_status_description[]', target.status_description);
                    }
                    if (target.start_date) {
                        formData.append('target_start_date[]', target.start_date);
                    }
                    if (target.end_date) {
                        formData.append('target_end_date[]', target.end_date);
                    }
                });
            }
            
            formData.append('auto_save', '1');
            
            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Store program_id for subsequent auto-saves
                    if (result.program_id) {
                        document.getElementById('program_id').value = result.program_id;
                    }
                    showAutoSaveStatus('Saved', 'success');
                    resolve(result);
                } else {
                    showAutoSaveStatus('Save failed', 'error');
                    console.error('Auto-save failed:', result.error);
                    reject(new Error(result.error || 'Auto-save failed'));
                }
            })
            .catch(error => {
                console.error('Auto-save error:', error);
                showAutoSaveStatus('Save failed', 'error');
                reject(error);
            });
        });
    }
    
    function showAutoSaveStatus(message, type) {
        const iconClass = type === 'success' ? 'fa-check-circle text-success' : 
                         type === 'warning' ? 'fa-clock text-warning' : 
                         'fa-exclamation-circle text-danger';
        
        autoSaveStatus.innerHTML = `
            <small class="text-muted">
                <i class="fas ${iconClass} me-1"></i>
                ${message}
            </small>
        `;
        autoSaveStatus.style.display = 'block';
        
        // Hide after 3 seconds for success/error messages
        if (type !== 'warning') {
            setTimeout(() => {
                autoSaveStatus.style.display = 'none';
            }, 3000);
        }
    }
    
    function setupValidation() {
        // Real-time validation for program name
        const programNameInput = document.getElementById('program_name');
        programNameInput.addEventListener('blur', function() {
            const name = this.value.trim();
            if (name === '') {
                showFieldError(this, 'Program name is required');
            } else if (name.length < 3) {
                showFieldError(this, 'Program name must be at least 3 characters long');
            } else {
                clearFieldError(this);
            }
        });
        
        // Date validation
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        
        // Ensure the elements exist before accessing them
        if (startDateInput && endDateInput) {
            function validateDates() {
                const startDate = startDateInput.value;
                const endDate = endDateInput.value;
                
                if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                    showFieldError(endDateInput, 'End date cannot be before start date');
                    return false;
                } else {
                    clearFieldError(endDateInput);
                    return true;
                }
            }
            
            startDateInput.addEventListener('change', validateDates);
            endDateInput.addEventListener('change', validateDates);
        } else {
            console.error('Start date or end date input element is missing in the DOM.');
        }
    }
    
    // Navigation event listeners
    // Ensure DOM is fully loaded and nextBtn exists
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            if (validateStep(currentStep)) {
                if (currentStep < totalSteps) {
                    currentStep++;
                    showStep(currentStep);
                }
            }
        });
    } else {
        console.error('nextBtn does not exist in the DOM');
    }
    
    prevBtn.addEventListener('click', function() {
        if (currentStep > 1) {
            currentStep--;
            showStep(currentStep);
        }
    });
    
    // Form submission
    wizard.addEventListener('submit', function(e) {
        if (!validateStep(currentStep)) {
            e.preventDefault();
            return false;
        }
        
        // Disable save button to prevent double submission
        saveDraftBtn.disabled = true;
        saveDraftBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving Draft...';
    });
    
    // Step indicator click navigation
    for (let i = 1; i <= totalSteps; i++) {
        document.getElementById(`step-${i}-indicator`).addEventListener('click', function() {
            if (i <= currentStep || validateStep(currentStep)) {
                currentStep = i;
                showStep(currentStep);
            }
        });
    }
      // JavaScript to dynamically add and remove targets with numbering
    function updateTargetNumbers() {
        const targetGroups = document.querySelectorAll('.target-group');
        targetGroups.forEach((group, index) => {
            const label = group.querySelector('.target-label');
            label.textContent = `Target ${index + 1}`;
            
            // Update input names to maintain proper indexing
            const targetInput = group.querySelector('input[name*="[target]"]');
            const statusInput = group.querySelector('input[name*="[status_description]"]');
            
            if (targetInput) {
                targetInput.name = `targets[${index}][target]`;
            }
            if (statusInput) {
                statusInput.name = `targets[${index}][status_description]`;
            }
        });
    }

    document.getElementById('add-target-button').addEventListener('click', function() {
        const container = document.getElementById('targets-container');
        const index = container.querySelectorAll('.target-group').length;

        const targetGroup = document.createElement('div');
        targetGroup.className = 'form-group target-group mb-3';

        targetGroup.innerHTML = `
            <label class="target-label fw-bold">Target ${index + 1}</label>
            <input type="text" name="targets[${index}][target]" class="form-control mb-2" placeholder="Enter target" required>

            <label class="fw-bold">Status Description</label>
            <input type="text" name="targets[${index}][status_description]" class="form-control mb-2" placeholder="Enter status description" required>

            <button type="button" class="btn btn-danger btn-sm remove-target-button">Remove Target</button>
        `;

        container.appendChild(targetGroup);
        updateTargetNumbers();

        // Add event listener for the remove button
        targetGroup.querySelector('.remove-target-button').addEventListener('click', function() {
            targetGroup.remove();
            updateTargetNumbers();            // Trigger auto-save after removal
            setTimeout(() => {
                autoSaveFormData().catch(error => {
                    console.error('Auto-save failed after target removal:', error);
                });
            }, 500);
        });
          // Setup auto-save for new inputs
        const newInputs = targetGroup.querySelectorAll('input');
        newInputs.forEach(input => {
            input.addEventListener('input', function() {
                clearTimeout(window.autoSaveTimeout);
                window.autoSaveTimeout = setTimeout(() => {
                    autoSaveFormData().catch(error => {
                        console.error('Auto-save failed from new target input:', error);
                    });
                }, 2000);
            });
        });
    });
      // Initialize the first target group
    const container = document.getElementById('targets-container');
    container.innerHTML = '';

    const targetGroup = document.createElement('div');
    targetGroup.className = 'form-group target-group mb-3';

    targetGroup.innerHTML = `
        <label class="target-label fw-bold">Target 1</label>
        <input type="text" name="targets[0][target]" class="form-control mb-2" placeholder="Enter target" required>

        <label class="fw-bold">Status Description <small class="text-muted">(e.g., "In progress")</small></label>
        <input type="text" name="targets[0][status_description]" class="form-control mb-2" placeholder="Enter status description" required>
    `;

    container.appendChild(targetGroup);
      // Setup auto-save for initial target inputs
    const initialInputs = targetGroup.querySelectorAll('input');
    initialInputs.forEach(input => {
        input.addEventListener('input', function() {
            clearTimeout(window.autoSaveTimeout);
            window.autoSaveTimeout = setTimeout(() => {
                autoSaveFormData().catch(error => {
                    console.error('Auto-save failed from initial target input:', error);
                });
            }, 2000);
        });
    });
    // Utility functions for showing/clearing field errors
    function showFieldError(field, message) {
        field.classList.add('is-invalid');
        
        // Remove existing error message
        const existingError = field.parentNode.querySelector('.invalid-feedback');
        if (existingError) {
            existingError.remove();
        }
        
        // Add new error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    }
    
    function clearFieldError(field) {
        field.classList.remove('is-invalid');
        const errorDiv = field.parentNode.querySelector('.invalid-feedback');
        if (errorDiv) {
            errorDiv.remove();
        }
    }

    // ===========================
    // ATTACHMENT FUNCTIONALITY
    // ===========================
    
    // (All code in this section has been removed as part of attachment removal)
    
    // ===========================
    // MANUAL HIERARCHICAL NUMBERING
      const initiativeSelect = document.getElementById('initiative_id');
    const programNumberField = document.getElementById('program_number');
    const finalNumberDisplay = document.getElementById('final-number-display');
    const finalNumberPreview = document.getElementById('final-number-preview');
    const numberHelpText = document.getElementById('number-help-text');
    const numberValidation = document.getElementById('number-validation');
    const validationMessage = document.getElementById('validation-message');
    
    // Initialize manual numbering system
    if (initiativeSelect && programNumberField) {
        setupManualNumbering();
    }
      function setupManualNumbering() {
        // Handle initiative selection change
        initiativeSelect.addEventListener('change', function() {
            updateInitiativeDisplay();
        });
        
        // Handle program number input
        programNumberField.addEventListener('input', function() {
            updateFinalNumberPreview();
            validateProgramNumber();
        });
        
        // Handle program number blur for validation
        programNumberField.addEventListener('blur', function() {
            validateProgramNumber();
        });
        
        // Initialize on page load
        updateInitiativeDisplay();
    }    function updateInitiativeDisplay() {
        const selectedInitiative = initiativeSelect.value;
        
        if (!selectedInitiative) {
            // No initiative selected - disable everything
            programNumberField.disabled = true;
            programNumberField.value = '';
            programNumberField.placeholder = 'Select initiative first';
            finalNumberDisplay.style.display = 'none';
            numberHelpText.textContent = 'Select an initiative to enable program numbering';
            hideValidation();
            return;
        }
        
        // Get initiative data from the selected option
        const selectedOption = initiativeSelect.options[initiativeSelect.selectedIndex];
        const initiativeText = selectedOption.textContent.trim();
        const initiativeNumber = extractInitiativeNumber(initiativeText);
        
        if (initiativeNumber) {
            // Initiative has a number - enable program numbering
            programNumberField.disabled = false;
            programNumberField.placeholder = `Enter program number (e.g., ${initiativeNumber}.1)`;
            finalNumberDisplay.style.display = 'block';
            numberHelpText.innerHTML = `<strong>Initiative ${initiativeNumber} selected</strong> - Enter your program number`;
            
            // Update preview if field already has value
            updateFinalNumberPreview();
        } else {
            // Initiative selected but has no number assigned
            programNumberField.disabled = true;
            programNumberField.value = '';
            programNumberField.placeholder = 'Initiative number needed';
            finalNumberDisplay.style.display = 'none';
            
            // Extract initiative name for better messaging (remove any parenthetical content)
            const initiativeName = initiativeText.replace(/\s*\([^)]*\)\s*$/, '').trim();
            numberHelpText.innerHTML = `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Initiative "${initiativeName}" needs a number assigned. Please contact an administrator to assign a number (e.g., 31) to this initiative first.</span>`;
            hideValidation();
        }
    }
      function extractInitiativeNumber(initiativeText) {
        // Extract number from text like "Initiative Name (30)" or "Initiative Name"
        // Look for parentheses at the end containing what should be a number
        const match = initiativeText.match(/\(([^)]+)\)$/);
        if (match) {
            const extracted = match[1].trim();
            // Verify it's actually a number or alphanumeric code
            if (extracted && extracted.length > 0) {
                return extracted;
            }
        }
        return null;
    }
      function updateFinalNumberPreview() {
        const programNumber = programNumberField.value.trim();
        
        if (programNumber) {
            finalNumberPreview.textContent = programNumber;
            finalNumberDisplay.style.display = 'block';
        } else {
            finalNumberDisplay.style.display = 'none';
        }
    }
    
    function updateProgramNumber() {
        // This function is kept for compatibility but now just updates the preview
        updateFinalNumberPreview();
    }
      function validateProgramNumber() {
        const programNumber = programNumberField.value.trim();
        const initiativeId = initiativeSelect.value;
        
        if (!programNumber || !initiativeId) {
            hideValidation();
            return;
        }
        
        // Show loading
        showValidation('Checking availability...', 'text-info');
        
        // Validate via AJAX
        fetch('<?php echo APP_URL; ?>/app/ajax/program_numbering.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'validate_number',
                program_number: programNumber,
                initiative_id: initiativeId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.valid) {
                showValidation('✓ Number is available', 'text-success');
            } else {
                showValidation('✗ ' + (data.message || 'Number already in use'), 'text-danger');
            }
        })
        .catch(error => {
            console.error('Error validating program number:', error);
            showValidation('Error validating number', 'text-warning');
        });
    }
    
    function showValidation(message, className) {
        validationMessage.textContent = message;
        validationMessage.className = 'small ' + className;
        numberValidation.style.display = 'block';
    }
    
    function hideValidation() {
        numberValidation.style.display = 'none';
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeHierarchicalNumbering);
    } else {
        initializeHierarchicalNumbering();
    }
});
</script>

<!-- Attachment CSS -->
<style>
.upload-zone {
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
}

.upload-zone:hover,
.upload-zone.drag-over {
    border-color: #0d6efd;
    background-color: rgba(13, 110, 253, 0.1);
}

.attachment-item {
    transition: all 0.2s ease;
}

.attachment-item:hover {
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.review-target-table {
    width: 100%;
    border-collapse: collapse;
}

.review-target-table th,
.review-target-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
}

.review-target-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #495057;
}
</style>
