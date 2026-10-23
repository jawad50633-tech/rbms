<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';

// Enforce Super Admin Role
requireLogin([ROLE_SUPER_ADMIN]);

$db   = getDB();
$user = currentUser();

$current_month = date('m');
$current_year  = date('Y');
$message       = '';
$msgType       = 'info';

// ── POST: Handle Create, Update, and Delete ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_expense') {
        $id          = (int)($_POST['id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $total_cost  = (float)($_POST['total_cost'] ?? 0);

        if (empty($description)) {
            $message = 'Expense description is required.';
            $msgType = 'danger';
        } elseif ($total_cost <= 0) {
            $message = 'Total cost must be a positive numeric value.';
            $msgType = 'danger';
        } else {
            if ($id > 0) {
                // UPDATE
                $stmt = $db->prepare('UPDATE expenses SET description = ?, total_cost = ? WHERE id = ?');
                $stmt->execute([$description, $total_cost, $id]);
                logActivity($user['id'], "Updated expense #{$id} - Rs. " . number_format($total_cost), 'Expenses');
                
                $message = 'Expense updated successfully.';
                $msgType = 'success';
            } else {
                // CREATE
                $stmt = $db->prepare('INSERT INTO expenses (description, total_cost) VALUES (?, ?)');
                $stmt->execute([$description, $total_cost]);
                logActivity($user['id'], "Added expense: {$description} - Rs. " . number_format($total_cost), 'Expenses');

                $message = 'Expense added successfully.';
                $msgType = 'success';
            }
        }
    } elseif ($action === 'delete_expense') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('DELETE FROM expenses WHERE id = ?');
            $stmt->execute([$id]);
            logActivity($user['id'], "Deleted expense record #{$id}", 'Expenses');

            $message = 'Expense deleted successfully.';
            $msgType = 'success';
        } else {
            $message = 'Invalid expense ID.';
            $msgType = 'danger';
        }
    }
}

// ── GET: Check if Edit Mode ────────────────────────────────
$edit_expense = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([$edit_id]);
    $edit_expense = $stmt->fetch();
}

// ── Fetch Expenses & Summaries ─────────────────────────────
$expenses = $db->query('SELECT * FROM expenses ORDER BY id DESC')->fetchAll();

$total_expenses = 0;
foreach ($expenses as $e) {
    $total_expenses += (float)$e['total_cost'];
}

$month_expenses_stmt = $db->prepare(
    'SELECT SUM(total_cost) FROM expenses WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?'
);
$month_expenses_stmt->execute([$current_month, $current_year]);
$month_expenses = (float)$month_expenses_stmt->fetchColumn();

$csrf = csrfToken();
renderHeader('Expense Management', 'expenses');
?>

<!-- ── Summary Cards ── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-6">
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#fee2e2;color:#ef4444">
        <i class="bi bi-wallet2"></i>
      </div>
      <div class="stat-value" style="font-size:1.4rem">Rs. <?= number_format($month_expenses) ?></div>
      <div class="stat-label mt-1"><?= date('F') ?> Expenses</div>
    </div>
  </div>
  <div class="col-6 col-md-6">
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#dbeafe;color:#3b82f6">
        <i class="bi bi-cash-stack"></i>
      </div>
      <div class="stat-value" style="font-size:1.4rem">Rs. <?= number_format($total_expenses) ?></div>
      <div class="stat-label mt-1">Total Expenses (All-Time)</div>
    </div>
  </div>
</div>

<!-- Flash Message -->
<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
  <?= $message ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Add / Edit Expense Form Card -->
<div class="content-card mb-4">
  <div class="card-body-custom py-3">
    <h6 class="mb-3 fw-700">
      <i class="bi <?= $edit_expense ? 'bi-pencil-square text-warning' : 'bi-plus-circle text-primary' ?> me-2"></i>
      <?= $edit_expense ? 'Edit Expense Record #' . $edit_expense['id'] : 'Add New Expense' ?>
    </h6>

    <form method="POST" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="save_expense">
      <?php if ($edit_expense): ?>
        <input type="hidden" name="id" value="<?= $edit_expense['id'] ?>">
      <?php endif; ?>

      <div class="col-md-7">
        <label class="form-label small fw-600">Description</label>
        <input type="text" class="form-control form-control-sm" name="description" 
               placeholder="e.g., Electricity Bill, Office Supplies" 
               value="<?= e($edit_expense['description'] ?? '') ?>" required>
      </div>

      <div class="col-md-3">
        <label class="form-label small fw-600">Total Cost (Rs.)</label>
        <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" name="total_cost" 
               placeholder="0.00" 
               value="<?= e($edit_expense['total_cost'] ?? '') ?>" required>
      </div>

      <div class="col-md-2 d-flex align-items-end gap-2">
        <?php if ($edit_expense): ?>
          <button type="submit" class="btn btn-warning btn-sm w-100 fw-600">Update</button>
          <a href="expenses.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
        <?php else: ?>
          <button type="submit" class="btn btn-primary btn-sm w-100 fw-600">Save</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Expenses Table -->
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom mb-0">
      <thead>
        <tr>
          <th class="ps-4">ID</th>
          <th>Description</th>
          <th>Total Cost</th>
          <th>Date Recorded</th>
          <th class="text-end pe-4">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($expenses as $e): ?>
        <tr>
          <td class="ps-4 fw-600">#<?= $e['id'] ?></td>
          <td><?= e($e['description']) ?></td>
          <td class="fw-600 text-danger">Rs. <?= number_format($e['total_cost'], 2) ?></td>
          <td class="small text-muted"><?= $e['created_at'] ?? '—' ?></td>
          <td class="text-end pe-4">
            <!-- Edit Button -->
            <a href="expenses.php?edit=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
              <i class="bi bi-pencil"></i>
            </a>
            <!-- Delete Form -->
            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this expense?');">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_expense">
              <input type="hidden" name="id" value="<?= $e['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($expenses)): ?>
        <tr>
          <td colspan="5" class="text-center text-muted py-5">
            No expenses recorded yet. Use the form above to log your first entry.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
renderFooter();
?>
