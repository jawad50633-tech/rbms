<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// Get teacher's assigned class — enforced server-side
$teacherRow = $db->prepare('SELECT u.class_id, c.name AS class_name, c.section FROM users u LEFT JOIN classes c ON c.id=u.class_id WHERE u.id=?');
$teacherRow->execute([$user['id']]);
$teacherRow = $teacherRow->fetch();
$myClassId  = $teacherRow['class_id'] ?? null;

// Handle grading POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['action']) && $_POST['action'] === 'grade') {
        $subId    = (int)($_POST['submission_id'] ?? 0);
        $marks    = (int)($_POST['marks']         ?? 0);
        $feedback = trim($_POST['feedback']        ?? '');

        // Security: only grade if submission belongs to teacher's own assignment AND student is in teacher's class
        $check = $db->prepare(
            'SELECT s.id FROM submissions s
             JOIN assignments a ON a.id = s.assignment_id
             JOIN students st   ON st.user_id = s.student_id
             WHERE s.id=? AND a.teacher_id=? AND st.class_id=?'
        );
        $check->execute([$subId, $user['id'], $myClassId]);
        if ($check->fetch()) {
            $db->prepare('UPDATE submissions SET marks=?, feedback=? WHERE id=?')
               ->execute([$marks, $feedback, $subId]);
            setFlash('success', 'Submission graded.');
        }
        $redirect = 'teacher_submissions.php';
        if (isset($_GET['assignment_id'])) $redirect .= '?assignment_id=' . (int)$_GET['assignment_id'];
        header('Location: ' . $redirect);
        exit;
    }
}
if (isset($_POST['action']) && $_POST['action'] === 'delete') {

    $subId = (int)($_POST['submission_id'] ?? 0);

    // Verify submission belongs to teacher
    $check = $db->prepare(
        'SELECT s.file_path 
         FROM submissions s
         JOIN assignments a ON a.id = s.assignment_id
         JOIN students st ON st.user_id = s.student_id
         WHERE s.id=? AND a.teacher_id=? AND st.class_id=?'
    );

    $check->execute([$subId, $user['id'], $myClassId]);
    $sub = $check->fetch();

    if ($sub) {

        // Delete file from server
        $file = __DIR__ . "/../uploads/assignments/" . $sub['file_path'];
        if (file_exists($file)) {
            unlink($file);
        }

        // Delete database record
        $db->prepare("DELETE FROM submissions WHERE id=?")->execute([$subId]);

        setFlash('success', 'Submission deleted.');
    }

    $redirect = 'teacher_submissions.php';
    if (isset($_GET['assignment_id'])) {
        $redirect .= '?assignment_id=' . (int)$_GET['assignment_id'];
    }

    header('Location: ' . $redirect);
    exit;
}

$assignmentId = (int)($_GET['assignment_id'] ?? 0);

// My assignments dropdown
$myAssignments = $db->prepare(
    'SELECT id, title FROM assignments WHERE teacher_id=? ORDER BY created_at DESC'
);
$myAssignments->execute([$user['id']]);
$myAssignments = $myAssignments->fetchAll();

// Verify selected assignment belongs to this teacher
$assignmentInfo = null;
if ($assignmentId) {
    $st = $db->prepare('SELECT * FROM assignments WHERE id=? AND teacher_id=?');
    $st->execute([$assignmentId, $user['id']]);
    $assignmentInfo = $st->fetch();
    if (!$assignmentInfo) {
        setFlash('error', 'Assignment not found or access denied.');
        header('Location: teacher_submissions.php');
        exit;
    }
}

// Submissions — scoped to teacher's assignments AND teacher's class students only
$where  = 'WHERE a.teacher_id=?';
$params = [$user['id']];

if ($myClassId) {
    // Only show submissions from students in teacher's class
    $where  .= ' AND st.class_id=?';
    $params[] = $myClassId;
}

if ($assignmentId) {
    $where  .= ' AND s.assignment_id=?';
    $params[] = $assignmentId;
}

$submissions = $db->prepare(
    "SELECT s.*, a.title AS assignment_title, a.total_marks,
            u.full_name AS student_name, u.username,
            st.roll_number, st.father_name
     FROM submissions s
     JOIN assignments a ON a.id = s.assignment_id
     JOIN users u        ON u.id = s.student_id
     JOIN students st    ON st.user_id = s.student_id
     $where
     ORDER BY s.submitted_at DESC"
);
$submissions->execute($params);
$submissions = $submissions->fetchAll();

$csrf = csrfToken();
renderHeader('Student Submissions', 'submissions');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="mb-0 fw-700">
      Student Submissions
      <?php if ($assignmentInfo): ?>
      <span class="text-muted fw-400 fs-6">— <?= e($assignmentInfo['title']) ?></span>
      <?php endif; ?>
    </h5>
    <?php if ($myClassId): ?>
    <small class="text-muted">
      <i class="bi bi-diagram-3 me-1"></i>
      Showing submissions from:
      <strong><?= e($teacherRow['class_name']) ?><?= $teacherRow['section'] ? ' (' . e($teacherRow['section']) . ')' : '' ?></strong>
    </small>
    <?php else: ?>
    <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>No class assigned — contact Super Admin.</small>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <form method="GET">
      <select class="form-select form-select-sm" name="assignment_id" onchange="this.form.submit()">
        <option value="">All Assignments</option>
        <?php foreach ($myAssignments as $a): ?>
        <option value="<?= $a['id'] ?>" <?= $assignmentId == $a['id'] ? 'selected' : '' ?>>
          <?= e($a['title']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($assignmentId): ?>
    <a href="teacher_submissions.php" class="btn btn-outline-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </div>
</div>

<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Student</th>
          <th>Roll No.</th>
          <th>Assignment</th>
          <th>File</th>
          <th>Submitted</th>
          <th>Marks</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($submissions as $sub): ?>
        <tr>
          <td>
            <div class="fw-600 small"><?= e($sub['student_name']) ?></div>
            <?php if ($sub['father_name']): ?>
            <div class="text-muted" style="font-size:.72rem">F: <?= e($sub['father_name']) ?></div>
            <?php endif; ?>
          </td>
          <td><code class="small"><?= e($sub['roll_number'] ?? '—') ?></code></td>
          <td class="small"><?= e($sub['assignment_title']) ?></td>
          <td>
            <a href="<?= BASE_URL ?>/uploads/assignments/<?= e($sub['file_path']) ?>"
               class="btn btn-sm btn-outline-info" target="_blank" download>
              <i class="bi bi-download me-1"></i><?= e($sub['file_name']) ?>
            </a>
            <div class="text-muted" style="font-size:.72rem"><?= formatBytes($sub['file_size'] ?? 0) ?></div>
          </td>
          <td class="small text-muted"><?= date('d M Y, H:i', strtotime($sub['submitted_at'])) ?></td>
          <td>
            <?php if ($sub['marks'] !== null): ?>
              <span class="badge bg-success"><?= $sub['marks'] ?>/<?= $sub['total_marks'] ?></span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Pending</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal" data-bs-target="#gradeModal"
                    onclick='openGrade(<?= json_encode([
                      "id"         => $sub["id"],
                      "student"    => $sub["student_name"],
                      "assignment" => $sub["assignment_title"],
                      "marks"      => $sub["marks"],
                      "total"      => $sub["total_marks"],
                      "feedback"   => $sub["feedback"],
                    ]) ?>)'>
              <i class="bi bi-patch-check me-1"></i>Grade
            </button>
            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this submission? This action cannot be undone.')">
              <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
              <input type="hidden" name="action"        value="delete">
              <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash me-1"></i>Delete
              </button>
              </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($submissions)): ?>
        <tr>
          <td colspan="7" class="text-center text-muted py-4">No submissions yet.</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Grade Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-700">Grade Submission</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="csrf_token"     value="<?= $csrf ?>">
          <input type="hidden" name="action"         value="grade">
          <input type="hidden" name="submission_id"  id="gradeSubId">
          <?php if ($assignmentId): ?>
          <input type="hidden" name="assignment_id"  value="<?= $assignmentId ?>">
          <?php endif; ?>
          <p class="text-muted small mb-3">
            Grading: <strong id="gradeName"></strong> — <em id="gradeAssign"></em>
          </p>
          <div class="mb-3">
            <label class="form-label">Marks (out of <span id="gradeTotal"></span>)</label>
            <input type="number" class="form-control" name="marks" id="gradeMarks" min="0" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Feedback <small class="text-muted">(optional)</small></label>
            <textarea class="form-control" name="feedback" id="gradeFeedback" rows="3"
                      placeholder="Write feedback for the student…"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i>Save Grade
          </button>
        </div>
      </form>
      
    </div>
  </div>
</div>

<script>
function openGrade(data) {
  document.getElementById('gradeSubId').value        = data.id;
  document.getElementById('gradeName').textContent   = data.student;
  document.getElementById('gradeAssign').textContent = data.assignment;
  document.getElementById('gradeTotal').textContent  = data.total;
  document.getElementById('gradeMarks').value        = data.marks ?? '';
  document.getElementById('gradeMarks').max          = data.total;
  document.getElementById('gradeFeedback').value     = data.feedback ?? '';
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
