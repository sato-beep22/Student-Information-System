<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDb();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['course_name'] ?? '');
        $code = trim($_POST['course_code'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name !== '' && $code !== '') {
            try {
                $pdo->prepare('INSERT INTO tbl_course (course_name, course_code, description) VALUES (?, ?, ?)')
                    ->execute([$name, $code, $desc ?: null]);
                $_SESSION['flash_success'] = 'Course created.';
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Failed (e.g. duplicate code).';
            }
        } else {
            $_SESSION['flash_error'] = 'Name and code required.';
        }
        redirect(base_url('admin/courses.php'));
    }
    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['course_name'] ?? '');
        $code = trim($_POST['course_code'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($id && $name !== '' && $code !== '') {
            $pdo->prepare('UPDATE tbl_course SET course_name = ?, course_code = ?, description = ? WHERE course_id = ?')
                ->execute([$name, $code, $desc ?: null, $id]);
            $_SESSION['flash_success'] = 'Course updated.';
        }
        redirect(base_url('admin/courses.php'));
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM tbl_course WHERE course_id = ?')->execute([$id]);
            $_SESSION['flash_success'] = 'Course deleted.';
        }
        redirect(base_url('admin/courses.php'));
    }
    // Handle section capacity update
    if ($action === 'update_section_capacity') {
        $section_id = (int) ($_POST['section_id'] ?? 0);
        $capacity = (int) ($_POST['capacity'] ?? 30);
        
        if ($section_id > 0 && $capacity > 0) {
            $stmt = $pdo->prepare('UPDATE tbl_sections SET capacity = ? WHERE section_id = ?');
            $stmt->execute([$capacity, $section_id]);
            $_SESSION['flash_success'] = 'Section capacity updated successfully.';
        }
        redirect(base_url('admin/courses.php'));
    }
    
    // Handle student section assignment
    if ($action === 'assign_section') {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $section_id = (int) ($_POST['section_id'] ?? 0);
        
        if ($student_id > 0 && $section_id > 0) {
            $stmt = $pdo->prepare('UPDATE tbl_users SET section_id = ? WHERE user_id = ?');
            if ($stmt->execute([$section_id, $student_id])) {
                $_SESSION['flash_success'] = 'Student assigned to section successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to assign student to section.';
            }
        }
        redirect(base_url('admin/courses.php'));
    }
}

$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalCourses = (int) $pdo->query('SELECT COUNT(*) FROM tbl_course')->fetchColumn();
$totalPages = max(1, (int) ceil($totalCourses / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$courses = $pdo->query('SELECT * FROM tbl_course ORDER BY course_name LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset)->fetchAll();

// Get sections with student counts for each course
$sectionsData = [];
foreach ($courses as $course) {
    $secStmt = $pdo->prepare('
        SELECT s.section_id, s.section_name, s.capacity, 
               COUNT(u.user_id) as student_count 
        FROM tbl_sections s 
        LEFT JOIN tbl_users u ON u.section_id = s.section_id AND u.role = "student"
        WHERE s.course_id = ?
        GROUP BY s.section_id
        ORDER BY s.section_name
    ');
    $secStmt->execute([$course['course_id']]);
    $sectionsData[$course['course_id']] = $secStmt->fetchAll();
}

// Get students per section
$studentsData = [];
foreach ($sectionsData as $courseId => $sections) {
    foreach ($sections as $sec) {
        $studentStmt = $pdo->prepare('
            SELECT user_id, full_name, email 
            FROM tbl_users 
            WHERE section_id = ? AND role = "student"
            ORDER BY full_name
        ');
        $studentStmt->execute([$sec['section_id']]);
        $studentsData[$sec['section_id']] = $studentStmt->fetchAll();
    }
}

// Get unassigned students per course (students without section but with this course)
$unassignedStudents = [];
foreach ($courses as $course) {
    $unstudentStmt = $pdo->prepare('
        SELECT user_id, full_name, email 
        FROM tbl_users 
        WHERE course_id = ? AND section_id IS NULL AND role = "student"
        ORDER BY full_name
    ');
    $unstudentStmt->execute([$course['course_id']]);
    $unassignedStudents[$course['course_id']] = $unstudentStmt->fetchAll();
}

$pageTitle = 'Courses';
$breadcrumb = [['label' => 'Admin', 'url' => base_url('admin/')], 'Courses'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php'), 'active' => true],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

ob_start();
?>
<div class="flex flex-wrap gap-4 justify-between items-center">
  <h2 class="text-xl font-semibold">Manage Courses</h2>
  <label for="modal-create-course" class="btn btn-primary">Add Course</label>
</div>

<div class="overflow-x-auto mt-4">
  <table class="table table-zebra">
    <thead>
      <tr>
        <th>Code</th>
        <th>Name</th>
        <th>Description</th>
        <th>Sections</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($courses as $c): ?>
        <tr>
          <td><?= e($c['course_code']) ?></td>
          <td><?= e($c['course_name']) ?></td>
          <td class="max-w-xs truncate"><?= e($c['description'] ?? '') ?></td>
          <td>
            <label for="modal-view-sections-<?= (int)$c['course_id'] ?>" class="btn btn-info btn-sm">View Sections</label>
          </td>
          <td class="flex gap-2">
            <label for="modal-edit-course-<?= (int)$c['course_id'] ?>" class="btn btn-ghost btn-sm">Edit</label>
            <form method="post" class="inline" onsubmit="return confirm('Delete this course?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$c['course_id'] ?>">
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
$pagination_base_path = 'admin/courses.php';
$pagination_total = $totalCourses;
$pagination_per_page = $perPage;
require_once __DIR__ . '/../includes/pagination.php';
?>

<!-- Create modal -->
<input type="checkbox" id="modal-create-course" class="modal-toggle">
<div class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg">Add Course</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-control">
        <label class="label">Course name</label>
        <input type="text" name="course_name" class="input input-bordered" required>
      </div>
      <div class="form-control">
        <label class="label">Course code</label>
        <input type="text" name="course_code" class="input input-bordered" required>
      </div>
      <div class="form-control">
        <label class="label">Description</label>
        <textarea name="description" class="textarea textarea-bordered" rows="2"></textarea>
      </div>
      <div class="modal-action">
        <label for="modal-create-course" class="btn">Cancel</label>
        <button type="submit" class="btn btn-primary">Create</button>
      </div>
    </form>
  </div>
  <label class="modal-backdrop" for="modal-create-course">Close</label>
</div>

<!-- Edit modals -->
<?php foreach ($courses as $c): ?>
<input type="checkbox" id="modal-edit-course-<?= (int)$c['course_id'] ?>" class="modal-toggle">
<div class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg">Edit Course</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$c['course_id'] ?>">
      <div class="form-control">
        <label class="label">Course name</label>
        <input type="text" name="course_name" class="input input-bordered" required value="<?= e($c['course_name']) ?>">
      </div>
      <div class="form-control">
        <label class="label">Course code</label>
        <input type="text" name="course_code" class="input input-bordered" required value="<?= e($c['course_code']) ?>">
      </div>
      <div class="form-control">
        <label class="label">Description</label>
        <textarea name="description" class="textarea textarea-bordered" rows="2"><?= e($c['description'] ?? '') ?></textarea>
      </div>
      <div class="modal-action">
        <label for="modal-edit-course-<?= (int)$c['course_id'] ?>" class="btn">Cancel</label>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
  <label class="modal-backdrop" for="modal-edit-course-<?= (int)$c['course_id'] ?>">Close</label>
</div>
<?php endforeach; ?>

<!-- View Sections Modals -->
<?php foreach ($courses as $c): ?>
<?php $courseSections = $sectionsData[$c['course_id']] ?? []; ?>
<input type="checkbox" id="modal-view-sections-<?= (int)$c['course_id'] ?>" class="modal-toggle">
<div class="modal">
  <div class="modal-box w-11/12 max-w-4xl">
    <h3 class="font-bold text-lg">Sections - <?= e($c['course_name']) ?> (<?= e($c['course_code']) ?>)</h3>
    
    <?php if (!empty($courseSections)): ?>
      <div class="mt-4 space-y-4">
        <?php foreach ($courseSections as $sec): ?>
          <div class="border rounded-lg p-4 bg-base-200">
            <div class="flex justify-between items-center flex-wrap gap-2">
              <h4 class="font-semibold">Section <?= e($sec['section_name']) ?></h4>
              <form method="post" class="flex items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_section_capacity">
                <input type="hidden" name="section_id" value="<?= (int)$sec['section_id'] ?>">
                <label class="text-sm">Capacity:</label>
                <input type="number" name="capacity" value="<?= (int)$sec['capacity'] ?>" min="1" max="100" class="input input-sm input-bordered w-20" required>
                <button type="submit" class="btn btn-sm btn-primary">Update</button>
              </form>
            </div>
            
            <div class="mt-2 text-sm">
              <span class="badge <?= (int)$sec['capacity'] - (int)$sec['student_count'] <= 5 ? 'badge-warning' : 'badge-success' ?>">
                <?= (int)$sec['student_count'] ?>/<?= (int)$sec['capacity'] ?> Students Enrolled
              </span>
            </div>
            
            <!-- Students List -->
            <div class="mt-3">
              <h5 class="text-sm font-medium mb-2">Enrolled Students:</h5>
              <?php $sectionStudents = $studentsData[$sec['section_id']] ?? []; ?>
              <?php if (!empty($sectionStudents)): ?>
                <div class="max-h-40 overflow-y-auto">
                  <table class="table table-sm">
                    <tbody>
                      <?php foreach ($sectionStudents as $student): ?>
                        <tr>
                          <td><?= e($student['full_name']) ?></td>
                          <td class="text-base-content/60"><?= e($student['email']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p class="text-base-content/50 text-sm">No students enrolled in this section.</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="mt-4 text-base-content/50">No sections found for this course.</p>
    <?php endif; ?>
    
    <?php $courseUnassigned = $unassignedStudents[$c['course_id']] ?? []; ?>
    <?php if (!empty($courseUnassigned)): ?>
    <div class="mt-6 border-t pt-4">
      <h4 class="font-semibold mb-3">Assign Students to Section</h4>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($courseUnassigned as $unstudent): ?>
        <form method="post" class="flex items-center gap-2 bg-base-200 p-2 rounded">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign_section">
          <input type="hidden" name="student_id" value="<?= (int)$unstudent['user_id'] ?>">
          <span class="text-sm"><?= e($unstudent['full_name']) ?></span>
          <select name="section_id" class="select select-bordered select-sm" required>
            <option value="">Select Section</option>
            <?php foreach ($courseSections as $sec): ?>
            <option value="<?= (int)$sec['section_id'] ?>">Section <?= e($sec['section_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-primary">Assign</button>
        </form>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    
    <div class="modal-action">
      <label for="modal-view-sections-<?= (int)$c['course_id'] ?>" class="btn">Close</label>
    </div>
  </div>
  <label class="modal-backdrop" for="modal-view-sections-<?= (int)$c['course_id'] ?>">Close</label>
</div>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
?>
