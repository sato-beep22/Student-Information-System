<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions/attendance.php';
requireAdmin();

$pdo = getDb();

// Handle attendance recording - process BEFORE checking flash messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'record') {
        $enrollmentIds = $_POST['enrollment_ids'] ?? [];
        $status = $_POST['status'] ?? '';
        $date = $_POST['attendance_date'] ?? date('Y-m-d');
        
        if (!empty($enrollmentIds) && in_array($status, ['present', 'absent', 'tardy'])) {
            $count = bulkRecordAttendance(array_map('intval', $enrollmentIds), $status, $date);
            $_SESSION['flash_success'] = "Recorded attendance for $count student(s).";
        } else {
            $_SESSION['flash_error'] = 'Please select students and status.';
        }
        redirect(base_url('admin/attendance.php'));
    }
}

// Get flash messages AFTER POST handling to prevent duplicates
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Get filter parameters
$courseId = (int) ($_GET['course_id'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$searchQuery = trim($_GET['search'] ?? '');
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Get courses for filter
$coursesStmt = $pdo->prepare('SELECT course_id, course_code, course_name FROM tbl_course ORDER BY course_code');
$coursesStmt->execute();
$courses = $coursesStmt->fetchAll();

// Get subjects for selected course
$subjects = [];
if ($courseId) {
    $subjectsStmt = $pdo->prepare('
        SELECT subject_id, subject_code, subject_name 
        FROM tbl_subjects 
        WHERE course_id = ? 
        ORDER BY subject_code
    ');
    $subjectsStmt->execute([$courseId]);
    $subjects = $subjectsStmt->fetchAll();
}

// Get enrollments for selected subject (students in alphabetical order)
$enrollments = [];
if ($subjectId) {
    $sql = '
        SELECT 
            e.enrollment_id,
            e.student_id,
            u.full_name,
            u.email,
            s.subject_code,
            s.subject_name,
            COALESCE(a.status, "no_record") as today_status
        FROM tbl_enrollments e
        JOIN tbl_users u ON u.user_id = e.student_id
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        LEFT JOIN tbl_attendance a ON a.enrollment_id = e.enrollment_id AND a.attendance_date = ?
        WHERE s.subject_id = ? AND e.status = "enrolled"
    ';
    
    $params = [$selectedDate, $subjectId];
    
    // Add search filter
    if ($searchQuery) {
        $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
        $searchPattern = '%' . $searchQuery . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }
    
    $sql .= ' ORDER BY TRIM(SUBSTRING_INDEX(u.full_name, " ", -1)) ASC, u.full_name ASC';
    
    $enrollmentsStmt = $pdo->prepare($sql);
    $enrollmentsStmt->execute($params);
    $enrollments = $enrollmentsStmt->fetchAll();
}

// Get students with warning status
$warningStudents = getStudentsWithWarning($courseId ?: null);

$pageTitle = 'Attendance Management';
$breadcrumb = [
  ['label' => 'Admin', 'url' => base_url('admin/')],
  'Attendance Management'
];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php'), 'active' => true],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

ob_start();
?>

<?php if ($flashSuccess): ?>
  <div class="alert alert-success mb-4">
    <svg class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
    <span><?= e($flashSuccess) ?></span>
  </div>
<?php endif; ?>

<?php if ($flashError): ?>
  <div class="alert alert-error mb-4">
    <svg class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l-2-2m0 0l-2-2m2 2l2-2m-2 2l-2 2"></path>
    </svg>
    <span><?= e($flashError) ?></span>
  </div>
<?php endif; ?>

<!-- Record Attendance Section -->
<div class="card bg-base-100 shadow-md mb-6">
  <div class="card-body">
    <h2 class="card-title text-lg mb-4">Record Attendance</h2>
    
    <!-- Filters -->
    <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div>
        <label class="form-control w-full">
          <div class="label">
            <span class="label-text">Course</span>
          </div>
          <select name="course_id" id="courseSelect" class="select select-bordered" onchange="loadSubjects()">
            <option value="">Select a course</option>
            <?php foreach ($courses as $course): ?>
              <option value="<?= $course['course_id'] ?>" <?= $courseId === $course['course_id'] ? 'selected' : '' ?>>
                <?= e($course['course_code'] . ' - ' . $course['course_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      
      <div>
        <label class="form-control w-full">
          <div class="label">
            <span class="label-text">Subject</span>
          </div>
          <select name="subject_id" id="subjectSelect" class="select select-bordered" onchange="document.getElementById('filterForm').submit()">
            <option value="">Select a subject</option>
            <?php foreach ($subjects as $subject): ?>
              <option value="<?= $subject['subject_id'] ?>" <?= $subjectId === $subject['subject_id'] ? 'selected' : '' ?>>
                <?= e($subject['subject_code'] . ' - ' . $subject['subject_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      
      <div>
        <label class="form-control w-full">
          <div class="label">
            <span class="label-text">Date</span>
          </div>
          <input type="date" name="date" value="<?= $selectedDate ?>" class="input input-bordered" id="dateInput" onchange="updateFilters()">
        </label>
      </div>
      
      <div>
        <label class="form-control w-full">
          <div class="label">
            <span class="label-text">Search Student</span>
          </div>
          <input type="text" name="search" value="<?= e($searchQuery) ?>" class="input input-bordered" placeholder="Name or email..." onchange="document.getElementById('filterForm').submit()">
        </label>
      </div>
    </form>

    <?php if ($subjectId && !empty($enrollments)): ?>
    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record">
      <input type="hidden" name="attendance_date" value="<?= $selectedDate ?>">
      
      <div class="overflow-x-auto mb-4">
        <table class="table table-zebra">
          <thead>
            <tr>
              <th class="w-8">
                <input type="checkbox" class="checkbox" id="selectAll" onchange="toggleAll(this)">
              </th>
              <th>Student Name</th>
              <th>Email</th>
              <th>Subject</th>
              <th>Today's Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($enrollments as $enrollment): ?>
              <tr>
                <td>
                  <input type="checkbox" name="enrollment_ids[]" value="<?= $enrollment['enrollment_id'] ?>" class="checkbox studentCheckbox">
                </td>
                <td>
                  <strong><?= e($enrollment['full_name']) ?></strong>
                </td>
                <td>
                  <?= e($enrollment['email']) ?>
                </td>
                <td>
                  <div><strong><?= e($enrollment['subject_code']) ?></strong></div>
                  <div class="text-sm text-base-content/70"><?= e($enrollment['subject_name']) ?></div>
                </td>
                <td>
                  <span class="badge badge-<?= 
                    $enrollment['today_status'] === 'present' ? 'success' : 
                    ($enrollment['today_status'] === 'absent' ? 'error' : 
                    ($enrollment['today_status'] === 'tardy' ? 'warning' : 'ghost'))
                  ?>">
                    <?= e(ucfirst(str_replace('_', ' ', $enrollment['today_status']))) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Status selector -->
      <div class="form-control w-full max-w-xs mb-4">
        <label class="label">
          <span class="label-text">Mark as:</span>
        </label>
        <select name="status" class="select select-bordered" required>
          <option value="">Select Status</option>
          <option value="present">Present</option>
          <option value="absent">Absent</option>
          <option value="tardy">Tardy</option>
        </select>
      </div>

      <button type="submit" class="btn btn-primary">Record Attendance</button>
    </form>
    <?php elseif ($subjectId): ?>
      <div class="alert alert-info">
        <svg class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span>No students enrolled in this subject.</span>
      </div>
    <?php else: ?>
      <div class="alert alert-info">
        <svg class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span>Select a course and subject to view students.</span>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Students with Warning Status -->
<?php if (!empty($warningStudents)): ?>
<div class="card bg-base-100 shadow-md">
  <div class="card-body">
    <h3 class="card-title text-base mb-4">⚠️ Students Requiring Attention</h3>
    <div class="overflow-x-auto">
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Student Name</th>
            <th>Email</th>
            <th>Course</th>
            <th>Status</th>
            <th>Grade</th>
            <th>Absences</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($warningStudents as $student): ?>
            <tr class="<?= $student['enrollment_status'] === 'dropped' ? 'bg-error/10' : 'bg-warning/10' ?>">
              <td><strong><?= e($student['full_name']) ?></strong></td>
              <td><?= e($student['email']) ?></td>
              <td><?= e($student['course_code']) ?></td>
              <td>
                <span class="badge badge-<?= $student['enrollment_status'] === 'dropped' ? 'error' : 'warning' ?>">
                  <?= e(ucfirst($student['enrollment_status'])) ?>
                </span>
              </td>
              <td><?= $student['grade'] !== null ? e($student['grade']) : '--' ?></td>
              <td><?= $student['absences'] ?? 0 ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function toggleAll(checkbox) {
  const checkboxes = document.querySelectorAll('.studentCheckbox');
  checkboxes.forEach(cb => cb.checked = checkbox.checked);
}

function loadSubjects() {
  const courseId = document.getElementById('courseSelect').value;
  const subjectSelect = document.getElementById('subjectSelect');
  
  // Reset subject dropdown
  subjectSelect.innerHTML = '<option value="">-- Select a subject --</option>';
  
  if (!courseId) {
    document.getElementById('filterForm').submit();
    return;
  }
  
  // Fetch subjects for selected course
  fetch('<?= base_url('admin/get_subjects.php') ?>?course_id=' + courseId)
    .then(response => response.json())
    .then(subjects => {
      let html = '<option value="">-- Select a subject --</option>';
      subjects.forEach(s => {
        html += '<option value="' + s.subject_id + '">' + s.subject_code + ' - ' + s.subject_name + '</option>';
      });
      subjectSelect.innerHTML = html;
    })
    .catch(error => console.error('Error loading subjects:', error));
}

function updateFilters() {
  const date = document.getElementById('dateInput').value;
  const courseId = document.getElementById('courseSelect').value;
  const subjectId = document.getElementById('subjectSelect').value;
  let url = '?';
  if (courseId) url += 'course_id=' + courseId + '&';
  if (subjectId) url += 'subject_id=' + subjectId + '&';
  url += 'date=' + date;
  window.location.href = url;
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
?>
