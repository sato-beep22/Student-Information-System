<?php
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$user = currentUser();
$pdo = getDb();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$studentCourseId = (int) ($user['course_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    if ($subject_id) {
        $subjectCourse = $pdo->prepare('SELECT course_id FROM tbl_subjects WHERE subject_id = ?');
        $subjectCourse->execute([$subject_id]);
        $subRow = $subjectCourse->fetch();
        if (!$subRow || (int)$subRow['course_id'] !== $studentCourseId) {
            $_SESSION['flash_error'] = 'You can only enroll in subjects for your course.';
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM tbl_enrollments WHERE student_id = ? AND subject_id = ?');
            $exists->execute([$user['user_id'], $subject_id]);
            if ($exists->fetch()) {
                $_SESSION['flash_error'] = 'You are Dropped From this Subject.';
            } else {
                try {
                    $pdo->prepare('INSERT INTO tbl_enrollments (student_id, subject_id, status) VALUES (?, ?, ?)')
                        ->execute([$user['user_id'], $subject_id, 'pending']);
                } catch (Throwable $e) {
                    $pdo->prepare('INSERT INTO tbl_enrollments (student_id, subject_id) VALUES (?, ?)')
                        ->execute([$user['user_id'], $subject_id]);
                }
                $_SESSION['flash_success'] = 'Enrollment request submitted. Awaiting admin approval.';
            }
        }
        redirect(base_url('student/enroll.php' . ($search !== '' ? '?q=' . rawurlencode($search) : '')));
    }
}

$courses = $pdo->query('SELECT course_id, course_name, course_code FROM tbl_course ORDER BY course_name')->fetchAll();
$studentCourse = null;
if ($studentCourseId) {
    $sc = $pdo->prepare('SELECT course_id, course_name, course_code FROM tbl_course WHERE course_id = ?');
    $sc->execute([$studentCourseId]);
    $studentCourse = $sc->fetch();
}

$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$baseJoins = "
  FROM tbl_subjects s
  JOIN tbl_course c ON c.course_id = s.course_id
";
$whereClause = "WHERE s.course_id = ?";
$params = [$studentCourseId];
if ($search !== '') {
    $whereClause .= " AND (s.subject_code LIKE ? OR s.subject_name LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
}
$countSql = "SELECT COUNT(*) " . $baseJoins . $whereClause;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalSubjects = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalSubjects / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$userSectionId = (int)($user['section_id'] ?? 0);

$sql = "
  SELECT s.subject_id, s.subject_code, s.subject_name, s.units, s.description, 
    c.course_id, c.course_code, c.course_name,
    (SELECT e.enrollment_status FROM tbl_enrollments e WHERE e.student_id = ? AND e.subject_id = s.subject_id LIMIT 1) AS enrollment_status,
    (SELECT COUNT(*) FROM tbl_enrollments e WHERE e.subject_id = s.subject_id AND IFNULL(e.enrollment_status, 'active') != 'dropped') AS enrollment_count
  " . $baseJoins . " " . $whereClause . " ORDER BY s.subject_code LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$params = array_merge([$user['user_id']], $params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Enroll in subjects';
$breadcrumb = [['label' => 'Student', 'url' => base_url('student/')], 'Enroll'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/')],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php')],
  ['label' => 'My profile', 'url' => base_url('student/profile.php')],
  ['label' => 'Enroll in subjects', 'url' => base_url('student/enroll.php'), 'active' => true],
  ['label' => 'My records', 'url' => base_url('student/records.php')],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php')],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php')],
  ['label' => 'Activities', 'url' => base_url('student/activities.php')],
];

ob_start();
?>
<h2 class="text-xl font-semibold mb-4">Enroll in subjects</h2>
<?php if (!$studentCourseId || !$studentCourse): ?>
  <div class="alert alert-warning">You have no course assigned. Please update your profile or contact the admin to assign a course before enrolling in subjects.</div>
<?php else: ?>
  <p class="text-base-content/70 mb-4">Subjects for your course: <strong><?= e($studentCourse['course_code']) ?> – <?= e($studentCourse['course_name']) ?></strong></p>
  <form method="get" class="flex gap-2 mb-4 flex-wrap items-center">
    <input type="text" name="q" placeholder="Search by subject code or name…" class="input input-bordered w-64 max-w-full" value="<?= e($search) ?>">
    <button type="submit" class="btn btn-ghost">Search</button>
    <?php if ($search !== ''): ?>
      <a href="<?= base_url('student/enroll.php') ?>" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
  </form>

<div class="overflow-x-auto">
  <table class="table table-zebra">
    <thead>
      <tr>
        <th>Course</th>
        <th>Subject</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($subjects as $s): 
        $enrollStatus = $s['enrollment_status'] ?? null;
        $isEnrolled = !empty($enrollStatus) && $enrollStatus !== 'dropped';
      ?>
        <tr>
          <td><?= e($s['course_code']) ?></td>
          <td>
            <div><strong><?= e($s['subject_code']) ?></strong></div>
            <div class="text-sm text-base-content/70"><?= e($s['subject_name']) ?></div>
          </td>
          <td>
            <?php if ($isEnrolled): ?>
              <?php if ($enrollStatus === 'dropped'): ?>
                <span class="badge badge-error">Dropped</span>
              <?php elseif ($enrollStatus === 'pending'): ?>
                <span class="badge badge-warning">Pending</span>
              <?php else: ?>
                <span class="badge badge-success">Enrolled</span>
              <?php endif; ?>
            <?php else: ?>
              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="subject_id" value="<?= (int)$s['subject_id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">Enroll</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if (empty($subjects)): ?>
  <p class="text-base-content/70 mt-2"><?= $search !== '' ? 'No subjects match your search.' : 'No subjects available for your course.' ?></p>
<?php endif; ?>
<?php
$pagination_current_page = $page;
$pagination_total_pages = $totalPages;
$pagination_base_path = 'student/enroll.php';
$pagination_total = $totalSubjects;
$pagination_per_page = $perPage;
require_once __DIR__ . '/../includes/pagination.php';
?>
<?php endif; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
