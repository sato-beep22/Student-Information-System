<?php
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$user = currentUser();
$pdo = getDb();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gender = $_POST['gender'] ?? '';
    if (!in_array($gender, ['male', 'female', 'other', 'prefer_not_to_say'], true)) $gender = null;
    if ($first === '' || $last === '' || $email === '') {
        $_SESSION['flash_error'] = 'First name, last name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = 'Invalid email.';
    } else {
        $pdo->prepare('UPDATE tbl_users SET full_name = ?, email = ? WHERE user_id = ?')
            ->execute([trim($first . ' ' . $last), $email, $user['user_id']]);
        try {
            $pdo->prepare('UPDATE tbl_profiles SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, gender = ? WHERE user_id = ?')
                ->execute([$first, $last, $email, $phone ?: null, $address ?: null, $gender, $user['user_id']]);
        } catch (Throwable $e) {
            try {
                $pdo->prepare('INSERT INTO tbl_profiles (user_id, first_name, last_name, email, phone, address, gender) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$user['user_id'], $first, $last, $email, $phone ?: null, $address ?: null, $gender]);
            } catch (Throwable $e2) { /* ignore */ }
        }
        $_SESSION['flash_success'] = 'Profile updated.';
        redirect(base_url('student/profile.php'));
    }
}

$pageTitle = 'My profile';
$breadcrumb = [['label' => 'Student', 'url' => base_url('student/')], 'Profile'];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/')],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php')],
  ['label' => 'My profile', 'url' => base_url('student/profile.php'), 'active' => true],
  ['label' => 'Enroll in subjects', 'url' => base_url('student/enroll.php')],
  ['label' => 'My records', 'url' => base_url('student/records.php')],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php')],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php')],
  ['label' => 'Activities', 'url' => base_url('student/activities.php')],
];

ob_start();
?>
<h2 class="text-xl font-semibold mb-4">Profile</h2>

<!-- Course and Section Info -->
<div class="alert alert-info mb-4">
  <div class="grid gap-2 sm:grid-cols-2">
    <div>
      <strong>Course:</strong> <?= e($user['course_id'] ? ($user['course_id'] ? 'Assigned' : 'Not assigned') : 'Not assigned') ?>
    </div>
    <div>
      <strong>Section:</strong> <?= e($user['section_name'] ? 'Section ' . $user['section_name'] : 'Not assigned') ?>
    </div>
  </div>
</div>

<div class="card bg-base-100 shadow max-w-xl">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="form-control">
          <label class="label">First name</label>
          <input type="text" name="first_name" class="input input-bordered" required value="<?= e($user['first_name'] ?? explode(' ', $user['full_name'] ?? '')[0] ?? '') ?>">
        </div>
        <div class="form-control">
          <label class="label">Last name</label>
          <input type="text" name="last_name" class="input input-bordered" required value="<?= e($user['last_name'] ?? (explode(' ', $user['full_name'] ?? '', 2)[1] ?? '')) ?>">
        </div>
      </div>
      <div class="form-control">
        <label class="label">Email</label>
        <input type="email" name="email" class="input input-bordered" required value="<?= e($user['email'] ?? '') ?>">
      </div>
      <div class="form-control">
        <label class="label">Phone</label>
        <input type="text" name="phone" class="input input-bordered" value="<?= e($user['phone'] ?? '') ?>">
      </div>
      <div class="form-control">
        <label class="label">Address</label>
        <textarea name="address" class="textarea textarea-bordered" rows="2"><?= e($user['address'] ?? '') ?></textarea>
      </div>
      <div class="form-control">
        <label class="label">Gender</label>
        <select name="gender" class="select select-bordered">
          <option value="">—</option>
          <option value="male" <?= ($user['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
          <option value="female" <?= ($user['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
          <option value="other" <?= ($user['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
          <option value="prefer_not_to_say" <?= ($user['gender'] ?? '') === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
        </select>
      </div>
      <div class="form-control mt-4">
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
