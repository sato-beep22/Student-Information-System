<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions/upload.php';
requireAdmin();

$user = currentUser();
$pdo = getDb();

// Handle activity creation with file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $dueDate = $_POST['due_date'] ?? null;
        
        if (!$courseId || !$subjectId || !$title) {
            $_SESSION['flash_error'] = 'Course, subject, and title are required.';
            redirect(base_url('admin/activities.php'));
        }
        
        // Check if file is uploaded
        if (!isset($_FILES['activity_file']) || $_FILES['activity_file']['error'] === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash_error'] = 'Please upload a file.';
            redirect(base_url('admin/activities.php'));
        }
        
        // Validate file
        $validation = validateDocxFile($_FILES['activity_file']);
        if (!$validation['valid']) {
            $_SESSION['flash_error'] = implode(' ', $validation['errors']);
            redirect(base_url('admin/activities.php'));
        }
        
        // Create upload directory if needed
        $uploadDir = dirname(__DIR__) . '/uploads/activities/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $extension = $validation['extension'];
        $newFilename = sprintf(
            'activity_%d_%d_%s.%s',
            $subjectId,
            time(),
            bin2hex(random_bytes(8)),
            $extension
        );
        
        $filePath = $uploadDir . $newFilename;
        
        // Move uploaded file
        if (!move_uploaded_file($_FILES['activity_file']['tmp_name'], $filePath)) {
            $_SESSION['flash_error'] = 'Failed to save uploaded file.';
            redirect(base_url('admin/activities.php'));
        }
        
        // Save to database
        try {
            $insert = $pdo->prepare('
                INSERT INTO tbl_activity_uploads 
                (student_id, enrollment_id, file_name, file_path, file_type, file_size, description, uploaded_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            // Use admin's user_id to indicate who posted the activity
            // Set enrollment_id to NULL for admin-posted activities (no specific student enrollment)
            $insert->execute([
                $user['user_id'], // Admin's user ID
                null, // NULL for enrollment_id - admin posts activities not tied to a specific enrollment
                '['.$subjectId.'] ' . $title,
                'uploads/activities/' . $newFilename,
                $_FILES['activity_file']['type'],
                $_FILES['activity_file']['size'],
                $description
            ]);
            
            $_SESSION['flash_success'] = 'Activity posted successfully.';
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = 'Failed to post activity: ' . $e->getMessage();
            if (file_exists($filePath)) {
                unlink($filePath); // Delete uploaded file if DB insert fails
            }
        }
        redirect(base_url('admin/activities.php'));
    } elseif ($action === 'delete') {
        $uploadId = (int) ($_POST['upload_id'] ?? 0);
        
        if (!$uploadId) {
            $_SESSION['flash_error'] = 'Invalid activity ID.';
            redirect(base_url('admin/activities.php'));
        }
        
        // Get the activity
        $stmt = $pdo->prepare('SELECT file_path FROM tbl_activity_uploads WHERE upload_id = ? AND student_id = ?');
        $stmt->execute([$uploadId, $user['user_id']]);
        $activity = $stmt->fetch();
        
        if (!$activity) {
            $_SESSION['flash_error'] = 'Activity not found or you do not have permission to delete it.';
            redirect(base_url('admin/activities.php'));
        }
        
        // Delete file from filesystem
        $filePath = dirname(__DIR__) . '/' . $activity['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete from database
        $delete = $pdo->prepare('DELETE FROM tbl_activity_uploads WHERE upload_id = ?');
        $delete->execute([$uploadId]);
        
        $_SESSION['flash_success'] = 'Activity deleted successfully.';
        redirect(base_url('admin/activities.php'));
    }
}

// Get flash messages AFTER POST handling to prevent duplicates
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Get filter parameters
$courseId = (int) ($_GET['course_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

// Get courses
$coursesStmt = $pdo->prepare('SELECT course_id, course_code, course_name FROM tbl_course ORDER BY course_code');
$coursesStmt->execute();
$courses = $coursesStmt->fetchAll();

// Get subjects by course if selected
$subjects = [];
if ($courseId) {
    $subjectsStmt = $pdo->prepare('SELECT subject_id, subject_code, subject_name FROM tbl_subjects WHERE course_id = ? ORDER BY subject_code');
    $subjectsStmt->execute([$courseId]);
    $subjects = $subjectsStmt->fetchAll();
}

// Get activities list (only admin-posted activities - those with NULL enrollment_id)
$sql = '
    SELECT au.* 
    FROM tbl_activity_uploads au
    WHERE au.student_id IN (SELECT user_id FROM tbl_users WHERE role = "admin")
    AND au.enrollment_id IS NULL
    ORDER BY au.uploaded_at DESC
';
$stmtCount = $pdo->query('
    SELECT COUNT(*) FROM tbl_activity_uploads au
    WHERE au.student_id IN (SELECT user_id FROM tbl_users WHERE role = "admin")
    AND au.enrollment_id IS NULL
');
$totalActivities = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalActivities / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$activitiesStmt = $pdo->query($sql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
$activities = $activitiesStmt->fetchAll();

$pageTitle = 'Activity Management';
$breadcrumb = [
  ['label' => 'Admin', 'url' => base_url('admin/')],
  'Activity Management'
];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('admin/')],
  ['label' => 'Courses', 'url' => base_url('admin/courses.php')],
  ['label' => 'Subjects', 'url' => base_url('admin/subjects.php')],
  ['label' => 'Students', 'url' => base_url('admin/students.php')],
  ['label' => 'Enrollments', 'url' => base_url('admin/student_enrollments.php')],
  ['label' => 'Attendance', 'url' => base_url('admin/attendance.php')],
  ['label' => 'Grade Requests', 'url' => base_url('admin/grade_requests.php')],
  ['label' => 'Activity Management', 'url' => base_url('admin/activities.php'), 'active' => true],
  ['label' => 'Announcements', 'url' => base_url('admin/announcements.php')],
];

// Modal - placed outside output buffer to prevent duplication
$modalHtml = '
<!-- Modal -->
<input type="checkbox" id="modal-post-activity" class="modal-toggle">
<div class="modal">
  <div class="modal-box w-11/12 max-w-2xl">
    <h3 class="font-bold text-lg">Post Activity</h3>
    <form method="post" enctype="multipart/form-data">
      ' . csrf_field() . '
      <input type="hidden" name="action" value="create">
      
      <div class="form-control">
        <label class="label">
          <span class="label-text">Course</span>
        </label>
        <select name="course_id" id="course_select" class="select select-bordered" required onchange="loadSubjects()">
          <option value="">-- Select Course --</option>
';

// Add courses to modal
foreach ($courses as $c) {
    $modalHtml .= '<option value="' . (int)$c['course_id'] . '">' . e($c['course_code'] . ' - ' . $c['course_name']) . '</option>';
}

$modalHtml .= '
        </select>
      </div>
      
      <div class="form-control">
        <label class="label">
          <span class="label-text">Subject</span>
        </label>
        <select name="subject_id" id="subject_select" class="select select-bordered" required>
          <option value="">-- Select Subject --</option>
        </select>
      </div>
      
      <div class="form-control">
        <label class="label">
          <span class="label-text">Activity Title</span>
        </label>
        <input type="text" name="title" class="input input-bordered" placeholder="e.g. Chapter 3 Quiz" required>
      </div>
      
      <div class="form-control">
        <label class="label">
          <span class="label-text">Description</span>
        </label>
        <textarea name="description" class="textarea textarea-bordered" placeholder="Activity instructions..." rows="3"></textarea>
      </div>
      
      <div class="form-control">
        <label class="label">
          <span class="label-text">Activity File</span>
        </label>
        <input type="file" name="activity_file" class="file-input file-input-bordered" accept=".docx,.doc,.pdf" required>
        <label class="label">
          <span class="label-text-alt">Accepted: DOCX, DOC, PDF (Max 2MB)</span>
        </label>
      </div>
      
      <div class="form-control">
        <label class="label">
          <span class="label-text">Due Date (Optional)</span>
        </label>
        <input type="date" name="due_date" class="input input-bordered">
      </div>
      
      <div class="modal-action">
        <label for="modal-post-activity" class="btn">Cancel</label>
        <button type="submit" class="btn btn-primary">Post Activity</button>
      </div>
    </form>
  </div>
  <label class="modal-backdrop" for="modal-post-activity">Close</label>
</div>

<script>
function loadSubjects() {
  const courseId = document.getElementById(\'course_select\').value;
  const subjectSelect = document.getElementById(\'subject_select\');
  
  if (!courseId) {
    subjectSelect.innerHTML = \'<option value="">-- Select Subject --</option>\';
    return;
  }
  
  // Fetch subjects for selected course
  fetch(\'' . base_url('admin/get_subjects.php') . '?course_id=\' + courseId)
    .then(response => response.json())
    .then(subjects => {
      let html = \'<option value="">-- Select Subject --</option>\';
      subjects.forEach(s => {
        html += \'<option value="\' + s.subject_id + \'">\' + s.subject_code + \' - \' + s.subject_name + \'</option>\';
      });
      subjectSelect.innerHTML = html;
    })
    .catch(error => console.error(\'Error loading subjects:\', error));
}
</script>
';

ob_start();
?>

<div class="flex flex-wrap gap-4 justify-between items-center mb-6">
  <h2 class="text-xl font-semibold">Post Activity</h2>
  <label for="modal-post-activity" class="btn btn-primary">Post New Activity</label>
</div>

<!-- Posted Activities List -->
<div class="card bg-base-100 shadow-md mb-6">
  <div class="card-body">
    <h3 class="card-title text-base mb-4">Posted Activities</h3>
    <?php if (!empty($activities)): ?>
      <div class="space-y-4">
        <?php foreach ($activities as $activity): ?>
          <div class="border rounded p-4 bg-base-200/50">
            <div class="flex justify-between items-start gap-4">
              <div class="flex-1">
                <h4 class="font-semibold"><?= e(preg_replace('/^\[\d+\]\s+/', '', $activity['file_name'])) ?></h4>
                <p class="text-sm text-base-content/70 mt-1"><?= e($activity['description']) ?></p>
                <div class="text-xs text-base-content/60 mt-2">
                  <span class="badge badge-sm"><?= round($activity['file_size'] / 1024, 2) ?> KB</span>
                  <span>Uploaded: <?= e(date('M j, Y g:i A', strtotime($activity['uploaded_at']))) ?></span>
                </div>
              </div>
              <form method="post" onsubmit="return confirm('Delete this activity?');" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="upload_id" value="<?= $activity['upload_id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm text-error">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      
      <?php if ($totalPages > 1): ?>
        <div class="flex justify-center gap-2 mt-6">
          <?php if ($page > 1): ?>
            <a href="?page=1" class="btn btn-sm">«</a>
            <a href="?page=<?= $page - 1 ?>" class="btn btn-sm">‹</a>
          <?php endif; ?>
          
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?= $i ?>" class="btn btn-sm <?= $i === $page ? 'btn-active' : '' ?>">
              <?= $i ?>
            </a>
          <?php endfor; ?>
          
          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>" class="btn btn-sm">›</a>
            <a href="?page=<?= $totalPages ?>" class="btn btn-sm">»</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-base-content/70">No activities posted yet.</p>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();

// Prepend modal to content (modal is outside the output buffer)
$content = $modalHtml . $content;

require_once __DIR__ . '/../includes/layout_drawer.php';
?>
