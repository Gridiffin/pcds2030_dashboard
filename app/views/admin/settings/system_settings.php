<?php
/**
 * System Settings
 * 
 * Admin page for configuring system-wide settings.
 */

// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admins/index.php';
require_once ROOT_PATH . 'app/lib/admins/settings.php';

// Verify user is an admin
if (!is_admin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Process form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['multi_sector'])) {
        $multi_sector = ($_POST['multi_sector'] === '1');
        $result = update_multi_sector_setting($multi_sector);
        
        if (isset($result['error'])) {
            $message = $result['error'];
            $messageType = 'danger';
        } elseif (isset($result['warning'])) {
            $message = $result['warning'];
            $messageType = 'warning';
        } elseif (isset($result['success'])) {
            $message = $result['message'];
            $messageType = 'success';
        }
    }
    
    // Process outcome creation setting
    if (isset($_POST['allow_outcome_creation'])) {
        $allow_outcome_creation = ($_POST['allow_outcome_creation'] === '1');
        $result = update_outcome_creation_setting($allow_outcome_creation);
        
        if (isset($result['error'])) {
            $message = $result['error'];
            $messageType = 'danger';
        } elseif (isset($result['warning'])) {
            $message = $result['warning'];
            $messageType = 'warning';
        } elseif (isset($result['success'])) {
            $message = $result['message'];
            $messageType = 'success';
        }
    }
}

// Get current settings state
$multi_sector_enabled = get_multi_sector_setting();
$allow_outcome_creation_enabled = get_outcome_creation_setting();

// Set page title
$pageTitle = 'System Settings';

// Include header
require_once '../../layouts/header.php';

// Configure the modern page header
$header_config = [
    'title' => 'System Settings',
    'subtitle' => 'Configure system-wide settings',
    'variant' => 'green',
    'actions' => []
];

// Include the modern page header
require_once '../../layouts/page_header.php';
?>

<main class="flex-fill">
<div class="container-fluid px-4 py-4"><?php if (!empty($message)): ?>
        <div class="alert alert-forest alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle alert-icon"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>    <!-- System Settings Card -->
    <div class="card admin-card mb-4">
        <div class="card-header">
            <h5 class="card-title m-0">General Settings</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <!-- Multi-Sector Mode Section -->
                <div class="mb-4">
                    <h6 class="mb-1">Multi-Sector Mode</h6>
                    <p class="text-muted mb-2">
                        Enable or disable multi-sector functionality. When enabled, the dashboard will display all sectors.
                        When disabled, the dashboard will focus exclusively on the Forestry sector.
                    </p>
                    <div class="form-check form-switch forest-switch mt-3">
                        <!-- Hidden field to ensure the form value is always sent -->
                        <input type="hidden" name="multi_sector" value="0">
                        
                        <input type="checkbox" class="form-check-input" id="multiSectorToggle" name="multi_sector" value="1"
                            <?php echo $multi_sector_enabled ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="multiSectorToggle">
                            <?php echo $multi_sector_enabled ? 'Enabled - All Sectors Visible' : 'Disabled - Forestry Sector Only'; ?>
                        </label>
                    </div>
                </div>                <!-- Outcome Creation Section -->
                <div class="mb-4">
                    <h6 class="mb-1">Outcome Creation</h6>
                    <p class="text-muted mb-2">
                        Allow or disallow the creation of new outcomes. When disabled, agencies and admins can only use existing 
                        outcome templates, ensuring consistent reporting structure across periods.
                    </p>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Important:</strong> Based on client requirements, outcomes are now managed with the following restrictions:
                        <ul class="mb-0 mt-2">
                            <li>Creation of new outcomes can be locked behind this admin toggle</li>
                            <li>Deletion of outcomes is no longer allowed to maintain historical consistency</li>
                            <li>Outcome history is tracked to support the workflow where agencies submit outcomes, 
                                admins generate reports, then unsubmit for the next period</li>
                        </ul>
                    </div>
                    <div class="form-check form-switch forest-switch mt-3">
                        <!-- Hidden field to ensure the form value is always sent -->
                        <input type="hidden" name="allow_outcome_creation" value="0">
                        
                        <input type="checkbox" class="form-check-input" id="outcomeCreationToggle" name="allow_outcome_creation" value="1"
                            <?php echo $allow_outcome_creation_enabled ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="outcomeCreationToggle">
                            <?php echo $allow_outcome_creation_enabled ? 'Enabled - Outcome Creation Allowed' : 'Disabled - Outcome Creation Restricted'; ?>
                        </label>
                    </div>
                </div>

                <!-- Current Status Section -->
                <div class="mb-4">
                    <div class="system-status-card">
                        <div class="card-body">
                            <h6 class="card-title">Current Status</h6>
                            <span class="status-indicator <?php echo $multi_sector_enabled ? 'status-success' : 'status-info'; ?> mb-2">
                                <?php echo $multi_sector_enabled ? 'Enabled' : 'Disabled'; ?>
                            </span>
                            <p class="small text-secondary mb-0">
                                <?php echo $multi_sector_enabled ? 'Dashboard will display all sectors.' : 'Dashboard is focused on the Forestry sector only.'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-forest alert-info">
                    <i class="fas fa-info-circle alert-icon"></i>
                    <strong>Note:</strong> Changing this setting will affect the entire system. All users may need to refresh their browsers to see the changes.
                </div>
                
                <button type="submit" class="btn btn-forest">
                    <i class="fas fa-save me-1"></i> Save Settings
                </button>
            </form>
        </div>
    </div>
      <!-- Other System Information Card -->
    <div class="card admin-card">
        <div class="card-header">
            <h5 class="card-title m-0">System Information</h5>
        </div>
        <div class="card-body">            <div class="row g-4">
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light">
                        <h6 class="text-forest mb-2">Application Version</h6>
                        <p class="h5 mb-0"><?php echo APP_VERSION; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light">
                        <h6 class="text-forest mb-2">PHP Version</h6>
                        <p class="h5 mb-0"><?php echo phpversion(); ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light">
                        <h6 class="text-forest mb-2">MySQL Version</h6>
                        <p class="h5 mb-0"><?php echo $conn->server_info; ?></p>
                    </div>
                </div>
            </div>
              <div class="alert alert-forest alert-info mt-3">
                <h6 class="text-forest"><i class="fas fa-flag me-2"></i>Implementation Scope</h6>
                <p class="mb-0">
                    <?php if ($multi_sector_enabled): ?>
                        This system is configured for all government sectors, including Forestry, Health, Education, and more.
                        Agency users can view data across sectors.
                    <?php else: ?>
                        This system is currently configured for the <strong>Forestry sector only</strong>. The following agencies are included:
                        <ul class="mb-0 mt-2">
                            <li>Forestry Department</li>
                            <li>Sarawak Forestry Corporation (SFC)</li>
                            <li>Sarawak Timber Industry Development Corporation (STIDC)</li>
                        </ul>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
// Update label text when toggle changes
document.addEventListener('DOMContentLoaded', function() {
    const toggleSwitch = document.getElementById('multiSectorToggle');
    const outcomeToggleSwitch = document.getElementById('outcomeCreationToggle');
    if (toggleSwitch) {
        toggleSwitch.addEventListener('change', function() {
            const label = this.nextElementSibling;
            if (this.checked) {
                label.textContent = 'Enabled - All Sectors Visible';
            } else {
                label.textContent = 'Disabled - Forestry Sector Only';
            }
        });
    }
    
    if (outcomeToggleSwitch) {
        outcomeToggleSwitch.addEventListener('change', function() {
            const label = this.nextElementSibling;
            if (this.checked) {
                label.textContent = 'Enabled - Outcome Creation Allowed';
            } else {
                label.textContent = 'Disabled - Outcome Creation Restricted';
            }
        });
    }
});
</script>
</main>

<?php
// Include footer
require_once '../../layouts/footer.php';
?>

