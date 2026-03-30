<?php
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$user = currentUser();
$pdo = getDb();

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Create notifications table if it doesn't exist
require_once __DIR__ . '/../config/database.php';
ensureNotificationsTable($pdo);

$announcements = $pdo->query("
  SELECT a.post_id, a.title, a.content, a.created_at, u.full_name AS author_name
  FROM tbl_announcements a
  LEFT JOIN tbl_users u ON u.user_id = a.author_id
  ORDER BY a.created_at DESC
  LIMIT 10
")->fetchAll();

$enrollments = $pdo->prepare("
  SELECT e.enrollment_id, e.enrollment_date, e.status,
    s.subject_code, s.subject_name, s.units,
    c.course_code, c.course_name
  FROM tbl_enrollments e
  JOIN tbl_subjects s ON s.subject_id = e.subject_id
  JOIN tbl_course c ON c.course_id = s.course_id
  WHERE e.student_id = ?
  ORDER BY e.enrollment_date DESC
  LIMIT 5
");
$enrollments->execute([$user['user_id']]);
$enrollments = $enrollments->fetchAll();

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    if (csrf_verify()) {
        try {
            $pdo->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['user_id']]);
            $_SESSION['flash_success'] = 'Notifications cleared.';
            redirect(base_url('student/index.php'));
        } catch (Exception $e) {}
    }
}

// Get unread notifications for the student
$notifications = [];
try {
    $notifStmt = $pdo->prepare("
      SELECT notification_id, title, message, type, is_read, created_at
      FROM tbl_notifications
      WHERE user_id = ? AND is_read = 0
      ORDER BY created_at DESC
      LIMIT 10
    ");
    $notifStmt->execute([$user['user_id']]);
    $notifications = $notifStmt->fetchAll();
} catch (Exception $e) {
    // Notifications table might not exist, continue without notifications
}

$pageTitle = 'Dashboard';
$breadcrumb = [['label' => 'Student', 'url' => base_url('student/')], 'Dashboard'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/'), 'active' => true],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php')],
  ['label' => 'My profile', 'url' => base_url('student/profile.php')],
  ['label' => 'Enrolled subjects', 'url' => base_url('student/enroll.php')],
  ['label' => 'My records', 'url' => base_url('student/records.php')],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php')],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php')],
  ['label' => 'Activities', 'url' => base_url('student/activities.php')],
];

ob_start();

// Check for success message from redirect
$successMessage = '';
if (isset($_GET['logged_in']) && $_GET['logged_in'] === '1') {
    $successMessage = 'Welcome back, ' . ($user['full_name'] ?? 'Student') . '!';
}
?>

<!-- Success Popup Modal -->
<?php if ($successMessage): ?>
<input type="checkbox" id="popup-success" class="modal-toggle" checked>
<div class="modal">
  <div class="modal-box text-center">
    <svg class="mx-auto mb-4 h-12 w-12 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
    <h3 class="mb-4 text-lg font-bold text-success">Welcome!</h3>
    <p class="text-base-content/70"><?= e($successMessage) ?></p>
    <div class="modal-action justify-center">
      <label for="popup-success" class="btn btn-primary">Continue</label>
    </div>
  </div>
  <label class="modal-backdrop" for="popup-success">Close</label>
</div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-2">
  <!-- Notifications Card -->
  <div class="card bg-base-100 shadow">
    <div class="card-body">
      <div class="flex justify-between items-center mb-2">
        <h2 class="card-title m-0">Notifications</h2>
        <?php if (!empty($notifications)): ?>
          <form method="post" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_read">
            <button type="submit" class="btn btn-xs btn-outline">Mark All as Read</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="space-y-3 max-h-96 overflow-y-auto">
        <?php if (!empty($notifications)): ?>
          <?php foreach ($notifications as $notif): ?>
            <div class="border-l-4 border-<?= $notif['type'] === 'warning' ? 'warning' : ($notif['type'] === 'danger' ? 'error' : ($notif['type'] === 'success' ? 'success' : 'info')) ?> pl-3 py-1">
              <h3 class="font-semibold"><?= e($notif['title']) ?></h3>
              <p class="text-sm text-base-content/70"><?= e($notif['message']) ?></p>
              <p class="text-xs text-base-content/60 mt-1"><?= e(date('M j, Y g:i A', strtotime($notif['created_at']))) ?></p>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="text-base-content/70">No notifications.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <!-- Announcements Card -->
  <div class="card bg-base-100 shadow">
    <div class="card-body">
      <h2 class="card-title">Announcements</h2>
      <div class="space-y-3 max-h-96 overflow-y-auto">
        <?php foreach ($announcements as $a): ?>
          <div class="border-l-4 border-primary pl-3 py-1">
            <h3 class="font-semibold"><?= e($a['title']) ?></h3>
            <p class="text-sm text-base-content/70"><?= e($a['author_name'] ?? 'Admin') ?> · <?= e(date('M j, Y', strtotime($a['created_at']))) ?></p>
            <p class="text-sm mt-1 whitespace-pre-wrap"><?= e(mb_substr($a['content'], 0, 200)) ?><?= mb_strlen($a['content']) > 200 ? '…' : '' ?></p>
          </div>
        <?php endforeach; ?>
        <?php if (empty($announcements)): ?>
          <p class="text-base-content/70">No announcements.</p>
        <?php endif; ?>
      </div>
      <a href="<?= base_url('student/announcements.php') ?>" class="link link-primary text-sm">View all announcements</a>
    </div>
  </div>
  
  <!-- Recent Enrollments Card -->
  <div class="card bg-base-100 shadow lg:col-span-2">
    <div class="card-body">
      <h2 class="card-title">Recent enrollments</h2>
      <div class="overflow-x-auto">
        <table class="table table-sm">
          <thead>
            <tr><th>Subject</th><th>Course</th><th>Status</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php foreach ($enrollments as $e): ?>
              <tr>
                <td><?= e($e['subject_code']) ?> – <?= e($e['subject_name']) ?></td>
                <td><?= e($e['course_code']) ?></td>
                <td><span class="badge badge-<?= ($e['status'] ?? 'enrolled') === 'enrolled' ? 'success' : 'warning' ?>"><?= e($e['status'] ?? 'enrolled') ?></span></td>
                <td><?= e(date('M j, Y', strtotime($e['enrollment_date']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (empty($enrollments)): ?>
        <p class="text-base-content/70">You have no enrollments yet. <a href="<?= base_url('student/enroll.php') ?>" class="link link-primary">Enroll in subjects</a>.</p>
      <?php else: ?>
        <a href="<?= base_url('student/records.php') ?>" class="link link-primary text-sm">View all records</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
