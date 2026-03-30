<?php
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$user = currentUser();
$pdo = getDb();

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tbl_enrollments e WHERE e.student_id = ? AND e.status IN ('enrolled', 'dropped')");
$stmtCount->execute([$user['user_id']]);
$totalEnrollments = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalEnrollments / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$enrollmentsStmt = $pdo->prepare("
  SELECT e.enrollment_id, e.enrollment_date, e.status, e.grade, e.academic_status, e.enrollment_status,
    s.subject_code, s.subject_name, s.units,
    c.course_code, c.course_name
  FROM tbl_enrollments e
  JOIN tbl_subjects s ON s.subject_id = e.subject_id
  JOIN tbl_course c ON c.course_id = s.course_id
  WHERE e.student_id = ? AND e.status IN ('enrolled', 'dropped')
  ORDER BY c.course_name, s.subject_code
  LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
);
$enrollmentsStmt->execute([$user['user_id']]);
$enrollments = $enrollmentsStmt->fetchAll();

$pageTitle = 'My records';
$breadcrumb = [['label' => 'Student', 'url' => base_url('student/')], 'My records'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/')],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php')],
  ['label' => 'My profile', 'url' => base_url('student/profile.php')],
  ['label' => 'Enroll in subjects', 'url' => base_url('student/enroll.php')],
  ['label' => 'My records', 'url' => base_url('student/records.php'), 'active' => true],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php')],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php')],
  ['label' => 'Activities', 'url' => base_url('student/activities.php')],
];

ob_start();
?>
<h2 class="text-xl font-semibold mb-4">Enrollment records</h2>
<?php if (empty($enrollments)): ?>
  <p class="text-base-content/70">You have no enrollments. <a href="<?= base_url('student/enroll.php') ?>" class="link link-primary">Enroll in subjects</a>.</p>
<?php else: ?>
  <div class="overflow-x-auto">
    <table class="table table-zebra">
      <thead>
        <tr>
          <th>Course</th>
          <th>Subject</th>
          <th>Grade</th>
          <th>Academic Status</th>
          <th>Enrollment Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($enrollments as $e): 
          $enrollStatus = $e['enrollment_status'] ?? 'active';
          $acadStatus = ($enrollStatus === 'dropped') ? 'dropped' : ($e['academic_status'] ?? 'pending');
        ?>
          <tr>
            <td><?= e($e['course_code']) ?> – <?= e($e['course_name']) ?></td>
            <td>
              <div><strong><?= e($e['subject_code']) ?></strong></div>
              <div class="text-sm text-base-content/70"><?= e($e['subject_name']) ?></div>
            </td>
            <td><?= $e['grade'] !== null ? e($e['grade']) : '<span class="text-base-content/50">--</span>' ?></td>
            <td><span class="badge badge-<?= $acadStatus === 'passed' ? 'success' : ($acadStatus === 'failed' ? 'error' : ($acadStatus === 'dropped' ? 'error' : 'warning')) ?>"><?= e(ucfirst($acadStatus)) ?></span></td>
            <td><span class="badge badge-<?= $enrollStatus === 'active' ? 'success' : ($enrollStatus === 'warning' ? 'warning' : 'error') ?>"><?= e(ucfirst($enrollStatus)) ?></span></td>
            <td><?= e(date('M j, Y', strtotime($e['enrollment_date']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php
  $pagination_current_page = $page;
  $pagination_total_pages = $totalPages;
  $pagination_base_path = 'student/records.php';
  $pagination_total = $totalEnrollments;
  $pagination_per_page = $perPage;
  require_once __DIR__ . '/../includes/pagination.php';
?>
<?php endif; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
