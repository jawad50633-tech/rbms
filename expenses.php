<?php
/**
 * Single-Page Expense Management System
 * Tech Stack: PHP 8+, MySQL (PDO), Bootstrap 5, Vanilla JS
 */

// ==========================================
// 1. SESSION & CSRF SETUP
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Flash messages helpers
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ==========================================
// 2. DATABASE CONFIGURATION & CONNECTION
// ==========================================
$db_host = 'sql208.infinityfree.com';
$db_user = 'if0_40816501';
$db_pass = 'lirW3R3fNvdaIU';
$db_name = 'if0_40816501_portal';
$db_charset = 'utf8mb4';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (\PDOException $e) {
    // Log error internally in production; display friendly message to user
    die('<div style="color:red; font-family:sans-serif; padding:20px; text-align:center;">
            <h2>Database Connection Failed</h2>
            <p>Please check your connection settings or ensure the MySQL server is running.</p>
         </div>');
}

// ==========================================
// 3. FORM VALIDATION & CRUD REQUEST HANDLING
// ==========================================

// Variables for edit mode
$is_edit_mode = false;
$edit_id = '';
$edit_description = '';
$edit_cost = '';

// Handle POST actions (Create, Update, Delete) - PRG Pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_error'] = "Invalid CSRF token. Request denied.";
        header("Location: index.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    // --- CREATE OR UPDATE EXPENSE ---
    if ($action === 'save_expense') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $description = trim($_POST['description'] ?? '');
        $total_cost = filter_input(INPUT_POST, 'total_cost', FILTER_VALIDATE_FLOAT);

        // Validation
        if (empty($description)) {
            $_SESSION['flash_error'] = "Expense description is required.";
        } elseif ($total_cost === false || $total_cost <= 0) {
            $_SESSION['flash_error'] = "Total cost must be a positive numeric value.";
        } else {
            if ($id) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE expenses SET description = :description, total_cost = :total_cost WHERE id = :id");
                $updated = $stmt->execute([
                    ':description' => $description,
                    ':total_cost' => $total_cost,
                    ':id' => $id
                ]);
                
                if ($updated) {
                    $_SESSION['flash_success'] = "Expense updated successfully.";
                } else {
                    $_SESSION['flash_error'] = "Failed to update expense.";
                }
            } else {
                // CREATE
                $stmt = $pdo->prepare("INSERT INTO expenses (description, total_cost) VALUES (:description, :total_cost)");
                $inserted = $stmt->execute([
                    ':description' => $description,
                    ':total_cost' => $total_cost
                ]);

                if ($inserted) {
                    $_SESSION['flash_success'] = "Expense added successfully.";
                } else {
                    $_SESSION['flash_error'] = "Failed to add expense.";
                }
            }
        }

        // Post/Redirect/Get Pattern
        header("Location: index.php");
        exit;
    }

    // --- DELETE EXPENSE ---
    if ($action === 'delete_expense') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = :id");
            if ($stmt->execute([':id' => $id])) {
                $_SESSION['flash_success'] = "Expense deleted successfully.";
            } else {
                $_SESSION['flash_error'] = "Failed to delete expense.";
            }
        } else {
            $_SESSION['flash_error'] = "Invalid expense ID.";
        }

        header("Location: index.php");
        exit;
    }
}

// Handle GET Requests (e.g., Load Edit Mode)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($edit_id) {
        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = :id");
        $stmt->execute([':id' => $edit_id]);
        $expense = $stmt->fetch();

        if ($expense) {
            $is_edit_mode = true;
            $edit_description = $expense['description'];
            $edit_cost = $expense['total_cost'];
        }
    }
}

// ==========================================
// 4. FETCH EXPENSES & CALCULATE AGGREGATES
// ==========================================
$stmt = $pdo->query("SELECT * FROM expenses ORDER BY id DESC");
$expenses = $stmt->fetchAll();

$grand_total = 0;
foreach ($expenses as $e) {
    $grand_total += $e['total_cost'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Management System</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome CDN for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-navy: #1a2a3a;
            --secondary-navy: #2c3e50;
            --accent-blue: #0d6efd;
            --bg-light: #f4f6f9;
        }

        body {
            background-color: var(--bg-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }

        .navbar-custom {
            background-color: var(--primary-navy);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .card-custom {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            background: #ffffff;
        }

        .summary-card {
            background: linear-gradient(135deg, var(--primary-navy), var(--secondary-navy));
            color: #ffffff;
        }

        .btn-primary-custom {
            background-color: var(--secondary-navy);
            border-color: var(--secondary-navy);
            color: #ffffff;
        }

        .btn-primary-custom:hover {
            background-color: var(--primary-navy);
            border-color: var(--primary-navy);
            color: #ffffff;
        }

        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }

        .table th {
            background-color: #f1f5f9;
            color: #475569;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .badge-cost {
            font-size: 1rem;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <nav class="navbar navbar-dark navbar-custom mb-4 py-3">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="fa-solid fa-wallet me-2"></i>Expense Management System
            </span>
        </div>
    </nav>

    <div class="container mb-5">

        <!-- Flash Messages -->
        <?php if ($flash_success): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($flash_success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($flash_error): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($flash_error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Dashboard Row -->
        <div class="row g-4 mb-4">
            <!-- Total Expenses Summary Card -->
            <div class="col-md-4">
                <div class="card card-custom summary-card p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <h6 class="text-white-50 text-uppercase fw-bold">Total Expenditures</h6>
                        <h2 class="display-6 fw-bold mb-0 mt-2">$<?= number_format($grand_total, 2) ?></h2>
                    </div>
                    <div class="mt-3 text-white-50 small">
                        <i class="fa-solid fa-list-check me-1"></i> Total Recorded: <?= count($expenses) ?> item(s)
                    </div>
                </div>
            </div>

            <!-- Expense Form (Create / Edit) -->
            <div class="col-md-8">
                <div class="card card-custom p-4">
                    <h5 class="fw-bold mb-3 text-secondary">
                        <i class="fa-solid <?= $is_edit_mode ? 'fa-pen-to-square' : 'fa-circle-plus' ?> me-2"></i>
                        <?= $is_edit_mode ? 'Edit Expense Record' : 'Add New Expense' ?>
                    </h5>
                    
                    <form action="index.php" method="POST" id="expenseForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="save_expense">
                        <?php if ($is_edit_mode): ?>
                            <input type="hidden" name="id" value="<?= htmlspecialchars($edit_id) ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-7">
                                <label for="description" class="form-label fw-semibold">Expense Description</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="description" 
                                       name="description" 
                                       placeholder="e.g., Office Supplies, Hosting Fee" 
                                       value="<?= htmlspecialchars($edit_description) ?>" 
                                       required>
                            </div>
                            <div class="col-md-5">
                                <label for="total_cost" class="form-label fw-semibold">Total Cost ($)</label>
                                <input type="number" 
                                       step="0.01" 
                                       min="0.01" 
                                       class="form-control" 
                                       id="total_cost" 
                                       name="total_cost" 
                                       placeholder="0.00" 
                                       value="<?= htmlspecialchars($edit_cost) ?>" 
                                       required>
                            </div>
                        </div>

                        <div class="mt-4 text-end">
                            <?php if ($is_edit_mode): ?>
                                <a href="index.php" class="btn btn-outline-secondary me-2">
                                    <i class="fa-solid fa-xmark me-1"></i> Cancel Edit
                                </a>
                                <button type="submit" class="btn btn-primary-custom">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Update Expense
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary-custom">
                                    <i class="fa-solid fa-plus me-1"></i> Save Expense
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Expense Table Section -->
        <div class="card card-custom p-4">
            <h5 class="fw-bold mb-3 text-secondary">
                <i class="fa-solid fa-receipt me-2"></i>Expense Records
            </h5>

            <?php if (empty($expenses)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-folder-open fa-3x mb-3 text-black-50"></i>
                    <p class="mb-0">No expenses recorded yet. Use the form above to add your first expense.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 10%;">ID</th>
                                <th style="width: 50%;">Description</th>
                                <th style="width: 20%;">Total Cost</th>
                                <th style="width: 20%;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                                <tr>
                                    <td class="text-secondary fw-semibold">#<?= htmlspecialchars($expense['id']) ?></td>
                                    <td class="fw-medium text-dark"><?= htmlspecialchars($expense['description']) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1">
                                            $<?= number_format($expense['total_cost'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <!-- Edit Action -->
                                        <a href="index.php?edit=<?= htmlspecialchars($expense['id']) ?>" 
                                           class="btn btn-sm btn-outline-primary me-1" 
                                           title="Edit Expense">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </a>

                                        <!-- Delete Action Form -->
                                        <form action="index.php" method="POST" class="d-inline delete-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete_expense">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($expense['id']) ?>">
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger btn-delete" 
                                                    title="Delete Expense">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="2" class="text-end">Grand Total:</td>
                                <td colspan="2" class="text-start text-primary">
                                    $<?= number_format($grand_total, 2) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom JavaScript Interactions -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Confirm before deleting
            const deleteButtons = document.querySelectorAll('.btn-delete');
            deleteButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    if (confirm('Are you sure you want to delete this expense entry? This action cannot be undone.')) {
                        e.target.closest('.delete-form').submit();
                    }
                });
            });

            // Client-side quick form validation check
            const form = document.getElementById('expenseForm');
            if (form) {
                form.addEventListener('submit', (e) => {
                    const descInput = document.getElementById('description');
                    const costInput = document.getElementById('total_cost');

                    if (!descInput.value.trim()) {
                        alert('Please fill out the expense description.');
                        e.preventDefault();
                        return;
                    }

                    if (parseFloat(costInput.value) <= 0 || isNaN(costInput.value)) {
                        alert('Please enter a valid positive cost amount.');
                        e.preventDefault();
                        return;
                    }
                });
            }
        });
    </script>
</body>
</html>
