<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$user = currentUser();
$pdo = getDb();

$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM tbl_users WHERE role = "student"')->fetchColumn();
$totalCourses = (int) $pdo->query('SELECT COUNT(*) FROM tbl_course')->fetchColumn();
$totalEnrollments = (int) $pdo->query('SELECT COUNT(*) FROM tbl_enrollments')->fetchColumn();

$studentsPerCourse = $pdo->query("
  SELECT c.course_code, c.course_name, COUNT(DISTINCT u.user_id) AS num_students
  FROM tbl_course c
  LEFT JOIN tbl_users u ON u.course_id = c.course_id AND u.role = 'student'
  GROUP BY c.course_id
  ORDER BY num_students DESC
")->fetchAll();

$pageTitle = 'Admin Dashboard';
$breadcrumb = [['label' => 'Admin', 'url' => base_url('admin/')], 'Dashboard'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/'), 'active' => true],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

ob_start();

// Check for success message from redirect
$successMessage = '';
if (isset($_GET['logged_in']) && $_GET['logged_in'] === '1') {
    $successMessage = 'Welcome back, ' . ($user['full_name'] ?? 'Admin') . '!';
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

<div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
  <div class="card bg-base-100 shadow">
    <div class="card-body">
      <h3 class="card-title text-base">Total Students</h3>
      <p class="text-3xl font-bold text-primary"><?= $totalStudents ?></p>
    </div>
  </div>
  <div class="card bg-base-100 shadow">
    <div class="card-body">
      <h3 class="card-title text-base">Total Courses</h3>
      <p class="text-3xl font-bold text-secondary"><?= $totalCourses ?></p>
    </div>
  </div>
  <div class="card bg-base-100 shadow">
    <div class="card-body">
      <h3 class="card-title text-base">Total Enrollments Per Subject</h3>
      <p class="text-3xl font-bold"><?= $totalEnrollments ?></p>
    </div>
  </div>
</div>

<div class="card bg-base-100 shadow mt-6">
  <div class="card-body">
    <h2 class="card-title">Students per Course</h2>
    <div class="overflow-x-auto">
      <table class="table table-zebra">
        <thead>
          <tr>
            <th>Course Code</th>
            <th>Course Name</th>
            <th>Students</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($studentsPerCourse as $row): ?>
            <tr>
              <td><?= e($row['course_code']) ?></td>
              <td><?= e($row['course_name']) ?></td>
              <td><?= (int) $row['num_students'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
