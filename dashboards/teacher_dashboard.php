<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// ── Get all classes assigned to this teacher ──────────────
$myClasses = $db->prepare(
    'SELECT c.id, c.name, c.section
     FROM teacher_classes tc
     JOIN classes c ON c.id = tc.class_id
     WHERE tc.teacher_id = ?
     ORDER BY c.name, c.section'
);
$myClasses->execute([$user['id']]);
$myClasses   = $myClasses->fetchAll();
$myClassIds  = array_column($myClasses, 'id');

// ── Stats ─────────────────────────────────────────────────
$totalAssignments = $db->prepare('SELECT COUNT(*) FROM assignments WHERE teacher_id=?');
$totalAssignments->execute([$user['id']]);
$totalAssignments = (int)$totalAssignments->fetchColumn();

$totalSubmissions = $db->prepare(
    'SELECT COUNT(*) FROM submissions s
     JOIN assignments a ON a.id = s.assignment_id
     WHERE a.teacher_id = ?'
);
$totalSubmissions->execute([$user['id']]);
$totalSubmissions = (int)$totalSubmissions->fetchColumn();

// Students across ALL teacher's classes
$totalStudents = 0;
if ($myClassIds) {
    $in  = implode(',', array_fill(0, count($myClassIds), '?'));
    $st  = $db->prepare("SELECT COUNT(*) FROM students WHERE class_id IN ($in)");
    $st->execute($myClassIds);
    $totalStudents = (int)$st->fetchColumn();
}

// Recent assignments
$recentAssignments = $db->prepare(
    'SELECT a.*, c.name AS class_name, c.section,
            (SELECT COUNT(*) FROM submissions WHERE assignment_id=a.id) AS submission_count
     FROM assignments a
     LEFT JOIN classes c ON c.id = a.class_id
     WHERE a.teacher_id = ?
     ORDER BY a.created_at DESC
     LIMIT 5'
);
$recentAssignments->execute([$user['id']]);
$recentAssignments = $recentAssignments->fetchAll();

renderHeader('Teacher Dashboard', 'dashboard');
?>

<!-- Class Assignment Banner -->
<?php if (empty($myClasses)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
  <div>
    <strong>No classes assigned yet.</strong>
    Please contact the Super Admin to assign you to one or more classes.
  </div>
</div>
<?php else: ?>
<div class="content-card mb-4 p-4" style="background:linear-gradient(135deg,#1e3a5f,#0f172a);border:none">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <div style="width:52px;height:52px;background:rgba(59,130,246,.25);border-radius:12px;
                display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="bi bi-diagram-3-fill text-primary fs-4"></i>
    </div>
    <div class="flex-grow-1">
      <div style="color:#94a3b8;font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px">
        Your Assigned Classes
      </div>
      <div class="d-flex flex-wrap gap-2 mt-1">
        <?php foreach ($myClasses as $cls): ?>
        <span style="background:rgba(59,130,246,.2);color:#60a5fa;font-size:.9rem;font-weight:700;
                     padding:4px 12px;border-radius:8px;border:1px solid rgba(59,130,246,.3)">
          <?= e($cls['name']) ?>
          <?= $cls['section'] ? '<span style="opacity:.7">(' . e($cls['section']) . ')</span>' : '' ?>
        </span>
        <?php endforeach; ?>
      </div>
      <div style="color:#94a3b8;font-size:.78rem;margin-top:6px">
        <?= $totalStudents ?> total students across <?= count($myClasses) ?> class<?= count($myClasses) > 1 ? 'es' : '' ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <?php $cards = [
    ['label' => 'My Assignments', 'value' => $totalAssignments, 'icon' => 'journal-text',      'color' => '3b82f6', 'bg' => 'dbeafe'],
    ['label' => 'Submissions',    'value' => $totalSubmissions, 'icon' => 'cloud-upload-fill', 'color' => '10b981', 'bg' => 'd1fae5'],
    ['label' => 'My Students',    'value' => $totalStudents,    'icon' => 'people-fill',        'color' => 'f59e0b', 'bg' => 'fef3c7'],
    ['label' => 'Classes',        'value' => count($myClasses), 'icon' => 'diagram-3-fill',     'color' => 'a78bfa', 'bg' => 'ede9fe'],
  ]; foreach ($cards as $c): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon mb-3" style="background:#<?= $c['bg'] ?>;color:#<?= $c['color'] ?>">
        <i class="bi bi-<?= $c['icon'] ?>"></i>
      </div>
      <div class="stat-value"><?= $c['value'] ?></div>
      <div class="stat-label mt-1"><?= $c['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Quick actions -->
<div class="content-card mb-4 p-3 d-flex gap-2 flex-wrap">
  <a href="teacher_assignments.php?action=add" class="btn btn-primary"
     <?= empty($myClasses) ? 'style="opacity:.5;pointer-events:none"' : '' ?>>
    <i class="bi bi-plus-circle-fill me-1"></i>Create Assignment
  </a>
  <a href="teacher_submissions.php" class="btn btn-outline-success">
    <i class="bi bi-eye-fill me-1"></i>View Submissions
  </a>
  <?php if (!empty($myClasses)): ?>
  <a href="teacher_students.php" class="btn btn-outline-info">
    <i class="bi bi-people-fill me-1"></i>My Students
  </a>
  <?php endif; ?>
</div>

<!-- Recent Assignments -->
<div class="content-card">
  <div class="card-header-custom">
    <h6><i class="bi bi-journal-text me-2 text-primary"></i>Recent Assignments</h6>
    <a href="teacher_assignments.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr><th>Title</th><th>Class</th><th>Due Date</th><th>Submissions</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentAssignments as $a): ?>
        <tr>
          <td class="fw-600 small"><?= e($a['title']) ?></td>
          <td class="small text-muted">
            <?= $a['class_name'] ? e($a['class_name']) . ($a['section'] ? ' (' . e($a['section']) . ')' : '') : '—' ?>
          </td>
          <td class="small <?= ($a['due_date'] && strtotime($a['due_date']) < time()) ? 'text-danger' : '' ?>">
            <?= $a['due_date'] ? formatDate($a['due_date']) : '—' ?>
          </td>
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
            <a href="teacher_submissions.php?assignment_id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-success">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentAssignments)): ?>
        <tr>
          <td colspan="6" class="text-center text-muted py-4">
            No assignments yet. <a href="teacher_assignments.php?action=add">Create one!</a>
          </td>
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
