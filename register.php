<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(base_url('student/'));
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password_confirm'] ?? '';
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $section_id = (int) ($_POST['section_id'] ?? 0);
        $full_name = trim($first . ' ' . $last);
        if ($full_name === '' || $email === '' || $password === '') {
            $error = 'Please fill required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $password2) {
            $error = 'Passwords do not match.';
        } elseif ($course_id <= 0) {
            $error = 'Please select a course.';
        } elseif ($section_id <= 0) {
            $error = 'Please select a section.';
        } else {
            $pdo = getDb();
            // Verify section and check capacity
            $sectionStmt = $pdo->prepare('
                SELECT s.section_id, s.capacity, COUNT(u.user_id) as student_count
                FROM tbl_sections s
                LEFT JOIN tbl_users u ON u.section_id = s.section_id AND u.role = "student"
                WHERE s.section_id = ? AND s.course_id = ?
                GROUP BY s.section_id
            ');
            $sectionStmt->execute([$section_id, $course_id]);
            $section = $sectionStmt->fetch();

            if (!$section) {
                $error = 'Invalid section for selected course.';
            } elseif ((int)($section['student_count'] ?? 0) >= (int)($section['capacity'] ?? 0)) {
                $error = 'The selected section is at full capacity. Please choose another section.';
            } else {
                $stmt = $pdo->prepare('SELECT user_id FROM tbl_users WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'Email already registered.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    try {
                        $pdo->prepare('INSERT INTO tbl_users (username, full_name, email, password, role, course_id, section_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$email, $full_name, $email, $hash, ROLE_STUDENT, $course_id, $section_id]);
                    } catch (Throwable $e) {
                        $pdo->prepare('INSERT INTO tbl_users (full_name, email, password, role, course_id, section_id) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$full_name, $email, $hash, ROLE_STUDENT, $course_id, $section_id]);
                    }
                    $user_id = (int) $pdo->lastInsertId();
                    try {
                        $pdo->prepare('INSERT INTO tbl_profiles (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)')
                            ->execute([$user_id, $first ?: 'Student', $last ?: '', $email]);
                    } catch (Throwable $e) { /* tbl_profiles may not exist */ }
                    $success = 'Registration successful. You can log in now.';
                    if (!headers_sent()) header('Location: ' . base_url('login.php?registered=1'));
                }
            }
        }
    }
}

$pdo = getDb();
$courses = $pdo->query('SELECT course_id, course_name, course_code FROM tbl_course ORDER BY course_name')->fetchAll();
$pageTitle = 'Register';

// Check for registered success message
$registeredMessage = '';
if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $registeredMessage = 'Registration successful! Please log in with your credentials.';
}

require_once __DIR__ . '/includes/header_plain.php';
?>

<!-- Success Popup Modal -->
<?php if ($registeredMessage): ?>
<input type="checkbox" id="popup-registered" class="modal-toggle" checked>
<div class="modal">
  <div class="modal-box text-center">
    <svg class="mx-auto mb-4 h-12 w-12 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
    <h3 class="mb-4 text-lg font-bold text-success">Registration Complete!</h3>
    <p class="text-base-content/70"><?= e($registeredMessage) ?></p>
    <div class="modal-action justify-center">
      <a href="<?= base_url('login.php') ?>" class="btn btn-primary">Go to Login</a>
    </div>
  </div>
  <label class="modal-backdrop" for="popup-registered">Close</label>
</div>
<?php endif; ?>

<div class="min-h-screen flex items-center justify-center bg-base-200 px-4 py-8">
  <div class="card w-full max-w-md bg-base-100 shadow-xl">
    <div class="card-body">
      <h1 class="card-title text-2xl justify-center">Student Registration</h1>
      <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
        <p><a href="<?= base_url('login.php') ?>" class="btn btn-primary">Go to Login</a></p>
      <?php else: ?>
        <?php if ($error): ?>
          <div class="alert alert-error text-sm"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="">
          <?= csrf_field() ?>
          <div class="form-control">
            <label class="label" for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" class="input input-bordered" required value="<?= e($_POST['first_name'] ?? '') ?>">
          </div>
          <div class="form-control">
            <label class="label" for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" class="input input-bordered" required value="<?= e($_POST['last_name'] ?? '') ?>">
          </div>
          <div class="form-control">
            <label class="label" for="email">Email</label>
            <input type="email" id="email" name="email" class="input input-bordered" required value="<?= e($_POST['email'] ?? '') ?>">
          </div>
          <div class="form-control">
            <label class="label" for="course_id">Course</label>
            <select id="course_id" name="course_id" class="select select-bordered" required onchange="loadSections()">
              <option value="">-- Select course --</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['course_id'] ?>" <?= (isset($_POST['course_id']) && (int)$_POST['course_id'] === (int)$c['course_id']) ? 'selected' : '' ?>><?= e($c['course_name']) ?> (<?= e($c['course_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-control">
            <label class="label" for="section_id">Section</label>
            <select id="section_id" name="section_id" class="select select-bordered" required>
              <option value="">-- Select section --</option>
            </select>
          </div>
          <script>
            function loadSections() {
              const courseId = document.getElementById('course_id').value;
              const sectionSelect = document.getElementById('section_id');
              sectionSelect.innerHTML = '<option value="">-- Select section --</option>';
              
              if (courseId) {
                fetch('<?= base_url('admin/get_sections.php') ?>?course_id=' + courseId)
                  .then(r => r.json())
                  .then(data => {
                    data.forEach(sec => {
                      const opt = document.createElement('option');
                      opt.value = sec.section_id;
                      opt.textContent = 'Section ' + sec.section_name + ' (Capacity: ' + sec.capacity + ')';
                      sectionSelect.appendChild(opt);
                    });
                  });
              }
            }
            // Load sections on page load if course was already selected
            if (document.getElementById('course_id').value) {
              loadSections();
            }
          </script>
          <div class="form-control">
            <label class="label" for="password">Password</label>
            <input type="password" id="password" name="password" class="input input-bordered" required minlength="6">
          </div>
          <div class="form-control">
            <label class="label" for="password_confirm">Confirm password</label>
            <input type="password" id="password_confirm" name="password_confirm" class="input input-bordered" required minlength="6">
          </div>
          <div class="form-control mt-4">
            <button type="submit" class="btn btn-primary">Register</button>
          </div>
        </form>
        <p class="text-center text-sm mt-2"><a href="<?= base_url('login.php') ?>" class="link link-primary">Already have an account? Login</a></p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer_plain.php'; ?>
              
