<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin([ROLE_ADMIN, ROLE_SUPER_ADMIN]);

$db   = getDB();
$user = currentUser();

// ── Month/Year filter (defaults to current) ─────────────────
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($month < 1 || $month > 12) $month = (int)date('m');

$search = trim($_GET['search'] ?? '');

// ── Fetch payments for the selected month ───────────────────
$where  = 'WHERE MONTH(f.payment_date) = ? AND YEAR(f.payment_date) = ? AND f.status = "Paid"';
$params = [$month, $year];

if ($search) {
    $where   .= ' AND (u.full_name LIKE ? OR s.roll_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$payments_stmt = $db->prepare(
    "SELECT f.id, f.fee_type, f.amount, f.discount, f.final_amount,
            f.payment_date, f.receipt_number,
            u.full_name AS student_name, s.roll_number,
            c.name AS class_name, c.section,
            cu.full_name AS collected_by_name
     FROM fees f
     JOIN users u ON u.id = f.student_id
     LEFT JOIN students s ON s.user_id = u.id
     LEFT JOIN classes c ON c.id = s.class_id
     LEFT JOIN users cu ON cu.id = f.collected_by
     $where
     ORDER BY f.payment_date DESC, u.full_name ASC"
);
$payments_stmt->execute($params);
$payments = $payments_stmt->fetchAll();

// ── Summary for the selected month ──────────────────────────
$total_collected  = 0;
$total_discount   = 0;
$total_admission  = 0;
$total_monthly    = 0;
$paid_student_ids = [];

foreach ($payments as $p) {
    $total_collected += (float)$p['final_amount'];
    $total_discount  += (float)$p['discount'];
    if ($p['fee_type'] === 'Admission') {
        $total_admission += (float)$p['final_amount'];
    } else {
        $total_monthly += (float)$p['final_amount'];
    }
    $paid_student_ids[$p['id']] = true; // keeps loop cheap; real distinct count below
}

$distinct_students = $db->prepare(
    "SELECT COUNT(DISTINCT f.student_id) FROM fees f
     JOIN users u ON u.id = f.student_id
     LEFT JOIN students s ON s.user_id = u.id
     $where"
);
$distinct_students->execute($params);
$paid_count = (int)$distinct_students->fetchColumn();

// Month options for the dropdown
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

renderHeader('Monthly Payments', 'fees');
?>

<!-- ── Summary Stats ── -->
<div class="row g-3 mb-4">
  <?php $cards = [
    ['label' => 'Total Collected',   'value' => 'Rs. ' . number_format($total_collected), 'icon' => 'cash-coin',            'color' => '10b981', 'bg' => 'd1fae5'],
    ['label' => 'Students Paid',     'value' => number_format($paid_count),                 'icon' => 'people-fill',          'color' => '3b82f6', 'bg' => 'dbeafe'],
    ['label' => 'Admission Fees',    'value' => 'Rs. ' . number_format($total_admission),  'icon' => 'mortarboard-fill',     'color' => '8b5cf6', 'bg' => 'ede9fe'],
    ['label' => 'Monthly Fees',      'value' => 'Rs. ' . number_format($total_monthly),    'icon' => 'calendar-check-fill',  'color' => 'f59e0b', 'bg' => 'fef3c7'],
  ]; foreach ($cards as $c): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#<?= $c['bg'] ?>;color:#<?= $c['color'] ?>">
        <i class="bi bi-<?= $c['icon'] ?>"></i>
      </div>
      <div class="stat-value" style="font-size:1.4rem"><?= $c['value'] ?></div>
      <div class="stat-label mt-1"><?= $c['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Header + Filters -->
<div class="content-card mb-3">
  <div class="card-body-custom py-3">
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
      <div>
        <h6 class="mb-0 fw-700">
          <i class="bi bi-calendar2-check-fill me-2 text-primary"></i>
          Students Paid — <span class="text-muted fw-400"><?= $month_names[$month] . ' ' . $year ?></span>
        </h6>
        <small class="text-muted"><?= count($payments) ?> payment(s)</small>
      </div>
      <form method="GET" class="d-flex gap-2 flex-wrap">
        <select name="month" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
          <?php foreach ($month_names as $num => $name): ?>
          <option value="<?= $num ?>" <?= $num === $month ? 'selected' : '' ?>><?= $name ?></option>
          <?php endforeach; ?>
        </select>
        <select name="year" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
          <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <input type="text" class="form-control form-control-sm" name="search"
               placeholder="Search student or roll no…" value="<?= e($search) ?>" style="width:200px">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($search): ?>
        <a href="admin_fees_monthly.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<!-- Payments Table -->
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th class="ps-4">Student</th>
          <th>Class</th>
          <th>Fee Type</th>
          <th>Amount</th>
          <th>Discount</th>
          <th>Paid</th>
          <th>Date</th>
          <th>Receipt</th>
          <th class="pe-4">Collected By</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
          <td class="ps-4">
            <div class="d-flex align-items-center gap-2">
              <div class="table-avatar-placeholder">
                <?= strtoupper(substr($p['student_name'], 0, 1)) ?>
              </div>
              <div>
                <div class="fw-600 small"><?= e($p['student_name']) ?></div>
                <div class="text-muted" style="font-size:.72rem">
                  <code><?= e($p['roll_number'] ?? '—') ?></code>
                </div>
              </div>
            </div>
          </td>

          <td class="small">
            <?= $p['class_name'] ? e($p['class_name']) . ($p['section'] ? ' (' . e($p['section']) . ')' : '') : '<span class="text-muted">—</span>' ?>
          </td>

          <td>
            <?php if ($p['fee_type'] === 'Admission'): ?>
              <span class="badge" style="background:rgba(139,92,246,.1);color:#a78bfa;border:1px solid #a78bfa">
                <i class="bi bi-mortarboard-fill me-1"></i>Admission
              </span>
            <?php else: ?>
              <span class="badge" style="background:rgba(59,130,246,.1);color:#60a5fa;border:1px solid #60a5fa">
                <i class="bi bi-calendar-check-fill me-1"></i>Monthly
              </span>
            <?php endif; ?>
          </td>

          <td class="small">Rs. <?= number_format($p['amount']) ?></td>

          <td class="small">
            <?php if ((float)$p['discount'] > 0): ?>
              <span class="text-warning">- Rs. <?= number_format($p['discount']) ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>

          <td class="small fw-600 text-success">Rs. <?= number_format($p['final_amount']) ?></td>

          <td class="small text-muted"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>

          <td class="small"><code><?= e($p['receipt_number']) ?></code></td>

          <td class="small pe-4"><?= e($p['collected_by_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($payments)): ?>
        <tr>
          <td colspan="9" class="text-center text-muted py-5">
            No payments recorded for <?= $month_names[$month] . ' ' . $year ?>.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($payments)): ?>
      <tfoot>
        <tr>
          <td colspan="5" class="text-end fw-700 small ps-4">Total:</td>
          <td class="fw-700 small text-success">Rs. <?= number_format($total_collected) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>