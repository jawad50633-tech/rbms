<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_STUDENT);
$db   = getDB();
$user = currentUser();

$errors = [];

// Get student class
$studentInfo = $db->prepare('SELECT class_id FROM students WHERE user_id=?');
$studentInfo->execute([$user['id']]);
$studentInfo = $studentInfo->fetch();
$classId = $studentInfo['class_id'] ?? null;

// Assignment to submit
$submitId = (int)($_GET['submit'] ?? 0);
$submitAssignment = null;
if ($submitId) {
    $st = $db->prepare(
        "SELECT a.*, u.full_name AS teacher_name
         FROM assignments a
         JOIN users u ON u.id = a.teacher_id
         WHERE a.id=? AND a.status='active' AND (a.class_id IS NULL OR a.class_id=?)"
    );
    $st->execute([$submitId, $classId]);
    $submitAssignment = $st->fetch();

    // Already submitted?
    if ($submitAssignment) {
        $already = $db->prepare('SELECT id FROM submissions WHERE assignment_id=? AND student_id=?');
        $already->execute([$submitId, $user['id']]);
        if ($already->fetch()) {
            setFlash('info', 'You have already submitted this assignment. To resubmit, delete and reupload.');
            header('Location: student_assignments.php');
            exit;
        }
    }
}

// ── POST: File Upload ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $assignId = (int)($_POST['assignment_id'] ?? 0);

    // Verify assignment exists and is accessible
    $st = $db->prepare(
        "SELECT a.* FROM assignments a
         WHERE a.id=? AND a.status='active' AND (a.class_id IS NULL OR a.class_id=?)"
    );
    $st->execute([$assignId, $classId]);
    $assignment = $st->fetch();

    if (!$assignment) {
        $errors[] = 'Assignment not found or access denied.';
    }

    // Check not already submitted
    if (empty($errors)) {
        $chk = $db->prepare('SELECT id FROM submissions WHERE assignment_id=? AND student_id=?');
        $chk->execute([$assignId, $user['id']]);
        if ($chk->fetch()) $errors[] = 'You have already submitted this assignment.';
    }

    // File validation
    if (empty($_FILES['file']['name'])) {
        $errors[] = 'Please select a file to upload.';
    }

    if (empty($errors)) {
        $file         = $_FILES['file'];
        $allowedMimes = [
       'application/pdf',
       'application/msword',
       'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
       'application/vnd.openxmlformats-officedocument.presentationml.presentation',
       'application/zip',
       'application/x-zip-compressed',
       'video/mp4',
       // Image types
       'image/jpeg',
       'image/png',
       'image/gif',
       'image/webp',
       'image/svg+xml'
        ];
        $allowedExts = ['pdf', 'doc', 'docx','ppt','pptx', 'zip', 'jpg','png','jpeg','mp4'];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($mime, $allowedMimes) || !in_array($ext, $allowedExts)) {
            $errors[] = 'Only PDF, DOC, DOCX, PPT, PPTX, JPG, JPEG, PNG, mp4, and ZIP files are allowed.';
        } elseif ($file['size'] > MAX_FILE_SIZE) {
            $errors[] = 'File size exceeds ' . formatBytes(MAX_FILE_SIZE) . ' limit.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload error. Please try again.';
        }
    }

    if (empty($errors)) {
        // Secure filename
        $safeName = sanitizeFileName(pathinfo($file['name'], PATHINFO_FILENAME));
        $fileName = $user['id'] . '_' . $assignId . '_' . $safeName . '_' . time() . '.' . $ext;
        $filePath = UPLOAD_ASSIGNMENTS . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $errors[] = 'Could not save file. Check uploads directory permissions.';
        } else {
            $db->prepare(
                'INSERT INTO submissions (assignment_id, student_id, file_name, file_path, file_size)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$assignId, $user['id'], $file['name'], $fileName, $file['size']]);

            logActivity($user['id'], "Submitted assignment #{$assignId}", 'Submissions');
            setFlash('success', 'Assignment submitted successfully!');
            header('Location: student_assignments.php');
            exit;
        }
    }
}

// ── Fetch all assignments for student ──────────────────────
$allAssignments = $db->prepare(
    "SELECT a.*, u.full_name AS teacher_name,
            sub.id AS submission_id, sub.file_name AS submitted_file,
            sub.submitted_at, sub.marks, sub.feedback, sub.file_path AS submitted_path
     FROM assignments a
     JOIN users u ON u.id = a.teacher_id
     LEFT JOIN submissions sub ON sub.assignment_id=a.id AND sub.student_id=?
     WHERE a.status='active' AND (a.class_id IS NULL OR a.class_id=?)
     ORDER BY a.due_date ASC"
);
$allAssignments->execute([$user['id'], $classId]);
$allAssignments = $allAssignments->fetchAll();

$csrf = csrfToken();
renderHeader($submitAssignment ? 'Submit Assignment' : 'My Assignments', 'assignments');
?>

<?php if ($submitAssignment): ?>
<!-- ── SUBMIT FORM ── -->
<div class="d-flex align-items-center gap-3 mb-4">
  <a href="student_assignments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
  <h5 class="mb-0 fw-700">Submit Assignment</h5>
</div>

<!-- Assignment info card -->
<div class="content-card mb-4 p-4" style="border-left:4px solid #3b82f6">
  <h6 class="fw-700 mb-1"><?= e($submitAssignment['title']) ?></h6>
  <div class="text-muted small mb-2">
    Teacher: <?= e($submitAssignment['teacher_name']) ?>
    <?php if($submitAssignment['due_date']): ?>
    · Due: <strong><?= formatDate($submitAssignment['due_date']) ?></strong>
    <?php endif; ?>
    · Total Marks: <strong><?= (int)$submitAssignment['total_marks'] ?></strong>
  </div>
  <?php if($submitAssignment['description']): ?>
  <div class="small" style="white-space:pre-line"><?= e($submitAssignment['description']) ?></div>
  <?php endif; ?>
</div>

<?php if(!empty($errors)): ?>
<div class="alert alert-danger">
  <?php foreach($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="content-card">
  <div class="card-header-custom"><h6><i class="bi bi-cloud-upload me-2"></i>Upload File</h6></div>
  <div class="card-body-custom">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="assignment_id" value="<?= $submitAssignment['id'] ?>">

      <div class="mb-4">
        <label class="form-label">Select File *</label>
        <div class="border border-2 border-dashed rounded-3 p-5 text-center"
             style="border-color:#cbd5e1!important;background:#f8fafc;cursor:pointer"
             onclick="document.getElementById('fileInput').click()">
          <i class="bi bi-cloud-upload-fill text-primary" style="font-size:2.5rem"></i>
          <div class="fw-600 mt-2 small">Click to browse or drag & drop</div>
          <div class="text-muted" style="font-size:.78rem">Accepted: PDF, DOC, DOCX, PPT, PPTX, JPG, JPEG, PNG · Max: <?= formatBytes(MAX_FILE_SIZE) ?></div>
          <div class="mt-2" id="fileNameDisplay"></div>
        </div>
        <input type="file" class="d-none" name="file" id="fileInput"
               accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png" required
               onchange="document.getElementById('fileNameDisplay').innerHTML=
               '<span class=\'badge bg-success mt-1\'><i class=\'bi bi-file-earmark-check me-1\'></i>'+this.files[0].name+'</span>'">
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-cloud-upload-fill me-1"></i>Submit Assignment
        </button>
        <a href="student_assignments.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── LIST ── -->
<h5 class="mb-4 fw-700">My Assignments</h5>

<div class="row g-3">
  <?php foreach($allAssignments as $a): ?>
  <?php $overdue = $a['due_date'] && strtotime($a['due_date']) < time(); ?>
  <div class="col-md-6 col-xl-4">
    <div class="content-card h-100">
      <div class="card-body-custom">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <span class="badge bg-<?= $overdue && !$a['submission_id'] ? 'danger' : ($a['submission_id'] ? 'success' : 'warning text-dark') ?>">
            <?= $a['submission_id'] ? 'Submitted' : ($overdue ? 'Overdue' : 'Pending') ?>
          </span>
          <small class="text-muted"><?= (int)$a['total_marks'] ?> marks</small>
        </div>
        <h6 class="fw-700 mb-1"><?= e($a['title']) ?></h6>
        <p class="text-muted small mb-2">
          <i class="bi bi-person-workspace me-1"></i><?= e($a['teacher_name']) ?>
        </p>
        <?php if($a['description']): ?>
        <p class="small text-muted mb-2" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
          <?= e($a['description']) ?>
        </p>
        <?php endif; ?>
        <?php if($a['due_date']): ?>
        <p class="small <?= $overdue ? 'text-danger' : 'text-muted' ?> mb-3">
          <i class="bi bi-calendar me-1"></i>Due: <?= formatDate($a['due_date']) ?>
        </p>
        <?php endif; ?>

        <?php if ($a['submission_id']): ?>
          <div class="border rounded p-2 mb-2 bg-light">
            <div class="small fw-600 text-success"><i class="bi bi-check-circle me-1"></i>Submitted</div>
            <div class="small text-muted"><?= e($a['submitted_file']) ?></div>
            <?php if($a['marks'] !== null): ?>
            <div class="mt-1">
              <span class="badge bg-success">Grade: <?= $a['marks'] ?>/<?= $a['total_marks'] ?></span>
            </div>
            <?php endif; ?>
            <?php if($a['feedback']): ?>
            <div class="small text-muted mt-1"><strong>Feedback:</strong> <?= e($a['feedback']) ?></div>
            <?php endif; ?>
          </div>
          <a href="<?= BASE_URL ?>/uploads/assignments/<?= e($a['submitted_path']) ?>"
             class="btn btn-sm btn-outline-info w-100" target="_blank">
            <i class="bi bi-eye me-1"></i>Preview Submission
          </a>
        <?php else: ?>
          <a href="student_assignments.php?submit=<?= $a['id'] ?>"
             class="btn btn-primary w-100">
            <i class="bi bi-cloud-upload me-1"></i>Submit Now
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if(empty($allAssignments)): ?>
  <div class="col-12">
    <div class="content-card p-5 text-center text-muted">
      <i class="bi bi-journal-x" style="font-size:3rem;opacity:.3"></i>
      <div class="mt-2">No assignments available yet.</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
