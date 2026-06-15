<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin([ROLE_SUPER_ADMIN]);

$db   = getDB();
$user = currentUser();

$current_month = date('m');
$current_year  = date('Y');
$message       = '';
$msgType       = 'info';

// ── POST: Collect Fee ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_fee'])) {
    verifyCsrf();

    $student_id = (int)($_POST['student_id'] ?? 0);
    $fee_type   = $_POST['fee_type'] ?? '';

    if (!in_array($fee_type, ['Admission', 'Monthly'])) {
        $message = 'Invalid fee type.';
        $msgType = 'danger';
    } else {
        // Get student + class fee structure
        $st = $db->prepare(
            'SELECT u.full_name, s.father_name, c.monthly_fee, c.admission_fee, c.name AS class_name
             FROM users u
             JOIN students s ON s.user_id = u.id
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE u.id = ? AND u.role = "student"'
        );
        $st->execute([$student_id]);
        $student = $st->fetch();

        if (!$student) {
            $message = 'Student not found.';
            $msgType = 'danger';
        } else {
            // Base amount from class fee structure
            $base_amount = ($fee_type === 'Admission')
                ? (float)($student['admission_fee'] ?? 800)
                : (float)($student['monthly_fee']   ?? 3000);

            // Discount
            $discount_key     = ($fee_type === 'Admission') ? 'discount_option_adm' : 'discount_option_mon';
            $discount_percent = (int)($_POST[$discount_key] ?? 0);
            $allowed_discounts = ($fee_type === 'Admission') ? [0, 100] : [0, 20, 100];
            if (!in_array($discount_percent, $allowed_discounts)) $discount_percent = 0;

            $discount      = ($base_amount * $discount_percent) / 100;
            $final_amount  = $base_amount - $discount;

            // Duplicate check
            if ($fee_type === 'Admission') {
                $check = $db->prepare('SELECT COUNT(*) FROM fees WHERE student_id=? AND fee_type="Admission"');
                $check->execute([$student_id]);
            } else {
                $check = $db->prepare(
                    'SELECT COUNT(*) FROM fees
                     WHERE student_id=? AND fee_type="Monthly"
                     AND MONTH(payment_date)=? AND YEAR(payment_date)=?'
                );
                $check->execute([$student_id, $current_month, $current_year]);
            }

            if ($check->fetchColumn() > 0) {
                $message = '⚠️ This fee has already been settled for this student.';
                $msgType = 'warning';
            } else {
                $receipt_number = 'REC-' . strtoupper(substr(md5(microtime()), 0, 6)) . rand(10, 99);
                $payment_date   = date('Y-m-d');

                // Insert into fees
                $db->prepare(
                    'INSERT INTO fees
                     (student_id, fee_type, amount, discount, discount_pct, final_amount, status, payment_date, receipt_number, collected_by)
                     VALUES (?, ?, ?, ?, ?, ?, "Paid", ?, ?, ?)'
                )->execute([
                    $student_id, $fee_type, $base_amount, $discount,
                    $discount_percent, $final_amount, $payment_date,
                    $receipt_number, $user['id']
                ]);

                // Insert into audit backup
                $db->prepare(
                    'INSERT INTO fees_backup
                     (student_id, fee_type, amount, discount, final_amount, status, payment_date, receipt_number, collected_by)
                     VALUES (?, ?, ?, ?, ?, "Paid", ?, ?, ?)'
                )->execute([
                    $student_id, $fee_type, $base_amount, $discount,
                    $final_amount, $payment_date, $receipt_number, $user['id']
                ]);

                logActivity($user['id'], "Collected {$fee_type} fee Rs.{$final_amount} from student #{$student_id}", 'Fees');

                $message = "✅ Payment of <strong>Rs. " . number_format($final_amount, 0) . "</strong> recorded for <strong>" . htmlspecialchars($student['full_name']) . "</strong>. Receipt: <strong>{$receipt_number}</strong>";
                $msgType = 'success';
            }
        }
    }
}

// ── Fetch all students with fee status ─────────────────────
$search = trim($_GET['search'] ?? '');
$where  = 'WHERE u.role = "student" AND u.status = \'active\'';
$params = [];
if ($search) {
    $where  .= ' AND (u.full_name LIKE ? OR s.roll_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$students_stmt = $db->prepare(
    "SELECT u.id, u.full_name,
            s.roll_number, s.father_name,
            c.name AS class_name, c.section, c.monthly_fee, c.admission_fee,
            (SELECT COUNT(*) FROM fees
             WHERE student_id=u.id AND fee_type='Admission') AS has_admission,
            (SELECT COUNT(*) FROM fees
             WHERE student_id=u.id AND fee_type='Monthly'
             AND MONTH(payment_date)=? AND YEAR(payment_date)=?) AS paid_this_month,
            (SELECT SUM(final_amount) FROM fees WHERE student_id=u.id) AS total_paid
     FROM users u
     JOIN students s ON s.user_id = u.id
     LEFT JOIN classes c ON c.id = s.class_id
     $where
     ORDER BY u.full_name ASC"
);
$students_stmt->execute(array_merge([$current_month, $current_year], $params));
$students = $students_stmt->fetchAll();

// ── Summary stats ──────────────────────────────────────────
$stats = $db->query(
    "SELECT
       SUM(final_amount) AS total_collected,
       SUM(CASE WHEN fee_type='Admission' THEN final_amount ELSE 0 END) AS total_admission,
       SUM(CASE WHEN fee_type='Monthly'   THEN final_amount ELSE 0 END) AS total_monthly,
       SUM(discount) AS total_discount,
       COUNT(*) AS total_transactions
     FROM fees_backup"
)->fetch();

$month_collected = $db->prepare(
    'SELECT SUM(final_amount) FROM fees
     WHERE MONTH(payment_date)=? AND YEAR(payment_date)=?'
);
$month_collected->execute([$current_month, $current_year]);
$month_collected = (float)$month_collected->fetchColumn();

$csrf = csrfToken();
renderHeader('Fees Manager', 'fees');
?>

<!-- ── Summary Stats ── -->
<div class="row g-3 mb-4">
  <?php $cards = [
    ['label' => date('F') . ' Collection', 'value' => 'Rs. ' . number_format($month_collected), 'icon' => 'calendar-check-fill', 'color' => '3b82f6', 'bg' => 'dbeafe'],
    ['label' => 'Total Collected',          'value' => 'Rs. ' . number_format($stats['total_collected'] ?? 0), 'icon' => 'cash-coin', 'color' => '10b981', 'bg' => 'd1fae5'],
    ['label' => 'Total Discounts Given',    'value' => 'Rs. ' . number_format($stats['total_discount'] ?? 0),  'icon' => 'tag-fill',  'color' => 'f59e0b', 'bg' => 'fef3c7'],
    ['label' => 'Total Transactions',       'value' => number_format($stats['total_transactions'] ?? 0),        'icon' => 'receipt',   'color' => '8b5cf6', 'bg' => 'ede9fe'],
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

<!-- Flash Message -->
<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
  <?= $message ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Header + Search -->
<div class="content-card mb-3">
  <div class="card-body-custom py-3">
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
      <div>
        <h6 class="mb-0 fw-700">
          <i class="bi bi-safe2-fill me-2 text-primary"></i>
          Fees Manager — <span class="text-muted fw-400"><?= date('F Y') ?></span>
        </h6>
        <small class="text-muted" data-students><?= count($students) ?> students</small>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <form method="GET" class="d-flex gap-2">
          <input type="text" class="form-control form-control-sm" name="search"
                 placeholder="Search student or roll no…" value="<?= e($search) ?>">
          <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
          <?php if ($search): ?>
          <a href="admin_fees.php" class="btn btn-outline-secondary btn-sm">Clear</a>
          <?php endif; ?>
        </form>
        <a href="admin_fees_audit.php" class="btn btn-sm btn-outline-warning">
          <i class="bi bi-shield-lock me-1"></i>Audit
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Students Fees Table -->
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th class="ps-4">Student</th>
          <th>Class</th>
          <th>Admission Fee</th>
          <th>Monthly Fee (<?= date('M') ?>)</th>
          <th>Total Paid</th>
          <th class="text-end pe-4">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
        <tr data-students>
          <td class="ps-4">
            <div class="d-flex align-items-center gap-2">
              <div class="table-avatar-placeholder">
                <?= strtoupper(substr($s['full_name'], 0, 1)) ?>
              </div>
              <div>
                <div class="fw-600 small"><?= e($s['full_name']) ?></div>
                <div class="text-muted" style="font-size:.72rem">
                  <?= $s['father_name'] ? 'F: ' . e($s['father_name']) . ' · ' : '' ?>
                  <code><?= e($s['roll_number'] ?? '—') ?></code>
                </div>
              </div>
            </div>
          </td>

          <td class="small">
            <?= $s['class_name'] ? e($s['class_name']) . ($s['section'] ? ' (' . e($s['section']) . ')' : '') : '<span class="text-muted">—</span>' ?>
          </td>

          <!-- Admission Fee Status -->
          <td>
            <?php if ($s['has_admission'] > 0): ?>
              <span class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded-2 small fw-700"
                    style="background:rgba(16,185,129,.1);color:#065f46;border:1px solid #065f46">
                <i class="bi bi-check-circle-fill"></i> PAID
              </span>
            <?php else: ?>
              <span class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded-2 small fw-700"
                    style="background:rgba(239,68,68,.1);color:#991b1b;border:1px solid #991b1b">
                <i class="bi bi-x-circle-fill"></i> DUE
                <small class="fw-400">Rs.<?= number_format($s['admission_fee'] ?? 800) ?></small>
              </span>
            <?php endif; ?>
          </td>

          <!-- Monthly Fee Status -->
          <td>
            <?php if ($s['paid_this_month'] > 0): ?>
              <span class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded-2 small fw-700"
                    style="background:rgba(16,185,129,.1);color:#065f46;border:1px solid #065f46">
                <i class="bi bi-check-circle-fill"></i> PAID
              </span>
            <?php else: ?>
              <span class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded-2 small fw-700"
                    style="background:rgba(239,68,68,.1);color:#991b1b;border:1px solid #991b1b">
                <i class="bi bi-clock-fill"></i> DUE
                <small class="fw-400">Rs.<?= number_format($s['monthly_fee'] ?? 3000) ?></small>
              </span>
            <?php endif; ?>
          </td>

          <td class="small fw-600 text-success">
            Rs. <?= number_format($s['total_paid'] ?? 0) ?>
          </td>

          <td class="text-end pe-4">
            <!-- Collect Button -->
            <button class="btn btn-sm btn-primary me-1 btn-collect"
                    data-id="<?= $s['id'] ?>"
                    data-name="<?= e($s['full_name']) ?>"
                    data-has-admission="<?= (int)$s['has_admission'] ?>"
                    data-paid-month="<?= (int)$s['paid_this_month'] ?>"
                    data-adm-fee="<?= (float)($s['admission_fee'] ?? 800) ?>"
                    data-mon-fee="<?= (float)($s['monthly_fee'] ?? 3000) ?>">
              <i class="bi bi-cash-coin me-1"></i>Collect
            </button>
            <!-- Ledger / Receipt history -->
            <a href="admin_receipts.php?student_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-receipt"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
        <tr>
          <td colspan="6" class="text-center text-muted py-5">
            No students found. <a href="admin_students.php?action=add">Enroll a student</a>.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ════ Payment Collection Modal ════ -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header border-0 pb-0">
        <div>
          <h5 class="modal-title fw-700" id="modalStudentName">Student Name</h5>
          <small class="text-muted">Select fee type to collect</small>
        </div>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body pt-3">

        <div class="row g-3">
          <!-- Admission Fee Card -->
          <div class="col-6">
            <form method="POST" id="formAdmission">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="pay_fee">
              <input type="hidden" name="student_id" id="admStudentId">
              <input type="hidden" name="fee_type" value="Admission">

              <label class="form-label small fw-600">Discount</label>
              <select name="discount_option_adm" class="form-select form-select-sm mb-2">
                <option value="0">No Discount</option>
                <option value="100">Full Scholarship (100%)</option>
              </select>

              <div id="cardAdm"
                   class="border rounded-3 p-3 text-center"
                   style="cursor:pointer;transition:.2s"
                   onclick="submitFee('formAdmission','cardAdm')">
                <i class="bi bi-mortarboard-fill text-primary" style="font-size:1.8rem"></i>
                <div class="fw-700 mt-2 small" id="textAdm">Admission Fee</div>
                <div class="text-muted small" id="admAmount"></div>
              </div>
            </form>
          </div>

          <!-- Monthly Fee Card -->
          <div class="col-6">
            <form method="POST" id="formMonthly">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="pay_fee">
              <input type="hidden" name="student_id" id="monStudentId">
              <input type="hidden" name="fee_type" value="Monthly">

              <label class="form-label small fw-600">Discount</label>
              <select name="discount_option_mon" class="form-select form-select-sm mb-2">
                <option value="0">No Discount</option>
                <option value="20">20% Discount</option>
                <option value="100">Full Scholarship (100%)</option>
              </select>

              <div id="cardMon"
                   class="border rounded-3 p-3 text-center"
                   style="cursor:pointer;transition:.2s"
                   onclick="submitFee('formMonthly','cardMon')">
                <i class="bi bi-calendar-check-fill text-success" style="font-size:1.8rem"></i>
                <div class="fw-700 mt-2 small" id="textMon">Monthly Fee</div>
                <div class="text-muted small" id="monAmount"></div>
              </div>
            </form>
          </div>
        </div>

        <div class="text-center mt-3">
          <button class="btn btn-link text-muted text-decoration-none small" data-bs-dismiss="modal">
            Cancel
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Wire up Collect buttons via event delegation
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.btn-collect').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openPayModal({
        id:            this.dataset.id,
        name:          this.dataset.name,
        has_admission: parseInt(this.dataset.hasAdmission),
        paid_month:    parseInt(this.dataset.paidMonth),
        adm_fee:       parseFloat(this.dataset.admFee),
        mon_fee:       parseFloat(this.dataset.monFee)
      });
    });
  });
});

function openPayModal(s) {
  // Student name
  document.getElementById('modalStudentName').textContent = s.name;

  // Student IDs
  document.getElementById('admStudentId').value = s.id;
  document.getElementById('monStudentId').value = s.id;

  // Amounts
  document.getElementById('admAmount').textContent = 'Rs. ' + s.adm_fee.toLocaleString();
  document.getElementById('monAmount').textContent = 'Rs. ' + s.mon_fee.toLocaleString();

  const cardAdm = document.getElementById('cardAdm');
  const cardMon = document.getElementById('cardMon');
  const textAdm = document.getElementById('textAdm');
  const textMon = document.getElementById('textMon');

  // Admission state
  if (s.has_admission > 0) {
    cardAdm.style.opacity = '.4';
    cardAdm.style.cursor  = 'not-allowed';
    cardAdm.style.background = '#f8f9fa';
    textAdm.innerHTML = '<i class="bi bi-check2-circle text-success me-1"></i>Already Settled';
    cardAdm._disabled = true;
  } else {
    cardAdm.style.opacity = '1';
    cardAdm.style.cursor  = 'pointer';
    cardAdm.style.background = '';
    textAdm.textContent = 'Admission Fee';
    cardAdm._disabled = false;
  }

  // Monthly state
  if (s.paid_month > 0) {
    cardMon.style.opacity = '.4';
    cardMon.style.cursor  = 'not-allowed';
    cardMon.style.background = '#f8f9fa';
    textMon.innerHTML = '<i class="bi bi-check2-circle text-success me-1"></i>Paid This Month';
    cardMon._disabled = true;
  } else {
    cardMon.style.opacity = '1';
    cardMon.style.cursor  = 'pointer';
    cardMon.style.background = '';
    textMon.textContent = 'Monthly Fee';
    cardMon._disabled = false;
  }

  new bootstrap.Modal(document.getElementById('payModal')).show();
}

function submitFee(formId, cardId) {
  const card = document.getElementById(cardId);
  if (card._disabled) return;

  if (confirm('Confirm collection of this payment?')) {
    document.getElementById(formId).submit();
  }
}

// Highlight card on hover
['cardAdm','cardMon'].forEach(id => {
  const card = document.getElementById(id);
  card.addEventListener('mouseenter', () => { if (!card._disabled) card.style.borderColor = '#3b82f6'; });
  card.addEventListener('mouseleave', () => { card.style.borderColor = ''; });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
