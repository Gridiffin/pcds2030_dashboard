<?php
/**
 * Admin Dashboard
 * 
 * Main interface for admin users.
 */

// Include necessary files
require_once '../../config/config.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_functions.php';

// Verify user is admin
if (!is_admin()) {
    header('Location: ../../login.php');
    exit;
}

// Set page title
$pageTitle = 'Admin Dashboard';

// Get current reporting period
$current_period = get_current_reporting_period();

// Add period_id handling for historical views
$period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : ($current_period['period_id'] ?? null);
$viewing_period = $period_id ? get_reporting_period($period_id) : $current_period;

// Get data for the dashboard
$submission_stats = get_period_submission_stats($period_id);
$sector_data = get_sector_data_for_period($period_id);
$recent_submissions = get_recent_submissions($period_id, 5);

// Additional styles
$additionalStyles = [
    APP_URL . '/assets/css/custom/admin.css'
];

// Additional scripts
$additionalScripts = [
    APP_URL . '/assets/js/charts/chart.min.js',
    APP_URL . '/assets/js/admin/dashboard_charts.js',
    APP_URL . '/assets/js/period_selector.js'
];

// Include header
require_once '../layouts/header.php';

// Include admin navigation
require_once '../layouts/admin_nav.php';
?>

<!-- Page Header -->
<div class="page-header pb-10">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-title">
                    <h3>Admin Dashboard</h3>
                    <p class="text-subtitle text-white">
                        PCDS 2030 System Performance Overview
                    </p>
                </div>
            </div>
            <div class="col-auto">
                <button id="refreshPage" class="btn btn-light">
                    <i class="fas fa-sync-alt me-1"></i> Refresh Data
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Dashboard Content -->
<section class="section">
    <div class="container-fluid">
        <!-- Period Selector Component -->
        <?php require_once '../../includes/period_selector.php'; ?>

        <!-- Quick Actions Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title m-0 text-dark"><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row justify-content-center text-center g-3">
                            <div class="col-lg-3 col-md-4 col-6">
                                <a href="manage_programs.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center py-3">
                                    <i class="fas fa-project-diagram fa-lg mb-2"></i>
                                    <span>Manage Programs</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-4 col-6">
                                <a href="manage_users.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center py-3">
                                    <i class="fas fa-users fa-lg mb-2"></i>
                                    <span>Manage Users</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-4 col-6">
                                <a href="manage_metrics.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center py-3">
                                    <i class="fas fa-chart-line fa-lg mb-2"></i>
                                    <span>Manage Metrics</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-4 col-6">
                                <a href="generate_reports.php" class="btn btn-outline-success w-100 h-100 d-flex flex-column align-items-center justify-content-center py-3">
                                    <i class="fas fa-file-powerpoint fa-lg mb-2"></i>
                                    <span>Generate Reports</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Overview -->
        <div data-period-content="stats_section">
            <div class="row">
                <!-- Agencies Reporting Card -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card primary">
                        <div class="card-body">
                            <div class="stat-card-header">
                                <div class="icon-container">
                                    <i class="fas fa-users stat-icon"></i>
                                </div>
                            </div>
                            <div class="stat-card-content">
                                <div class="stat-title">Agencies Reporting</div>
                                <div class="stat-value">
                                    <?php echo $submission_stats['agencies_reported'] ?? 0; ?>/<?php echo $submission_stats['total_agencies'] ?? 0; ?>
                                </div>
                                <div class="stat-subtitle">
                                    <i class="fas fa-check me-1"></i>
                                    <?php echo $submission_stats['agencies_reported'] ?? 0; ?> Agencies Reported
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Programs On Track Card (repeat structure for other cards) -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card success">
                        <div class="card-body">
                            <div class="stat-card-header">
                                <div class="icon-container">
                                    <i class="fas fa-check-circle stat-icon"></i>
                                </div>
                            </div>
                            <div class="stat-card-content">
                                <div class="stat-title">Programs On Track</div>
                                <div class="stat-value">
                                    <?php echo $submission_stats['on_track_programs'] ?? 0; ?>
                                </div>
                                <?php if (isset($submission_stats['total_programs']) && $submission_stats['total_programs'] > 0): ?>
                                <div class="stat-subtitle">
                                    <i class="fas fa-chart-line me-1"></i>
                                    <?php echo round(($submission_stats['on_track_programs'] / $submission_stats['total_programs']) * 100); ?>% of total
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Programs Delayed Card -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card warning">
                        <div class="card-body">
                            <div class="stat-card-header">
                                <div class="icon-container">
                                    <i class="fas fa-exclamation-triangle stat-icon"></i>
                                </div>
                            </div>
                            <div class="stat-card-content">
                                <div class="stat-title">Programs Delayed</div>
                                <div class="stat-value">
                                    <?php echo $submission_stats['delayed_programs'] ?? 0; ?>
                                </div>
                                <?php if (isset($submission_stats['total_programs']) && $submission_stats['total_programs'] > 0): ?>
                                <div class="stat-subtitle">
                                    <i class="fas fa-chart-line me-1"></i>
                                    <?php echo round(($submission_stats['delayed_programs'] / $submission_stats['total_programs']) * 100); ?>% of total
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Overall Completion Card -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card info">
                        <div class="card-body">
                            <div class="stat-card-header">
                                <div class="icon-container">
                                    <i class="fas fa-clipboard-list stat-icon"></i>
                                </div>
                            </div>
                            <div class="stat-card-content">
                                <div class="stat-title">Overall Completion</div>
                                <div class="stat-value">
                                    <?php echo $submission_stats['completion_percentage'] ?? 0; ?>%
                                </div>
                                <div class="stat-subtitle progress mt-2" style="height: 10px;">
                                    <div class="progress-bar bg-info" role="progressbar" 
                                         style="width: <?php echo $submission_stats['completion_percentage'] ?? 0; ?>%"
                                         aria-valuenow="<?php echo $submission_stats['completion_percentage'] ?? 0; ?>" 
                                         aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Sector Overview -->
            <div class="col-lg-8 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title m-0">Sector Overview</h5>
                        <a href="sector_details.php" class="btn btn-sm btn-outline-primary">View Details</a>
                    </div>
                    <div class="card-body" data-period-content="sectors_section">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Sector</th>
                                        <th>Agencies</th>
                                        <th>Programs</th>
                                        <th>Submissions</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sector_data as $sector): ?>
                                        <tr>
                                            <td><?php echo $sector['sector_name']; ?></td>
                                            <td><?php echo $sector['agency_count']; ?></td>
                                            <td><?php echo $sector['program_count']; ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-<?php echo $sector['submission_pct'] >= 100 ? 'success' : 'primary'; ?>" 
                                                         style="width: <?php echo $sector['submission_pct']; ?>%">
                                                        <?php echo $sector['submission_pct']; ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($sector['submission_pct'] >= 100): ?>
                                                    <span class="badge bg-success">Complete</span>
                                                <?php elseif ($sector['submission_pct'] >= 75): ?>
                                                    <span class="badge bg-info">Almost Complete</span>
                                                <?php elseif ($sector['submission_pct'] >= 25): ?>
                                                    <span class="badge bg-warning">In Progress</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Just Started</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Submissions -->
            <div class="col-lg-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="card-title m-0">Recent Submissions</h5>
                    </div>
                    <div class="card-body" data-period-content="submissions_section">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Agency</th>
                                        <th>Program</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_submissions)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-3">No recent submissions for this period</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_submissions as $submission): ?>
                                            <tr>
                                                <td><?php echo $submission['agency_name']; ?></td>
                                                <td><?php echo $submission['program_name']; ?></td>
                                                <td>
                                                    <?php 
                                                        $status_class = 'secondary';
                                                        switch ($submission['status']) {
                                                            case 'on-track': $status_class = 'success'; break;
                                                            case 'delayed': $status_class = 'warning'; break;
                                                            case 'completed': $status_class = 'primary'; break;
                                                            case 'not-started': $status_class = 'secondary'; break;
                                                        }
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo ucfirst(str_replace('-', ' ', $submission['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, g:i a', strtotime($submission['submission_date'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
// Include footer
require_once '../layouts/footer.php';
?>
