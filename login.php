<?php
require_once __DIR__ . '/includes/auth.php';




$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $error = 'Please enter username and password.';
} elseif (login($username, $password)) {
            $role = $_SESSION['role'] ?? '';
            redirect($role === 'admin' ? base_url('admin/?logged_in=1') : base_url('student/?logged_in=1'));
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$pageTitle = 'Login';

// Check for success message from redirect (login or registration)
$successMessage = '';
$popupId = '';
$continueUrl = base_url('student/');

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $successMessage = 'Registration successful! Please log in with your credentials.';
    $popupId = 'popup-registered';
} elseif (isset($_GET['logged_in']) && $_GET['logged_in'] === '1') {
    $successMessage = 'Login successful! Welcome back!';
    $popupId = 'popup-success';
    $continueUrl = isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? base_url('admin/') : base_url('student/');
}

require_once __DIR__ . '/includes/header_plain.php';
?>

<!-- Success Popup Modal -->
<?php if ($successMessage && $popupId): ?>
<input type="checkbox" id="<?= $popupId ?>" class="modal-toggle" checked>
<div class="modal">
  <div class="modal-box text-center">
    <svg class="mx-auto mb-4 h-12 w-12 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
    <h3 class="mb-4 text-lg font-bold text-success"><?= isset($_GET['registered']) ? 'Registration Complete!' : 'Welcome!' ?></h3>
    <p class="text-base-content/70"><?= e($successMessage) ?></p>
    <div class="modal-action justify-center">
      <a href="<?= $continueUrl ?>" class="btn btn-primary">Continue</a>
    </div>
  </div>
  <label class="modal-backdrop" for="<?= $popupId ?>">Close</label>
</div>
<?php endif; ?>

<div class="min-h-screen flex items-center justify-center bg-base-200 px-4">
  <div class="card w-full max-w-md bg-base-100 shadow-xl">
    <div class="card-body">
      <h1 class="card-title text-2xl justify-center">Student Info-System</h1>
      <p class="text-center text-base-content/70">Sign in to your account</p>
      <?php if ($error): ?>
        <div class="alert alert-error text-sm"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="">
        <?= csrf_field() ?>
        <div class="form-control">
          <label class="label" for="username">Username or Email</label>
          <input type="text" id="username" name="username" class="input input-bordered" required
                 value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username">
        </div>
        <div class="form-control mt-2">
          <label class="label" for="password">Password</label>
          <input type="password" id="password" name="password" class="input input-bordered" required autocomplete="current-password">
        </div>
        <div class="form-control mt-6">
          <button type="submit" class="btn btn-primary">Login</button>
        </div>
      </form>
      <p class="text-center text-sm text-base-content/60 mt-2">
        <a href="<?= base_url('register.php') ?>" class="link link-primary">Register as student</a>
      </p>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer_plain.php'; ?>
