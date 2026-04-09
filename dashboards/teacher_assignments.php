<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// Get teacher's assigned class
$teacherRow = $db->prepare('SELECT u.class_id, c.name AS class_name, c.section FROM users u LEFT JOIN classes c ON c.id=u.class_id WHERE u.id=?');
$teacherRow->execute([$user['id']]);
$teacherRow = $teacherRow->fetch();
$myClassId  = $teacherRow['class_id'] ?? null;

$errors   = [];
$editItem = null;
$showForm = isset($_GET['action']) && $_GET['action'] === 'add';

// ── POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id     = (int)($_POST['id']          ?? 0);
        $title  = trim($_POST['title']        ?? '');
        $desc   = trim($_POST['description']  ?? '');
        $due    = $_POST['due_date']          ?? '';
        $marks  = (int)($_POST['total_marks'] ?? 100);
        $status = $_POST['status']            ?? 'active';
        // Always use teacher's own assigned class — cannot be overridden
        $classId = $myClassId;

        if (!$title)   $errors[] = 'Title is required.';
        if (!$classId) $errors[] = 'You must be assigned to a class before creating assignments. Contact Super Admin.';

        if (empty($errors)) {
            if ($id) {
                // Verify ownership
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
            header('Location: teacher_assignments.php');
            exit;
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
        header('Location: teacher_assignments.php');
        exit;
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
    'SELECT a.*,
            (SELECT COUNT(*) FROM submissions WHERE assignment_id=a.id) AS submission_count
     FROM assignments a
     WHERE a.teacher_id = ?
     ORDER BY a.created_at DESC'
);
$assignments->execute([$user['id']]);
$assignments = $assignments->fetchAll();

$csrf = csrfToken();
renderHeader($showForm ? ($editItem ? 'Edit Assignment' : 'Create Assignment') : 'My Assignments', 'assignments');
?>

<?php if (!$myClassId && $showForm): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  You are not assigned to any class. <strong>Contact the Super Admin</strong> to assign you a class before creating assignments.
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<!-- FORM -->
<div class="d-flex align-items-center gap-3 mb-4">
  <a href="teacher_assignments.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
  <h5 class="mb-0 fw-700"><?= $editItem ? 'Edit Assignment' : 'Create Assignment' ?></h5>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $e): ?><div><i class="bi bi-exclamation-circle me-1"></i><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="content-card">
      <div class="card-header-custom">
        <h6>Assignment Details</h6>
        <?php if ($myClassId): ?>
        <span class="badge bg-primary">
          <i class="bi bi-diagram-3 me-1"></i>
          <?= e($teacherRow['class_name']) ?><?= $teacherRow['section'] ? ' (' . e($teacherRow['section']) . ')' : '' ?>
        </span>
        <?php endif; ?>
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

          <!-- Class is auto-set to teacher's class — shown as read-only info -->
          <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            This assignment will be posted to your class:
            <strong><?= $myClassId ? e($teacherRow['class_name']) . ($teacherRow['section'] ? ' (' . e($teacherRow['section']) . ')' : '') : 'Not assigned' ?></strong>
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
            <button type="submit" class="btn btn-primary" <?= !$myClassId ? 'disabled' : '' ?>>
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
<!-- LIST -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="mb-0 fw-700">My Assignments <span class="badge bg-primary ms-1"><?= count($assignments) ?></span></h5>
    <?php if ($myClassId): ?>
    <small class="text-muted">
      <i class="bi bi-diagram-3 me-1"></i>
      Class: <strong><?= e($teacherRow['class_name']) ?><?= $teacherRow['section'] ? ' (' . e($teacherRow['section']) . ')' : '' ?></strong>
    </small>
    <?php endif; ?>
  </div>
  <a href="teacher_assignments.php?action=add" class="btn btn-primary" <?= !$myClassId ? 'title="Assign a class first" style="opacity:.5;pointer-events:none"' : '' ?>>
    <i class="bi bi-plus-circle-fill me-1"></i>Create Assignment
  </a>
</div>

<?php if (!$myClassId): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  You are not assigned to any class. Contact Super Admin to get a class assigned.
</div>
<?php endif; ?>

<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr><th>Title</th><th>Due Date</th><th>Marks</th><th>Submissions</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($assignments as $a): ?>
        <tr>
          <td class="fw-600 small"><?= e($a['title']) ?></td>
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
        <tr>
          <td colspan="6" class="text-center text-muted py-4">
            No assignments yet. <a href="?action=add">Create one</a>.
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
