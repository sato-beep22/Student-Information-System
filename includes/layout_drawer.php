<?php
/**
 * Drawer/sidebar layout for dashboard.
 * Expects: $pageTitle, $sidebarLinks (array of [label, url, icon?]), optional $breadcrumb
 */
$currentUser = currentUser();
?>
<!DOCTYPE html>
<html lang="en" data-theme="cupcake">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle ?? 'Dashboard') ?> | Student Information System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body style= "font-family: 'Poppins', sans-serif;">
<div class="drawer lg:drawer-open">
  <input id="drawer-toggle" type="checkbox" class="drawer-toggle">
  <div class="drawer-content flex flex-col">
    <header class="navbar bg-base-100 border-b border-base-300 px-4 lg:px-6">
      <label for="drawer-toggle" class="btn btn-ghost drawer-button lg:hidden">☰</label>
      <div class="flex-1">
        <span class="text-lg font-semibold"><?= e($pageTitle ?? 'Dashboard') ?></span>
      </div>
      <div class="flex gap-2 items-center">
        <span class="text-sm text-base-content/70 hidden sm:inline"><?= e($currentUser['full_name'] ?? $currentUser['username'] ?? '') ?></span>
        <a href="<?= base_url('logout.php') ?>" class="btn btn-ghost btn-sm">Logout</a>
      </div>
    </header>
    <main class="flex-1 p-4 lg:p-6">
      <?php if (!empty($breadcrumb)): ?>
        <div class="text-sm breadcrumbs mb-4">
          <ul>
            <?php foreach ($breadcrumb as $i => $item): ?>
              <li><?php if (is_array($item)): ?><a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a><?php else: ?><?= e($item) ?><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success mb-4"><?= e($flashSuccess) ?></div>
      <?php endif; ?>
      <?php if (!empty($flashError)): ?>
        <div class="alert alert-error mb-4"><?= e($flashError) ?></div>
      <?php endif; ?>
      <?= $content ?? '' ?>
    </main>
    <footer class="footer footer-center p-4 text-base-content/70 text-sm border-t border-base-300">
      <aside>
        <p>Developed by <strong>John Jehu Amora</strong> and <strong>Precious Lyn Lucas</strong></p>
      </aside>
    </footer>
  </div>
  <aside class="drawer-side">
    <label for="drawer-toggle" class="drawer-overlay" aria-label="close sidebar"></label>
    <div class="menu p-4 w-64 min-h-full bg-base-200 text-base-content">
      <div class="mb-4">
        <a href="<?= base_url($currentUser['role'] === 'admin' ? 'admin/' : 'student/') ?>" class="text-xl font-bold">SIS</a>
        <p class="text-xs text-base-content/70"><?= e(ucfirst($currentUser['role'] ?? '')) ?></p>
      </div>
      <ul class="menu gap-1">
        <?php foreach ($sidebarLinks as $link): ?>
          <li>
            <a href="<?= e($link['url']) ?>" class="<?= (isset($link['active']) && $link['active']) ? 'active' : '' ?>">
              <?= isset($link['icon']) ? $link['icon'] . ' ' : '' ?><?= e($link['label']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </aside>
</div>
</body>
</html>
