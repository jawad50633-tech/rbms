<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin([ROLE_ADMIN, ROLE_SUPER_ADMIN]);
$db = getDB();

$totalStudents  = $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$activeStudents = $db->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetchColumn();
$pendingStudents = $totalStudents - $activeStudents;

$recentStudents = $db->query(
    "SELECT u.id, u.full_name, u.status, u.created_at,
            s.roll_number, s.father_name,
            c.name AS class_name, c.section
     FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     LEFT JOIN classes  c ON c.id = s.class_id
     WHERE u.role = 'student'
     ORDER BY u.created_at DESC
     LIMIT 8"
)->fetchAll();

renderHeader('Admin Dashboard', 'dashboard');
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3" data-students>
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#dbeafe;color:#3b82f6">
        <i class="bi bi-person-badge-fill"></i>
      </div>
      <div class="stat-value"><?= (int)$totalStudents ?></div>
      <div class="stat-label mt-1">Total Students</div>
    </div>
  </div>
  <div class="col-6 col-md-3" data-students>
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#d1fae5;color:#10b981">
        <i class="bi bi-check-circle-fill"></i>
      </div>
      <div class="stat-value"><?= (int)$activeStudents ?></div>
      <div class="stat-label mt-1">Portal Active</div>
    </div>
  </div>
  <div class="col-6 col-md-3" data-students>
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#fef3c7;color:#f59e0b">
        <i class="bi bi-clock-fill"></i>
      </div>
      <div class="stat-value"><?= (int)$pendingStudents ?></div>
      <div class="stat-label mt-1">Awaiting Login Setup</div>
    </div>
  </div>
  <div class="col-6 col-md-3" data-students>
    <div class="content-card d-flex align-items-center justify-content-center p-3">
      <a href="admin_students.php?action=add" class="btn btn-primary w-100">
        <i class="bi bi-person-plus-fill me-1"></i>Enroll Student
      </a>
    </div>
  </div>
</div>

<?php if ($pendingStudents > 0): ?>
<!-- Reminder for pending logins -->
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4" data-students>
  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
  <div>
    <strong><?= (int)$pendingStudents ?> student(s)</strong> are enrolled but don't have portal login access yet.
    Ask the <strong>Super Admin</strong> to activate their login credentials in User Management.
  </div>
</div>
<?php endif; ?>

<!-- Recent Students -->
<div class="content-card" data-students>
  <div class="card-header-custom">
    <h6><i class="bi bi-person-badge me-2 text-primary"></i>Recently Enrolled Students</h6>
    <a href="admin_students.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Student</th><th>Father</th><th>Roll No.</th>
          <th>Class</th><th>Login Status</th><th>Enrolled</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentStudents as $s): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="table-avatar-placeholder"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
              <span class="small fw-600"><?= e($s['full_name']) ?></span>
            </div>
          </td>
          <td class="small text-muted"><?= e($s['father_name'] ?? '—') ?></td>
          <td><code class="small"><?= e($s['roll_number'] ?? '—') ?></code></td>
          <td class="small">
            <?= $s['class_name']
              ? e($s['class_name']) . ($s['section'] ? ' (' . e($s['section']) . ')' : '')
              : '<span class="badge bg-danger">No Class</span>' ?>
          </td>
          <td>
            <span class="badge bg-<?= $s['status'] === 'active' ? 'success' : 'warning text-dark' ?>">
              <?= $s['status'] === 'active' ? '✓ Active' : '⏳ Pending' ?>
            </span>
          </td>
          <td class="small text-muted"><?= formatDate($s['created_at']) ?></td>
          <td>
            <a href="admin_students.php?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-pencil"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentStudents)): ?>
        <tr>
          <td colspan="7" class="text-center text-muted py-4">
            No students enrolled yet. <a href="admin_students.php?action=add">Enroll one now</a>.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
