<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDb();

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$courses = $pdo->query('SELECT course_id, course_name, course_code FROM tbl_course ORDER BY course_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['subject_name'] ?? '');
        $code = trim($_POST['subject_code'] ?? '');
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $units = (int) ($_POST['units'] ?? 3);
        if ($name !== '' && $code !== '' && $course_id) {
            try {
                $stmt = $pdo->prepare('INSERT INTO tbl_subjects (subject_name, subject_code, course_id, units) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $code, $course_id, $units]);
                $_SESSION['flash_success'] = 'Subject created.';
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Failed (e.g. duplicate code).';
            }
        } else {
            $_SESSION['flash_error'] = 'Name, code and course required.';
        }
        redirect(base_url('admin/subjects.php'));
    }
    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['subject_name'] ?? '');
        $code = trim($_POST['subject_code'] ?? '');
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $units = (int) ($_POST['units'] ?? 3);
        if ($id && $name !== '' && $code !== '' && $course_id) {
            try {
                $pdo->prepare('UPDATE tbl_subjects SET subject_name = ?, subject_code = ?, course_id = ?, units = ? WHERE subject_id = ?')
                    ->execute([$name, $code, $course_id, $units, $id]);
                $_SESSION['flash_success'] = 'Subject updated.';
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Update failed.';
            }
        }
        redirect(base_url('admin/subjects.php'));
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM tbl_subjects WHERE subject_id = ?')->execute([$id]);
            $_SESSION['flash_success'] = 'Subject deleted.';
        }
        redirect(base_url('admin/subjects.php'));
    }
}

// Get filter parameters
$courseFilter = (int) ($_GET['course_id'] ?? 0);
$searchQuery = trim($_GET['search'] ?? '');

$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Build query with filters
$whereClauses = [];
$params = [];

if ($courseFilter > 0) {
    $whereClauses[] = 's.course_id = ?';
    $params[] = $courseFilter;
}

if ($searchQuery !== '') {
    $whereClauses[] = '(s.subject_name LIKE ? OR s.subject_code LIKE ?)';
    $searchPattern = '%' . $searchQuery . '%';
    $params[] = $searchPattern;
    $params[] = $searchPattern;
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Get total count with filters
$countSql = "SELECT COUNT(*) FROM tbl_subjects s $whereSql";
$totalSubjectsStmt = $pdo->prepare($countSql);
$totalSubjectsStmt->execute($params);
$totalSubjects = (int) $totalSubjectsStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalSubjects / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Get subjects with filters
$subjectsSql = "
  SELECT s.*, c.course_code, c.course_name
  FROM tbl_subjects s
  JOIN tbl_course c ON c.course_id = s.course_id
  $whereSql
  ORDER BY c.course_name, s.subject_code
  LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$subjectsStmt = $pdo->prepare($subjectsSql);
$subjectsStmt->execute($params);
$subjects = $subjectsStmt->fetchAll();

// Get enrollment counts for each subject using tbl_enrollments
$subjectIds = array_column($subjects, 'subject_id');
$enrollmentCounts = [];
if (!empty($subjectIds)) {
    $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
    $enrollStmt = $pdo->prepare("
        SELECT subject_id, COUNT(*) as count 
        FROM tbl_enrollments 
        WHERE subject_id IN ($placeholders) 
        GROUP BY subject_id
    ");
    $enrollStmt->execute($subjectIds);
    foreach ($enrollStmt->fetchAll() as $row) {
        $enrollmentCounts[$row['subject_id']] = (int) $row['count'];
    }
}

$pageTitle = 'Subjects';
$breadcrumb = [['label' => 'Admin', 'url' => base_url('admin/')], 'Subjects'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php'), 'active' => true],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

ob_start();
?>

<!-- Filters -->
<form method="get" class="flex flex-wrap gap-4 mb-6">
  <div class="flex-1 min-w-[200px]">
    <input type="text" name="search" class="input input-bordered w-full" placeholder="Search subjects..." value="<?= e($searchQuery) ?>">
  </div>
  <div class="w-64">
    <select name="course_id" class="select select-bordered w-full" onchange="this.form.submit()">
      <option value="">All Courses</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['course_id'] ?>" <?= $courseFilter === (int)$c['course_id'] ? 'selected' : '' ?>>
          <?= e($c['course_code']) ?> – <?= e($c['course_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Filter</button>
  <?php if ($courseFilter > 0 || $searchQuery !== ''): ?>
    <a href="<?= base_url('admin/subjects.php') ?>" class="btn btn-ghost">Clear</a>
  <?php endif; ?>
</form>

<div class="flex flex-wrap gap-4 justify-between items-center">
  <h2 class="text-xl font-semibold">Manage Subjects</h2>
  <label for="modal-create-subject" class="btn btn-primary">Add Subject</label>
</div>

<div class="overflow-x-auto mt-4">
  <table class="table table-zebra">
    <thead>
      <tr>
        <th>Code</th>
        <th>Name</th>
        <th>Course</th>
        <th>Units</th>
        <th>Enrolled</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($subjects as $s): ?>
        <tr>
          <td><?= e($s['subject_code']) ?></td>
          <td><?= e($s['subject_name']) ?></td>
          <td><?= e($s['course_code']) ?> – <?= e($s['course_name']) ?></td>
          <td><?= (int)($s['units'] ?? 0) ?></td>
          <td>
            <label for="modal-view-students-<?= (int)$s['subject_id'] ?>" class="btn btn-info btn-sm">View Students (<?= $enrollmentCounts[$s['subject_id']] ?? 0 ?>)</label>
          </td>
          <td class="flex gap-2">
            <label for="modal-edit-subject-<?= (int)$s['subject_id'] ?>" class="btn btn-ghost btn-sm">Edit</label>
            <form method="post" class="inline" onsubmit="return confirm('Delete this subject?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$s['subject_id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm text-error">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$pagination_current_page = $page;
$pagination_total_pages = $totalPages;
$pagination_base_path = 'admin/subjects.php';
$pagination_total = $totalSubjects;
$pagination_per_page = $perPage;
require_once __DIR__ . '/../includes/pagination.php';
?>

<!-- Create modal - Description removed -->
<input type="checkbox" id="modal-create-subject" class="modal-toggle">
<div class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg">Add Subject</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-control">
        <label class="label">Subject name</label>
        <input type="text" name="subject_name" class="input input-bordered" required>
      </div>
      <div class="form-control">
        <label class="label">Subject code</label>
        <input type="text" name="subject_code" class="input input-bordered" required>
      </div>
      <div class="form-control">
        <label class="label">Course</label>
        <select name="course_id" class="select select-bordered" required>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['course_id'] ?>"><?= e($c['course_code']) ?> – <?= e($c['course_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-control">
        <label class="label">Units</label>
        <input type="number" name="units" class="input input-bordered" min="1" value="3">
      </div>
      <div class="modal-action">
        <label for="modal-create-subject" class="btn">Cancel</label>
        <button type="submit" class="btn btn-primary">Create</button>
      </div>
    </form>
  </div>
  <label class="modal-backdrop" for="modal-create-subject">Close</label>
</div>

<!-- Edit modals - Description removed -->
<?php foreach ($subjects as $s): ?>
<input type="checkbox" id="modal-edit-subject-<?= (int)$s['subject_id'] ?>" class="modal-toggle">
<div class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg">Edit Subject</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$s['subject_id'] ?>">
      <div class="form-control">
        <label class="label">Subject name</label>
        <input type="text" name="subject_name" class="input input-bordered" required value="<?= e($s['subject_name']) ?>">
      </div>
      <div class="form-control">
        <label class="label">Subject code</label>
        <input type="text" name="subject_code" class="input input-bordered" required value="<?= e($s['subject_code']) ?>">
      </div>
      <div class="form-control">
        <label class="label">Course</label>
        <select name="course_id" class="select select-bordered" required>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['course_id'] ?>" <?= (int)$c['course_id'] === (int)$s['course_id'] ? 'selected' : '' ?>><?= e($c['course_code']) ?> – <?= e($c['course_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-control">
        <label class="label">Units</label>
        <input type="number" name="units" class="input input-bordered" min="1" value="<?= (int)($s['units'] ?? 3) ?>">
      </div>
      <div class="modal-action">
        <label for="modal-edit-subject-<?= (int)$s['subject_id'] ?>" class="btn">Cancel</label>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
  <label class="modal-backdrop" for="modal-edit-subject-<?= (int)$s['subject_id'] ?>">Close</label>
</div>
<?php endforeach; ?>

<!-- View Students modals -->
<?php foreach ($subjects as $s): ?>
<?php
// Get enrolled students for this subject from tbl_enrollments
$enrolledStmt = $pdo->prepare("
    SELECT e.*, u.full_name, u.email, u.user_id as student_user_id
    FROM tbl_enrollments e
    JOIN tbl_users u ON u.user_id = e.student_id
    WHERE e.subject_id = ?
    ORDER BY u.full_name
");
$enrolledStmt->execute([$s['subject_id']]);
$enrolledStudents = $enrolledStmt->fetchAll();
?>
<input type="checkbox" id="modal-view-students-<?= (int)$s['subject_id'] ?>" class="modal-toggle">
<div class="modal">
  <div class="modal-box max-w-2xl">
    <h3 class="font-bold text-lg">Enrolled Students - <?= e($s['subject_code']) ?>: <?= e($s['subject_name']) ?></h3>
    <div class="mt-4">
      <?php if (empty($enrolledStudents)): ?>
        <p class="text-base-content/70">No students enrolled in this subject.</p>
      <?php else: ?>
        <div class="overflow-x-auto max-h-96 overflow-y-auto">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($enrolledStudents as $student): ?>
                <tr>
                  <td><?= e($student['student_user_id']) ?></td>
                  <td><?= e($student['full_name']) ?></td>
                  <td><?= e($student['email'] ?? '') ?></td>
                  <td><span class="badge badge-sm badge-<?= ($student['enrollment_status'] ?? 'active') === 'active' ? 'success' : (($student['enrollment_status'] ?? 'active') === 'dropped' ? 'error' : 'warning') ?>"><?= e(ucfirst($student['enrollment_status'] ?? 'active')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <div class="modal-action">
      <label for="modal-view-students-<?= (int)$s['subject_id'] ?>" class="btn">Close</label>
    </div>
  </div>
  <label class="modal-backdrop" for="modal-view-students-<?= (int)$s['subject_id'] ?>">Close</label>
</div>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
