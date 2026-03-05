<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions/grade_request.php';
requireAdmin();

$pdo = getDb();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle grade request processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'process') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes = trim($_POST['admin_notes'] ?? '');
        
        if ($requestId && in_array($status, ['approved', 'rejected'])) {
            $currentUser = currentUser();
            if (processGradeRequest($requestId, $currentUser['user_id'], $status, $notes)) {
                $_SESSION['flash_success'] = 'Grade request ' . $status . ' successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to process request.';
            }
        }
        redirect(base_url('admin/grade_requests.php'));
    }
}

// Get filter
$filter = $_GET['status'] ?? 'pending';
$allowedFilters = ['pending', 'approved', 'rejected'];
if (!in_array($filter, $allowedFilters)) {
    $filter = 'pending';
}

// Get grade requests
$requests = getGradeRequests($filter);
$pendingCount = getPendingGradeRequestCount();

$pageTitle = 'Grade Requests';
$breadcrumb = [
  ['label' => 'Admin', 'url' => base_url('admin/')],
  'Grade Requests',
];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php'), 'active' => true],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

ob_start();
?>
<h2 class="text-xl font-semibold mb-4">Grade Review Requests</h2>

<?php if ($flashSuccess): ?>
<div class="alert alert-success mb-4"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-error mb-4"><?= e($flashError) ?></div>
<?php endif; ?>

<div class="tabs tabs-boxed mb-4">
  <a href="?status=pending" class="tab <?= $filter === 'pending' ? 'tab-active' : '' ?>">
    Pending <?php if ($pendingCount > 0): ?>(<?= $pendingCount ?>)<?php endif; ?>
  </a>
  <a href="?status=approved" class="tab <?= $filter === 'approved' ? 'tab-active' : '' ?>">Approved</a>
  <a href="?status=rejected" class="tab <?= $filter === 'rejected' ? 'tab-active' : '' ?>">Rejected</a>
</div>

<?php if (empty($requests)): ?>
  <p class="text-base-content/70">No grade requests found.</p>
<?php else: ?>
  <div class="overflow-x-auto">
    <table class="table table-zebra">
      <thead>
        <tr>
          <th>Student</th>
          <th>Subject</th>
          <th>Current Grade</th>
          <th>Reason</th>
          <th>Requested</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td>
              <?= e($r['student_name']) ?><br>
              <span class="text-sm text-base-content/70"><?= e($r['student_email']) ?></span>
            </td>
            <td>
              <?= e($r['subject_code']) ?> - <?= e($r['subject_name']) ?><br>
              <span class="text-sm text-base-content/70"><?= e($r['course_code']) ?></span>
            </td>
            <td>
              <?php if ($r['grade'] !== null): ?>
                <span class="badge badge-<?= $r['academic_status'] === 'passed' ? 'success' : 'error' ?>">
                  <?= number_format($r['grade'], 2) ?>%
                </span>
              <?php else: ?>
                <span class="text-base-content/70">Not graded</span>
              <?php endif; ?>
            </td>
            <td class="max-w-xs"><?= e(mb_strimwidth($r['reason'], 0, 100, '...')) ?></td>
            <td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
            <td>
              <span class="badge badge-<?= 
                $r['status'] === 'pending' ? 'warning' : 
                ($r['status'] === 'approved' ? 'success' : 'error') 
              ?>">
                <?= e(ucfirst($r['status'])) ?>
              </span>
            </td>
            <td>
              <label for="modal-process-<?= (int)$r['request_id'] ?>" class="btn btn-ghost btn-sm">
                <?= $r['status'] === 'pending' ? 'Review' : 'View' ?>
              </label>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- Process Modal -->
<?php foreach ($requests as $r): ?>
<input type="checkbox" id="modal-process-<?= (int)$r['request_id'] ?>" class="modal-toggle">
<div class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg">Grade Request #<?= (int)$r['request_id'] ?></h3>
    
    <div class="py-4">
      <div class="grid grid-cols-2 gap-4 text-sm">
        <div>
          <span class="font-semibold">Student:</span>
          <p><?= e($r['student_name']) ?></p>
          <p class="text-base-content/70"><?= e($r['student_email']) ?></p>
        </div>
        <div>
          <span class="font-semibold">Subject:</span>
          <p><?= e($r['subject_code']) ?> - <?= e($r['subject_name']) ?></p>
          <p class="text-base-content/70"><?= e($r['course_code']) ?></p>
        </div>
        <div>
          <span class="font-semibold">Current Grade:</span>
          <p>
            <?php if ($r['grade'] !== null): ?>
              <span class="badge badge-<?= $r['academic_status'] === 'passed' ? 'success' : 'error' ?>">
                <?= number_format($r['grade'], 2) ?>%
              </span>
            <?php else: ?>
              Not graded
            <?php endif; ?>
          </p>
        </div>
        <div>
          <span class="font-semibold">Academic Status:</span>
          <p>
            <?php if ($r['academic_status']): ?>
              <span class="badge badge-<?= $r['academic_status'] === 'passed' ? 'success' : ($r['academic_status'] === 'failed' ? 'error' : 'warning') ?>">
                <?= ucfirst($r['academic_status']) ?>
              </span>
            <?php else: ?>
              Pending
            <?php endif; ?>
          </p>
        </div>
      </div>
      
      <div class="mt-4">
        <span class="font-semibold">Reason for Review:</span>
        <p class="mt-1 p-3 bg-base-200 rounded"><?= e($r['reason']) ?></p>
      </div>
      
      <?php if ($r['status'] !== 'pending'): ?>
        <div class="mt-4">
          <span class="font-semibold">Admin Notes:</span>
          <p class="mt-1 p-3 bg-base-200 rounded"><?= e($r['admin_notes'] ?? 'No notes provided') ?></p>
        </div>
        <div class="mt-2 text-sm text-base-content/70">
          Processed by <?= e($r['processed_by_name'] ?? 'Unknown') ?> on <?= e(date('M j, Y g:i A', strtotime($r['processed_at']))) ?>
        </div>
      <?php endif; ?>
    </div>
    
    <?php if ($r['status'] === 'pending'): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="process">
        <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
        
        <div class="form-control">
          <label class="label">Admin Notes (optional)</label>
          <textarea name="admin_notes" class="textarea textarea-bordered" rows="2" placeholder="Add notes about your decision..."></textarea>
        </div>
        
        <div class="modal-action">
          <label for="modal-process-<?= (int)$r['request_id'] ?>" class="btn">Cancel</label>
          <button type="submit" name="status" value="rejected" class="btn btn-error">Reject</button>
          <button type="submit" name="status" value="approved" class="btn btn-success">Approve</button>
        </div>
      </form>
    <?php else: ?>
      <div class="modal-action">
        <label for="modal-process-<?= (int)$r['request_id'] ?>" class="btn">Close</label>
      </div>
    <?php endif; ?>
  </div>
  <label class="modal-backdrop" for="modal-process-<?= (int)$r['request_id'] ?>">Close</label>
</div>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
