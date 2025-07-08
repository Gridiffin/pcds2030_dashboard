<?php
/**
 * Recent Reports Table AJAX Endpoint
 * 
 * This endpoint now redirects to the new paginated endpoint for compatibility
 * with existing code that may still call this endpoint.
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
    echo '<div class="alert alert-danger">Access denied</div>';
    exit;
}

// Get any existing parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
$per_page = min(50, max(5, intval(isset($_GET['per_page']) ? $_GET['per_page'] : 10)));

// Redirect to the new paginated endpoint
$params = http_build_query([
    'page' => $page,
    'per_page' => $per_page,
    'search' => $search,
    'format' => 'html'
]);

// Include the paginated endpoint directly
$_GET['page'] = $page;
$_GET['per_page'] = $per_page;
$_GET['search'] = $search;
$_GET['format'] = 'html';

include 'recent_reports_paginated.php';
?>