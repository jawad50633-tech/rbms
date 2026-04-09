<?php
require_once __DIR__ . '/../config.php';

requireLogin([ROLE_ADMIN, ROLE_SUPER_ADMIN]);
$db   = getDB();
$user = currentUser();

// ── PRINT MODE: single receipt, 3 copies ─────────────────
$print_id = (int)($_GET['print'] ?? 0);
if ($print_id) {
    $st = $db->prepare(
        'SELECT f.*, u.full_name AS student_name, s.father_name, s.roll_number,
                c.name AS class_name, c.section,
                admin.full_name AS collected_by_name
         FROM fees f
         JOIN users u ON u.id = f.student_id
         JOIN students s ON s.user_id = u.id
         LEFT JOIN classes c ON c.id = s.class_id
         LEFT JOIN users admin ON admin.id = f.collected_by
         WHERE f.id = ?'
    );
    $st->execute([$print_id]);
    $r = $st->fetch();

    if (!$r) { header('Location: admin_fees.php'); exit; }

    $copies = ['Office Copy', 'Student Copy', 'Teacher Copy'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Receipt — <?= e($r['receipt_number']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <style>
    @page { size: A4 landscape; margin: 5mm; }
    body  { font-family: 'Inter', sans-serif; background: #fff; color: #000; margin: 0; }

    .no-print {
      background: #0f172a;
      padding: 12px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .receipt-wrapper {
      display: flex;
      gap: 6px;
      padding: 6px;
      min-height: 95vh;
    }

    .receipt-box {
      flex: 1;
      border: 2px solid #000;
      display: flex;
      flex-direction: column;
      position: relative;
      padding-bottom: 10px;
    }

    .receipt-box + .receipt-box {
      border-left: 2px dashed #aaa;
    }

    .receipt-header {
      background: #0f172a;
      color: #fff;
      padding: 14px 16px;
      text-align: center;
    }

    .receipt-header h2 {
      font-size: 14px;
      font-weight: 900;
      letter-spacing: 1px;
      margin: 0 0 3px;
    }

    .copy-label {
      display: inline-block;
      border: 1px solid rgba(255,255,255,.5);
      font-size: 9px;
      font-weight: 700;
      padding: 2px 10px;
      border-radius: 20px;
      letter-spacing: 1.5px;
    }

    .info-section {
      margin: 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      overflow: hidden;
    }

    .info-row {
      display: flex;
      border-bottom: 1px solid #eee;
    }

    .info-row:last-child { border-bottom: none; }

    .info-key {
      flex: 0 0 38%;
      background: #f8f9fa;
      padding: 5px 10px;
      font-size: 9.5px;
      font-weight: 700;
      text-transform: uppercase;
      color: #555;
      border-right: 1px solid #eee;
    }

    .info-val {
      padding: 5px 10px;
      font-size: 11px;
      font-weight: 700;
      color: #000;
    }

    .amount-box {
      margin: 10px;
      border: 3px double #0f172a;
      border-radius: 10px;
      padding: 16px;
      text-align: center;
    }

    .amount-box .label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #666; }
    .amount-box .value { font-size: 26px; font-weight: 900; color: #0f172a; line-height: 1.1; }
    .amount-box .fee-type-label { font-size: 10px; color: #555; margin-top: 2px; }

    <?php if ($r['discount'] > 0): ?>
    .discount-badge {
      display: inline-block;
      background: #fef3c7;
      border: 1px solid #f59e0b;
      color: #92400e;
      font-size: 9px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 20px;
      margin-top: 4px;
    }
    <?php endif; ?>

    .footer-row {
      margin: auto 10px 0;
      padding-top: 30px;
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
    }

    .stamp-circle {
      width: 70px;
      height: 70px;
      border: 2px dashed #ccc;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 8px;
      color: #bbb;
      text-align: center;
      line-height: 1.3;
    }

    .sig-line {
      text-align: center;
      border-top: 2px solid #000;
      padding-top: 4px;
      font-size: 9px;
      font-weight: 800;
      width: 110px;
    }

    @media print {
      .no-print { display: none !important; }
      body { margin: 0; }
    }
  </style>
</head>
<body onload="window.print()">

<div class="no-print">
  <button onclick="window.print()" class="btn btn-sm btn-info fw-700">
    <i class="bi bi-printer me-1"></i> Print Receipt
  </button>
  <a href="admin_receipts.php?student_id=<?= $r['student_id'] ?>" class="btn btn-sm btn-outline-light">
    ← Back to Ledger
  </a>
  <a href="admin_fees.php" class="btn btn-sm btn-outline-secondary">
    ← Fees Manager
  </a>
</div>

<div class="receipt-wrapper">
  <?php foreach ($copies as $copy): ?>
  <div class="receipt-box">

    <div class="receipt-header">
      <h2>AI FUTURE LEADERS ACADEMY</h2>
      <div class="copy-label"><?= strtoupper($copy) ?></div>
    </div>

    <div class="info-section">
      <div class="info-row">
        <div class="info-key">Receipt No</div>
        <div class="info-val"><?= e($r['receipt_number']) ?></div>
      </div>
      <div class="info-row">
        <div class="info-key">Reg. No</div>
        <div class="info-val"><?= e($r['roll_number'] ?? 'N/A') ?></div>
      </div>
      <div class="info-row">
        <div class="info-key">Student</div>
        <div class="info-val"><?= strtoupper(e($r['student_name'])) ?></div>
      </div>
      <?php if ($r['father_name']): ?>
      <div class="info-row">
        <div class="info-key">Father</div>
        <div class="info-val"><?= strtoupper(e($r['father_name'])) ?></div>
      </div>
      <?php endif; ?>
      <div class="info-row">
        <div class="info-key">Class</div>
        <div class="info-val">
          <?= e($r['class_name'] ?? '—') ?>
          <?= $r['section'] ? '(' . e($r['section']) . ')' : '' ?>
        </div>
      </div>
      <div class="info-row">
        <div class="info-key">Date Paid</div>
        <div class="info-val"><?= date('d-m-Y', strtotime($r['payment_date'])) ?></div>
      </div>
      <div class="info-row">
        <div class="info-key">Collected By</div>
        <div class="info-val"><?= e($r['collected_by_name'] ?? 'Admin') ?></div>
      </div>
    </div>

    <div class="amount-box">
      <div class="label">Total Fee Paid</div>
      <div class="value">Rs. <?= number_format($r['final_amount'], 0) ?></div>
      <div class="fee-type-label"><?= $r['fee_type'] ?> Fee</div>
      <?php if ($r['discount'] > 0): ?>
      <div class="discount-badge">
        Discount: Rs. <?= number_format($r['discount'], 0) ?>
        (<?= $r['discount_pct'] ?>%)
        — Base: Rs. <?= number_format($r['amount'], 0) ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="footer-row">
      <div class="stamp-circle">ACADEMY<br>STAMP</div>
      <div class="sig-line">Authorized Signature</div>
    </div>

  </div>
  <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</body>
</html>
<?php
    exit; // Don't render normal page
}

// ── DELETE a fee record ────────────────────────────────────
if (isset($_GET['delete'])) {
    $fee_id    = (int)($_GET['delete']    ?? 0);
    $student_id = (int)($_GET['student_id'] ?? 0);
    // Note: fees_backup is intentionally NOT deleted (audit trail)
    $db->prepare('DELETE FROM fees WHERE id=?')->execute([$fee_id]);
    logActivity($user['id'], "Deleted fee record #{$fee_id}", 'Fees');
    setFlash('success', 'Fee record deleted. Backup audit trail preserved.');
    header("Location: admin_receipts.php?student_id={$student_id}");
    exit;
}

// ── LEDGER VIEW ────────────────────────────────────────────
require_once __DIR__ . '/../includes/header.php';

$student_id = (int)($_GET['student_id'] ?? 0);
if (!$student_id) {
    header('Location: admin_fees.php');
    exit;
}

// Student info
$st = $db->prepare(
    'SELECT u.full_name, u.email, s.roll_number, s.father_name,
            c.name AS class_name, c.section
     FROM users u
     JOIN students s ON s.user_id = u.id
     LEFT JOIN classes c ON c.id = s.class_id
     WHERE u.id = ? AND u.role = "student"'
);
$st->execute([$student_id]);
$student = $st->fetch();

if (!$student) {
    setFlash('error', 'Student not found.');
    header('Location: admin_fees.php');
    exit;
}

// All fee records for this student
$fees = $db->prepare(
    'SELECT f.*, admin.full_name AS collector
     FROM fees f
     LEFT JOIN users admin ON admin.id = f.collected_by
     WHERE f.student_id = ?
     ORDER BY f.id DESC'
);
$fees->execute([$student_id]);
$fees = $fees->fetchAll();

// Totals
$totals = $db->prepare(
    'SELECT SUM(final_amount) AS total, SUM(discount) AS total_discount, COUNT(*) AS count
     FROM fees WHERE student_id=?'
);
$totals->execute([$student_id]);
$totals = $totals->fetch();

renderHeader('Receipt Ledger — ' . $student['full_name'], 'fees');
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="admin_fees.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back to Fees
  </a>
  <h5 class="mb-0 fw-700">Ledger: <?= e($student['full_name']) ?></h5>
</div>

<!-- Student Summary Card -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="content-card p-4">
      <div class="d-flex align-items-center gap-3">
        <div class="table-avatar-placeholder" style="width:52px;height:52px;font-size:1.2rem">
          <?= strtoupper(substr($student['full_name'], 0, 1)) ?>
        </div>
        <div>
          <div class="fw-700"><?= e($student['full_name']) ?></div>
          <?php if ($student['father_name']): ?>
          <div class="text-muted small">Father: <?= e($student['father_name']) ?></div>
          <?php endif; ?>
          <div class="text-muted small">
            <?= e($student['class_name'] ?? '—') ?><?= $student['section'] ? ' (' . e($student['section']) . ')' : '' ?>
            · Roll: <code><?= e($student['roll_number'] ?? '—') ?></code>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-2 col-6">
    <div class="stat-card text-center">
      <div class="stat-value text-success">Rs. <?= number_format($totals['total'] ?? 0) ?></div>
      <div class="stat-label mt-1">Total Paid</div>
    </div>
  </div>
  <div class="col-md-2 col-6">
    <div class="stat-card text-center">
      <div class="stat-value text-warning">Rs. <?= number_format($totals['total_discount'] ?? 0) ?></div>
      <div class="stat-label mt-1">Discounts</div>
    </div>
  </div>
  <div class="col-md-2 col-6">
    <div class="stat-card text-center">
      <div class="stat-value"><?= (int)$totals['count'] ?></div>
      <div class="stat-label mt-1">Transactions</div>
    </div>
  </div>
</div>

<!-- Receipts Table -->
<div class="content-card">
  <div class="card-header-custom">
    <h6><i class="bi bi-receipt me-2 text-primary"></i>Payment History</h6>
    <a href="admin_fees.php?student_id=<?= $student_id ?>" class="btn btn-sm btn-primary">
      <i class="bi bi-cash-coin me-1"></i>Collect Fee
    </a>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Receipt No.</th>
          <th>Type</th>
          <th>Base Amount</th>
          <th>Discount</th>
          <th>Paid</th>
          <th>Date</th>
          <th>Collected By</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fees as $fee): ?>
        <tr>
          <td><code class="text-primary fw-600"><?= e($fee['receipt_number']) ?></code></td>
          <td>
            <span class="badge bg-<?= $fee['fee_type'] === 'Admission' ? 'primary' : 'success' ?>">
              <?= $fee['fee_type'] ?>
            </span>
          </td>
          <td class="small text-muted">Rs. <?= number_format($fee['amount'], 0) ?></td>
          <td class="small">
            <?php if ($fee['discount'] > 0): ?>
            <span class="text-warning fw-600">
              − Rs. <?= number_format($fee['discount'], 0) ?>
              <small>(<?= $fee['discount_pct'] ?>%)</small>
            </span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="fw-700 text-success">Rs. <?= number_format($fee['final_amount'], 0) ?></td>
          <td class="small text-muted"><?= date('d M Y', strtotime($fee['payment_date'])) ?></td>
          <td class="small text-muted"><?= e($fee['collector'] ?? 'Admin') ?></td>
          <td class="text-end">
            <a href="admin_receipts.php?print=<?= $fee['id'] ?>" target="_blank"
               class="btn btn-sm btn-outline-info me-1" title="Print Receipt">
              <i class="bi bi-printer"></i>
            </a>
            <a href="admin_receipts.php?delete=<?= $fee['id'] ?>&student_id=<?= $student_id ?>"
               class="btn btn-sm btn-outline-danger"
               title="Delete Record"
               onclick="return confirm('Delete this record? Audit backup is preserved.')">
              <i class="bi bi-trash"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($fees)): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">No payments recorded yet.</td>
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
