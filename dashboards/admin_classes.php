<?php
// admin_classes.php — Shared by admin and super_admin
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin([ROLE_ADMIN, ROLE_SUPER_ADMIN]);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $section = trim($_POST['section'] ?? '');
        if (!$name) { setFlash('error', 'Class name required.'); header('Location: admin_classes.php'); exit; }
        if ($id) {
            $db->prepare('UPDATE classes SET name=?,section=? WHERE id=?')->execute([$name,$section?:null,$id]);
        } else {
            $db->prepare('INSERT INTO classes (name,section) VALUES (?,?)')->execute([$name,$section?:null]);
        }
        setFlash('success', $id ? 'Class updated.' : 'Class created.');
        header('Location: admin_classes.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM classes WHERE id=?')->execute([$id]);
        setFlash('success', 'Class deleted.');
        header('Location: admin_classes.php'); exit;
    }
}

$classes = $db->query(
    'SELECT c.*, COUNT(s.id) AS student_count
     FROM classes c
     LEFT JOIN students s ON s.class_id = c.id
     GROUP BY c.id
     ORDER BY c.name, c.section'
)->fetchAll();

$editClass = null;
if (isset($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM classes WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editClass = $st->fetch();
}

$csrf = csrfToken();
renderHeader('Class Management', 'classes');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="mb-0 fw-700">Classes</h5>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#classModal"
          onclick="setCreate()">
    <i class="bi bi-plus-circle-fill me-1"></i>Add Class
  </button>
</div>

<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead><tr><th>#</th><th>Class Name</th><th>Section</th><th>Students</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($classes as $i => $c): ?>
        <tr>
          <td class="text-muted small"><?= $i+1 ?></td>
          <td class="fw-600 small"><?= e($c['name']) ?></td>
          <td class="small"><?= e($c['section'] ?? '—') ?></td>
          <td><span class="badge bg-primary rounded-pill"><?= (int)$c['student_count'] ?></span></td>
          <td>
            <button class="btn btn-sm btn-outline-primary me-1"
                    onclick='editCls(<?= json_encode($c) ?>)'
                    data-bs-toggle="modal" data-bs-target="#classModal">
              <i class="bi bi-pencil"></i>
            </button>
            <form method="POST" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"
                      data-confirm="Delete class <?= e($c['name']) ?>?">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($classes)): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No classes yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="classModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-0"><h5 class="modal-title fw-700" id="clsTitle">Add Class</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="clsId" value="">
          <div class="mb-3">
            <label class="form-label">Class Name *</label>
            <input type="text" class="form-control" name="name" id="clsName" placeholder="e.g. Class 10" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Section</label>
            <input type="text" class="form-control" name="section" id="clsSection" placeholder="e.g. A">
          </div>
        </div>
        <div class="modal-footer border-0">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function setCreate(){document.getElementById('clsTitle').textContent='Add Class';document.getElementById('clsId').value='';document.getElementById('clsName').value='';document.getElementById('clsSection').value='';}
function editCls(c){document.getElementById('clsTitle').textContent='Edit Class';document.getElementById('clsId').value=c.id;document.getElementById('clsName').value=c.name;document.getElementById('clsSection').value=c.section||'';}
<?php if($editClass): ?>window.addEventListener('load',function(){editCls(<?= json_encode($editClass) ?>);new bootstrap.Modal(document.getElementById('classModal')).show();});<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; renderFooter(); ?>
