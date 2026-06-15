<?php
// superadmin_classes.php — Super Admin ONLY
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_SUPER_ADMIN);
$db   = getDB();
$user = currentUser();

// ── POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Create / Edit class
    if ($action === 'save_class') {
        $id      = (int)($_POST['id']            ?? 0);
        $name    = trim($_POST['name']           ?? '');
        $section = trim($_POST['section']        ?? '');
        $monFee  = (float)($_POST['monthly_fee']   ?? 3000);
        $admFee  = (float)($_POST['admission_fee'] ?? 800);
        if (!$name) { setFlash('error', 'Class name is required.'); header('Location: superadmin_classes.php'); exit; }
        if ($id) {
            $db->prepare('UPDATE classes SET name=?, section=?, monthly_fee=?, admission_fee=? WHERE id=?')
               ->execute([$name, $section ?: null, $monFee, $admFee, $id]);
            setFlash('success', 'Class updated.');
        } else {
            $db->prepare('INSERT INTO classes (name, section, monthly_fee, admission_fee) VALUES (?, ?, ?, ?)')
               ->execute([$name, $section ?: null, $monFee, $admFee]);
            setFlash('success', 'Class created.');
        }
        header('Location: superadmin_classes.php'); exit;
    }

    // Delete class
    if ($action === 'delete_class') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM classes WHERE id=?')->execute([$id]);
        setFlash('success', 'Class deleted.');
        header('Location: superadmin_classes.php'); exit;
    }

    // Assign multiple classes to a teacher (replaces ALL previous assignments)
    if ($action === 'assign_teacher') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classIds  = array_map('intval', (array)($_POST['class_ids'] ?? []));
        $classIds  = array_filter($classIds); // remove 0s

        // Verify user is actually a teacher
        $check = $db->prepare('SELECT id FROM users WHERE id=? AND role="teacher"');
        $check->execute([$teacherId]);
        if (!$check->fetch()) {
            setFlash('error', 'Invalid teacher.'); header('Location: superadmin_classes.php'); exit;
        }

        // Remove old assignments then re-insert chosen ones
        $db->prepare('DELETE FROM teacher_classes WHERE teacher_id=?')->execute([$teacherId]);
        if ($classIds) {
            $ins = $db->prepare('INSERT IGNORE INTO teacher_classes (teacher_id, class_id) VALUES (?,?)');
            foreach ($classIds as $cid) {
                $ins->execute([$teacherId, $cid]);
            }
        }

        logActivity($user['id'],
            "Updated class assignments for teacher #{$teacherId}: " . implode(',', $classIds ?: ['none']),
            'Classes'
        );
        setFlash('success', 'Teacher class assignments updated.');
        header('Location: superadmin_classes.php'); exit;
    }
}

// ── Data ───────────────────────────────────────────────────
$classes = $db->query(
    'SELECT c.*,
            COUNT(DISTINCT s.id)  AS student_count,
            COUNT(DISTINCT tc.teacher_id) AS teacher_count
     FROM classes c
     LEFT JOIN students s        ON s.class_id = c.id
     LEFT JOIN teacher_classes tc ON tc.class_id = c.id
     GROUP BY c.id
     ORDER BY c.name, c.section'
)->fetchAll();

// All teachers with their currently assigned classes
$teachers = $db->query(
    'SELECT u.id, u.full_name, u.email
     FROM users u
     WHERE u.role = "teacher" AND u.status = "active"
     ORDER BY u.full_name'
)->fetchAll();

// Build a map: teacher_id → [class_id, ...]
$teacherClassMap = [];
$tcRows = $db->query('SELECT teacher_id, class_id FROM teacher_classes')->fetchAll();
foreach ($tcRows as $row) {
    $teacherClassMap[$row['teacher_id']][] = $row['class_id'];
}

// Class labels for display
$classLabels = [];
foreach ($classes as $c) {
    $classLabels[$c['id']] = $c['name'] . ($c['section'] ? ' (' . $c['section'] . ')' : '');
}

$editClass = null;
if (isset($_GET['edit_class'])) {
    $st = $db->prepare('SELECT * FROM classes WHERE id=?');
    $st->execute([(int)$_GET['edit_class']]);
    $editClass = $st->fetch();
}

$csrf = csrfToken();
renderHeader('Classes & Teacher Assignment', 'classes');
?>

<div class="row g-3">

  <!-- ══ LEFT: Classes ══ -->
  <div class="col-lg-7">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0 fw-700">Classes</h5>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#classModal"
              onclick="setCreate()">
        <i class="bi bi-plus-circle-fill me-1"></i>Add Class
      </button>
    </div>

    <div class="content-card">
      <div class="table-responsive">
        <table class="table table-custom">
          <thead>
            <tr><th>#</th><th>Class</th><th>Fees</th><th>Students</th><th>Teachers</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($classes as $i => $c): ?>
            <tr>
              <td class="text-muted small"><?= $i + 1 ?></td>
              <td>
                <div class="fw-600 small"><?= e($c['name']) ?></div>
                <?php if ($c['section']): ?>
                <div class="text-muted" style="font-size:.72rem">Section: <?= e($c['section']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small text-muted">
                Adm: <strong>Rs.<?= number_format($c['admission_fee']) ?></strong><br>
                Mon: <strong>Rs.<?= number_format($c['monthly_fee']) ?></strong>
              </td>
              <td><span class="badge bg-primary rounded-pill"><?= (int)$c['student_count'] ?></span></td>
              <td><span class="badge bg-success rounded-pill"><?= (int)$c['teacher_count'] ?></span></td>
              <td>
                <button class="btn btn-sm btn-outline-primary me-1"
                        data-bs-toggle="modal" data-bs-target="#classModal"
                        onclick='editCls(<?= json_encode($c) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_class">
                  <input type="hidden" name="id"     value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"
                          data-confirm="Delete class '<?= e($c['name']) ?>'? Students will be unassigned.">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($classes)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No classes yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══ RIGHT: Teacher Assignment ══ -->
  <div class="col-lg-5">
    <div class="mb-3">
      <h5 class="mb-0 fw-700">Teacher → Class Assignment</h5>
      <small class="text-muted">Each teacher can be assigned to multiple classes.</small>
    </div>

    <?php if (empty($teachers)): ?>
    <div class="content-card">
      <div class="card-body-custom text-center text-muted py-4">
        No teachers found. <a href="superadmin_users.php">Create a teacher account</a>.
      </div>
    </div>
    <?php else: ?>
    <?php foreach ($teachers as $t):
      $assigned = $teacherClassMap[$t['id']] ?? [];
    ?>
    <div class="content-card mb-3">
      <div class="card-header-custom py-2">
        <div>
          <div class="fw-600 small"><?= e($t['full_name']) ?></div>
          <div class="text-muted" style="font-size:.72rem"><?= e($t['email']) ?></div>
        </div>
        <!-- Current classes badge row -->
        <div class="d-flex flex-wrap gap-1">
          <?php if ($assigned): ?>
            <?php foreach ($assigned as $cid): ?>
            <span class="badge bg-success"><?= e($classLabels[$cid] ?? '#'.$cid) ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="badge bg-warning text-dark">Unassigned</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body-custom py-3">
        <form method="POST">
          <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
          <input type="hidden" name="action"      value="assign_teacher">
          <input type="hidden" name="teacher_id"  value="<?= $t['id'] ?>">

          <label class="form-label small text-muted mb-2">
            <i class="bi bi-check2-square me-1"></i>Select one or more classes:
          </label>
          <div class="row g-2 mb-3">
            <?php foreach ($classes as $cl): ?>
            <div class="col-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       name="class_ids[]"
                       value="<?= $cl['id'] ?>"
                       id="tc_<?= $t['id'] ?>_<?= $cl['id'] ?>"
                       <?= in_array($cl['id'], $assigned) ? 'checked' : '' ?>>
                <label class="form-check-label small"
                       for="tc_<?= $t['id'] ?>_<?= $cl['id'] ?>">
                  <?= e($cl['name']) ?><?= $cl['section'] ? ' <span class="text-muted">(' . e($cl['section']) . ')</span>' : '' ?>
                </label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-floppy me-1"></i>Save Assignments
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- Class Modal -->
<div class="modal fade" id="classModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-700" id="clsTitle">Add Class</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action"     value="save_class">
          <input type="hidden" name="id"         id="clsId" value="">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Class Name *</label>
              <input type="text" class="form-control" name="name" id="clsName"
                     placeholder="e.g. Class 10" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Section</label>
              <input type="text" class="form-control" name="section" id="clsSection"
                     placeholder="e.g. A">
            </div>
            <div class="col-md-6">
              <label class="form-label">Admission Fee (Rs.)</label>
              <input type="number" class="form-control" name="admission_fee" id="clsAdmFee"
                     value="800" min="0">
            </div>
            <div class="col-md-6">
              <label class="form-label">Monthly Fee (Rs.)</label>
              <input type="number" class="form-control" name="monthly_fee" id="clsMonFee"
                     value="3000" min="0">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Class</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function setCreate() {
  document.getElementById('clsTitle').textContent = 'Add Class';
  document.getElementById('clsId').value          = '';
  document.getElementById('clsName').value        = '';
  document.getElementById('clsSection').value     = '';
  document.getElementById('clsAdmFee').value      = '800';
  document.getElementById('clsMonFee').value      = '3000';
}
function editCls(c) {
  document.getElementById('clsTitle').textContent = 'Edit Class';
  document.getElementById('clsId').value          = c.id;
  document.getElementById('clsName').value        = c.name;
  document.getElementById('clsSection').value     = c.section || '';
  document.getElementById('clsAdmFee').value      = c.admission_fee || 800;
  document.getElementById('clsMonFee').value      = c.monthly_fee   || 3000;
}
<?php if ($editClass): ?>
window.addEventListener('load', function () {
  editCls(<?= json_encode($editClass) ?>);
  new bootstrap.Modal(document.getElementById('classModal')).show();
});
<?php endif; ?>
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
