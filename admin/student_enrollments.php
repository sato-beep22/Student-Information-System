<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/includes/functions/academic.php';
require_once dirname(__DIR__) . '/includes/functions/attendance.php';

$pdo = getDb();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;

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

// Determine target student
$studentId = (int) ($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));

// If no specific student, show all enrollments list
if (!$studentId) {
    $perPage = 10;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $totalEnrollments = (int) $pdo->query('SELECT COUNT(*) FROM tbl_enrollments')->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEnrollments / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    
    $allEnrollmentsStmt = $pdo->query('
        SELECT 
            e.enrollment_id,
            e.student_id,
            e.status,
            e.enrollment_status,
            e.grade,
            u.full_name as student_name,
            u.email,
            s.subject_code,
            s.subject_name,
            c.course_code,
            e.enrollment_date
        FROM tbl_enrollments e
        JOIN tbl_users u ON u.user_id = e.student_id
        JOIN tbl_subjects s ON s.subject_id = e.subject_id
        JOIN tbl_course c ON c.course_id = s.course_id
        ORDER BY e.enrollment_date DESC
        LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
    );
    $allEnrollments = $allEnrollmentsStmt->fetchAll();
    
    $pageTitle = 'All Enrollments';
    $breadcrumb = [
      ['label' => 'Admin', 'url' => base_url('admin/')],
      'Enrollments'
    ];
    $sidebarLinks = [
      ['label' => 'Dashboard', 'url' => base_url('admin/')],
      ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
      ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
      ['label' => 'Students', 'url' => base_url('admin/students.php')],
      ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php'), 'active' => true],
      ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
      ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
      ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
      ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
    ];
    
    ob_start();
    ?>
    <h2 class="text-xl font-semibold mb-4">Student Enrollments</h2>
    <div class="overflow-x-auto card bg-base-100 shadow-md">
      <table class="table table-zebra">
        <thead>
          <tr>
            <th>Student</th>
            <th>Subject</th>
            <th>Course</th>
            <th>Grade</th>
            <th>Status</th>
            <th>Enrolled Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allEnrollments as $e): ?>
            <tr>
              <td>
                <div><strong><?= e($e['student_name']) ?></strong></div>
                <div class="text-sm text-base-content/70"><?= e($e['email']) ?></div>
              </td>
              <td>
                <div><strong><?= e($e['subject_code']) ?></strong></div>
                <div class="text-sm text-base-content/70"><?= e($e['subject_name']) ?></div>
              </td>
              <td><?= e($e['course_code']) ?></td>
              <td><?= $e['grade'] !== null ? e($e['grade']) : '--' ?></td>
              <td>
                <span class="badge badge-<?= $e['status'] === 'enrolled' ? 'success' : 'warning' ?>">
                  <?= e(ucfirst($e['status'])) ?>
                </span>
              </td>
              <td><?= e(date('M j, Y', strtotime($e['enrollment_date']))) ?></td>
              <td>
                <a href="?student_id=<?= (int)$e['student_id'] ?>" class="btn btn-sm btn-ghost">Manage</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    $pagination_current_page = $page;
    $pagination_total_pages = $totalPages;
    $pagination_base_path = 'admin/student_enrollments.php';
    $pagination_total = $totalEnrollments;
    $pagination_per_page = $perPage;
    require_once __DIR__ . '/../includes/pagination.php';
    ?>
    <?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../includes/layout_drawer.php';
    exit;
}

// Load student info
$stmt = $pdo->prepare("
  SELECT u.user_id, u.full_name, u.email, u.username, u.course_id, u.section_id,
    c.course_id, c.course_code, c.course_name,
    s.section_id, s.section_name
  FROM tbl_users u
  LEFT JOIN tbl_course c ON c.course_id = u.course_id
  LEFT JOIN tbl_sections s ON s.section_id = u.section_id
  WHERE u.user_id = ? AND u.role = 'student'
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $_SESSION['flash_error'] = 'Student not found.';
    redirect(base_url('admin/students.php'));
}

$studentCourseId = (int) ($student['course_id'] ?? 0);

// Handle admin enroll/drop actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'enroll') {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        if ($subjectId && $studentCourseId) {
            // Ensure subject belongs to student's course
            $subjectCourse = $pdo->prepare('SELECT course_id FROM tbl_subjects WHERE subject_id = ?');
            $subjectCourse->execute([$subjectId]);
            $subRow = $subjectCourse->fetch();
            if (!$subRow || (int)$subRow['course_id'] !== $studentCourseId) {
                $_SESSION['flash_error'] = 'You can only enroll the student in subjects for their course.';
            } else {
                // Check if already enrolled
                $exists = $pdo->prepare('SELECT 1 FROM tbl_enrollments WHERE student_id = ? AND subject_id = ?');
                $exists->execute([$student['user_id'], $subjectId]);
                if ($exists->fetch()) {
                    $_SESSION['flash_error'] = 'Student is already enrolled in this subject.';
                } else {
                    try {
                        $ins = $pdo->prepare('INSERT INTO tbl_enrollments (student_id, subject_id, status) VALUES (?, ?, ?)');
                        $ins->execute([$student['user_id'], $subjectId, 'enrolled']);
                    } catch (Throwable $e) {
                        // Fallback for databases without status column
                        $ins = $pdo->prepare('INSERT INTO tbl_enrollments (student_id, subject_id) VALUES (?, ?)');
                        $ins->execute([$student['user_id'], $subjectId]);
                    }
                    $_SESSION['flash_success'] = 'Student enrolled successfully.';
                }
            }
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }

    if ($action === 'drop') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        if ($enrollmentId) {
            // Get enrollment details before updating for notification
            $enrollDetailsStmt = $pdo->prepare('
                SELECT e.*, s.subject_code, s.subject_name, u.full_name as student_name
                FROM tbl_enrollments e
                JOIN tbl_subjects s ON s.subject_id = e.subject_id
                JOIN tbl_users u ON u.user_id = e.student_id
                WHERE e.enrollment_id = ? AND e.student_id = ?
            ');
            $enrollDetailsStmt->execute([$enrollmentId, $student['user_id']]);
            $enrollmentDetails = $enrollDetailsStmt->fetch();
            
            // Update the enrollment status to 'dropped' instead of deleting
            $update = $pdo->prepare('UPDATE tbl_enrollments SET enrollment_status = ? WHERE enrollment_id = ? AND student_id = ?');
            $update->execute(['dropped', $enrollmentId, $student['user_id']]);
            
            // Send notification to the student
            if ($enrollmentDetails) {
                $notificationTitle = 'Subject Dropped by Admin';
                $notificationMessage = "You have been dropped from {$enrollmentDetails['subject_code']} - {$enrollmentDetails['subject_name']} by the administrator.";
                $notificationType = 'warning';
                
                // Insert notification
                try {
                    $notifStmt = $pdo->prepare('
                        INSERT INTO tbl_notifications (user_id, title, message, type, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ');
                    $notifStmt->execute([$student['user_id'], $notificationTitle, $notificationMessage, $notificationType]);
                } catch (Exception $e) {
                    // Log error for debugging
                    error_log("Notification error: " . $e->getMessage());
                }
            }
            
            $_SESSION['flash_success'] = 'Enrollment dropped. Student has been notified.';
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }

    if ($action === 'reenroll') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        if ($enrollmentId) {
            // Get enrollment details before updating for notification
            $enrollDetailsStmt = $pdo->prepare('
                SELECT e.*, s.subject_code, s.subject_name, u.full_name as student_name
                FROM tbl_enrollments e
                JOIN tbl_subjects s ON s.subject_id = e.subject_id
                JOIN tbl_users u ON u.user_id = e.student_id
                WHERE e.enrollment_id = ? AND e.student_id = ?
            ');
            $enrollDetailsStmt->execute([$enrollmentId, $student['user_id']]);
            $enrollmentDetails = $enrollDetailsStmt->fetch();
            
            // Update the enrollment status back to 'active'
            $update = $pdo->prepare('UPDATE tbl_enrollments SET enrollment_status = ? WHERE enrollment_id = ? AND student_id = ?');
            $update->execute(['active', $enrollmentId, $student['user_id']]);
            
            // Send notification to the student
            if ($enrollmentDetails) {
                $notificationTitle = 'Subject Re-enrolled by Admin';
                $notificationMessage = "You have been re-enrolled in {$enrollmentDetails['subject_code']} - {$enrollmentDetails['subject_name']} by the administrator.";
                $notificationType = 'success';
                
                // Insert notification
                try {
                    $notifStmt = $pdo->prepare('
                        INSERT INTO tbl_notifications (user_id, title, message, type, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ');
                    $notifStmt->execute([$student['user_id'], $notificationTitle, $notificationMessage, $notificationType]);
                } catch (Exception $e) {
                    // Log error for debugging
                    error_log("Notification error: " . $e->getMessage());
                }
            }
            
            $_SESSION['flash_success'] = 'Student re-enrolled successfully. Student has been notified.';
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }

    if ($action === 'update_grade') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        $grade = $_POST['grade'] ?? null;
        if ($enrollmentId && $grade !== '' && $grade !== null) {
            $gradeValue = (float) $grade;
            if ($gradeValue >= 0 && $gradeValue <= 100) {
                if (updateGrade($enrollmentId, $gradeValue)) {
                    $_SESSION['flash_success'] = 'Grade updated successfully.';
                } else {
                    $_SESSION['flash_error'] = 'Failed to update grade.';
                }
            } else {
                $_SESSION['flash_error'] = 'Grade must be between 0 and 100.';
            }
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }

    if ($action === 'update_section') {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if ($sectionId > 0) {
            // Check capacity before assigning, excluding the current student from the count
            $capStmt = $pdo->prepare('
                SELECT s.capacity, COUNT(u.user_id) as student_count
                FROM tbl_sections s
                LEFT JOIN tbl_users u ON u.section_id = s.section_id AND u.role = "student" AND u.user_id != ?
                WHERE s.section_id = ?
                GROUP BY s.section_id
            ');
            $capStmt->execute([$student['user_id'], $sectionId]);
            $section = $capStmt->fetch();

            if ($section && (int)($section['student_count'] ?? 0) >= (int)($section['capacity'] ?? 0)) {
                $_SESSION['flash_error'] = 'Cannot assign section. The selected section is at full capacity.';
            } else {
                $stmt = $pdo->prepare('UPDATE tbl_users SET section_id = ? WHERE user_id = ?');
                if ($stmt->execute([$sectionId, $student['user_id']])) {
                    $_SESSION['flash_success'] = 'Section assigned successfully.';
                } else {
                    $_SESSION['flash_error'] = 'Failed to update section.';
                }
            }
        } elseif ($sectionId === 0) { // Handle un-assignment
            $pdo->prepare('UPDATE tbl_users SET section_id = NULL WHERE user_id = ?')->execute([$student['user_id']]);
            $_SESSION['flash_success'] = 'Section removed.';
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }

    if ($action === 'record_attendance') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        $attendanceStatus = $_POST['attendance_status'] ?? 'present';
        if ($enrollmentId && in_array($attendanceStatus, ['present', 'absent', 'tardy'])) {
            if (recordAttendance($enrollmentId, $attendanceStatus)) {
                $_SESSION['flash_success'] = 'Attendance recorded.';
            } else {
                $_SESSION['flash_error'] = 'Failed to record attendance.';
            }
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }

    if ($action === 'approve') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        if ($enrollmentId) {
            $upd = $pdo->prepare('UPDATE tbl_enrollments SET status = ? WHERE enrollment_id = ? AND student_id = ?');
            $upd->execute(['enrolled', $enrollmentId, $student['user_id']]);
            $_SESSION['flash_success'] = 'Enrollment approved.';
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }

    if ($action === 'reject') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        if ($enrollmentId) {
            $upd = $pdo->prepare('UPDATE tbl_enrollments SET status = ? WHERE enrollment_id = ? AND student_id = ?');
            $upd->execute(['rejected', $enrollmentId, $student['user_id']]);
            $_SESSION['flash_success'] = 'Enrollment rejected.';
        }
        redirect(base_url('admin/student_enrollments.php?student_id=' . (int) $student['user_id']));
    }
}

// Load current enrollments for the student
$enrollmentsStmt = $pdo->prepare("
  SELECT e.enrollment_id, e.enrollment_date, e.status, e.grade, e.academic_status, e.enrollment_status,
    s.subject_code, s.subject_name, s.units,
    c.course_code, c.course_name
  FROM tbl_enrollments e
  JOIN tbl_subjects s ON s.subject_id = e.subject_id
  JOIN tbl_course c ON c.course_id = s.course_id
  WHERE e.student_id = ?
  ORDER BY c.course_name, s.subject_code
");
$enrollmentsStmt->execute([$student['user_id']]);
$enrollments = $enrollmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Load available subjects for this student's course that are not yet enrolled
$availableSubjects = [];
if ($studentCourseId) {
    $availStmt = $pdo->prepare("
      SELECT s.subject_id, s.subject_code, s.subject_name, s.units
      FROM tbl_subjects s
      WHERE s.course_id = ?
        AND NOT EXISTS (
          SELECT 1 FROM tbl_enrollments e
          WHERE e.student_id = ? AND e.subject_id = s.subject_id
        )
      ORDER BY s.subject_code
    ");
    $availStmt->execute([$studentCourseId, $student['user_id']]);
    $availableSubjects = $availStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Manage enrollments';
$breadcrumb = [
  ['label' => 'Admin', 'url' => base_url('admin/')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  'Manage enrollments',
];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php'), 'active' => true],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

ob_start();
?>
<div class="flex flex-wrap justify-between items-center gap-4 mb-4">
  <div>
    <h2 class="text-xl font-semibold">Manage enrollments</h2>
    <p class="text-base-content/70">
      Student: <strong><?= e($student['full_name']) ?></strong>
      (<?= e($student['email']) ?>)
    </p>
    <p class="text-base-content/70">
      Course:
      <?php if ($studentCourseId && !empty($student['course_code'])): ?>
        <strong><?= e($student['course_code']) ?> – <?= e($student['course_name']) ?></strong>
      <?php else: ?>
        <span class="text-warning">No course assigned</span>
      <?php endif; ?>
    </p>
    <p class="text-base-content/70">
      Section:
      <?php if ($student['section_name']): ?>
        <strong>Section <?= e($student['section_name']) ?></strong>
        <button class="btn btn-xs btn-ghost ml-2" onclick="section_modal.showModal()">Change</button>
      <?php else: ?>
        <span class="text-warning">No section assigned</span>
        <button class="btn btn-xs btn-warning ml-2" onclick="section_modal.showModal()">Assign</button>
      <?php endif; ?>
    </p>
  </div>
  <a href="<?= base_url('admin/students.php') ?>" class="btn btn-ghost btn-sm">Back to students</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div>
    <h3 class="font-semibold mb-2">Current enrollments</h3>
    <?php if (empty($enrollments)): ?>
      <p class="text-base-content/70">This student is not enrolled in any subjects.</p>
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
              <th>Enrolled on</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($enrollments as $e): 
              $attendance = getAttendanceSummary($e['enrollment_id']);
              $absences = $attendance['absent'] ?? 0;
              $tardiness = $attendance['tardy'] ?? 0;
            ?>
              <tr>
                <td><?= e($e['course_code']) ?> – <?= e($e['course_name']) ?></td>
                <td>
                  <div><strong><?= e($e['subject_code']) ?></strong></div>
                  <div class="text-sm text-base-content/70"><?= e($e['subject_name']) ?></div>
                  <div class="text-sm"><?= (int)($e['units'] ?? 0) ?> units</div>
                </td>
                <td>
                  <form method="post" class="flex gap-1 items-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_grade">
                    <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                    <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                    <input type="number" name="grade" min="0" max="100" step="0.01" 
                           class="input input-bordered input-sm w-20" 
                           value="<?= $e['grade'] !== null ? e($e['grade']) : '' ?>" 
                           placeholder="--">
                    <button type="submit" class="btn btn-ghost btn-xs">Save</button>
                  </form>
                </td>
                <td>
                  <?php $acadStatus = $e['academic_status'] ?? 'pending'; ?>
                  <span class="badge badge-<?= $acadStatus === 'passed' ? 'success' : ($acadStatus === 'failed' ? 'error' : 'warning') ?>">
                    <?= e(ucfirst($acadStatus)) ?>
                  </span>
                </td>
                <td>
                  <?php $enrollStatus = $e['enrollment_status'] ?? 'active'; ?>
                  <span class="badge badge-<?= $enrollStatus === 'active' ? 'success' : ($enrollStatus === 'warning' ? 'warning' : 'error') ?>">
                    <?= e(ucfirst($enrollStatus)) ?>
                  </span>
                  <div class="text-xs mt-1">
                    Abs: <?= (int)$absences ?> | Tardy: <?= (int)$tardiness ?>
                  </div>
                </td>
                <td><?= e(date('M j, Y', strtotime($e['enrollment_date']))) ?></td>
                <td>
                  <?php if ($e['enrollment_status'] === 'dropped'): ?>
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reenroll">
                      <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                      <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-xs btn-success" title="Re-enroll">Re-enroll</button>
                    </form>
                  <?php else: ?>
                    <?php if ($e['status'] === 'pending'): ?>
                      <div class="flex flex-col gap-2 mb-2">
                        <span class="badge badge-warning text-xs">Pending</span>
                        <div class="flex gap-1">
                          <form method="post" class="inline flex-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                            <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-xs btn-success w-full text-xs">Approve</button>
                          </form>
                          <form method="post" class="inline flex-1" onsubmit="return confirm('Reject this enrollment?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                            <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-xs btn-error w-full text-xs">Reject</button>
                          </form>
                        </div>
                      </div>
                    <?php endif; ?>
                    <div class="flex gap-0.5">
                      <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="record_attendance">
                        <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                        <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                        <input type="hidden" name="attendance_status" value="present">
                        <button type="submit" class="btn btn-ghost btn-xs text-success" title="Mark Present">P</button>
                      </form>
                      <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="record_attendance">
                        <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                        <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                        <input type="hidden" name="attendance_status" value="absent">
                        <button type="submit" class="btn btn-ghost btn-xs text-error" title="Mark Absent">A</button>
                      </form>
                      <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="record_attendance">
                        <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                        <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                        <input type="hidden" name="attendance_status" value="tardy">
                        <button type="submit" class="btn btn-ghost btn-xs text-warning" title="Mark Tardy">T</button>
                      </form>
                      <form method="post" class="inline" onsubmit="return confirm('Drop this enrollment?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="drop">
                        <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                        <input type="hidden" name="enrollment_id" value="<?= (int) $e['enrollment_id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-xs text-error" title="Drop">Drop</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <h3 class="font-semibold mb-2">Enroll in a new subject</h3>
    <?php if (!$studentCourseId): ?>
      <p class="text-base-content/70">
        This student has no course assigned. Assign a course to the student first before enrolling them in subjects.
      </p>
    <?php else: ?>
      <?php if (empty($availableSubjects)): ?>
        <p class="text-base-content/70">
          There are no additional subjects available for this student's course, or they are already enrolled in all of them.
        </p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table table-zebra">
            <thead>
              <tr>
                <th>Subject code</th>
                <th>Subject name</th>
                <th>Units</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($availableSubjects as $s): ?>
                <tr>
                  <td><?= e($s['subject_code']) ?></td>
                  <td><?= e($s['subject_name']) ?></td>
                  <td><?= (int)($s['units'] ?? 0) ?></td>
                  <td>
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="enroll">
                      <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                      <input type="hidden" name="subject_id" value="<?= (int) $s['subject_id'] ?>">
                      <button type="submit" class="btn btn-primary btn-sm">Enroll</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Section Assignment Modal -->
<dialog id="section_modal" class="modal">
  <div class="modal-box">
    <h3 class="font-bold text-lg mb-4">Assign Section</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_section">
      <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
      
      <div class="form-control">
        <label class="label">Student</label>
        <input type="text" value="<?= e($student['full_name']) ?>" disabled class="input input-bordered">
      </div>
      
      <div class="form-control mt-4">
        <label class="label">Course</label>
        <input type="text" value="<?= e($student['course_name'] ?? 'Not assigned') ?>" disabled class="input input-bordered">
      </div>
      
      <div class="form-control mt-4">
        <label class="label">Select Section</label>
        <?php if ($studentCourseId): ?>
          <?php 
            $sectionsStmt = $pdo->prepare('SELECT section_id, section_name, capacity FROM tbl_sections WHERE course_id = ? ORDER BY section_name');
            $sectionsStmt->execute([$studentCourseId]);
            $availableSections = $sectionsStmt->fetchAll();
          ?>
          <select name="section_id" class="select select-bordered" required>
            <option value="">-- Select a section --</option>
            <?php foreach ($availableSections as $sec): ?>
              <option value="<?= (int)$sec['section_id'] ?>" <?= $student['section_id'] == $sec['section_id'] ? 'selected' : '' ?>>
                Section <?= e($sec['section_name']) ?> (Capacity: <?= (int)$sec['capacity'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <p class="text-error">Student must be assigned to a course first.</p>
        <?php endif; ?>
      </div>

      <div class="modal-action">
        <button type="button" class="btn" onclick="section_modal.close()">Cancel</button>
        <?php if ($studentCourseId): ?>
          <button type="submit" class="btn btn-primary">Save Section</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <form method="dialog" class="modal-backdrop">
    <button>close</button>
  </form>
</dialog>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
