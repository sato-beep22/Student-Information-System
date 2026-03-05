<?php
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$user = currentUser();
$pdo = getDb();

// Create notifications table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tbl_notifications` (
          `notification_id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `message` TEXT NOT NULL,
          `type` ENUM('info', 'warning', 'danger', 'success') NOT NULL DEFAULT 'info',
          `is_read` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          PRIMARY KEY (`notification_id`),
          KEY `user_id` (`user_id`),
          KEY `is_read` (`is_read`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (Exception $e) {
    // Table might already exist or other issue, continue anyway
}

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

// Get notifications for the student
$notifications = [];
try {
    $notifStmt = $pdo->prepare("
      SELECT notification_id, title, message, type, is_read, created_at
      FROM tbl_notifications
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 10
    ");
    $notifStmt->execute([$user['user_id']]);
    $notifications = $notifStmt->fetchAll();
    
    // Mark notifications as read
    if (!empty($notifications)) {
        $idList = [];
        foreach ($notifications as $n) {
            if (isset($n['notification_id'])) {
                $idList[] = (int)$n['notification_id'];
            }
        }
        if (!empty($idList)) {
            $idString = implode(',', $idList);
            $pdo->exec("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id IN ($idString)");
        }
    }
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
      <h2 class="card-title">Notifications</h2>
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
