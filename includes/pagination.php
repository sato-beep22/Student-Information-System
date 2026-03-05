<?php
/**
 * Pagination UI. Expects: $pagination_current_page, $pagination_total_pages, $pagination_base_path.
 * Optional: $pagination_total, $pagination_per_page (for "Showing X–Y of Z").
 */
if (empty($pagination_total_pages) || $pagination_total_pages <= 1) return;
$current = (int) $pagination_current_page;
$totalPages = (int) $pagination_total_pages;
$basePath = $pagination_base_path ?? '';
$baseQuery = $_GET;
$link = function ($page) use ($basePath, $baseQuery) {
    $q = array_merge($baseQuery, ['page' => $page]);
    if ($page <= 1) unset($q['page']);
    $queryString = http_build_query($q);
    return base_url($basePath) . ($queryString !== '' ? '?' . $queryString : '');
};
?>
<div class="flex flex-wrap items-center justify-between gap-4 mt-4">
  <?php if (isset($pagination_total, $pagination_per_page)): ?>
    <?php
    $from = (($current - 1) * $pagination_per_page) + 1;
    $to = min($current * $pagination_per_page, $pagination_total);
    ?>
    <p class="text-sm text-base-content/70">Showing <?= $from ?>–<?= $to ?> of <?= (int) $pagination_total ?></p>
  <?php else: ?>
    <p class="text-sm text-base-content/70">Page <?= $current ?> of <?= $totalPages ?></p>
  <?php endif; ?>
  <div class="join">
    <a href="<?= e($link(max(1, $current - 1))) ?>" class="join-item btn btn-sm" <?= $current <= 1 ? 'aria-disabled="true"' : '' ?>>Previous</a>
    <?php
    $start = max(1, $current - 2);
    $end = min($totalPages, $current + 2);
    for ($i = $start; $i <= $end; $i++):
      $isCurrent = $i === $current;
    ?>
      <a href="<?= e($link($i)) ?>" class="join-item btn btn-sm <?= $isCurrent ? 'btn-active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <a href="<?= e($link(min($totalPages, $current + 1))) ?>" class="join-item btn btn-sm" <?= $current >= $totalPages ? 'aria-disabled="true"' : '' ?>>Next</a>
  </div>
</div>
