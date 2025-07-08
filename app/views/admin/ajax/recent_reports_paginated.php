<?php
/**
 * Recent Reports Paginated AJAX Endpoint
 * 
 * Returns paginated HTML and metadata for the recent reports section
 * Supports search, pagination, and maintains existing functionality
 */

// Include necessary files
require_once '../../../config/config.php';
require_once '../../../lib/db_connect.php';
require_once '../../../lib/session.php';
require_once '../../../lib/functions.php';
require_once '../../../lib/admins/index.php';

// Verify user is admin
if (!is_admin()) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get pagination parameters
$page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
$per_page = min(50, max(5, intval(isset($_GET['per_page']) ? $_GET['per_page'] : 10))); // Min 5, max 50
$search = trim(isset($_GET['search']) ? $_GET['search'] : '');

// Calculate offset
$offset = ($page - 1) * $per_page;

/**
 * Format period display name
 * @param array $report Report data with quarter and year
 * @return string Formatted period name
 */
function formatPeriod($report) {
    if (!$report || !isset($report['quarter'], $report['year'])) {
        return 'Unknown';
    }
    
    return get_period_display_name($report);
}

/**
 * Check if a report should display the "NEW" badge
 * @param array $report Report data
 * @return bool True if report should show NEW badge
 */
function shouldShowNewBadge($report) {
    if (!$report || !isset($report['generated_at'])) {
        return false;
    }
    
    // Show badge for reports generated in the last 10 minutes
    $generatedTime = strtotime($report['generated_at']);
    $currentTime = time();
    $tenMinutesAgo = $currentTime - (10 * 60); // 10 minutes in seconds
    
    return $generatedTime > $tenMinutesAgo;
}

/**
 * Get paginated reports with search capability
 */
function getPaginatedReports($offset, $per_page, $search = '') {
    global $conn;
    
    // Base query
    $base_query = "FROM reports r 
                   LEFT JOIN reporting_periods rp ON r.period_id = rp.period_id 
                   LEFT JOIN users u ON r.generated_by = u.user_id";
    
    $where_clause = "";
    $params = [];
    $param_types = "";
    
    // Add search conditions if search term provided
    if (!empty($search)) {
        $where_clause = " WHERE (
            r.report_name LIKE ? OR 
            u.username LIKE ? OR 
            CONCAT('Q', rp.quarter, ' ', rp.year) LIKE ? OR
            CASE 
                WHEN rp.quarter = 5 THEN CONCAT('H1 ', rp.year)
                WHEN rp.quarter = 6 THEN CONCAT('H2 ', rp.year)
                ELSE CONCAT('Q', rp.quarter, ' ', rp.year)
            END LIKE ?
        )";
        $search_param = "%{$search}%";
        $params = [$search_param, $search_param, $search_param, $search_param];
        $param_types = "ssss";
    }
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total " . $base_query . $where_clause;
    $count_stmt = $conn->prepare($count_query);
    
    if (!empty($params)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_reports = $count_result->fetch_assoc()['total'];
    
    // Get paginated reports
    $reports_query = "SELECT r.report_id, r.report_name, r.pptx_path, r.generated_at, r.is_public,
                             rp.quarter, rp.year, u.username " . 
                     $base_query . $where_clause . 
                     " ORDER BY r.generated_at DESC LIMIT ? OFFSET ?";
    
    $reports_stmt = $conn->prepare($reports_query);
    
    // Add pagination parameters to existing params
    $params[] = $per_page;
    $params[] = $offset;
    $param_types .= "ii";
    
    if (!empty($params)) {
        $reports_stmt->bind_param($param_types, ...$params);
    }
    
    $reports_stmt->execute();
    $reports_result = $reports_stmt->get_result();
    
    $reports = [];
    while ($row = $reports_result->fetch_assoc()) {
        $reports[] = $row;
    }
    
    return [
        'reports' => $reports,
        'total' => $total_reports
    ];
}

try {
    // Get paginated data
    $result = getPaginatedReports($offset, $per_page, $search);
    $reports = $result['reports'];
    $total_reports = $result['total'];
    
    // Calculate pagination metadata
    $total_pages = ceil($total_reports / $per_page);
    
    // Determine response format
    $format = isset($_GET['format']) ? $_GET['format'] : 'html';
    
    if ($format === 'json') {
        // Return JSON response for AJAX calls
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'reports' => $reports,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_reports' => $total_reports,
                'total_pages' => $total_pages,
                'has_previous' => $page > 1,
                'has_next' => $page < $total_pages,
                'search' => $search
            ]
        ]);
        exit;
    }
    
    // Return HTML response (default)
    ?>
    <div class="reports-content">
        <?php if (!empty($reports)): ?>
            <div class="recent-reports-grid">
                <?php foreach ($reports as $report): ?>
                    <div class="report-card" data-report-id="<?php echo $report['report_id']; ?>">
                        <?php if (shouldShowNewBadge($report)): ?>
                            <span class="new-report-badge">NEW</span>
                        <?php endif; ?>
                        <div class="report-card-body">
                            <div class="report-info">
                                <h6 class="report-title" title="<?php echo htmlspecialchars($report['report_name']); ?>">
                                    <?php echo htmlspecialchars($report['report_name']); ?>
                                </h6>
                                <div class="report-meta">
                                    <span class="period-badge">
                                        <i class="fas fa-calendar me-1"></i>
                                        <?php echo formatPeriod($report); ?>
                                    </span>
                                    <span class="date-badge">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('M j, Y g:i A', strtotime($report['generated_at'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="report-actions">
                                <?php if (!empty($report['pptx_path'])): ?>
                                    <a href="<?php echo APP_URL; ?>/download.php?type=report&file=<?php echo urlencode($report['pptx_path']); ?>" 
                                       class="btn btn-success btn-sm" 
                                       title="Download Report">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php endif; ?>
                                <button type="button" 
                                        class="btn btn-outline-danger btn-sm delete-report-btn" 
                                        title="Delete Report"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteReportModal"
                                        data-report-id="<?php echo $report['report_id']; ?>" 
                                        data-report-name="<?php echo htmlspecialchars($report['report_name']); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state text-center py-5">
                <?php if (!empty($search)): ?>
                    <i class="fas fa-search fa-4x text-muted mb-3"></i>
                    <h5 class="text-muted">No reports found</h5>
                    <p class="text-muted mb-3">No reports match your search criteria: <strong>"<?php echo htmlspecialchars($search); ?>"</strong></p>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearSearchAndReload()">
                        <i class="fas fa-times me-1"></i>Clear Search
                    </button>
                <?php else: ?>
                    <i class="fas fa-file-powerpoint fa-4x text-muted mb-3"></i>
                    <h5 class="text-muted">No reports generated yet</h5>
                    <p class="text-muted mb-3">Get started by generating your first report below.</p>
                    <button type="button" class="btn btn-primary" id="generateReportToggleEmpty">
                        <i class="fas fa-plus me-1"></i>Generate First Report
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($total_reports > 0): ?>
        <div class="pagination-section mt-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <!-- Results Info -->
                <div class="pagination-info mb-2 mb-md-0">
                    <small class="text-muted">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_reports); ?> 
                        of <?php echo $total_reports; ?> reports
                        <?php if (!empty($search)): ?>
                            for "<?php echo htmlspecialchars($search); ?>"
                        <?php endif; ?>
                    </small>
                </div>
                
                <!-- Page Size Selector -->
                <div class="page-size-selector mb-2 mb-md-0">
                    <small class="text-muted me-2">Show:</small>
                    <select class="form-select form-select-sm d-inline-block w-auto" id="pageSizeSelect" data-current="<?php echo $per_page; ?>">
                        <option value="5" <?php echo $per_page == 5 ? 'selected' : ''; ?>>5</option>
                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                    </select>
                    <small class="text-muted ms-2">per page</small>
                </div>
                
                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Reports pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous Button -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <button class="page-link" data-page="<?php echo $page - 1; ?>" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                            </li>
                            
                            <?php
                            // Calculate page range to show
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            // Show first page if not in range
                            if ($start_page > 1): ?>
                                <li class="page-item">
                                    <button class="page-link" data-page="1">1</button>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <button class="page-link" data-page="<?php echo $i; ?>"><?php echo $i; ?></button>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Show last page if not in range -->
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <button class="page-link" data-page="<?php echo $total_pages; ?>"><?php echo $total_pages; ?></button>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Next Button -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <button class="page-link" data-page="<?php echo $page + 1; ?>" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Store pagination metadata for JavaScript -->
    <script>
        window.reportsPaginationData = {
            currentPage: <?php echo $page; ?>,
            perPage: <?php echo $per_page; ?>,
            totalReports: <?php echo $total_reports; ?>,
            totalPages: <?php echo $total_pages; ?>,
            search: <?php echo json_encode($search); ?>,
            hasPrevious: <?php echo $page > 1 ? 'true' : 'false'; ?>,
            hasNext: <?php echo $page < $total_pages ? 'true' : 'false'; ?>
        };
    </script>
    
<?php
} catch (Exception $e) {
    error_log("Error in recent_reports_paginated.php: " . $e->getMessage());
    
    if ((isset($_GET['format']) ? $_GET['format'] : 'html') === 'json') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'An error occurred while loading reports.'
        ]);
    } else {
        ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            An error occurred while loading reports. Please try again.
        </div>
        <?php
    }
}
?>
