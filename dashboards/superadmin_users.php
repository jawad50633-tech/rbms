<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_SUPER_ADMIN);
$db = getDB();

$errors  = [];
$success = '';
$editUser = null;

// ── Handle POST Actions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    // ── CREATE / EDIT ──────────────────────────────────────
    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email']     ?? '');
        $username = trim($_POST['username']  ?? '');
        $role     = $_POST['role']           ?? '';
        $status   = $_POST['status']         ?? 'active';
        $password = $_POST['password']       ?? '';

        // Validation
        if (!$fullName)  $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!$username)  $errors[] = 'Username is required.';
        if (!in_array($role, ['super_admin','admin','teacher','student'])) $errors[] = 'Invalid role.';
        if (!$id && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($id && $password && strlen($password) < 8) $errors[] = 'New password must be at least 8 characters.';

        // Check unique email/username (exclude self)
        if (empty($errors)) {
            $dup = $db->prepare('SELECT id FROM users WHERE (email=? OR username=?) AND id!=?');
            $dup->execute([$email, $username, $id]);
            if ($dup->fetch()) $errors[] = 'Email or username already exists.';
        }

        if (empty($errors)) {
            if ($id) {
                // UPDATE
                if ($password) {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $st = $db->prepare('UPDATE users SET full_name=?,email=?,username=?,role=?,status=?,password=? WHERE id=?');
                    $st->execute([$fullName,$email,$username,$role,$status,$hash,$id]);
                } else {
                    $st = $db->prepare('UPDATE users SET full_name=?,email=?,username=?,role=?,status=? WHERE id=?');
                    $st->execute([$fullName,$email,$username,$role,$status,$id]);
                }
                logActivity(currentUser()['id'], "Updated user #{$id}: {$username}", 'Users');
                setFlash('success', 'User updated successfully.');
            } else {
                // CREATE
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $st = $db->prepare('INSERT INTO users (full_name,email,username,password,role,status) VALUES (?,?,?,?,?,?)');
                $st->execute([$fullName,$email,$username,$hash,$role,$status]);
                $newId = $db->lastInsertId();
                // If student, create student profile
                if ($role === 'student') {
                    $roll = 'STU-' . date('Y') . '-' . str_pad($newId, 3, '0', STR_PAD_LEFT);
                    $db->prepare('INSERT INTO students (user_id, roll_number) VALUES (?,?)')->execute([$newId,$roll]);
                }
                logActivity(currentUser()['id'], "Created user: {$username} ({$role})", 'Users');
                setFlash('success', 'User created successfully.');
            }
            header('Location: superadmin_users.php');
            exit;
        }
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === currentUser()['id']) {
            setFlash('error', 'You cannot delete your own account.');
        } else {
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            logActivity(currentUser()['id'], "Deleted user #{$id}", 'Users');
            setFlash('success', 'User deleted successfully.');
        }
        header('Location: superadmin_users.php');
        exit;
    }
}

// ── Load user for editing ──────────────────────────────────
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editUser = $db->prepare('SELECT * FROM users WHERE id=?');
    $editUser->execute([$editId]);
    $editUser = $editUser->fetch();
}

// ── Fetch all users (with search + role filter) ────────────
$search     = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role_filter'] ?? '';

$where  = 'WHERE 1=1';
$params = [];

if ($search) {
    $where   .= ' AND (full_name LIKE ? OR email LIKE ? OR username LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($roleFilter) {
    $where   .= ' AND role = ?';
    $params[] = $roleFilter;
}

$stmt = $db->prepare("SELECT * FROM users $where ORDER BY created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$csrf = csrfToken();
renderHeader('User Management', 'users');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="mb-0 fw-700">User Management</h5>
    <small class="text-muted"><?= count($users) ?> user(s) found</small>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal"
          onclick="setCreateMode()">
    <i class="bi bi-person-plus-fill me-1"></i> Add User
  </button>
</div>

<!-- Filters -->
<div class="content-card mb-3">
  <div class="card-body-custom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5">
        <input type="text" class="form-control" name="search"
               placeholder="Search name, email, username…" value="<?= e($search) ?>">
      </div>
      <div class="col-md-3">
        <select class="form-select" name="role_filter">
          <option value="">All Roles</option>
          <?php foreach (['super_admin','admin','teacher','student'] as $r): ?>
          <option value="<?= $r ?>" <?= $roleFilter===$r?'selected':'' ?>>
            <?= ucfirst(str_replace('_',' ',$r)) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
      </div>
      <?php if ($search || $roleFilter): ?>
      <div class="col-md-2">
        <a href="superadmin_users.php" class="btn btn-outline-secondary w-100">Clear</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Users Table -->
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>#</th>
          <th>User</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Joined</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $i => $u): ?>
        <?php $rBadge = ['super_admin'=>'danger','admin'=>'primary','teacher'=>'success','student'=>'warning text-dark'][$u['role']] ?? 'secondary'; ?>
        <tr>
          <td class="text-muted small"><?= $i+1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="table-avatar-placeholder"><?= strtoupper(substr($u['full_name'],0,1)) ?></div>
              <div>
                <div class="small fw-600"><?= e($u['full_name']) ?></div>
                <div class="text-muted" style="font-size:.73rem">@<?= e($u['username']) ?></div>
              </div>
            </div>
          </td>
          <td class="small"><?= e($u['email']) ?></td>
          <td><span class="badge bg-<?= $rBadge ?>"><?= ucfirst(str_replace('_',' ',$u['role'])) ?></span></td>
          <td>
            <?php if ($u['status'] === 'inactive' && $u['role'] === 'student'): ?>
            <span class="badge bg-warning text-dark" title="Enrolled but login not activated">
              <i class="bi bi-clock me-1"></i>Needs Activation
            </span>
            <?php else: ?>
            <span class="badge bg-<?= $u['status']==='active'?'success':'secondary' ?>">
              <?= ucfirst($u['status']) ?>
            </span>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= formatDate($u['created_at']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary me-1"
                    onclick='editUser(<?= json_encode($u) ?>)'
                    data-bs-toggle="modal" data-bs-target="#userModal">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($u['id'] !== currentUser()['id']): ?>
            <form method="POST" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"
                      data-confirm="Delete <?= e($u['full_name']) ?>? This cannot be undone.">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── User Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-700" id="modalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="userId" value="">

          <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?>
            <div><i class="bi bi-exclamation-circle me-1"></i><?= e($err) ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name *</label>
              <input type="text" class="form-control" name="full_name" id="fieldName"
                     value="<?= e($_POST['full_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email *</label>
              <input type="email" class="form-control" name="email" id="fieldEmail"
                     value="<?= e($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username *</label>
              <input type="text" class="form-control" name="username" id="fieldUsername"
                     value="<?= e($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Role *</label>
              <select class="form-select" name="role" id="fieldRole" required>
                <?php foreach (['super_admin','admin','teacher','student'] as $r): ?>
                <option value="<?= $r ?>"><?= ucfirst(str_replace('_',' ',$r)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select class="form-select" name="status" id="fieldStatus">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" id="passLabel">Password *</label>
              <input type="password" class="form-control" name="password" id="fieldPass"
                     placeholder="Min 8 characters">
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirm Password</label>
              <input type="password" class="form-control" name="confirm_password"
                     placeholder="Repeat password">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i> Save User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function setCreateMode() {
  document.getElementById('modalTitle').textContent = 'Add New User';
  document.getElementById('userId').value    = '';
  document.getElementById('fieldName').value = '';
  document.getElementById('fieldEmail').value= '';
  document.getElementById('fieldUsername').value = '';
  document.getElementById('fieldRole').value = 'student';
  document.getElementById('fieldStatus').value = 'active';
  document.getElementById('fieldPass').value = '';
  document.getElementById('passLabel').textContent = 'Password *';
}

function editUser(u) {
  document.getElementById('modalTitle').textContent = 'Edit User';
  document.getElementById('userId').value    = u.id;
  document.getElementById('fieldName').value = u.full_name;
  document.getElementById('fieldEmail').value= u.email;
  document.getElementById('fieldUsername').value = u.username;
  document.getElementById('fieldRole').value = u.role;
  document.getElementById('fieldStatus').value = u.status;
  document.getElementById('fieldPass').value = '';
  document.getElementById('passLabel').textContent = 'New Password (leave blank to keep)';
}

<?php if ($editUser): ?>
// Auto-open modal for edit via URL
window.addEventListener('load', function() {
  editUser(<?= json_encode($editUser) ?>);
  new bootstrap.Modal(document.getElementById('userModal')).show();
});
<?php endif; ?>
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
