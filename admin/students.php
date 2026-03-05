<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDb();
$search = trim($_GET['q'] ?? '');
$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Handle section assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'assign_section') {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $section_id = (int) ($_POST['section_id'] ?? 0);
        
        if ($student_id > 0) {
            if ($section_id > 0) {
                // Check capacity before assigning
                $capStmt = $pdo->prepare('
                    SELECT s.capacity, COUNT(u.user_id) as student_count
                    FROM tbl_sections s
                    LEFT JOIN tbl_users u ON u.section_id = s.section_id AND u.role = "student" AND u.user_id != ?
                    WHERE s.section_id = ?
                    GROUP BY s.section_id
                ');
                $capStmt->execute([$student_id, $section_id]);
                $section = $capStmt->fetch();

                if ($section && (int)($section['student_count'] ?? 0) >= (int)($section['capacity'] ?? 0)) {
                    $_SESSION['flash_error'] = 'Cannot assign student. The selected section is already full.';
                } else {
                    $stmt = $pdo->prepare('UPDATE tbl_users SET section_id = ? WHERE user_id = ? AND role = "student"');
                    if ($stmt->execute([$section_id, $student_id])) {
                        $_SESSION['flash_success'] = 'Student section updated successfully.';
                    } else {
                        $_SESSION['flash_error'] = 'Failed to update student section.';
                    }
                }
            } else {
                // Un-assigning section
                $stmt = $pdo->prepare('UPDATE tbl_users SET section_id = NULL WHERE user_id = ? AND role = "student"');
                $stmt->execute([$student_id]);
                $_SESSION['flash_success'] = 'Student section updated successfully.';
            }
        }
        redirect(base_url('admin/students.php'));
    }
}

$sql = "
  SELECT u.user_id, u.full_name, u.email, u.created_at, u.course_id,
    c.course_code, c.course_name,
    u.section_id, s.section_name,
    (SELECT COUNT(*) FROM tbl_enrollments e WHERE e.student_id = u.user_id) AS enrolled_subjects
  FROM tbl_users u
  LEFT JOIN tbl_course c ON c.course_id = u.course_id
  LEFT JOIN tbl_sections s ON s.section_id = u.section_id
  WHERE u.role = 'student'
";
$params = [];
if ($search !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
}
$sql .= " ORDER BY u.full_name";

$countSql = "SELECT COUNT(*) FROM tbl_users u WHERE u.role = 'student'";
if ($search !== '') {
    $countSql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
}
if ($params) {
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalStudents = (int) $stmt->fetchColumn();
} else {
    $totalStudents = (int) $pdo->query($countSql)->fetchColumn();
}
$totalPages = max(1, (int) ceil($totalStudents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql .= " LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
if ($params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} else {
    $students = $pdo->query($sql)->fetchAll();
}

$pageTitle = 'Students';
$breadcrumb = [['label' => 'Admin', 'url' => base_url('admin/')], 'Students'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php'), 'active' => true],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

// Get all sections for the modal dropdown
$sections = $pdo->query('SELECT s.section_id, s.section_name, c.course_name, c.course_code 
                          FROM tbl_sections s 
                          LEFT JOIN tbl_course c ON c.course_id = s.course_id
                          ORDER BY c.course_code, s.section_name')->fetchAll();

ob_start();
?>
<h2 class="text-xl font-semibold mb-4">Registered Students</h2>
<form method="get" class="flex gap-2 mb-4 flex-wrap items-center">
  <input type="text" name="q" placeholder="Search by name or email…" class="input input-bordered w-64 max-w-full" value="<?= e($search) ?>">
  <button type="submit" class="btn btn-ghost">Search</button>
  <?php if ($search !== ''): ?>
    <a href="<?= base_url('admin/students.php') ?>" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
</form>
<div class="overflow-x-auto">
  <table class="table table-zebra">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Course</th>
        <th>Section</th>
        <th>Enrolled subjects</th>
        <th>Registered</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($students as $st): ?>
        <tr>
          <td><?= e($st['full_name']) ?></td>
          <td><?= e($st['email']) ?></td>
          <td><?= e($st['course_code'] ?? '') ?><?= ($st['course_name'] ?? '') ? ' – ' . e($st['course_name']) : '—' ?></td>
          <td><?= $st['section_name'] ? 'Section ' . e($st['section_name']) : '—' ?></td>
          <td><?= (int) $st['enrolled_subjects'] ?></td>
          <td><?= e(date('M j, Y', strtotime($st['created_at']))) ?></td>
          <td>
            <div class="flex gap-1">
              <label for="modal-assign-section-<?= (int)$st['user_id'] ?>" class="btn btn-ghost btn-sm">
                Change Section
              </label>
              <a href="<?= base_url('admin/student_enrollments.php?student_id=' . (int) $st['user_id']) ?>" class="btn btn-ghost btn-sm">
                Manage
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if (empty($students)): ?>
  <p class="text-base-content/70 mt-2"><?= $search !== '' ? 'No students match your search.' : 'No registered students yet.' ?></p>
<?php endif; ?>
<?php
$pagination_current_page = $page;
$pagination_total_pages = $totalPages;
$pagination_base_path = 'admin/students.php';
$pagination_total = $totalStudents;
$pagination_per_page = $perPage;
require_once __DIR__ . '/../includes/pagination.php';
?>

<!-- Section Assignment Modals -->
<?php foreach ($students as $st): ?>
<input type="checkbox" id="modal-assign-section-<?= (int)$st['user_id'] ?>" class="modal-toggle">
<div class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg">Assign Section - <?= e($st['full_name']) ?></h3>
    <p class="text-sm text-base-content/70 mb-4">Current Course: <?= e($st['course_code'] ?? '') ?><?= ($st['course_name'] ?? '') ? ' - ' . e($st['course_name']) : '' ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_section">
      <input type="hidden" name="student_id" value="<?= (int)$st['user_id'] ?>">
      <div class="form-control">
        <label class="label">Select Section</label>
        <select name="section_id" class="select select-bordered" required>
          <option value="">-- Select Section --</option>
          <?php foreach ($sections as $sec): ?>
            <?php if ($sec['course_code'] == ($st['course_code'] ?? '')): ?>
            <option value="<?= (int)$sec['section_id'] ?>" <?= (int)$st['section_id'] === (int)$sec['section_id'] ? 'selected' : '' ?>>
              Section <?= e($sec['section_name']) ?> (<?= e($sec['course_code']) ?>)
            </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-action">
        <label for="modal-assign-section-<?= (int)$st['user_id'] ?>" class="btn">Cancel</label>
        <button type="submit" class="btn btn-primary">Change</button>
      </div>
    </form>
  </div>
  <label class="modal-backdrop" for="modal-assign-section-<?= (int)$st['user_id'] ?>">Close</label>
</div>
<?php endforeach; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
