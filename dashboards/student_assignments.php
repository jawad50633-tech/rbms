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
$studentStmt = $db->prepare('SELECT class_id FROM students WHERE user_id = ?');
$studentStmt->execute([$user['id']]);
$studentInfo = $studentStmt->fetch();

$classId = $studentInfo['class_id'] ?? null;

// FIX #7 — Guard against students with no assigned class.
// A student without a class_id should not be able to view or submit assignments.
if (!$classId) {
    setFlash('warning', 'You are not assigned to a class. Please contact your administrator.');
    header('Location: dashboard.php');
    exit;
}

// ─────────────────────────────────────────────────────────────
// Assignment to Submit (GET)
// ─────────────────────────────────────────────────────────────
$submitId         = (int)($_GET['submit'] ?? 0);
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

    if ($submitAssignment) {

        // FIX #5 — Check overdue on the GET (display) path too, not only on POST.
        if (!empty($submitAssignment['due_date']) && strtotime($submitAssignment['due_date']) < time()) {
            setFlash('warning', 'The submission deadline for this assignment has passed.');
            header('Location: student_assignments.php');
            exit;
        }

        $already = $db->prepare(
            'SELECT id FROM submissions
             WHERE assignment_id = ? AND student_id = ?'
        );
        $already->execute([$submitId, $user['id']]);

        if ($already->fetch()) {
            setFlash('info', 'You have already submitted this assignment.');
            header('Location: student_assignments.php');
            exit;
        }

    } else {
        setFlash('warning', 'Assignment not found or you do not have access.');
        header('Location: student_assignments.php');
        exit;
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
        "SELECT * FROM assignments
         WHERE id = ?
           AND status = 'active'
           AND (class_id IS NULL OR class_id = ?)"
    );
    $st->execute([$assignId, $classId]);
    $assignment = $st->fetch();

    // FIX #2 — Explicit error if assignment not found; prevents falling through
    // to file operations with a null $assignment.
    if (!$assignment) {
        $errors[] = 'Assignment not found or access denied.';
    }

    // Prevent overdue submissions
    // FIX #4 — NULL due_date is intentionally treated as "no deadline".
    // Document this clearly. If a deadline is always required, enforce NOT NULL
    // at the DB schema level and remove the !empty() guard here.
    if (
        empty($errors) &&
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
             WHERE assignment_id = ? AND student_id = ?'
        );
        $chk->execute([$assignId, $user['id']]);
        if ($chk->fetch()) {
            $errors[] = 'You have already submitted this assignment.';
        }
    }

    // Validate file selected
    if (empty($errors) && empty($_FILES['file']['name'])) {
        $errors[] = 'Please select a file to upload.';
    }

    // File validation — only runs when no earlier errors exist (FIX #2 gate)
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

                // Images
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',

                // Video
                'video/mp4',
                'video/mpeg',
                // FIX #9 — finfo can report these MIME types for MPEG files
                // depending on OS/libmagic version.
                'video/x-mpeg',
                'audio/mpeg',
                'video/x-msvideo',
                'video/quicktime',
                'video/x-matroska',
                'video/webm',
                'video/x-ms-wmv',
                'video/3gpp',
                'video/x-flv',
            ];

            $allowedExts = [
                'pdf', 'doc', 'docx',
                'ppt', 'pptx',
                'zip',
                'jpg', 'jpeg', 'png', 'gif', 'webp',
                'mp4', 'mpeg', 'mpg',
                'avi', 'mov', 'mkv',
                'webm', 'wmv', '3gp', 'flv',
            ];

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($mime, $allowedMimes) || !in_array($ext, $allowedExts)) {
                $errors[] = 'Only PDF, DOC, DOCX, PPT, PPTX, ZIP, JPG, PNG, GIF, WEBP, MP4, AVI, MOV, MKV, WEBM, WMV, MPEG, 3GP and FLV files are allowed.';
            } elseif ($file['size'] > MAX_FILE_SIZE) {
                $errors[] = 'File size exceeds ' . formatBytes(MAX_FILE_SIZE) . ' limit.';
            }
        }
    }

    // Save file + DB insert — wrapped in a transaction (FIX #6)
    // File is moved only after the DB row is inserted successfully.
    // On any failure the transaction is rolled back and the temp file
    // (still in PHP's temp dir) is cleaned up automatically.
    if (empty($errors)) {

        $safeExt  = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $fileName = bin2hex(random_bytes(16)) . '.' . $safeExt;

        // FIX #10 — Store a relative sub-path (e.g. "assignments/abc.pdf")
        // so the record remains valid if the base upload root changes.
        $relPath  = 'assignments' . DIRECTORY_SEPARATOR . $fileName;
        $absPath  = rtrim(BASE_PATH . '/uploads', '/\\') . DIRECTORY_SEPARATOR . $relPath;

        // FIX #1 — UPLOAD_ASSIGNMENTS must be outside the web root.
        // If it is inside the web root, add an .htaccess in that folder:
        //   php_flag engine off
        //   Options -ExecCGI
        //   AddType application/octet-stream .php .php5 .phtml .phar
        // This code assumes the directory is already protected.

        try {
            $db->beginTransaction();

            // Insert DB row first — if this fails we never touch the filesystem.
            $insert = $db->prepare(
                'INSERT INTO submissions
                 (assignment_id, student_id, file_name, file_path, file_size)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $assignId,
                $user['id'],
                $file['name'],
                $relPath,       // FIX #10 — relative path, not bare filename
                $file['size'],
            ]);

            $newSubmissionId = $db->lastInsertId();

            // Move the file only after the DB row is committed.
            if (!move_uploaded_file($file['tmp_name'], $absPath)) {
                // File move failed — roll back the DB row.
                $db->rollBack();
                $errors[] = 'Could not save file. Check upload folder permissions.';
            } else {
                $db->commit();
                logActivity($user['id'], "Submitted assignment #{$assignId}", 'Submissions');
                setFlash('success', 'Assignment submitted successfully!');
                header('Location: student_assignments.php');
                exit;
            }

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Clean up the temp file if it was somehow moved before the exception.
            if (isset($absPath) && file_exists($absPath)) {
                unlink($absPath);
            }
            $errors[] = 'A database error occurred. Please try again.';
        }
    }
}

// ─────────────────────────────────────────────────────────────
// Fetch All Assignments
// FIX #3 — Use a distinct statement variable to avoid overwriting
// the PDOStatement with its own result. Guard against fetchAll() failure.
// ─────────────────────────────────────────────────────────────
$assignStmt = $db->prepare(
    "SELECT
        a.*,
        u.full_name AS teacher_name,
        sub.id        AS submission_id,
        sub.file_name AS submitted_file,
        sub.submitted_at,
        sub.marks,
        sub.feedback,
        sub.file_path AS submitted_path
     FROM assignments a
     JOIN users u ON u.id = a.teacher_id
     LEFT JOIN submissions sub
           ON sub.assignment_id = a.id
          AND sub.student_id    = ?
     WHERE a.status = 'active'
       AND (a.class_id IS NULL OR a.class_id = ?)
     ORDER BY a.due_date ASC"
);
$assignStmt->execute([$user['id'], $classId]);
$allAssignments = $assignStmt->fetchAll();

// Guard against fetchAll() returning false on DB error
if ($allAssignments === false) {
    $allAssignments = [];
}

$csrf = csrfToken();

renderHeader(
    $submitAssignment ? 'Submit Assignment' : 'My Assignments',
    'assignments'
);
?>

<?php if ($submitAssignment): ?>

    <!-- ── Submit Form ─────────────────────────────────────────── -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="student_assignments.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <h5 class="mb-0 fw-700">Submit Assignment</h5>
    </div>

    <!-- Assignment Info Card -->
    <div class="content-card mb-4 p-4" style="border-left:4px solid #3b82f6">
        <h6 class="fw-700 mb-1"><?= e($submitAssignment['title']) ?></h6>
        <div class="text-muted small mb-2">
            Teacher: <?= e($submitAssignment['teacher_name']) ?>
            <?php if ($submitAssignment['due_date']): ?>
                &nbsp;·&nbsp; Due: <strong><?= formatDate($submitAssignment['due_date']) ?></strong>
            <?php endif; ?>
            &nbsp;·&nbsp; Total Marks: <strong><?= (int)$submitAssignment['total_marks'] ?></strong>
        </div>
        <?php if ($submitAssignment['description']): ?>
            <div class="small" style="white-space:pre-line">
                <?= e($submitAssignment['description']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Errors -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-4">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Upload Box -->
    <div class="content-card">
        <div class="card-header-custom">
            <h6><i class="bi bi-cloud-upload me-2"></i>Upload File</h6>
        </div>
        <div class="card-body-custom">

            <form method="POST"
                  action="student_assignments.php?submit=<?= (int)$submitAssignment['id'] ?>"
                  enctype="multipart/form-data">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e($csrf) ?>">

                <input type="hidden"
                       name="assignment_id"
                       value="<?= (int)$submitAssignment['id'] ?>">

                <div class="mb-4">
                    <label class="form-label fw-600">Select File *</label>

                    <div id="dropZone"
                         class="border border-2 border-dashed rounded-3 p-5 text-center"
                         style="border-color:#232b3a!important;background:#161c29;cursor:pointer"
                         onclick="document.getElementById('fileInput').click()">

                        <i class="bi bi-cloud-upload-fill text-primary"
                           style="font-size:2.5rem"></i>

                        <div class="fw-600 mt-2 small">
                            Click to browse or drag &amp; drop
                        </div>

                        <div class="text-muted" style="font-size:.78rem">
                            Accepted: PDF, DOC, DOCX, PPT, PPTX, ZIP,
                            JPG, PNG, GIF, WEBP,
                            MP4, AVI, MOV, MKV, WEBM, WMV, MPEG, 3GP, FLV
                            &nbsp;·&nbsp; Max: <?= formatBytes(MAX_FILE_SIZE) ?>
                        </div>

                        <div class="mt-2" id="fileNameDisplay"></div>
                    </div>

                    <input type="file"
                           class="d-none"
                           name="file"
                           id="fileInput"
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,
                                   .jpg,.jpeg,.png,.gif,.webp,
                                   .mp4,.mpeg,.mpg,.avi,.mov,
                                   .mkv,.webm,.wmv,.3gp,.flv"
                           required>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
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

    <script>
    (function () {
        // FIX #11 — Client-side file size check for instant feedback
        var MAX_BYTES = <?= (int)MAX_FILE_SIZE ?>;

        var zone    = document.getElementById('dropZone');
        var input   = document.getElementById('fileInput');
        var display = document.getElementById('fileNameDisplay');

        function showFile(file) {
            // FIX #11 — Reject oversized files immediately in the browser
            if (file.size > MAX_BYTES) {
                display.innerHTML =
                    '<span class="badge bg-danger mt-1">' +
                    '<i class="bi bi-exclamation-triangle me-1"></i>' +
                    'File too large (' + formatBytes(file.size) + '). Max is <?= formatBytes(MAX_FILE_SIZE) ?>.' +
                    '</span>';
                input.value = '';
                return;
            }
            display.innerHTML =
                '<span class="badge bg-success mt-1">' +
                '<i class="bi bi-file-earmark-check me-1"></i>' +
                escHtml(file.name) + '</span>';
        }

        function escHtml(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(str));
            return d.innerHTML;
        }

        // FIX #11 — Simple byte formatter for client-side display
        function formatBytes(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024)       return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        }

        input.addEventListener('change', function () {
            if (this.files[0]) showFile(this.files[0]);
        });

        zone.addEventListener('dragover', function (e) {
            e.preventDefault();
            zone.style.background   = 'rgba(59,130,246,.10)';
            zone.style.borderColor  = '#3b82f6';
        });

        zone.addEventListener('dragleave', function () {
            zone.style.background  = '#161c29';
            zone.style.borderColor = '#232b3a';
        });

        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.style.background  = '#161c29';
            zone.style.borderColor = '#232b3a';

            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                // FIX #8 — DataTransfer assignment is not supported in all browsers.
                // Test the assignment and fall back to showing a warning if it fails.
                try {
                    var dt = new DataTransfer();
                    dt.items.add(e.dataTransfer.files[0]);
                    input.files = dt.files;

                    // Verify the assignment actually worked
                    if (input.files && input.files.length > 0) {
                        showFile(input.files[0]);
                    } else {
                        throw new Error('assignment failed');
                    }
                } catch (err) {
                    // Browser does not support programmatic FileList assignment.
                    // Show the filename visually but warn the user to use the picker.
                    display.innerHTML =
                        '<span class="badge bg-warning text-dark mt-1">' +
                        '<i class="bi bi-exclamation-circle me-1"></i>' +
                        'Drag &amp; drop not supported in this browser — please use the file picker.' +
                        '</span>';
                }
            }
        });
    })();
    </script>

<?php else: ?>

    <!-- ── Assignments List ─────────────────────────────────────── -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h5 class="mb-0 fw-700">My Assignments</h5>
    </div>

    <?php if (empty($allAssignments)): ?>

        <div class="content-card p-5 text-center text-muted">
            <i class="bi bi-journal-x" style="font-size:2.5rem"></i>
            <div class="mt-2">No assignments have been posted yet.</div>
        </div>

    <?php else: ?>

        <div class="row g-3">
        <?php foreach ($allAssignments as $a):
            $submitted = !empty($a['submission_id']);
            $overdue   = !empty($a['due_date']) && strtotime($a['due_date']) < time();
            $color     = $submitted ? '#22c55e' : ($overdue ? '#ef4444' : '#3b82f6');
        ?>
            <div class="col-12">
                <div class="content-card p-4"
                     style="border-left:4px solid <?= $color ?>">

                    <div class="d-flex flex-wrap align-items-start
                                justify-content-between gap-2">

                        <!-- Left: info -->
                        <div>
                            <h6 class="fw-700 mb-1">
                                <?= e($a['title']) ?>
                            </h6>
                            <div class="text-muted small">
                                Teacher: <?= e($a['teacher_name']) ?>
                                <?php if ($a['due_date']): ?>
                                    &nbsp;·&nbsp; Due:
                                    <strong><?= formatDate($a['due_date']) ?></strong>
                                <?php endif; ?>
                                &nbsp;·&nbsp; Marks:
                                <strong><?= (int)$a['total_marks'] ?></strong>
                            </div>
                            <?php if ($a['description']): ?>
                                <div class="small mt-1" style="white-space:pre-line">
                                    <?= e($a['description']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right: status / action -->
                        <div class="d-flex flex-column align-items-end gap-2">

                            <?php if ($submitted): ?>

                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle me-1"></i>Submitted
                                </span>

                                <?php if ($a['marks'] !== null): ?>
                                    <span class="badge bg-primary">
                                        Marks: <?= (int)$a['marks'] ?> /
                                        <?= (int)$a['total_marks'] ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($a['feedback']): ?>
                                    <div class="small text-muted fst-italic">
                                        <?= e($a['feedback']) ?>
                                    </div>
                                <?php endif; ?>

                            <?php elseif ($overdue): ?>

                                <span class="badge bg-danger">Overdue</span>

                            <?php else: ?>

                                <a href="student_assignments.php?submit=<?= (int)$a['id'] ?>"
                                   class="btn btn-primary btn-sm">
                                    <i class="bi bi-cloud-upload me-1"></i>Submit
                                </a>

                            <?php endif; ?>

                        </div>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
        </div>

    <?php endif; ?>

<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>