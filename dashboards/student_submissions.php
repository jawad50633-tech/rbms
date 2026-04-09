<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
requireLogin(ROLE_STUDENT);
$db   = getDB();
$user = currentUser();

$submissions = $db->prepare(
    'SELECT s.*, a.title AS assignment_title, a.total_marks, a.due_date,
            u.full_name AS teacher_name
     FROM submissions s
     JOIN assignments a ON a.id = s.assignment_id
     JOIN users u        ON u.id = a.teacher_id
     WHERE s.student_id = ?
     ORDER BY s.submitted_at DESC'
);
$submissions->execute([$user['id']]);
$submissions = $submissions->fetchAll();

renderHeader('My Submissions', 'submissions');
?>
<h5 class="mb-4 fw-700">My Submissions <span class="badge bg-primary ms-1"><?= count($submissions) ?></span></h5>

<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr><th>Assignment</th><th>Teacher</th><th>File</th><th>Submitted</th><th>Marks</th><th>Feedback</th></tr>
      </thead>
      <tbody>
        <?php foreach($submissions as $sub): ?>
        <tr>
          <td>
            <div class="fw-600 small"><?= e($sub['assignment_title']) ?></div>
            <?php if($sub['due_date']): ?>
            <div class="text-muted" style="font-size:.72rem">Due: <?= formatDate($sub['due_date']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= e($sub['teacher_name']) ?></td>
          <td>
            <a href="<?= BASE_URL ?>/uploads/assignments/<?= e($sub['file_path']) ?>"
               class="btn btn-sm btn-outline-info" target="_blank">
              <i class="bi bi-eye me-1"></i>Preview
            </a>
            <div class="text-muted" style="font-size:.72rem;margin-top:2px"><?= e($sub['file_name']) ?></div>
          </td>
          <td class="small text-muted"><?= date('d M Y, H:i', strtotime($sub['submitted_at'])) ?></td>
          <td>
            <?php if($sub['marks'] !== null): ?>
              <span class="badge bg-<?= ($sub['marks']/$sub['total_marks']) >= 0.6 ? 'success':'danger' ?>">
                <?= $sub['marks'] ?>/<?= $sub['total_marks'] ?>
              </span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Pending</span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?= $sub['feedback'] ? '<span class="text-muted">'.e($sub['feedback']).'</span>' : '<span class="text-muted">—</span>' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($submissions)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No submissions yet. <a href="student_assignments.php">View assignments</a>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; renderFooter(); ?>
