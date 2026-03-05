<?php
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$pdo = getDb();
$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalAnnouncements = (int) $pdo->query("SELECT COUNT(*) FROM tbl_announcements")->fetchColumn();
$totalPages = max(1, (int) ceil($totalAnnouncements / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$announcements = $pdo->query("
  SELECT a.post_id, a.title, a.content, a.created_at, u.full_name AS author_name
  FROM tbl_announcements a
  LEFT JOIN tbl_users u ON u.user_id = a.author_id
  ORDER BY a.created_at DESC
  LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
)->fetchAll();

$pageTitle = 'Announcements';
$breadcrumb = [['label' => 'Student', 'url' => base_url('student/')], 'Announcements'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/')],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php'), 'active' => true],
  ['label' => 'My profile', 'url' => base_url('student/profile.php')],
  ['label' => 'Enroll in subjects', 'url' => base_url('student/enroll.php')],
  ['label' => 'My records', 'url' => base_url('student/records.php')],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php')],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php')],
  ['label' => 'Activities', 'url' => base_url('student/activities.php')],
];

ob_start();
?>
<h2 class="text-xl font-semibold mb-4">Announcements</h2>
<div class="space-y-4">
  <?php foreach ($announcements as $a): ?>
    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h3 class="card-title text-base"><?= e($a['title']) ?></h3>
        <p class="text-sm text-base-content/70"><?= e($a['author_name'] ?? 'Admin') ?> · <?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?></p>
        <p class="whitespace-pre-wrap"><?= e($a['content']) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($announcements)): ?>
    <p class="text-base-content/70">No announcements yet.</p>
  <?php endif; ?>
</div>
<?php
$pagination_current_page = $page;
$pagination_total_pages = $totalPages;
$pagination_base_path = 'student/announcements.php';
$pagination_total = $totalAnnouncements;
$pagination_per_page = $perPage;
require_once __DIR__ . '/../includes/pagination.php';
?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
