<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin([ROLE_ADMIN, ROLE_SUPER_ADMIN]);
$db = getDB();

$errors      = [];
$editStudent = null;
$showForm    = isset($_GET['action']) && $_GET['action'] === 'add';

// ── Classes dropdown ───────────────────────────────────────
$classes = $db->query('SELECT * FROM classes ORDER BY name, section')->fetchAll();

// ── Handle POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── SAVE ──────────────────────────────────────────────
    if ($action === 'save') {
        $userId     = (int)($_POST['user_id']    ?? 0);
        $studId     = (int)($_POST['student_id'] ?? 0);
        $fullName   = trim($_POST['full_name']   ?? '');
        $fatherName = trim($_POST['father_name'] ?? '');
        $classId    = (int)($_POST['class_id']   ?? 0) ?: null;
        $age           = (int)($_POST['age']           ?? 0);
        $qualification = trim($_POST['qualification']  ?? '');
        $institute     = trim($_POST['institute']      ?? '');
        $phone      = trim($_POST['phone']       ?? '');
        $address    = trim($_POST['address']     ?? '');

        // Validation — no email/password/username here
        if (!$fullName) $errors[] = 'Student full name is required.';
        if (!$classId)  $errors[] = 'You must assign the student to a class.';

        // Photo upload
        $photoFileName = null;
        if (!empty($_FILES['photo']['name'])) {
            $photo = $_FILES['photo'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($photo['tmp_name']);
            $ext   = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'])) {
                $errors[] = 'Photo must be JPG, PNG, or WebP.';
            } elseif ($photo['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Photo max size is 2 MB.';
            } else {
                $photoFileName = 'photo_' . uniqid() . '.' . $ext;
                if (!move_uploaded_file($photo['tmp_name'], UPLOAD_PHOTOS . $photoFileName)) {
                    $errors[] = 'Photo upload failed. Check folder permissions.';
                    $photoFileName = null;
                }
            }
        }

        if (empty($errors)) {
            if ($userId) {
                // ── UPDATE existing student info (no login fields touched) ──
                $db->prepare('UPDATE users SET full_name=? WHERE id=?')
                   ->execute([$fullName, $userId]);

                $photoSql = $photoFileName ? ', photo=?' : '';
                $params   = [$fatherName, $classId, $age ?: null, $qualification, $institute, $phone, $address];
                if ($photoFileName) $params[] = $photoFileName;
                $params[] = $studId;
                $db->prepare("UPDATE students SET father_name=?, class_id=?, age=?, qualification=?, institute=?, phone=?, address=?{$photoSql} WHERE id=?")
                   ->execute($params);

                logActivity(currentUser()['id'], "Updated student #{$userId}: {$fullName}", 'Students');
                setFlash('success', 'Student record updated successfully.');

            } else {
                // ── CREATE new student record ──────────────────────────────
                // Admin creates the record only — Super Admin must create the login account
                // in User Management for this student to be able to log in.
                // We create a placeholder user with no usable password and a system username.
                $placeholder_username = 'student_' . uniqid();
                $placeholder_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

         $newUserId = 0;

try {
    $placeholder_email = 'student_' . uniqid() . '@placeholder.local'; // ← unique placeholder
    $db->prepare(
        'INSERT INTO users (full_name, username, password, role, status, email) VALUES (?, ?, ?, "student", "inactive", ?)'
    )->execute([$fullName, $placeholder_username, $placeholder_password, $placeholder_email]);
    $newUserId = (int)$db->lastInsertId();
} catch (PDOException $e) {
    $errors[] = 'Failed to create user record: ' . $e->getMessage();
}

if (empty($errors) && $newUserId > 0) {
    // verify user actually exists before inserting student
    $check = $db->prepare('SELECT id FROM users WHERE id = ?');
    $check->execute([$newUserId]);
    if (!$check->fetch()) {
        $errors[] = 'User record was not saved correctly. Please try again.';
    }
}

if (empty($errors) && $newUserId > 0) {
    $roll = 'STU-' . date('Y') . '-' . str_pad($newUserId, 4, '0', STR_PAD_LEFT);

    $db->prepare(
        'INSERT INTO students (user_id, father_name, roll_number, class_id, age, qualification, institute, phone, address, photo, enrolled_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
    )->execute([$newUserId, $fatherName, $roll, $classId, $age ?: null, $qualification, $institute, $phone, $address, $photoFileName]);

    logActivity(currentUser()['id'], "Enrolled student record: {$fullName} → {$roll}", 'Students');
    setFlash('success',
        "Student enrolled. Roll Number: <strong>{$roll}</strong>. " .
        "To activate portal login, ask the <strong>Super Admin</strong> to set up login credentials in User Management."
    );

    header('Location: admin_students.php');
    exit;
}
                $roll = 'STU-' . date('Y') . '-' . str_pad($newUserId, 4, '0', STR_PAD_LEFT);

                $db->prepare(
                    'INSERT INTO students (user_id, father_name, roll_number, class_id, age, qualification, institute, phone, address, photo, enrolled_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
                )->execute([$newUserId, $fatherName, $roll, $classId, $age ?: null, $qualification, $institute, $phone, $address, $photoFileName]);

                logActivity(currentUser()['id'], "Enrolled student record: {$fullName} → {$roll}", 'Students');
                setFlash('success',
                    "Student enrolled. Roll Number: <strong>{$roll}</strong>. " .
                    "To activate portal login, ask the <strong>Super Admin</strong> to set up login credentials in User Management."
                );
            }
            header('Location: admin_students.php');
            exit;
        }
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $row = $db->prepare('SELECT photo FROM students WHERE user_id=?');
        $row->execute([$uid]);
        $ph = $row->fetchColumn();
        if ($ph && file_exists(UPLOAD_PHOTOS . $ph)) unlink(UPLOAD_PHOTOS . $ph);
        $db->prepare('DELETE FROM users WHERE id=? AND role="student"')->execute([$uid]);
        logActivity(currentUser()['id'], "Deleted student #{$uid}", 'Students');
        setFlash('success', 'Student deleted.');
        header('Location: admin_students.php');
        exit;
    }
}

// ── Load for editing ───────────────────────────────────────
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st  = $db->prepare(
        'SELECT u.id, u.full_name, u.status,
                s.id AS student_id, s.roll_number, s.father_name,
                s.class_id, s.age, s.qualification, s.institute, s.phone, s.address, s.photo
         FROM users u
         JOIN students s ON s.user_id = u.id
         WHERE u.id=? AND u.role="student"'
    );
    $st->execute([$eid]);
    $editStudent = $st->fetch();
    $showForm    = (bool)$editStudent;
}

// ── Student list ───────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$where  = "WHERE u.role='student' AND u.status='active'";
$params = [];
if ($search) {
    $where   .= ' AND (u.full_name LIKE ? OR s.father_name LIKE ? OR s.roll_number LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$students = $db->prepare(
    "SELECT u.id, u.full_name, u.status, u.created_at,
            s.id AS student_id, s.roll_number, s.father_name, s.photo, s.class_id,
            c.name AS class_name, c.section
     FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     LEFT JOIN classes  c ON c.id = s.class_id
     $where
     ORDER BY u.created_at DESC"
);
$students->execute($params);
$students = $students->fetchAll();

$csrf = csrfToken();
renderHeader(
    $showForm ? ($editStudent ? 'Edit Student' : 'Enroll Student') : 'Student Management',
    'students'
);
?>

<?php if ($showForm): ?>
<!-- ══════════ FORM ══════════ -->
<div class="d-flex align-items-center gap-3 mb-4">
  <a href="admin_students.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
  <h5 class="mb-0 fw-700"><?= $editStudent ? 'Edit Student Record' : 'Enroll New Student' ?></h5>
</div>

<?php if (!$editStudent): ?>
<!-- Info notice for new enrollment -->
<div class="alert alert-info d-flex gap-2 align-items-start mb-4">
  <i class="bi bi-info-circle-fill fs-5 mt-1 flex-shrink-0"></i>
  <div>
    <strong>About Portal Login:</strong> You are enrolling a student record.
    To give this student login access to the portal, the <strong>Super Admin</strong>
    must set up their username and password in <em>User Management</em>.
    The student's account will be <strong>inactive</strong> until that is done.
  </div>
</div>
<?php endif; ?>

<?php if ($editStudent && $editStudent['status'] === 'inactive'): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start mb-4">
  <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
  <div>
    <strong>Portal login not yet activated.</strong>
    Ask the Super Admin to set a username and password for this student in User Management.
  </div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $err): ?>
  <div><i class="bi bi-exclamation-circle me-1"></i><?= e($err) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
  <input type="hidden" name="action"      value="save">
  <input type="hidden" name="user_id"     value="<?= $editStudent['id']         ?? 0 ?>">
  <input type="hidden" name="student_id"  value="<?= $editStudent['student_id'] ?? 0 ?>">

  <div class="row g-3">

    <!-- Left column -->
    <div class="col-lg-8">
      <div class="content-card mb-3">
        <div class="card-header-custom">
          <h6><i class="bi bi-person-fill me-2"></i>Personal Information</h6>
          <?php if ($editStudent): ?>
          <code class="small text-muted"><?= e($editStudent['roll_number'] ?? '') ?></code>
          <?php endif; ?>
        </div>
        <div class="card-body-custom">
          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label">Student Full Name *</label>
              <input type="text" class="form-control" name="full_name"
                     value="<?= e($editStudent['full_name'] ?? $_POST['full_name'] ?? '') ?>"
                     placeholder="e.g. Ali Hassan" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Father's Name</label>
              <input type="text" class="form-control" name="father_name"
                     value="<?= e($editStudent['father_name'] ?? $_POST['father_name'] ?? '') ?>"
                     placeholder="e.g. Muhammad Hassan">
            </div>

            <div class="col-md-4">
              <label class="form-label">Age</label>
              <input type="number" class="form-control" name="age" min="1" max="100"
                     value="<?= e($editStudent['age'] ?? $_POST['age'] ?? '') ?>"
                     placeholder="e.g. 16">
            </div>

            <div class="col-md-4">
              <label class="form-label">Qualification</label>
              <input type="text" class="form-control" name="qualification"
                     value="<?= e($editStudent['qualification'] ?? $_POST['qualification'] ?? '') ?>"
                     placeholder="e.g. Matric, FSc">
            </div>

            <div class="col-md-4">
              <label class="form-label">Institute</label>
              <input type="text" class="form-control" name="institute"
                     value="<?= e($editStudent['institute'] ?? $_POST['institute'] ?? '') ?>"
                     placeholder="e.g. City High School">
            </div>

            <div class="col-md-4">
              <label class="form-label">Phone Number</label>
              <input type="tel" class="form-control" name="phone"
                     value="<?= e($editStudent['phone'] ?? '') ?>"
                     placeholder="0300-1234567">
            </div>

            <?php if ($editStudent): ?>
            <div class="col-md-4">
              <label class="form-label">Roll Number</label>
              <input type="text" class="form-control bg-light"
                     value="<?= e($editStudent['roll_number'] ?? '') ?>" disabled>
              <small class="text-muted">Auto-assigned at enrollment.</small>
            </div>
            <?php endif; ?>

            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea class="form-control" name="address" rows="2"
                        placeholder="Street, City"><?= e($editStudent['address'] ?? '') ?></textarea>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- Right column -->
    <div class="col-lg-4">
      <div class="content-card mb-3">
        <div class="card-header-custom">
          <h6><i class="bi bi-diagram-3 me-2"></i>Class &amp; Photo</h6>
        </div>
        <div class="card-body-custom">

          <div class="mb-3">
            <label class="form-label">Assign to Class *</label>
            <select class="form-select" name="class_id" required>
              <option value="" disabled <?= empty($editStudent['class_id']) ? 'selected' : '' ?>>
                — Select a Class —
              </option>
              <?php foreach ($classes as $cl): ?>
              <option value="<?= $cl['id'] ?>"
                <?= ($editStudent['class_id'] ?? $_POST['class_id'] ?? 0) == $cl['id'] ? 'selected' : '' ?>>
                <?= e($cl['name']) ?><?= $cl['section'] ? ' (' . e($cl['section']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">Required — student must belong to a class.</small>
          </div>

          <div>
            <label class="form-label">Student Photo</label>
            <?php if (!empty($editStudent['photo'])): ?>
            <div class="mb-2">
              <img src="<?= BASE_URL ?>/uploads/photos/<?= e($editStudent['photo']) ?>"
                   style="width:80px;height:80px;object-fit:cover;border-radius:10px;border:2px solid #e2e8f0">
            </div>
            <?php endif; ?>
            <input type="file" class="form-control" name="photo"
                   accept="image/jpeg,image/png,image/webp">
            <small class="text-muted">JPG, PNG, WebP — max 2 MB</small>
          </div>

        </div>
      </div>

      <!-- Login Status (read-only for admin) -->
      <div class="content-card mb-3">
        <div class="card-header-custom">
          <h6><i class="bi bi-shield-lock me-2"></i>Login Status</h6>
        </div>
        <div class="card-body-custom">
          <?php if ($editStudent): ?>
            <?php if ($editStudent['status'] === 'active'): ?>
            <div class="d-flex align-items-center gap-2 text-success small fw-600">
              <i class="bi bi-check-circle-fill"></i> Portal login active
            </div>
            <small class="text-muted d-block mt-1">
              Login credentials managed by Super Admin.
            </small>
            <?php else: ?>
            <div class="d-flex align-items-center gap-2 text-warning small fw-600">
              <i class="bi bi-clock-fill"></i> Awaiting login setup
            </div>
            <small class="text-muted d-block mt-1">
              Ask Super Admin to activate this student's portal login.
            </small>
            <?php endif; ?>
          <?php else: ?>
          <div class="d-flex align-items-center gap-2 text-muted small">
            <i class="bi bi-lock-fill"></i> Login credentials set by Super Admin only
          </div>
          <small class="text-muted d-block mt-1">
            After enrollment, Super Admin can activate portal login from User Management.
          </small>
          <?php endif; ?>
        </div>
      </div>

      <div class="content-card">
        <div class="card-body-custom">
          <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="bi bi-check2 me-1"></i>
            <?= $editStudent ? 'Update Student' : 'Enroll Student' ?>
          </button>
          <a href="admin_students.php" class="btn btn-outline-secondary w-100">Cancel</a>
        </div>
      </div>

    </div>
  </div>
</form>

<?php else: ?>
<!-- ══════════ LIST ══════════ -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="mb-0 fw-700">Student Management</h5>
    <small class="text-muted"><?= count($students) ?> student(s) enrolled</small>
  </div>
  <a href="admin_students.php?action=add" class="btn btn-primary">
    <i class="bi bi-person-plus-fill me-1"></i>Enroll Student
  </a>
</div>

<div class="content-card mb-3">
  <div class="card-body-custom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-6">
        <input type="text" class="form-control" name="search"
               placeholder="Search name, father name, roll number…"
               value="<?= e($search) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
      </div>
      <?php if ($search): ?>
      <div class="col-auto">
        <a href="admin_students.php" class="btn btn-outline-secondary">Clear</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Photo</th><th>Student</th><th>Father</th>
          <th>Roll No.</th><th>Class</th><th>Login</th><th>Enrolled</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
        <tr>
          <td>
            <?php if ($s['photo'] && file_exists(UPLOAD_PHOTOS . $s['photo'])): ?>
              <img src="<?= BASE_URL ?>/uploads/photos/<?= e($s['photo']) ?>" class="table-avatar">
            <?php else: ?>
              <div class="table-avatar-placeholder"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="fw-600 small"><?= e($s['full_name']) ?></div>
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
              <i class="bi bi-<?= $s['status'] === 'active' ? 'check-circle' : 'clock' ?> me-1"></i>
              <?= $s['status'] === 'active' ? 'Active' : 'Pending' ?>
            </span>
          </td>
          <td class="small text-muted"><?= formatDate($s['created_at']) ?></td>
          <td>
            <a href="admin_students.php?edit=<?= $s['id'] ?>"
               class="btn btn-sm btn-outline-primary me-1">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="POST" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action"     value="delete">
              <input type="hidden" name="user_id"    value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"
                      data-confirm="Delete <?= e($s['full_name']) ?>? This cannot be undone.">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">
            No students enrolled yet.
            <a href="admin_students.php?action=add">Enroll the first student</a>.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
