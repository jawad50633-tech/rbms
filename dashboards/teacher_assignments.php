<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// ── Get all classes assigned to this teacher ─────────────
$myClassRows = $db->prepare(
    'SELECT c.id, c.name, c.section
     FROM teacher_classes tc
     JOIN classes c ON c.id = tc.class_id
     WHERE tc.teacher_id = ?
     ORDER BY c.name, c.section'
);
$myClassRows->execute([$user['id']]);
$myClassRows = $myClassRows->fetchAll();
$myClassIds  = array_column($myClassRows, 'id');

$errors   = [];
$editItem = null;
$showForm = isset($_GET['action']) && $_GET['action'] === 'add';

// ── POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id']           ?? 0);
        $title   = trim($_POST['title']         ?? '');
        $desc    = trim($_POST['description']   ?? '');
        $due     = $_POST['due_date']           ?? '';
        $marks   = (int)($_POST['total_marks']  ?? 100);
        $status  = $_POST['status']             ?? 'active';
        $classId = (int)($_POST['class_id']     ?? 0) ?: null;

        if (!$title)   $errors[] = 'Title is required.';
        if (!$classId) $errors[] = 'Please select a class for this assignment.';

        // Ensure teacher actually teaches this class
        if ($classId && !in_array($classId, $myClassIds)) {
            $errors[] = 'You are not assigned to that class.';
        }

        if (empty($errors)) {
            if ($id) {
                $own = $db->prepare('SELECT id FROM assignments WHERE id=? AND teacher_id=?');
                $own->execute([$id, $user['id']]);
                if (!$own->fetch()) { http_response_code(403); die('Forbidden'); }

                $db->prepare('UPDATE assignments SET title=?,description=?,class_id=?,due_date=?,total_marks=?,status=? WHERE id=?')
                   ->execute([$title, $desc, $classId, $due ?: null, $marks, $status, $id]);
                logActivity($user['id'], "Updated assignment #{$id}: {$title}", 'Assignments');
                setFlash('success', 'Assignment updated.');
            } else {
                $db->prepare('INSERT INTO assignments (teacher_id,title,description,class_id,due_date,total_marks,status) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$user['id'], $title, $desc, $classId, $due ?: null, $marks, $status]);
                logActivity($user['id'], "Created assignment: {$title}", 'Assignments');
                setFlash('success', 'Assignment created.');
            }
            header('Location: teacher_assignments.php'); exit;
        }
    }

    if ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $own = $db->prepare('SELECT id FROM assignments WHERE id=? AND teacher_id=?');
        $own->execute([$id, $user['id']]);
        if ($own->fetch()) {
            $db->prepare('DELETE FROM assignments WHERE id=?')->execute([$id]);
            logActivity($user['id'], "Deleted assignment #{$id}", 'Assignments');
            setFlash('success', 'Assignment deleted.');
        }
        header('Location: teacher_assignments.php'); exit;
    }
}

// ── Load for edit ─────────────────────────────────────────
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st  = $db->prepare('SELECT * FROM assignments WHERE id=? AND teacher_id=?');
    $st->execute([$eid, $user['id']]);
    $editItem = $st->fetch();
    $showForm = (bool)$editItem;
}

// ── List ──────────────────────────────────────────────────
$assignments = $db->prepare(
    'SELECT a.*, c.name AS class_name, c.section,
            (SELECT COUNT(*) FROM submissions WHERE assignment_id=a.id) AS submission_count
     FROM assignments a
     LEFT JOIN classes c ON c.id = a.class_id
     WHERE a.teacher_id = ?
     ORDER BY a.created_at DESC'
);
$assignments->execute([$user['id']]);
$assignments = $assignments->fetchAll();

$csrf = csrfToken();
renderHeader($showForm ? ($editItem ? 'Edit Assignment' : 'Create Assignment') : 'My Assignments', 'assignments');
?>

<?php if ($showForm): ?>
<!-- ── FORM ─────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-3 mb-4">
  <a href="teacher_assignments.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
  <h5 class="mb-0 fw-700"><?= $editItem ? 'Edit Assignment' : 'Create Assignment' ?></h5>
</div>

<?php if (empty($myClassIds)): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  You are not assigned to any class. Contact the Super Admin.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $err): ?>
  <div><i class="bi bi-exclamation-circle me-1"></i><?= e($err) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="content-card">
      <div class="card-header-custom">
        <h6>Assignment Details</h6>
      </div>
      <div class="card-body-custom">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action"     value="save">
          <input type="hidden" name="id"         value="<?= $editItem['id'] ?? 0 ?>">

          <div class="mb-3">
            <label class="form-label">Title *</label>
            <input type="text" class="form-control" name="title"
                   value="<?= e($editItem['title'] ?? '') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Description / Instructions</label>
            <textarea class="form-control" name="description" rows="5"><?= e($editItem['description'] ?? '') ?></textarea>
          </div>

          <!-- Class selector — teacher picks from their assigned classes -->
          <div class="mb-3">
            <label class="form-label">Assign to Class *</label>
            <select class="form-select" name="class_id" required>
              <option value="">— Select a class —</option>
              <?php foreach ($myClassRows as $cl): ?>
              <option value="<?= $cl['id'] ?>"
                <?= ($editItem['class_id'] ?? 0) == $cl['id'] ? 'selected' : '' ?>>
                <?= e($cl['name']) ?><?= $cl['section'] ? ' (' . e($cl['section']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Only classes you are assigned to are shown.</div>
          </div>

          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label">Due Date</label>
              <input type="date" class="form-control" name="due_date"
                     value="<?= e($editItem['due_date'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Total Marks</label>
              <input type="number" class="form-control" name="total_marks" min="1"
                     value="<?= (int)($editItem['total_marks'] ?? 100) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select class="form-select" name="status">
                <option value="active" <?= ($editItem['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="closed" <?= ($editItem['status'] ?? '')        === 'closed' ? 'selected' : '' ?>>Closed</option>
              </select>
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary" <?= empty($myClassIds) ? 'disabled' : '' ?>>
              <i class="bi bi-check2 me-1"></i><?= $editItem ? 'Update' : 'Create Assignment' ?>
            </button>
            <a href="teacher_assignments.php" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── LIST ─────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="mb-0 fw-700">My Assignments <span class="badge bg-primary ms-1"><?= count($assignments) ?></span></h5>
  <a href="teacher_assignments.php?action=add" class="btn btn-primary"
     <?= empty($myClassIds) ? 'style="opacity:.5;pointer-events:none" title="No class assigned"' : '' ?>>
    <i class="bi bi-plus-circle-fill me-1"></i>Create Assignment
  </a>
</div>

<?php if (empty($myClassIds)): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  You are not assigned to any class. Contact the Super Admin to get classes assigned.
</div>
<?php endif; ?>

<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr><th>Title</th><th>Class</th><th>Due Date</th><th>Marks</th><th>Submissions</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($assignments as $a): ?>
        <tr>
          <td class="fw-600 small"><?= e($a['title']) ?></td>
          <td class="small">
            <?php if ($a['class_name']): ?>
            <span class="badge bg-info text-dark">
              <?= e($a['class_name']) ?><?= $a['section'] ? ' (' . e($a['section']) . ')' : '' ?>
            </span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small <?= ($a['due_date'] && strtotime($a['due_date']) < time()) ? 'text-danger' : '' ?>">
            <?= $a['due_date'] ? formatDate($a['due_date']) : '—' ?>
          </td>
          <td class="small"><?= (int)$a['total_marks'] ?></td>
          <td>
            <a href="teacher_submissions.php?assignment_id=<?= $a['id'] ?>"
               class="badge bg-primary rounded-pill text-decoration-none">
              <?= (int)$a['submission_count'] ?> view
            </a>
          </td>
          <td>
            <span class="badge bg-<?= $a['status'] === 'active' ? 'success' : 'secondary' ?>">
              <?= ucfirst($a['status']) ?>
            </span>
          </td>
          <td>
            <a href="teacher_assignments.php?edit=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="POST" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action"     value="delete">
              <input type="hidden" name="id"         value="<?= $a['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"
                      data-confirm="Delete '<?= e($a['title']) ?>'? All submissions will be lost.">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($assignments)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No assignments yet. <a href="?action=add">Create one</a>.</td></tr>
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
