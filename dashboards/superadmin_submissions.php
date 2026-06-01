<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
requireLogin(ROLE_SUPER_ADMIN);
$db = getDB();

$submissions = $db->query(
    'SELECT s.*, a.title AS assignment_title, a.total_marks,
            student.full_name AS student_name,
            teacher.full_name AS teacher_name
     FROM submissions s
     JOIN assignments a     ON a.id = s.assignment_id
     JOIN users student     ON student.id = s.student_id
     JOIN users teacher     ON teacher.id = a.teacher_id
     ORDER BY s.submitted_at DESC'
)->fetchAll();

renderHeader('All Submissions', 'submissions');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="mb-0 fw-700">All Submissions</h5>
  <span class="badge bg-primary"><?= count($submissions) ?> total</span>
</div>
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead><tr><th>Student</th><th>Assignment</th><th>Teacher</th><th>File</th><th>Date</th><th>Marks</th></tr></thead>
      <tbody>
        <?php foreach($submissions as $sub): ?>
        <tr>
          <td class="fw-600 small"><?= e($sub['student_name']) ?></td>
          <td class="small"><?= e($sub['assignment_title']) ?></td>
          <td class="small text-muted"><?= e($sub['teacher_name']) ?></td>
          <td>
            <a href="<?= BASE_URL ?>/uploads/assignments/<?= e($sub['file_path']) ?>"
               class="btn btn-sm btn-outline-info" target="_blank" download>
              <i class="bi bi-download me-1"></i><?= e($sub['file_name']) ?>
            </a>
          </td>
          <td class="small text-muted"><?= date('d M Y', strtotime($sub['submitted_at'])) ?></td>
          <td>
            <?php if($sub['marks'] !== null): ?>
            <span class="badge bg-success"><?= $sub['marks'] ?>/<?= $sub['total_marks'] ?></span>
            <?php else: ?>
            <span class="badge bg-warning text-dark">Pending</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($submissions)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No submissions yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; renderFooter(); ?>
