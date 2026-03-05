<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions/grade_request.php';
requireStudent();

$user = currentUser();
$pdo = getDb();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Get student's enrollments with grades
$enrollmentsStmt = $pdo->prepare("
  SELECT e.enrollment_id, e.grade, e.academic_status,
    s.subject_code, s.subject_name, s.units,
    c.course_code
  FROM tbl_enrollments e
  JOIN tbl_subjects s ON s.subject_id = e.subject_id
  JOIN tbl_course c ON c.course_id = s.course_id
  WHERE e.student_id = ?
  AND e.grade IS NOT NULL
  ORDER BY c.course_code, s.subject_code
");
$enrollmentsStmt->execute([$user['user_id']]);
$enrollments = $enrollmentsStmt->fetchAll();

// Handle grade request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if ($enrollmentId && $reason) {
            $requestId = submitGradeRequest($enrollmentId, $user['user_id'], $reason);
            if ($requestId) {
                $_SESSION['flash_success'] = 'Grade review request submitted successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to submit request. You may already have a pending request for this subject.';
            }
        } else {
            $_SESSION['flash_error'] = 'Please select a subject and provide a reason.';
        }
        redirect(base_url('student/grade_request.php'));
    }
}

// Get student's grade requests
$myRequests = getGradeRequests(null, $user['user_id']);

$pageTitle = 'Grade Request';
$breadcrumb = [
  ['label' => 'Student', 'url' => base_url('student/')],
  'Grade Request',
];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/')],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php')],
  ['label' => 'My profile', 'url' => base_url('student/profile.php')],
  ['label' => 'Enroll in subjects', 'url' => base_url('student/enroll.php')],
  ['label' => 'My records', 'url' => base_url('student/records.php')],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php')],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php'), 'active' => true],
  ['label' => 'Activities', 'url' => base_url('student/activities.php')],
];

ob_start();
?>
<h2 class="text-xl font-semibold mb-4">Grade Request</h2>

<?php if ($flashSuccess): ?>
<div class="alert alert-success mb-4"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-error mb-4"><?= e($flashError) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <!-- Submit Request Form -->
  <div>
    <div class="card bg-base-200 shadow">
      <div class="card-body">
        <h3 class="card-title">Submit Grade Request</h3>
        <p class="text-sm text-base-content/70 mb-4">
          If you believe there is an error in your grade, you can submit a formal request for review.
        </p>
        
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="submit">
          
          <div class="form-control">
            <label class="label">Select Subject</label>
            <select name="enrollment_id" class="select select-bordered" required>
              <option value="">-- Select a subject --</option>
              <?php foreach ($enrollments as $e): ?>
                <?php 
                // Check if there's already a pending request
                $hasPending = hasPendingGradeRequest($e['enrollment_id'], $user['user_id']);
                ?>
                <option value="<?= (int)$e['enrollment_id'] ?>" <?= $hasPending ? 'disabled' : '' ?>>
                  <?= e($e['subject_code']) ?> - <?= e($e['subject_name']) ?> 
                  (Grade: <?= $e['grade'] !== null ? number_format($e['grade'], 2) . '%' : 'N/A' ?>)
                  <?= $hasPending ? ' - Pending Request' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-control">
            <label class="label">Reason for Review</label>
            <textarea name="reason" class="textarea textarea-bordered" rows="4" 
              placeholder="Explain why you believe your grade should be reviewed..." required></textarea>
          </div>
          
          <div class="card-actions justify-end mt-4">
            <button type="submit" class="btn btn-primary">Submit Request</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  
  <!-- My Requests -->
  <div>
    <div class="card bg-base-200 shadow">
      <div class="card-body">
        <h3 class="card-title">My Requests</h3>
        
        <?php if (empty($myRequests)): ?>
          <p class="text-base-content/70">You haven't submitted any grade review requests.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="table table-xs">
              <thead>
                <tr>
                  <th>Subject</th>
                  <th>Grade</th>
                  <th>Status</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($myRequests as $req): ?>
                  <tr>
                    <td>
                      <?= e($req['subject_code']) ?><br>
                      <span class="text-xs text-base-content/70"><?= e($req['subject_name']) ?></span>
                    </td>
                    <td><?= $req['grade'] !== null ? number_format($req['grade'], 2) . '%' : 'N/A' ?></td>
                    <td>
                      <span class="badge badge-xs badge-<?= 
                        $req['status'] === 'pending' ? 'warning' : 
                        ($req['status'] === 'approved' ? 'success' : 'error') 
                      ?>">
                        <?= ucfirst($req['status']) ?>
                      </span>
                    </td>
                    <td><?= e(date('M j, Y', strtotime($req['created_at']))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
