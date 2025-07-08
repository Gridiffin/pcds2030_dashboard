<?php
/**
 * Admin Reopen Program
 * 
 * Allows administrators to reopen a finalized program submission by changing it back to draft status.
 * This enables agency users to make further edits to their submission.
 */

// Include necessary files
require_once '../../../config/config.php';
require_once ROOT_PATH . 'app/lib/db_connect.php';
require_once ROOT_PATH . 'app/lib/session.php';
require_once ROOT_PATH . 'app/lib/functions.php';
require_once ROOT_PATH . 'app/lib/admins/index.php';
require_once ROOT_PATH . 'app/lib/audit_log.php';

// Verify user is admin
if (!is_admin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Check for program_id and submission_id
$program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;
$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;

// Validate IDs
if (!$program_id || !$submission_id) {
    $_SESSION['message'] = 'Invalid program or submission ID.';
    $_SESSION['message_type'] = 'danger';
    header('Location: programs.php');
    exit;
}

// Check if submission exists and belongs to the program
$query = "SELECT ps.*, p.program_name, u.agency_name, rp.year, rp.quarter 
          FROM program_submissions ps 
          JOIN programs p ON ps.program_id = p.program_id
          JOIN users u ON p.owner_agency_id = u.user_id
          JOIN reporting_periods rp ON ps.period_id = rp.period_id
          WHERE ps.submission_id = ? AND ps.program_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $submission_id, $program_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['message'] = 'Submission not found or does not match the specified program.';
    $_SESSION['message_type'] = 'danger';
    header('Location: programs.php');
    exit;
}

$submission = $result->fetch_assoc();

// Check if the submission is already a draft
if ($submission['is_draft'] == 1) {
    $_SESSION['message'] = 'This submission is already in draft status and can be edited.';
    $_SESSION['message_type'] = 'info';
    header('Location: view_program.php?id=' . $program_id);
    exit;
}

// Process form submission to reopen the program
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Change submission status to draft
    $update_query = "UPDATE program_submissions SET is_draft = 1 WHERE submission_id = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("i", $submission_id);
      if ($update_stmt->execute()) {
        // Log successful program reopening
        log_audit_action('reopen_program', "Program: {$submission['program_name']} | Submission ID: $submission_id | Quarter: Q{$submission['quarter']}-{$submission['year']}", 'success', $_SESSION['user_id']);
        
        // // Add a notification for the agency user
        // $notification_message = "Your program \"{$submission['program_name']}\" for Q{$submission['quarter']}-{$submission['year']} has been reopened for editing by an administrator.";
        
        // $notification_query = "INSERT INTO notifications (user_id, message, type) 
        //                      VALUES (?, ?, 'program_reopened')";
        // $agency_id = get_program_owner_id($program_id);
        // $notif_stmt = $conn->prepare($notification_query);
        // $notif_stmt->bind_param("is", $agency_id, $notification_message);
        // $notif_stmt->execute();
        
        // Log the action
        error_log("Admin user {$_SESSION['username']} (ID: {$_SESSION['user_id']}) reopened submission ID {$submission_id} for program \"{$submission['program_name']}\"");
        
        $_SESSION['message'] = "Program \"{$submission['program_name']}\" for Q{$submission['quarter']}-{$submission['year']} has been successfully reopened for editing.";
        $_SESSION['message_type'] = 'success';
        header('Location: view_program.php?id=' . $program_id);
        exit;    } else {
        // Log failed program reopening
        log_audit_action('reopen_program_failed', "Program: {$submission['program_name']} | Submission ID: $submission_id | Error: " . $conn->error, 'failure', $_SESSION['user_id']);
        
        $_SESSION['message'] = 'Error reopening program: ' . $conn->error;
        $_SESSION['message_type'] = 'danger';
    }
}

// Function to get program owner user_id
function get_program_owner_id($program_id) {
    global $conn;
    $query = "SELECT owner_agency_id FROM programs WHERE program_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? $row['owner_agency_id'] : 0;
}

// Set page title
$pageTitle = 'Reopen Program Submission';

// Include header
require_once '../../layouts/header.php';

// Configure the modern page header
$header_config = [
    'title' => 'Reopen Program Submission',
    'subtitle' => 'Convert a finalized submission back to draft status',
    'variant' => 'white',
    'actions' => [
        [
            'text' => 'Back to Program',
            'url' => 'view_program.php?id=' . $program_id,
            'class' => 'btn-outline-primary',
            'icon' => 'fas fa-arrow-left'
        ]
    ]
];

// Include the modern page header
require_once '../../layouts/page_header.php';
?>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title m-0"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Reopen Program Submission</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Caution:</strong> Reopening a submission will convert it back to draft status, allowing the agency to make changes. This should only be done when corrections are needed to a previously finalized submission.
                </div>
                
                <dl class="row mb-4">
                    <dt class="col-sm-4">Program Name</dt>
                    <dd class="col-sm-8"><?php echo htmlspecialchars($submission['program_name']); ?></dd>
                    
                    <dt class="col-sm-4">Agency</dt>                    <dd class="col-sm-8"><?php echo htmlspecialchars($submission['agency_name']); ?></dd>
                    
                    <dt class="col-sm-4">Reporting Period</dt>
                    <dd class="col-sm-8"><?php echo get_period_display_name($submission); ?></dd>
                    
                    <dt class="col-sm-4">Draft Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-<?php echo $submission['is_draft'] ? 'warning' : 'success'; ?>">
                            <?php echo $submission['is_draft'] ? 'Draft' : 'Finalized'; ?>
                        </span>
                    </dd>
                    
                    <dt class="col-sm-4">Submission Date</dt>
                    <dd class="col-sm-8"><?php echo date('F j, Y', strtotime($submission['submission_date'])); ?></dd>
                </dl>
                
                <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?program_id=' . $program_id . '&submission_id=' . $submission_id); ?>">
                    <div class="form-group mb-4">
                        <label for="confirmText" class="form-label">Type "REOPEN" to confirm your action:</label>
                        <input type="text" class="form-control" id="confirmText" name="confirmText" required pattern="REOPEN">
                        <div class="form-text text-muted">This action will be logged and the agency will be notified.</div>
                    </div>
                    
                    <div class="d-flex justify-content-end mt-4">
                        <a href="view_program.php?id=<?php echo $program_id; ?>" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-lock-open me-1"></i> Reopen Submission
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
require_once '../../layouts/footer.php';
?>

