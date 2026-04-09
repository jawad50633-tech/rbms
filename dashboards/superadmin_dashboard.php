<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_SUPER_ADMIN);

$db = getDB();

// ── Stats ──────────────────────────────────────────────────
$stats = $db->query(
    'SELECT
       SUM(role = "super_admin") AS total_superadmins,
       SUM(role = "admin")       AS total_admins,
       SUM(role = "teacher")     AS total_teachers,
       SUM(role = "student")     AS total_students,
       COUNT(*)                  AS total_users
     FROM users'
)->fetch();

$totalAssignments = $db->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$totalSubmissions = $db->query('SELECT COUNT(*) FROM submissions')->fetchColumn();

// ── Recent Users ───────────────────────────────────────────
$recentUsers = $db->query(
    'SELECT id, full_name, username, role, status, created_at
     FROM users
     ORDER BY created_at DESC
     LIMIT 8'
)->fetchAll();

// ── Recent Activity ────────────────────────────────────────
$activity = $db->query(
    'SELECT al.action, al.module, al.created_at, u.full_name, u.role
     FROM activity_log al
     LEFT JOIN users u ON al.user_id = u.id
     ORDER BY al.created_at DESC
     LIMIT 10'
)->fetchAll();

renderHeader('Super Admin Dashboard', 'dashboard');
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['label' => 'Total Users',   'value' => $stats['total_users'],    'icon' => 'people-fill',       'color' => '3b82f6', 'bg' => 'dbeafe'],
    ['label' => 'Admins',        'value' => $stats['total_admins'],   'icon' => 'person-gear-fill',  'color' => '8b5cf6', 'bg' => 'ede9fe'],
    ['label' => 'Teachers',      'value' => $stats['total_teachers'], 'icon' => 'person-workspace',  'color' => '10b981', 'bg' => 'd1fae5'],
    ['label' => 'Students',      'value' => $stats['total_students'], 'icon' => 'person-badge-fill', 'color' => 'f59e0b', 'bg' => 'fef3c7', 'hide' => true],
    ['label' => 'Assignments',   'value' => $totalAssignments,        'icon' => 'journal-text',      'color' => 'ef4444', 'bg' => 'fee2e2'],
    ['label' => 'Submissions',   'value' => $totalSubmissions,        'icon' => 'cloud-upload-fill', 'color' => '06b6d4', 'bg' => 'cffafe'],
  ];
  foreach ($cards as $c): ?>
  <div class="col-6 col-md-4 col-xl-2" <?= !empty($c['hide']) ? 'data-students' : '' ?>>
    <div class="stat-card h-100">
      <div class="stat-icon mb-3" style="background:#<?= $c['bg'] ?>; color:#<?= $c['color'] ?>">
        <i class="bi bi-<?= $c['icon'] ?>"></i>
      </div>
      <div class="stat-value"><?= (int)$c['value'] ?></div>
      <div class="stat-label mt-1"><?= $c['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Recent Users + Activity -->
<div class="row g-3">
  <!-- Recent Users -->
  <div class="col-lg-7">
    <div class="content-card">
      <div class="card-header-custom">
        <h6><i class="bi bi-people me-2 text-primary"></i>Recent Users</h6>
        <a href="superadmin_users.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table table-custom">
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Status</th>
              <th>Joined</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentUsers as $u): ?>
            <?php
              $roleBadge = [
                'super_admin' => 'danger',
                'admin'       => 'primary',
                'teacher'     => 'success',
                'student'     => 'warning text-dark',
              ][$u['role']] ?? 'secondary';
            ?>
            <tr <?= $u['role'] === 'student' ? 'data-students' : '' ?>>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="table-avatar-placeholder">
                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                  </div>
                  <div>
                    <div class="fw-600 small"><?= e($u['full_name']) ?></div>
                    <div class="text-muted" style="font-size:.75rem">@<?= e($u['username']) ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge bg-<?= $roleBadge ?>"><?= ucfirst(str_replace('_',' ',$u['role'])) ?></span></td>
              <td>
                <span class="badge bg-<?= $u['status'] === 'active' ? 'success' : 'secondary' ?>">
                  <?= ucfirst($u['status']) ?>
                </span>
              </td>
              <td class="text-muted small"><?= formatDate($u['created_at']) ?></td>
              <td>
                <a href="superadmin_users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-pencil"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Activity Log -->
  <div class="col-lg-5">
    <div class="content-card h-100">
      <div class="card-header-custom">
        <h6><i class="bi bi-clock-history me-2 text-warning"></i>Activity Log</h6>
        <a href="superadmin_activity.php" class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body-custom p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($activity as $log): ?>
          <li class="list-group-item border-0 py-2 px-3">
            <div class="d-flex gap-2 align-items-start">
              <div class="mt-1">
                <i class="bi bi-circle-fill text-primary" style="font-size:.45rem"></i>
              </div>
              <div class="flex-grow-1">
                <div class="small fw-500"><?= e($log['action']) ?></div>
                <div class="text-muted" style="font-size:.73rem">
                  <?= e($log['full_name'] ?? 'System') ?>
                  · <?= date('d M, H:i', strtotime($log['created_at'])) ?>
                </div>
              </div>
            </div>
          </li>
          <?php endforeach; ?>
          <?php if (empty($activity)): ?>
          <li class="list-group-item text-muted text-center small py-4">No activity yet.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
