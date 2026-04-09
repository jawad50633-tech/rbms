<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// ── Get teacher's assigned class ───────────────────────────
$teacherInfo = $db->prepare(
    'SELECT u.class_id, c.name AS class_name, c.section
     FROM users u
     LEFT JOIN classes c ON c.id = u.class_id
     WHERE u.id = ?'
);
$teacherInfo->execute([$user['id']]);
$teacherInfo = $teacherInfo->fetch();
$myClassId   = $teacherInfo['class_id'] ?? null;

// ── Stats scoped to teacher's class ───────────────────────
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

// Students in teacher's class
$totalStudents = 0;
if ($myClassId) {
    $st = $db->prepare("SELECT COUNT(*) FROM students WHERE class_id=?");
    $st->execute([$myClassId]);
    $totalStudents = (int)$st->fetchColumn();
}

// Recent assignments
$recentAssignments = $db->prepare(
    'SELECT a.*, c.name AS class_name,
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
<?php if (!$myClassId): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
  <div>
    <strong>No class assigned yet.</strong>
    Please contact the Super Admin to assign you to a class before you can manage students or post assignments.
  </div>
</div>
<?php else: ?>
<div class="content-card mb-4 p-4" style="background:linear-gradient(135deg,#1e3a5f,#0f172a);border:none">
  <div class="d-flex align-items-center gap-3">
    <div style="width:52px;height:52px;background:rgba(59,130,246,.25);border-radius:12px;display:flex;align-items:center;justify-content:center">
      <i class="bi bi-diagram-3-fill text-primary fs-4"></i>
    </div>
    <div>
      <div style="color:#94a3b8;font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Your Assigned Class</div>
      <div style="color:#fff;font-size:1.3rem;font-weight:700">
        <?= e($teacherInfo['class_name']) ?>
        <?= $teacherInfo['section'] ? '<span style="color:#60a5fa">(' . e($teacherInfo['section']) . ')</span>' : '' ?>
      </div>
      <div style="color:#94a3b8;font-size:.8rem"><?= $totalStudents ?> students enrolled in this class</div>
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
  ]; foreach ($cards as $c): ?>
  <div class="col-4">
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
  <a href="teacher_assignments.php?action=add" class="btn btn-primary">
    <i class="bi bi-plus-circle-fill me-1"></i>Create Assignment
  </a>
  <a href="teacher_submissions.php" class="btn btn-outline-success">
    <i class="bi bi-eye-fill me-1"></i>View Submissions
  </a>
  <?php if ($myClassId): ?>
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
        <tr><th>Title</th><th>Due Date</th><th>Submissions</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentAssignments as $a): ?>
        <tr>
          <td class="fw-600 small"><?= e($a['title']) ?></td>
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
          <td colspan="5" class="text-center text-muted py-4">
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
