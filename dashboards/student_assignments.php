<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_STUDENT);

$db   = getDB();
$user = currentUser();

$errors = [];

// ─────────────────────────────────────────────────────────────
// Get Student Class
// ─────────────────────────────────────────────────────────────
$studentInfo = $db->prepare('SELECT class_id FROM students WHERE user_id = ?');
$studentInfo->execute([$user['id']]);
$studentInfo = $studentInfo->fetch();

$classId = $studentInfo['class_id'] ?? null;

// ─────────────────────────────────────────────────────────────
// Assignment to Submit
// ─────────────────────────────────────────────────────────────
$submitId = (int)($_GET['submit'] ?? 0);
$submitAssignment = null;

if ($submitId) {

    $st = $db->prepare(
        "SELECT a.*, u.full_name AS teacher_name
         FROM assignments a
         JOIN users u ON u.id = a.teacher_id
         WHERE a.id = ?
         AND a.status = 'active'
         AND (a.class_id IS NULL OR a.class_id = ?)"
    );

    $st->execute([$submitId, $classId]);
    $submitAssignment = $st->fetch();

    // Already submitted?
    if ($submitAssignment) {

        $already = $db->prepare(
            'SELECT id FROM submissions
             WHERE assignment_id = ?
             AND student_id = ?'
        );

        $already->execute([$submitId, $user['id']]);

        if ($already->fetch()) {

            setFlash(
                'info',
                'You have already submitted this assignment.'
            );

            header('Location: student_assignments.php');
            exit;
        }
    }
}

// ─────────────────────────────────────────────────────────────
// POST: File Upload
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrf();

    $assignId = (int)($_POST['assignment_id'] ?? 0);

    // Verify assignment
    $st = $db->prepare(
        "SELECT *
         FROM assignments
         WHERE id = ?
         AND status = 'active'
         AND (class_id IS NULL OR class_id = ?)"
    );

    $st->execute([$assignId, $classId]);
    $assignment = $st->fetch();

    if (!$assignment) {
        $errors[] = 'Assignment not found or access denied.';
    }

    // Prevent overdue submissions
    if (
        $assignment &&
        !empty($assignment['due_date']) &&
        strtotime($assignment['due_date']) < time()
    ) {
        $errors[] = 'Submission deadline has passed.';
    }

    // Check already submitted
    if (empty($errors)) {

        $chk = $db->prepare(
            'SELECT id FROM submissions
             WHERE assignment_id = ?
             AND student_id = ?'
        );

        $chk->execute([$assignId, $user['id']]);

        if ($chk->fetch()) {
            $errors[] = 'You have already submitted this assignment.';
        }
    }

    // Validate upload
    if (empty($_FILES['file']['name'])) {
        $errors[] = 'Please select a file to upload.';
    }

    // File validation
    if (empty($errors)) {

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {

            $errors[] = 'File upload error. Please try again.';

        } else {

            $allowedMimes = [

                // Documents
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

                // PowerPoint
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',

                // ZIP
                'application/zip',
                'application/x-zip-compressed',

                // Video
                'video/mp4',

                // Images
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp'
            ];

            $allowedExts = [

                'pdf',
                'doc',
                'docx',

                'ppt',
                'pptx',

                'zip',

                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp',

                'mp4'
            ];

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            $mime = $finfo->file($file['tmp_name']);

            $ext = strtolower(
                pathinfo(
                    $file['name'],
                    PATHINFO_EXTENSION
                )
            );

            if (
                !in_array($mime, $allowedMimes) ||
                !in_array($ext, $allowedExts)
            ) {

                $errors[] =
                    'Only PDF, DOC, DOCX, PPT, PPTX, ZIP, JPG, PNG, GIF, WEBP and MP4 files are allowed.';

            } elseif ($file['size'] > MAX_FILE_SIZE) {

                $errors[] =
                    'File size exceeds ' .
                    formatBytes(MAX_FILE_SIZE) .
                    ' limit.';
            }
        }
    }

    // Save file
    if (empty($errors)) {

        $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);

        // Secure random filename
        $fileName =
            bin2hex(random_bytes(16)) .
            '.' .
            $safeExt;

        $filePath =
            rtrim(UPLOAD_ASSIGNMENTS, '/\\') .
            DIRECTORY_SEPARATOR .
            $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {

            $errors[] =
                'Could not save file. Check upload folder permissions.';

        } else {

            $insert = $db->prepare(
                'INSERT INTO submissions
                (
                    assignment_id,
                    student_id,
                    file_name,
                    file_path,
                    file_size
                )
                VALUES (?, ?, ?, ?, ?)'
            );

            $insert->execute([
                $assignId,
                $user['id'],
                $file['name'],
                $fileName,
                $file['size']
            ]);

            logActivity(
                $user['id'],
                "Submitted assignment #{$assignId}",
                'Submissions'
            );

            setFlash(
                'success',
                'Assignment submitted successfully!'
            );

            header('Location: student_assignments.php');
            exit;
        }
    }
}

// ─────────────────────────────────────────────────────────────
// Fetch Assignments
// ─────────────────────────────────────────────────────────────
$allAssignments = $db->prepare(
    "SELECT
        a.*,
        u.full_name AS teacher_name,

        sub.id AS submission_id,
        sub.file_name AS submitted_file,
        sub.submitted_at,
        sub.marks,
        sub.feedback,
        sub.file_path AS submitted_path

     FROM assignments a

     JOIN users u
     ON u.id = a.teacher_id

     LEFT JOIN submissions sub
     ON sub.assignment_id = a.id
     AND sub.student_id = ?

     WHERE a.status = 'active'
     AND (a.class_id IS NULL OR a.class_id = ?)

     ORDER BY a.due_date ASC"
);

$allAssignments->execute([
    $user['id'],
    $classId
]);

$allAssignments = $allAssignments->fetchAll();

$csrf = csrfToken();

renderHeader(
    $submitAssignment
        ? 'Submit Assignment'
        : 'My Assignments',
    'assignments'
);
?>

<?php if ($submitAssignment): ?>

<!-- Submit Form -->
<div class="d-flex align-items-center gap-3 mb-4">

    <a href="student_assignments.php"
       class="btn btn-outline-secondary btn-sm">

        <i class="bi bi-arrow-left me-1"></i>
        Back

    </a>

    <h5 class="mb-0 fw-700">
        Submit Assignment
    </h5>

</div>

<!-- Assignment Card -->
<div class="content-card mb-4 p-4"
     style="border-left:4px solid #3b82f6">

    <h6 class="fw-700 mb-1">
        <?= e($submitAssignment['title']) ?>
    </h6>

    <div class="text-muted small mb-2">

        Teacher:
        <?= e($submitAssignment['teacher_name']) ?>

        <?php if ($submitAssignment['due_date']): ?>

            · Due:
            <strong>
                <?= formatDate($submitAssignment['due_date']) ?>
            </strong>

        <?php endif; ?>

        · Total Marks:
        <strong>
            <?= (int)$submitAssignment['total_marks'] ?>
        </strong>

    </div>

    <?php if ($submitAssignment['description']): ?>

        <div class="small"
             style="white-space:pre-line">

            <?= e($submitAssignment['description']) ?>

        </div>

    <?php endif; ?>

</div>

<!-- Errors -->
<?php if (!empty($errors)): ?>

<div class="alert alert-danger">

    <?php foreach ($errors as $err): ?>

        <div><?= e($err) ?></div>

    <?php endforeach; ?>

</div>

<?php endif; ?>

<!-- Upload Box -->
<div class="content-card">

    <div class="card-header-custom">

        <h6>
            <i class="bi bi-cloud-upload me-2"></i>
            Upload File
        </h6>

    </div>

    <div class="card-body-custom">

        <form method="POST"
              enctype="multipart/form-data">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= $csrf ?>">

            <input type="hidden"
                   name="assignment_id"
                   value="<?= $submitAssignment['id'] ?>">

            <div class="mb-4">

                <label class="form-label">
                    Select File *
                </label>

                <div class="border border-2 border-dashed rounded-3 p-5 text-center"
                     style="border-color:#cbd5e1!important;background:#f8fafc;cursor:pointer"
                     onclick="document.getElementById('fileInput').click()">

                    <i class="bi bi-cloud-upload-fill text-primary"
                       style="font-size:2.5rem"></i>

                    <div class="fw-600 mt-2 small">
                        Click to browse or drag & drop
                    </div>

                    <div class="text-muted"
                         style="font-size:.78rem">

                        Accepted:
                        PDF, DOC, DOCX, PPT, PPTX,
                        ZIP, JPG, PNG, GIF, WEBP, MP4

                        · Max:
                        <?= formatBytes(MAX_FILE_SIZE) ?>

                    </div>

                    <div class="mt-2"
                         id="fileNameDisplay"></div>

                </div>

                <input
                    type="file"
                    class="d-none"
                    name="file"
                    id="fileInput"

                    accept="
                    .pdf,
                    .doc,
                    .docx,
                    .ppt,
                    .pptx,
                    .zip,
                    .jpg,
                    .jpeg,
                    .png,
                    .gif,
                    .webp,
                    .mp4"

                    required

                    onchange="
                    document.getElementById('fileNameDisplay').innerHTML =
                    '<span class=\'badge bg-success mt-1\'>' +
                    '<i class=\'bi bi-file-earmark-check me-1\'></i>' +
                    this.files[0].name +
                    '</span>'">

            </div>

            <div class="d-flex gap-2">

                <button type="submit"
                        class="btn btn-primary">

                    <i class="bi bi-cloud-upload-fill me-1"></i>
                    Submit Assignment

                </button>

                <a href="student_assignments.php"
                   class="btn btn-outline-secondary">

                    Cancel

                </a>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>