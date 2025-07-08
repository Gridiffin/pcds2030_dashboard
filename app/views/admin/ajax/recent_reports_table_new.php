<?php
/**
 * Recent Reports Table AJAX View
 * Simple endpoint to get recent reports data
 */

// Include necessary files
require_once '../../../../app/config/config.php';
require_once '../../../../app/lib/db_connect.php';
require_once '../../../../app/lib/session.php';
require_once '../../../../app/lib/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Access denied</div>';
    exit;
}

// Get recent reports from database
$query = "SELECT r.report_id, r.report_name, r.pptx_path, r.generated_at, r.is_public,
                 rp.quarter, rp.year, u.username
          FROM reports r 
          LEFT JOIN reporting_periods rp ON r.period_id = rp.period_id 
          LEFT JOIN users u ON r.generated_by = u.user_id 
          ORDER BY r.generated_at DESC 
          LIMIT 10";

$result = $conn->query($query);
$reports = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
}

// Format period function
function formatPeriod($report) {
    if (!$report || !isset($report['quarter'], $report['year'])) {
        return 'Unknown';
    }
    
    return get_period_display_name($report);
}
?>

<?php if (!empty($reports)): ?>
    <div class="table-responsive">
        <table class="table table-hover table-sm">
            <thead>
                <tr>
                    <th>Report Name</th>
                    <th>Period</th>
                    <th>Generated</th>
                    <th>By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $report): ?>
                    <tr>
                        <td>
                            <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" 
                                 title="<?php echo htmlspecialchars($report['report_name']); ?>">
                                <?php echo htmlspecialchars($report['report_name']); ?>
                            </div>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo formatPeriod($report); ?>
                            </small>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo date('M j, Y g:i A', strtotime($report['generated_at'])); ?>
                            </small>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo htmlspecialchars(isset($report['username']) ? $report['username'] : 'Unknown'); ?>
                            </small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <?php if (!empty($report['pptx_path'])): ?>
                                    <a href="<?php echo APP_URL; ?>/download.php?type=report&file=<?php echo urlencode($report['pptx_path']); ?>" 
                                       class="btn btn-outline-success btn-sm" 
                                       title="Download Report">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php endif; ?>                                <button type="button" 
                                        class="btn btn-outline-danger btn-sm delete-report-btn" 
                                        title="Delete Report"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteReportModal"
                                        data-report-id="<?php echo $report['report_id']; ?>" 
                                        data-report-name="<?php echo htmlspecialchars($report['report_name']); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="text-center text-muted py-4">
        <i class="fas fa-file-powerpoint fa-3x mb-3 opacity-50"></i>
        <p class="mb-0">No reports generated yet.</p>
        <small>Generated reports will appear here.</small>
    </div>
<?php endif; ?>
