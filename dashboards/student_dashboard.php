<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_STUDENT);
$db   = getDB();
$user = currentUser();

// Get student's class
$studentInfo = $db->prepare(
    'SELECT s.*, c.id AS class_id, c.name AS class_name, c.section
     FROM students s
     LEFT JOIN classes c ON c.id = s.class_id
     WHERE s.user_id = ?'
);
$studentInfo->execute([$user['id']]);
$studentInfo = $studentInfo->fetch();
$classId = $studentInfo['class_id'] ?? null;

// Assignments available to this student (class match or global)
$params = [$classId, $classId];
$assignments = $db->prepare(
    "SELECT a.*, u.full_name AS teacher_name,
            (SELECT id FROM submissions WHERE assignment_id=a.id AND student_id=?) AS submitted,
            (SELECT marks FROM submissions WHERE assignment_id=a.id AND student_id=?) AS marks
     FROM assignments a
     JOIN users u ON u.id = a.teacher_id
     WHERE a.status='active' AND (a.class_id IS NULL OR a.class_id=?)
     ORDER BY a.due_date ASC"
);
$assignments->execute([$user['id'], $user['id'], $classId]);
$assignments = $assignments->fetchAll();

$pending   = array_filter($assignments, fn($a) => !$a['submitted']);
$submitted = array_filter($assignments, fn($a) => $a['submitted']);

renderHeader('Student Dashboard', 'dashboard');
?>

<!-- Welcome Banner -->
<div class="content-card mb-4 p-4" style="background:linear-gradient(135deg,#1e3a5f,#0f172a);border:none">
  <div class="row align-items-center">
    <div class="col">
      <h4 class="text-white mb-1">Welcome back, <?= e(explode(' ', $user['name'])[0]) ?>! 👋</h4>
      <p class="text-secondary mb-0" style="color:#94a3b8!important">
        <?php if($studentInfo && $studentInfo['class_name']): ?>
        Class: <strong style="color:#60a5fa"><?= e($studentInfo['class_name']) ?><?= $studentInfo['section'] ? ' (' . e($studentInfo['section']) . ')' : '' ?></strong> ·
        Roll: <strong style="color:#60a5fa"><?= e($studentInfo['roll_number'] ?? '—') ?></strong>
        <?php else: ?>
        You are enrolled. Check your assignments below.
        <?php endif; ?>
      </p>
    </div>
    <div class="col-auto">
      <div class="row g-2">
        <div class="col-auto text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#fff"><?= count($pending) ?></div>
          <div style="font-size:.72rem;color:#94a3b8">Pending</div>
        </div>
        <div class="col-auto text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#4ade80"><?= count($submitted) ?></div>
          <div style="font-size:.72rem;color:#94a3b8">Submitted</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Pending Assignments -->
<?php if(!empty($pending)): ?>
<div class="content-card mb-4">
  <div class="card-header-custom">
    <h6><i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Assignments</h6>
    <span class="badge bg-warning text-dark"><?= count($pending) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead><tr><th>Title</th><th>Teacher</th><th>Due Date</th><th>Marks</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach($pending as $a): ?>
        <?php $overdue = $a['due_date'] && strtotime($a['due_date']) < time(); ?>
        <tr>
          <td class="fw-600 small"><?= e($a['title']) ?></td>
          <td class="small text-muted"><?= e($a['teacher_name']) ?></td>
          <td class="small <?= $overdue ? 'text-danger fw-600' : '' ?>">
            <?= $a['due_date'] ? formatDate($a['due_date']) : '—' ?>
            <?= $overdue ? '<span class="badge bg-danger ms-1">Overdue</span>' : '' ?>
          </td>
          <td class="small"><?= (int)$a['total_marks'] ?></td>
          <td>
            <a href="student_assignments.php?submit=<?= $a['id'] ?>" class="btn btn-sm btn-primary">
              <i class="bi bi-cloud-upload me-1"></i>Submit
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Submitted Assignments -->
<div class="content-card">
  <div class="card-header-custom">
    <h6><i class="bi bi-check-circle me-2 text-success"></i>Submitted Assignments</h6>
    <span class="badge bg-success"><?= count($submitted) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead><tr><th>Title</th><th>Teacher</th><th>Due Date</th><th>Marks</th></tr></thead>
      <tbody>
        <?php foreach($submitted as $a): ?>
        <tr>
          <td class="fw-600 small"><?= e($a['title']) ?></td>
          <td class="small text-muted"><?= e($a['teacher_name']) ?></td>
          <td class="small"><?= $a['due_date'] ? formatDate($a['due_date']) : '—' ?></td>
          <td>
            <?php if($a['marks'] !== null): ?>
              <span class="badge bg-success"><?= $a['marks'] ?>/<?= $a['total_marks'] ?></span>
            <?php else: ?>
              <span class="badge bg-info text-dark">Awaiting Grade</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($submitted)): ?>
        <tr><td colspan="4" class="text-center text-muted py-3">No submissions yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
