<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDb();
$user = currentUser();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if ($title !== '') {
            $pdo->prepare('INSERT INTO tbl_announcements (author_id, title, content) VALUES (?, ?, ?)')
                ->execute([$user['user_id'], $title, $content]);
            $_SESSION['flash_success'] = 'Announcement posted.';
        } else {
            $_SESSION['flash_error'] = 'Title is required.';
        }
        redirect(base_url('admin/announcements.php'));
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM tbl_announcements WHERE post_id = ?')->execute([$id]);
            $_SESSION['flash_success'] = 'Announcement deleted.';
        }
        redirect(base_url('admin/announcements.php'));
    }
}

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
$breadcrumb = [['label' => 'Admin', 'url' => base_url('admin/')], 'Announcements'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php'), 'active' => true],
];

ob_start();
?>
<div class="flex flex-wrap gap-4 justify-between items-center">
  <h2 class="text-xl font-semibold">Announcements</h2>
  <label for="modal-new-announcement" class="btn btn-primary">Post Announcement</label>
</div>

<div class="mt-6 space-y-4">
  <?php foreach ($announcements as $a): ?>
    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <div class="flex justify-between items-start gap-4 flex-wrap">
          <div class="flex-1 min-w-0">
            <h3 class="card-title text-base"><?= e($a['title']) ?></h3>
            <p class="text-sm text-base-content/70"><?= e($a['author_name'] ?? 'Admin') ?> · <?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?></p>
            <p class="mt-2 whitespace-pre-wrap"><?= e($a['content']) ?></p>
          </div>
          <form method="post" class="inline" onsubmit="return confirm('Delete this announcement?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$a['post_id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm text-error">Delete</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($announcements)): ?>
    <p class="text-base-content/70">No announcements yet. Post one to show on the student dashboard.</p>
  <?php endif; ?>
</div>
<?php
$pagination_current_page = $page;
$pagination_total_pages = $totalPages;
$pagination_base_path = 'admin/announcements.php';
$pagination_total = $totalAnnouncements;
$pagination_per_page = $perPage;
require_once __DIR__ . '/../includes/pagination.php';
?>
<input type="checkbox" id="modal-new-announcement" class="modal-toggle">
<div class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg">Post Announcement</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-control">
        <label class="label">Title</label>
        <input type="text" name="title" class="input input-bordered" required>
      </div>
      <div class="form-control">
        <label class="label">Content</label>
        <textarea name="content" class="textarea textarea-bordered" rows="4" required></textarea>
      </div>
      <div class="modal-action">
        <label for="modal-new-announcement" class="btn">Cancel</label>
        <button type="submit" class="btn btn-primary">Post</button>
      </div>
    </form>
  </div>
  <label class="modal-backdrop" for="modal-new-announcement">Close</label>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
