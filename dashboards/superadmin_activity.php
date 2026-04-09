<?php
// superadmin_activity.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
requireLogin(ROLE_SUPER_ADMIN);
$db = getDB();

$logs = $db->query(
    'SELECT al.*, u.full_name, u.role, u.username
     FROM activity_log al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 200'
)->fetchAll();

renderHeader('Activity Log', 'activity');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="mb-0 fw-700">System Activity Log</h5>
  <span class="text-muted small"><?= count($logs) ?> recent entries</span>
</div>
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead><tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach($logs as $l): ?>
        <tr>
          <td class="small text-muted"><?= date('d M, H:i:s', strtotime($l['created_at'])) ?></td>
          <td class="small">
            <?php if($l['full_name']): ?>
            <div class="fw-600"><?= e($l['full_name']) ?></div>
            <div class="text-muted" style="font-size:.72rem">@<?= e($l['username']) ?></div>
            <?php else: ?>
            <span class="text-muted">System</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if($l['role']): ?>
            <span class="badge bg-secondary badge-role"><?= ucfirst(str_replace('_',' ',$l['role'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($l['action']) ?></td>
          <td><span class="badge bg-light text-dark border small"><?= e($l['module'] ?? '—') ?></span></td>
          <td class="small text-muted"><code><?= e($l['ip_address'] ?? '—') ?></code></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($logs)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No activity recorded.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; renderFooter(); ?>
