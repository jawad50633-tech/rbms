<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_SUPER_ADMIN);
$db = getDB();

// ── Totals from backup (permanent audit trail) ─────────────
$stats = $db->query(
    'SELECT
       SUM(final_amount)                                              AS total_collected,
       SUM(CASE WHEN fee_type="Admission" THEN final_amount ELSE 0 END) AS total_admission,
       SUM(CASE WHEN fee_type="Monthly"   THEN final_amount ELSE 0 END) AS total_monthly,
       SUM(discount)                                                  AS total_discounts,
       COUNT(*)                                                       AS total_transactions
     FROM fees_backup'
)->fetch();

// ── This month ─────────────────────────────────────────────
$month_stats = $db->prepare(
    'SELECT SUM(final_amount) AS collected, COUNT(*) AS count
     FROM fees_backup
     WHERE MONTH(payment_date)=? AND YEAR(payment_date)=?'
);
$month_stats->execute([date('m'), date('Y')]);
$month_stats = $month_stats->fetch();

// ── Monthly breakdown (last 6 months) ─────────────────────
$monthly = $db->query(
    'SELECT DATE_FORMAT(payment_date, "%b %Y") AS month_label,
            SUM(final_amount) AS total,
            COUNT(*) AS txn
     FROM fees_backup
     GROUP BY YEAR(payment_date), MONTH(payment_date)
     ORDER BY YEAR(payment_date) DESC, MONTH(payment_date) DESC
     LIMIT 6'
)->fetchAll();

// ── Top payers ─────────────────────────────────────────────
$topStudents = $db->query(
    'SELECT u.full_name, s.roll_number, SUM(f.final_amount) AS paid, COUNT(*) AS txn
     FROM fees_backup f
     JOIN users u ON u.id = f.student_id
     JOIN students s ON s.user_id = u.id
     GROUP BY f.student_id
     ORDER BY paid DESC
     LIMIT 10'
)->fetchAll();

// ── Recent 20 transactions ─────────────────────────────────
$recent = $db->query(
    'SELECT f.*, u.full_name AS student_name, a.full_name AS admin_name
     FROM fees_backup f
     JOIN users u ON u.id = f.student_id
     LEFT JOIN users a ON a.id = f.collected_by
     ORDER BY f.created_at DESC
     LIMIT 20'
)->fetchAll();

renderHeader('Fees Audit Report', 'fees');
?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <?php $cards = [
    ['label' => 'All-Time Collected',    'value' => 'Rs. ' . number_format($stats['total_collected']  ?? 0), 'icon' => 'cash-stack',           'color' => '10b981', 'bg' => 'd1fae5'],
    ['label' => 'Admission Fees Total',  'value' => 'Rs. ' . number_format($stats['total_admission']  ?? 0), 'icon' => 'mortarboard-fill',      'color' => '3b82f6', 'bg' => 'dbeafe'],
    ['label' => 'Monthly Fees Total',    'value' => 'Rs. ' . number_format($stats['total_monthly']    ?? 0), 'icon' => 'calendar-check-fill',   'color' => '8b5cf6', 'bg' => 'ede9fe'],
    ['label' => 'Discounts Given',       'value' => 'Rs. ' . number_format($stats['total_discounts']  ?? 0), 'icon' => 'tag-fill',              'color' => 'f59e0b', 'bg' => 'fef3c7'],
    ['label' => 'Total Transactions',    'value' => number_format($stats['total_transactions'] ?? 0),         'icon' => 'receipt-cutoff',        'color' => 'ef4444', 'bg' => 'fee2e2'],
    ['label' => date('F') . ' Revenue',  'value' => 'Rs. ' . number_format($month_stats['collected'] ?? 0),  'icon' => 'graph-up-arrow',        'color' => '06b6d4', 'bg' => 'cffafe'],
  ]; foreach ($cards as $c): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#<?= $c['bg'] ?>;color:#<?= $c['color'] ?>">
        <i class="bi bi-<?= $c['icon'] ?>"></i>
      </div>
      <div class="stat-value" style="font-size:1.1rem"><?= $c['value'] ?></div>
      <div class="stat-label mt-1"><?= $c['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <!-- Monthly Breakdown -->
  <div class="col-lg-4">
    <div class="content-card">
      <div class="card-header-custom">
        <h6><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Monthly Breakdown</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-custom">
          <thead><tr><th>Month</th><th>Collected</th><th>Txns</th></tr></thead>
          <tbody>
            <?php foreach ($monthly as $m): ?>
            <tr>
              <td class="small fw-600"><?= e($m['month_label']) ?></td>
              <td class="small text-success fw-600">Rs. <?= number_format($m['total']) ?></td>
              <td><span class="badge bg-primary rounded-pill"><?= $m['txn'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($monthly)): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Top Payers -->
  <div class="col-lg-4">
    <div class="content-card">
      <div class="card-header-custom">
        <h6><i class="bi bi-trophy-fill me-2 text-warning"></i>Top Paying Students</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-custom">
          <thead><tr><th>Student</th><th>Total Paid</th></tr></thead>
          <tbody>
            <?php foreach ($topStudents as $i => $ts): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($i < 3): ?>
                  <span style="font-size:.9rem"><?= ['🥇','🥈','🥉'][$i] ?></span>
                  <?php else: ?>
                  <span class="text-muted small fw-600">#<?= $i+1 ?></span>
                  <?php endif; ?>
                  <div>
                    <div class="small fw-600"><?= e($ts['full_name']) ?></div>
                    <div class="text-muted" style="font-size:.72rem"><code><?= e($ts['roll_number'] ?? '—') ?></code></div>
                  </div>
                </div>
              </td>
              <td class="small fw-700 text-success">Rs. <?= number_format($ts['paid']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($topStudents)): ?>
            <tr><td colspan="2" class="text-center text-muted py-3">No data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Recent Transactions -->
  <div class="col-lg-4">
    <div class="content-card">
      <div class="card-header-custom">
        <h6><i class="bi bi-clock-history me-2 text-success"></i>Recent Transactions</h6>
      </div>
      <ul class="list-group list-group-flush">
        <?php foreach ($recent as $r): ?>
        <li class="list-group-item border-0 py-2 px-3">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="small fw-600"><?= e($r['student_name']) ?></div>
              <div class="text-muted" style="font-size:.72rem">
                <?= $r['fee_type'] ?> · <?= date('d M', strtotime($r['payment_date'])) ?>
                <?= $r['discount'] > 0 ? '· <span class="text-warning">-' . $r['discount_pct'] . '%</span>' : '' ?>
              </div>
            </div>
            <span class="badge bg-success">Rs. <?= number_format($r['final_amount']) ?></span>
          </div>
        </li>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
        <li class="list-group-item text-center text-muted small py-4">No transactions yet.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<div class="mt-3">
  <small class="text-muted">
    <i class="bi bi-shield-lock me-1"></i>
    This audit report reads from the permanent <code>fees_backup</code> table.
    Deleted fee records still appear here for accountability.
    Report generated: <?= date('d M Y, H:i:s') ?>
  </small>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
