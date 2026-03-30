<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions/attendance.php';
requireStudent();

$user = currentUser();
$pdo = getDb();

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Get month and year from query params or use current
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('m'));

// Validate month/year
if ($month < 1 || $month > 12) $month = (int) date('m');
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

// Get attendance summary for student
$summary = getStudentAttendanceSummary($user['user_id']);

// Get attendance records for the selected month
$startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$endDate = date('Y-m-t', strtotime($startDate));

$attendanceData = getStudentAttendanceByDateRange($user['user_id'], $startDate, $endDate);

// Create a map of dates to attendance status for quick lookup
$attendanceMap = [];
foreach ($attendanceData as $record) {
    $attendanceMap[$record['attendance_date']] = [
        'status' => $record['status'],
        'subject' => $record['subject_code'],
        'subject_name' => $record['subject_name']
    ];
}

// Calculate calendar grid
$firstDay = strtotime("$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01");
$lastDay = strtotime($endDate);
$daysInMonth = date('d', $lastDay);
$startingDayOfWeek = (int) date('w', $firstDay); // 0 = Sunday

// Month navigation
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$pageTitle = 'My Attendance';
$breadcrumb = [
  ['label' => 'Student', 'url' => base_url('student/')],
  'Attendance'
];
$sidebarLinks = [
  ['label' => 'Dashboard', 'url' => base_url('student/')],
  ['label' => 'Announcements', 'url' => base_url('student/announcements.php')],
  ['label' => 'My profile', 'url' => base_url('student/profile.php')],
  ['label' => 'Enroll in subjects', 'url' => base_url('student/enroll.php')],
  ['label' => 'My records', 'url' => base_url('student/records.php')],
  ['label' => 'Attendance', 'url' => base_url('student/attendance.php'), 'active' => true],
  ['label' => 'Grade Request', 'url' => base_url('student/grade_request.php')],
  ['label' => 'Activities', 'url' => base_url('student/activities.php')],
];

ob_start();
?>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-6">
  <!-- Summary Cards -->
  <div class="card bg-base-100 shadow-md">
    <div class="card-body">
      <div class="text-sm text-base-content/70">Present</div>
      <div class="text-2xl font-bold text-success"><?= $summary['present'] ?></div>
    </div>
  </div>
  
  <div class="card bg-base-100 shadow-md">
    <div class="card-body">
      <div class="text-sm text-base-content/70">Absent</div>
      <div class="text-2xl font-bold text-error"><?= $summary['absences'] ?></div>
    </div>
  </div>
  
  <div class="card bg-base-100 shadow-md">
    <div class="card-body">
      <div class="text-sm text-base-content/70">Tardy</div>
      <div class="text-2xl font-bold text-warning"><?= $summary['tardiness'] ?></div>
    </div>
  </div>
  
  <div class="card bg-base-100 shadow-md">
    <div class="card-body">
      <div class="text-sm text-base-content/70">Total Records</div>
      <div class="text-2xl font-bold text-info"><?= $summary['total'] ?></div>
    </div>
  </div>
</div>

<!-- Calendar Card -->
<div class="card bg-base-100 shadow-md">
  <div class="card-body">
    <!-- Header with navigation -->
    <div class="flex items-center justify-between mb-6">
      <h2 class="card-title text-lg">
        <?= date('F Y', strtotime("$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01")) ?>
      </h2>
      <div class="flex gap-2">
        <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-sm btn-ghost">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
          </svg>
        </a>
        <a href="?month=<?= date('m') ?>&year=<?= date('Y') ?>" class="btn btn-sm btn-outline">Today</a>
        <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-sm btn-ghost">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
          </svg>
        </a>
      </div>
    </div>

    <!-- Day labels -->
    <div class="grid grid-cols-7 gap-1 mb-2">
      <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayLabel): ?>
        <div class="text-center font-semibold text-sm text-base-content/60 py-2">
          <?= $dayLabel ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Calendar grid -->
    <div class="grid grid-cols-7 gap-1">
      <?php
      // Empty cells for days before month starts
      for ($i = 0; $i < $startingDayOfWeek; $i++) {
        echo '<div class="aspect-square"></div>';
      }

      // Days of the month
      for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);
        $isToday = $dateStr === date('Y-m-d');
        $hasAttendance = isset($attendanceMap[$dateStr]);
        $status = $hasAttendance ? $attendanceMap[$dateStr]['status'] : null;

        // Determine colors based on status
        $bgClass = '';
        $tooltip = '';
        if ($status === 'present') {
          $bgClass = 'bg-success/20 border border-success';
          $tooltip = 'Present';
        } elseif ($status === 'absent') {
          $bgClass = 'bg-error/20 border border-error';
          $tooltip = 'Absent';
        } elseif ($status === 'tardy') {
          $bgClass = 'bg-warning/20 border border-warning';
          $tooltip = 'Tardy';
        } else {
          $bgClass = 'bg-base-200/50 border border-base-300';
        }

        $todayClass = $isToday ? 'ring-2 ring-primary' : '';
      ?>
        <div class="aspect-square flex flex-col items-center justify-center p-2 rounded <?= $bgClass ?> <?= $todayClass ?> relative group" title="<?= $tooltip ?>">
          <div class="font-semibold text-sm"><?= $day ?></div>
          <?php if ($hasAttendance): ?>
            <div class="text-xs capitalize font-medium mt-1">
              <?php if ($status === 'present'): ?>
                <span class="text-success">✓</span>
              <?php elseif ($status === 'absent'): ?>
                <span class="text-error">✗</span>
              <?php else: ?>
                <span class="text-warning">⏱</span>
              <?php endif; ?>
            </div>
            <!-- Tooltip with subject name -->
            <div class="hidden group-hover:block absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 bg-base-900 text-white text-xs px-2 py-1 rounded whitespace-nowrap z-10">
              <?= e($attendanceMap[$dateStr]['subject']) ?>
            </div>
          <?php endif; ?>
        </div>
      <?php } ?>
    </div>
  </div>
</div>

<!-- Legend -->
<div class="mt-6 flex flex-wrap gap-4 text-sm">
  <div class="flex items-center gap-2">
    <div class="w-4 h-4 bg-success/20 border border-success rounded"></div>
    <span>Present</span>
  </div>
  <div class="flex items-center gap-2">
    <div class="w-4 h-4 bg-error/20 border border-error rounded"></div>
    <span>Absent</span>
  </div>
  <div class="flex items-center gap-2">
    <div class="w-4 h-4 bg-warning/20 border border-warning rounded"></div>
    <span>Tardy</span>
  </div>
</div>

<!-- Attendance List for the month -->
<?php if (!empty($attendanceData)): ?>
<div class="card bg-base-100 shadow-md mt-6">
  <div class="card-body">
    <h3 class="card-title text-base mb-4">Attendance Details</h3>
    <div class="overflow-x-auto">
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Date</th>
            <th>Subject</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attendanceData as $record): ?>
            <tr>
              <td><?= e(date('M j, Y', strtotime($record['attendance_date']))) ?></td>
              <td>
                <div><strong><?= e($record['subject_code']) ?></strong></div>
                <div class="text-xs text-base-content/70"><?= e($record['subject_name']) ?></div>
              </td>
              <td>
                <span class="badge badge-<?= $record['status'] === 'present' ? 'success' : ($record['status'] === 'absent' ? 'error' : 'warning') ?>">
                  <?= e(ucfirst($record['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-info mt-6">
  <svg class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
  </svg>
  <span>No attendance records for this month.</span>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_drawer.php';
?>
