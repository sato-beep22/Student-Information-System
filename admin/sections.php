<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDb();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle capacity update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $capacity = (int) ($_POST['capacity'] ?? 30);
    
    if ($section_id > 0 && $capacity > 0) {
        $stmt = $pdo->prepare('UPDATE tbl_sections SET capacity = ? WHERE section_id = ?');
        if ($stmt->execute([$capacity, $section_id])) {
            $_SESSION['flash_success'] = 'Section capacity updated successfully.';
        } else {
            $_SESSION['flash_error'] = 'Failed to update section capacity.';
        }
    }
    redirect(base_url('admin/sections.php'));
}

// Get filter parameters
$courseId = (int) ($_GET['course_id'] ?? 0);

// Get courses
$coursesStmt = $pdo->prepare('SELECT course_id, course_code, course_name FROM tbl_course ORDER BY course_code');
$coursesStmt->execute();
$courses = $coursesStmt->fetchAll();

// Get sections
$sql = 'SELECT s.section_id, s.section_name, s.capacity, c.course_id, c.course_code, c.course_name, COUNT(u.user_id) as student_count FROM tbl_sections s JOIN tbl_course c ON c.course_id = s.course_id LEFT JOIN tbl_users u ON u.section_id = s.section_id AND u.role = "student" WHERE 1=1';
$params = [];

if ($courseId > 0) {
    $sql .= ' AND c.course_id = ?';
    $params[] = $courseId;
}

$sql .= ' GROUP BY s.section_id ORDER BY c.course_code, s.section_name';

$stmtSections = $pdo->prepare($sql);
$stmtSections->execute($params);
$sections = $stmtSections->fetchAll();

$pageTitle = 'Section Management';
$breadcrumb = [['label' => 'Admin', 'url' => base_url('admin/')], 'Sections'];
$sidebarLinks = [
    ['label' => 'Dashboard', 'url' => base_url('admin/')],
    ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
    ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
    ['label' => 'Students', 'url' => base_url('admin/students.php')],
    ['label' => 'Student Enrollments', 'url' => base_url('admin/student_enrollments.php')],
    ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
    ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
    ['label' => 'Sections', 'url' => base_url('admin/sections.php')],
    ['label' => 'Activity Management', 'url' => base_url('admin/activities.php')],
    ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

ob_start();
?>

<h2 class="text-xl font-semibold mb-4">Section Management</h2>

<?php if ($flashSuccess): ?>
<div class="alert alert-success mb-4"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError): ?>
<div class="alert alert-error mb-4"><?= e($flashError) ?></div>
<?php endif; ?>

<div class="mb-4">
    <form method="get" class="flex gap-2 items-end flex-wrap">
        <div class="form-control">
            <label class="label">Filter by Course</label>
            <select name="course_id" class="select select-sm select-bordered" onchange="this.form.submit()">
                <option value="">All Courses</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['course_id'] ?>" <?= $courseId === (int)$c['course_id'] ? 'selected' : '' ?>>
                    <?= e($c['course_code']) ?> - <?= e($c['course_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="overflow-x-auto">
    <table class="table table-zebra w-full">
        <thead>
            <tr>
                <th>Course</th>
                <th>Section</th>
                <th>Capacity</th>
                <th>Enrolled Students</th>
                <th>Available Spots</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sections as $sec): ?>
            <tr>
                <td class="font-medium">
                    <?= e($sec['course_code']) ?><br>
                    <span class="text-xs text-base-content/60"><?= e($sec['course_name']) ?></span>
                </td>
                <td>Section <?= e($sec['section_name']) ?></td>
                <td><?= (int)$sec['capacity'] ?></td>
                <td><?= (int)$sec['student_count'] ?></td>
                <td>
                    <?php $available = max(0, (int)$sec['capacity'] - (int)$sec['student_count']); ?>
                    <span class="badge <?= $available <= 5 ? 'badge-warning' : 'badge-success' ?>">
                        <?= $available ?>/<?= (int)$sec['capacity'] ?>
                    </span>
                </td>
                <td>
                    <button class="btn btn-ghost btn-xs" onclick="edit_modal_<?= (int)$sec['section_id'] ?>.showModal()">Edit</button>
                </td>
            </tr>

            <!-- Edit Modal -->
            <dialog id="edit_modal_<?= (int)$sec['section_id'] ?>" class="modal">
                <div class="modal-box">
                    <h3 class="font-bold text-lg">Edit Section <?= e($sec['section_name']) ?></h3>
                    <form method="post" class="mt-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section_id" value="<?= (int)$sec['section_id'] ?>">
                        
                        <div class="form-control">
                            <label class="label">Course</label>
                            <input type="text" value="<?= e($sec['course_name']) ?> (<?= e($sec['course_code']) ?>)" disabled class="input input-bordered">
                        </div>
                        
                        <div class="form-control mt-2">
                            <label class="label">Section</label>
                            <input type="text" value="Section <?= e($sec['section_name']) ?>" disabled class="input input-bordered">
                        </div>
                        
                        <div class="form-control mt-2">
                            <label class="label">Capacity (Max Students)</label>
                            <input type="number" name="capacity" value="<?= (int)$sec['capacity'] ?>" min="1" max="100" required class="input input-bordered">
                        </div>

                        <div class="modal-action">
                            <button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
                <form method="dialog" class="modal-backdrop">
                    <button>close</button>
                </form>
            </dialog>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (empty($sections)): ?>
<p class="text-center text-base-content/50 mt-4">No sections found.</p>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
?>
