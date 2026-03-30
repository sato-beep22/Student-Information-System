<?php
require_once __DIR__ . '/../includes/auth.php';
requireStudent();
require_once __DIR__ . '/../includes/functions/upload.php';

$user = currentUser();
$pdo = getDb();

// Handle activity submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit') {
        $activityUploadId = (int) ($_POST['activity_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        if (!$activityUploadId) {
            $_SESSION['flash_error'] = 'Invalid activity.';
            redirect(base_url('student/activities.php'));
        }
        
        // Get the admin activity to find the subject
        $activityStmt = $pdo->prepare('
            SELECT 
                au.*,
                s.subject_id,
                s.subject_code,
                s.subject_name
            FROM tbl_activity_uploads au
            INNER JOIN tbl_subjects s ON s.subject_id = (
                CAST(SUBSTRING_INDEX(SUBSTRING(au.file_name, 2), \']\', 1) AS UNSIGNED)
            )
            WHERE au.upload_id = ?
            AND au.student_id IN (SELECT user_id FROM tbl_users WHERE role = "admin")
            AND au.enrollment_id IS NULL
        ');
        $activityStmt->execute([$activityUploadId]);
        $adminActivity = $activityStmt->fetch();
        
        if (!$adminActivity) {
            $_SESSION['flash_error'] = 'Activity not found.';
            redirect(base_url('student/activities.php'));
        }
        
        // Check if student is enrolled in this subject
        $enrollStmt = $pdo->prepare('
            SELECT enrollment_id FROM tbl_enrollments 
            WHERE student_id = ? AND subject_id = ?
        ');
        $enrollStmt->execute([$user['user_id'], $adminActivity['subject_id']]);
        $enrollment = $enrollStmt->fetch();
        
        if (!$enrollment) {
            $_SESSION['flash_error'] = 'You are not enrolled in this subject.';
            redirect(base_url('student/activities.php'));
        }
        
        // Check if file is uploaded
        if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash_error'] = 'Please upload a file.';
            redirect(base_url('student/activities.php'));
        }
        
        // Validate file
        $validation = validateDocxFile($_FILES['submission_file']);
        if (!$validation['valid']) {
            $_SESSION['flash_error'] = implode(' ', $validation['errors']);
            redirect(base_url('student/activities.php'));
        }
        
        // Create upload directory if needed
        $uploadDir = dirname(__DIR__) . '/uploads/activities/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename - include subject ID and student ID
        $extension = $validation['extension'];
        $newFilename = sprintf(
            'submission_%d_%d_%d_%s.%s',
            $adminActivity['subject_id'],
            $enrollment['enrollment_id'],
            $user['user_id'],
            time(),
            $extension
        );
        
        $filePath = $uploadDir . $newFilename;
        
        // Move uploaded file
        if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $filePath)) {
            $_SESSION['flash_error'] = 'Failed to save uploaded file.';
            redirect(base_url('student/activities.php'));
        }
        
        // Save to database - link to enrollment
        try {
            $insert = $pdo->prepare('
                INSERT INTO tbl_activity_uploads 
                (enrollment_id, student_id, file_name, file_path, file_type, file_size, description, uploaded_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $finalDescription = '[ActivityID:' . $adminActivity['upload_id'] . '] ' . ($description ?: 'Submission for: ' . preg_replace('/^\[\d+\]\s+/', '', $adminActivity['file_name']));
            
            $insert->execute([
                $enrollment['enrollment_id'],
                $user['user_id'],
                $_FILES['submission_file']['name'],
                'uploads/activities/' . $newFilename,
                $_FILES['submission_file']['type'],
                $_FILES['submission_file']['size'],
                $finalDescription
            ]);
            
            $_SESSION['flash_success'] = 'Activity submitted successfully!';
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = 'Failed to save submission.';
        }
        
        redirect(base_url('student/activities.php'));
    } elseif ($action === 'undo') {
        $uploadId = (int) ($_POST['upload_id'] ?? 0);
        if ($uploadId) {
            // Find and delete the submission
            $stmt = $pdo->prepare('SELECT file_path FROM tbl_activity_uploads WHERE upload_id = ? AND student_id = ? AND enrollment_id IS NOT NULL');
            $stmt->execute([$uploadId, $user['user_id']]);
            $sub = $stmt->fetch();
            if ($sub) {
                $filePath = dirname(__DIR__) . '/' . $sub['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $pdo->prepare('DELETE FROM tbl_activity_uploads WHERE upload_id = ?')->execute([$uploadId]);
                $_SESSION['flash_success'] = 'Submission undone successfully.';
            } else {
                $_SESSION['flash_error'] = 'Submission not found or permission denied.';
            }
        }
        redirect(base_url('student/activities.php'));
    }
}

// Get flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Get all activities posted by admin for subjects the student is enrolled in
// Admin activities have enrollment_id = NULL, and subject_id is embedded in file_name as [subjectId]
$activitiesStmt = $pdo->prepare('
    SELECT 
        au.upload_id,
        au.file_name,
        au.file_path,
        au.file_size,
        au.description,
        au.uploaded_at,
        s.subject_id,
        s.subject_code,
        s.subject_name,
        c.course_code,
        c.course_name
    FROM tbl_activity_uploads au
    INNER JOIN tbl_subjects s ON s.subject_id = (
        CAST(SUBSTRING_INDEX(SUBSTRING(au.file_name, 2), \']\', 1) AS UNSIGNED)
    )
    INNER JOIN tbl_course c ON c.course_id = s.course_id
    INNER JOIN tbl_enrollments e ON e.subject_id = s.subject_id
    WHERE e.student_id = ? 
    AND au.student_id IN (SELECT user_id FROM tbl_users WHERE role = "admin")
    AND au.enrollment_id IS NULL
    ORDER BY s.subject_code, au.uploaded_at DESC
');
$activitiesStmt->execute([$user['user_id']]);
$activities = $activitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get student's submissions for these activities
$submissionsStmt = $pdo->prepare('
    SELECT 
        au.upload_id,
        au.file_name,
        au.file_path,
        au.file_size,
        au.description,
        au.uploaded_at,
        au.is_checked,
        au.checked_at,
        s.subject_id,
        s.subject_code,
        s.subject_name,
        c.course_code,
        c.course_name
    FROM tbl_activity_uploads au
    INNER JOIN tbl_enrollments e ON e.enrollment_id = au.enrollment_id
    INNER JOIN tbl_subjects s ON s.subject_id = e.subject_id
    INNER JOIN tbl_course c ON c.course_id = s.course_id
    WHERE au.student_id = ?
    AND au.enrollment_id IS NOT NULL
    ORDER BY s.subject_code, au.uploaded_at DESC
');
$submissionsStmt->execute([$user['user_id']]);
$submissions = $submissionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Group activities by subject
$activitiesBySubject = [];
foreach ($activities as $activity) {
    $subjectKey = $activity['subject_id'];
    if (!isset($activitiesBySubject[$subjectKey])) {
        $activitiesBySubject[$subjectKey] = [
            'subject_code' => $activity['subject_code'],
            'subject_name' => $activity['subject_name'],
            'course_code' => $activity['course_code'],
            'course_name' => $activity['course_name'],
            'items' => []
        ];
    }
    $activitiesBySubject[$subjectKey]['items'][] = $activity;
}

// Group submissions by subject
$submissionsBySubject = [];
$submittedActivityIds = [];
foreach ($submissions as $submission) {
    if (preg_match('/^\[ActivityID:(\d+)\]/', $submission['description'], $matches)) {
        $submittedActivityIds[$matches[1]] = true;
    }
    
    $subjectKey = $submission['subject_id'];
    if (!isset($submissionsBySubject[$subjectKey])) {
        $submissionsBySubject[$subjectKey] = [
            'subject_code' => $submission['subject_code'],
            'subject_name' => $submission['subject_name'],
            'course_code' => $submission['course_code'],
            'course_name' => $submission['course_name'],
            'items' => []
        ];
    }
    $submissionsBySubject[$subjectKey]['items'][] = $submission;
}

$pageTitle = 'Activities';
$breadcrumb = [
  ['label' => 'Student', 'url' => base_url('student/')],
  'Activities'
];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/')],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php')],
  ['label' => 'My profile', 'url' => base_url('student/profile.php')],
  ['label' => 'Enroll in subjects', 'url' => base_url('student/enroll.php')],
  ['label' => 'My records', 'url' => base_url('student/records.php')],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php')],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php')],
  ['label' => 'Activities', 'url' => base_url('student/activities.php'), 'active' => true],
];

ob_start();
?>

<h2 class="text-xl font-semibold mb-6">Activities</h2>

<?php if (empty($activitiesBySubject)): ?>
    <div class="alert alert-info">
        <svg class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span>No activities posted yet.</span>
    </div>
<?php else: ?>
    <!-- Admin Posted Activities -->
    <h3 class="text-lg font-semibold mb-4">Posted Activities</h3>
    <?php foreach ($activitiesBySubject as $subject): ?>
        <div class="card bg-base-100 shadow-md mb-6">
            <div class="card-body">
                <h3 class="card-title text-base">
                    <span><?= e($subject['subject_code']) ?></span>
                    <span class="text-sm font-normal text-base-content/70"><?= e($subject['subject_name']) ?></span>
                </h3>
                <p class="text-sm text-base-content/70 mb-4"><?= e($subject['course_code']) ?> – <?= e($subject['course_name']) ?></p>
                
                <div class="space-y-3">
                    <?php foreach ($subject['items'] as $activity): ?>
                        <div class="border rounded p-4 bg-base-200/30 hover:bg-base-200/50 transition">
                            <div class="flex justify-between items-start gap-4">
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-semibold break-words"><?= e(preg_replace('/^\\[\\d+\\]\\s+/', '', $activity['file_name'])) ?></h4>
                                    <?php if ($activity['description']): ?>
                                        <p class="text-sm text-base-content/70 mt-2"><?= e($activity['description']) ?></p>
                                    <?php endif; ?>
                                    <div class="flex gap-3 text-xs text-base-content/60 mt-3 flex-wrap">
                                        <span class="badge badge-sm badge-outline">
                                            <?= round($activity['file_size'] / 1024, 2) ?> KB
                                        </span>
                                        <span>
                                            Posted: <?= e(date('M j, Y g:i A', strtotime($activity['uploaded_at']))) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2">
                                    <a href="<?= base_url('download.php?id=' . $activity['upload_id']) ?>" class="btn btn-sm btn-primary gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                        </svg>
                                        Download
                                    </a>
                                    <?php if (isset($submittedActivityIds[$activity['upload_id']])): ?>
                                        <button disabled class="btn btn-sm btn-success gap-2 opacity-50 cursor-not-allowed">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            Submitted
                                        </button>
                                    <?php else: ?>
                                        <label for="modal-submit-<?= (int)$activity['upload_id'] ?>" class="btn btn-sm btn-success gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                            </svg>
                                            Submit
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Modal -->
                        <input type="checkbox" id="modal-submit-<?= (int)$activity['upload_id'] ?>" class="modal-toggle">
                        <div class="modal">
                            <div class="modal-box">
                                <h3 class="font-bold text-lg">Submit Activity</h3>
                                <p class="text-sm text-base-content/70 mb-4">
                                    <strong>Activity:</strong> <?= e(preg_replace('/^\\[\\d+\\]\\s+/', '', $activity['file_name'])) ?><br>
                                    <strong>Subject:</strong> <?= e($subject['subject_code']) ?> - <?= e($subject['subject_name']) ?>
                                </p>
                                <form method="post" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="submit">
                                    <input type="hidden" name="activity_id" value="<?= (int)$activity['upload_id'] ?>">
                                    
                                    <div class="form-control">
                                        <label class="label">Upload Your Work</label>
                                        <input type="file" name="submission_file" class="file-input file-input-bordered w-full" required accept=".docx,.doc,.pdf">
                                        <label class="label">
                                            <span class="label-text-alt">Allowed: DOCX, DOC, PDF (max 2MB)</span>
                                        </label>
                                    </div>
                                    
                                    <div class="form-control mt-4">
                                        <label class="label">Description (optional)</label>
                                        <textarea name="description" class="textarea textarea-bordered" rows="2" placeholder="Add notes about your submission..."></textarea>
                                    </div>
                                    
                                    <div class="modal-action">
                                        <label for="modal-submit-<?= (int)$activity['upload_id'] ?>" class="btn">Cancel</label>
                                        <button type="submit" class="btn btn-primary">Submit</button>
                                    </div>
                                </form>
                            </div>
                            <label class="modal-backdrop" for="modal-submit-<?= (int)$activity['upload_id'] ?>">Close</label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- My Submissions Section -->
<?php if (!empty($submissionsBySubject)): ?>
    <h3 class="text-lg font-semibold mb-4 mt-8">My Submissions</h3>
    <?php foreach ($submissionsBySubject as $subject): ?>
        <div class="card bg-base-100 shadow-md mb-6">
            <div class="card-body">
                <h3 class="card-title text-base">
                    <span><?= e($subject['subject_code']) ?></span>
                    <span class="text-sm font-normal text-base-content/70"><?= e($subject['subject_name']) ?></span>
                </h3>
                <p class="text-sm text-base-content/70 mb-4"><?= e($subject['course_code']) ?> – <?= e($subject['course_name']) ?></p>
                
                <div class="space-y-3">
                    <?php foreach ($subject['items'] as $submission): ?>
                        <div class="border rounded p-4 bg-base-200/30 hover:bg-base-200/50 transition">
                            <div class="flex justify-between items-start gap-4">
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-semibold break-words"><?= e($submission['file_name']) ?></h4>
                                    <?php 
                                        $desc = $submission['description'];
                                        $desc = preg_replace('/^\[ActivityID:\d+\]\s*/', '', $desc);
                                        if ($desc): 
                                    ?>
                                        <p class="text-sm text-base-content/70 mt-2"><?= e($desc) ?></p>
                                    <?php endif; ?>
                                    <div class="flex gap-3 text-xs text-base-content/60 mt-3 flex-wrap">
                                        <?php if ($submission['is_checked']): ?>
                                            <span class="badge badge-sm badge-success gap-1">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                Checked
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-sm badge-ghost">Pending review</span>
                                        <?php endif; ?>
                                        <span class="badge badge-sm badge-outline">
                                            <?= round($submission['file_size'] / 1024, 2) ?> KB
                                        </span>
                                        <span>
                                            Submitted: <?= e(date('M j, Y g:i A', strtotime($submission['uploaded_at']))) ?>
                                        </span>
                                        <?php if ($submission['is_checked'] && $submission['checked_at']): ?>
                                            <span class="text-success">
                                                Checked: <?= e(date('M j, Y g:i A', strtotime($submission['checked_at']))) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2">
                                    <a href="<?= base_url('download.php?id=' . $submission['upload_id']) ?>" class="btn btn-sm btn-primary gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                        </svg>
                                        Download
                                    </a>
                                    <form method="post" onsubmit="return confirm('Are you sure you want to undo this submission?');" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="undo">
                                        <input type="hidden" name="upload_id" value="<?= (int)$submission['upload_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-error gap-2 w-full">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                            </svg>
                                            Undo
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
?>
