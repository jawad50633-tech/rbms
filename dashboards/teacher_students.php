<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// ── Get teacher's assigned class ──────────────────────────────────────────
$teacherRow = $db->prepare('SELECT class_id FROM users WHERE id=? AND role="teacher"');
$teacherRow->execute([$user['id']]);
$teacherRow = $teacherRow->fetch();
$myClassId  = $teacherRow['class_id'] ?? null;

if (!$myClassId) {
    setFlash('error', 'You have not been assigned to a class yet. Contact the Super Admin.');
    header('Location: teacher_dashboard.php');
    exit;
}

// ── Handle marks save (AJAX POST) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_marks') {
    header('Content-Type: application/json');

    $studentUserId   = (int)($_POST['student_user_id'] ?? 0);
    $examMarks       = $_POST['exam_marks'] !== '' ? (float)$_POST['exam_marks'] : null;
    $reexamMarks     = $_POST['reexam_marks'] !== '' ? (float)$_POST['reexam_marks'] : null;
    $totalExamMarks  = (float)($_POST['total_exam_marks'] ?? 100);

    // Validate: exam_marks is required
    if ($examMarks === null) {
        echo json_encode(['success' => false, 'message' => 'Exam marks are required.']);
        exit;
    }

    // Validate ranges
    if ($examMarks < 0 || $examMarks > $totalExamMarks) {
        echo json_encode(['success' => false, 'message' => "Exam marks must be between 0 and {$totalExamMarks}."]);
        exit;
    }
    if ($reexamMarks !== null && ($reexamMarks < 0 || $reexamMarks > $totalExamMarks)) {
        echo json_encode(['success' => false, 'message' => "Re-exam marks must be between 0 and {$totalExamMarks}."]);
        exit;
    }

    // Security: make sure this student actually belongs to teacher's class
    $check = $db->prepare(
        'SELECT s.id FROM students s
         JOIN users u ON u.id = s.user_id
         WHERE s.user_id = ? AND s.class_id = ? AND u.status = "active"'
    );
    $check->execute([$studentUserId, $myClassId]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Student not found in your class.']);
        exit;
    }

    $upd = $db->prepare(
        'UPDATE students
         SET exam_marks = ?, reexam_marks = ?, total_exam_marks = ?
         WHERE user_id = ?'
    );
    $upd->execute([$examMarks, $reexamMarks, $totalExamMarks, $studentUserId]);

    echo json_encode(['success' => true, 'message' => 'Marks saved successfully.']);
    exit;
}

// ── Class info ────────────────────────────────────────────────────────────
$classInfo = $db->prepare('SELECT * FROM classes WHERE id=?');
$classInfo->execute([$myClassId]);
$classInfo = $classInfo->fetch();

// ── Students in teacher's class ───────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$where  = 'WHERE s.class_id = ? AND u.status = \'active\'';
$params = [$myClassId];
if ($search) {
    $where   .= ' AND (u.full_name LIKE ? OR s.roll_number LIKE ? OR s.father_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$students = $db->prepare(
    "SELECT u.id, u.full_name, u.email,
            s.roll_number, s.father_name, s.photo, s.date_of_birth, s.phone,
            s.exam_marks, s.reexam_marks, s.total_exam_marks,
            (SELECT COUNT(*) FROM submissions sub
             JOIN assignments a ON a.id = sub.assignment_id
             WHERE sub.student_id = u.id AND a.teacher_id = ?) AS submission_count
     FROM users u
     JOIN students s ON s.user_id = u.id
     $where
     ORDER BY u.full_name ASC"
);
$students->execute(array_merge([$user['id']], $params));
$students = $students->fetchAll();

renderHeader('My Students', 'students');
?>

<!-- Class banner -->
<div class="content-card mb-4 p-4"
     style="background:linear-gradient(135deg,#1e3a5f,#0f172a);border:none">
  <div class="d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <div style="width:48px;height:48px;background:rgba(59,130,246,.25);border-radius:12px;
                  display:flex;align-items:center;justify-content:center">
        <i class="bi bi-diagram-3-fill text-primary fs-5"></i>
      </div>
      <div>
        <div style="color:#94a3b8;font-size:.75rem;font-weight:600;text-transform:uppercase">
          Your Class
        </div>
        <div style="color:#fff;font-size:1.2rem;font-weight:700">
          <?= e($classInfo['name']) ?>
          <?= $classInfo['section']
              ? '<span style="color:#60a5fa">(' . e($classInfo['section']) . ')</span>'
              : '' ?>
        </div>
      </div>
    </div>
    <div style="color:#fff;font-size:2rem;font-weight:700">
      <?= count($students) ?>
      <div style="font-size:.7rem;color:#94a3b8;font-weight:400">Students</div>
    </div>
  </div>
</div>

<!-- Search -->
<div class="content-card mb-3">
  <div class="card-body-custom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-6">
        <input type="text" class="form-control" name="search"
               placeholder="Search name, roll number, father name…"
               value="<?= e($search) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Search
        </button>
      </div>
      <?php if ($search): ?>
      <div class="col-auto">
        <a href="teacher_students.php" class="btn btn-outline-secondary">Clear</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Flash / save feedback -->
<div id="saveAlert" class="alert d-none mb-3" role="alert"></div>

<!-- Student List -->
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Photo</th>
          <th>Student</th>
          <th>Father</th>
          <th>Roll No.</th>
          <th>Phone</th>
          <th>Submissions</th>
          <th>Exam Marks</th>
          <th>Re-Exam</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s):
          $total  = (float)($s['total_exam_marks'] ?? 100);
          $exam   = $s['exam_marks']   !== null ? (float)$s['exam_marks']   : null;
          $reexam = $s['reexam_marks'] !== null ? (float)$s['reexam_marks'] : null;
        ?>
        <tr id="row-<?= $s['id'] ?>">

          <!-- Photo -->
          <td>
            <?php if ($s['photo'] && file_exists(UPLOAD_PHOTOS . $s['photo'])): ?>
              <img src="<?= BASE_URL ?>/uploads/photos/<?= e($s['photo']) ?>"
                   class="table-avatar">
            <?php else: ?>
              <div class="table-avatar-placeholder">
                <?= strtoupper(substr($s['full_name'], 0, 1)) ?>
              </div>
            <?php endif; ?>
          </td>

          <!-- Name / email -->
          <td>
            <div class="fw-600 small"><?= e($s['full_name']) ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= e($s['email']) ?></div>
          </td>

          <td class="small text-muted"><?= e($s['father_name'] ?? '—') ?></td>
          <td><code class="small"><?= e($s['roll_number'] ?? '—') ?></code></td>
          <td class="small text-muted"><?= e($s['phone'] ?? '—') ?></td>

          <!-- Submissions badge -->
          <td>
            <span class="badge bg-<?= $s['submission_count'] > 0 ? 'success' : 'secondary' ?> rounded-pill">
              <?= (int)$s['submission_count'] ?> submitted
            </span>
          </td>

          <!-- Exam marks display -->
          <td class="small" id="exam-cell-<?= $s['id'] ?>">
            <?php if ($exam !== null): ?>
              <span class="badge bg-primary">
                <?= number_format($exam, 1) ?>/<?= (int)$total ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>

          <!-- Re-exam marks display -->
          <td class="small" id="reexam-cell-<?= $s['id'] ?>">
            <?php if ($reexam !== null): ?>
              <span class="badge bg-warning text-dark">
                <?= number_format($reexam, 1) ?>/<?= (int)$total ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>

          <!-- Marks button -->
          <td>
            <button type="button"
                    class="btn btn-sm btn-outline-info btn-marks"
                    data-uid="<?= $s['id'] ?>"
                    data-name="<?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                    data-exam="<?= $exam   !== null ? $exam   : '' ?>"
                    data-reexam="<?= $reexam !== null ? $reexam : '' ?>"
                    data-total="<?= $total ?>">
              <i class="bi bi-pencil-square me-1"></i>Marks
            </button>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php if (empty($students)): ?>
        <tr>
          <td colspan="9" class="text-center text-muted py-4">
            No students in your class yet.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Marks Modal ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="marksModal" tabindex="-1" aria-labelledby="marksModalLabel"
     aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
    <div class="modal-content"
         style="background:#0f172a;border:1px solid #1e3a5f;border-radius:16px">

      <!-- Header -->
      <div class="modal-header" style="border-bottom:1px solid #1e3a5f">
        <div>
          <h6 class="modal-title text-white mb-0" id="marksModalLabel">
            <i class="bi bi-mortarboard me-2 text-info"></i>Enter Marks
          </h6>
          <div id="modalStudentName"
               style="font-size:.75rem;color:#94a3b8;margin-top:2px"></div>
        </div>
        <button type="button" class="btn-close btn-close-white"
                data-bs-dismiss="modal"></button>
      </div>

      <!-- Body -->
      <div class="modal-body p-4">

        <input type="hidden" id="modalStudentId">
        <input type="hidden" id="modalTotal">

        <!-- Total marks selector -->
        <div class="mb-3">
          <label class="form-label small text-secondary">Total / Out of</label>
          <select class="form-select form-select-sm" id="modalTotalSelect"
                  onchange="document.getElementById('modalTotal').value = this.value;
                            updateMaxHints();"
                  style="background:#1e293b;border-color:#334155;color:#e2e8f0;
                         max-width:120px">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="150">150</option>
            <option value="200">200</option>
          </select>
        </div>

        <!-- Exam marks (required) -->
        <div class="mb-3">
          <label class="form-label small text-secondary" for="modalExamMarks">
            Exam Marks <span class="text-danger">*</span>
          </label>
          <div class="input-group input-group-sm">
            <input type="number" id="modalExamMarks"
                   class="form-control"
                   placeholder="e.g. 78"
                   min="0" step="0.5"
                   required
                   style="background:#1e293b;border-color:#334155;color:#e2e8f0">
            <span class="input-group-text" id="examMaxHint"
                  style="background:#1e293b;border-color:#334155;color:#64748b">
              / 100
            </span>
          </div>
          <div id="examError" class="text-danger mt-1" style="font-size:.75rem"></div>
        </div>

        <!-- Re-exam marks (optional) -->
        <div class="mb-1">
          <label class="form-label small text-secondary" for="modalReexamMarks">
            Re-Exam Marks
            <span class="badge bg-secondary ms-1" style="font-size:.65rem">Optional</span>
          </label>
          <div class="input-group input-group-sm">
            <input type="number" id="modalReexamMarks"
                   class="form-control"
                   placeholder="Leave blank if not taken"
                   min="0" step="0.5"
                   style="background:#1e293b;border-color:#334155;color:#e2e8f0">
            <span class="input-group-text" id="reexamMaxHint"
                  style="background:#1e293b;border-color:#334155;color:#64748b">
              / 100
            </span>
          </div>
          <div id="reexamError" class="text-danger mt-1" style="font-size:.75rem"></div>
        </div>
        <div style="font-size:.72rem;color:#64748b" class="mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Only fill re-exam marks if the student appeared for a re-examination.
        </div>

      </div>

      <!-- Footer -->
      <div class="modal-footer" style="border-top:1px solid #1e3a5f">
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm px-4" id="saveMarksBtn"
                onclick="saveMarks()">
          <i class="bi bi-floppy me-1"></i>Save Marks
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ── JavaScript ───────────────────────────────────────────────────────── -->
<script>
// Open modal via data-attributes — safe against apostrophes/special chars in names
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.btn-marks');
  if (!btn) return;

  const userId = btn.dataset.uid;
  const name   = btn.dataset.name;
  const exam   = btn.dataset.exam;
  const reexam = btn.dataset.reexam;
  const total  = parseFloat(btn.dataset.total) || 100;

  document.getElementById('modalStudentId').value         = userId;
  document.getElementById('modalStudentName').textContent = name;
  document.getElementById('modalTotal').value             = total;

  // Set total select (fallback to 100 if value not in list)
  const sel = document.getElementById('modalTotalSelect');
  sel.value = total;
  if (parseFloat(sel.value) !== total) sel.value = 100;

  document.getElementById('modalExamMarks').value   = exam;
  document.getElementById('modalReexamMarks').value = reexam;

  // Clear any previous errors
  document.getElementById('examError').textContent   = '';
  document.getElementById('reexamError').textContent = '';

  updateMaxHints();

  // Compatible with both Bootstrap 4 and Bootstrap 5
  if (typeof bootstrap !== 'undefined') {
    new bootstrap.Modal(document.getElementById('marksModal')).show();
  } else {
    $('#marksModal').modal('show');
  }
});

function updateMaxHints() {
  const total = document.getElementById('modalTotal').value || 100;
  document.getElementById('examMaxHint').textContent   = '/ ' + total;
  document.getElementById('reexamMaxHint').textContent = '/ ' + total;
  document.getElementById('modalExamMarks').max   = total;
  document.getElementById('modalReexamMarks').max = total;
}

function saveMarks() {
  const userId      = document.getElementById('modalStudentId').value;
  const examVal     = document.getElementById('modalExamMarks').value.trim();
  const reexamVal   = document.getElementById('modalReexamMarks').value.trim();
  const total       = parseFloat(document.getElementById('modalTotal').value) || 100;

  // Clear old errors
  document.getElementById('examError').textContent   = '';
  document.getElementById('reexamError').textContent = '';

  // Client-side validation
  let valid = true;
  if (examVal === '') {
    document.getElementById('examError').textContent = 'Exam marks are required.';
    valid = false;
  } else if (parseFloat(examVal) < 0 || parseFloat(examVal) > total) {
    document.getElementById('examError').textContent = `Must be between 0 and ${total}.`;
    valid = false;
  }
  if (reexamVal !== '' && (parseFloat(reexamVal) < 0 || parseFloat(reexamVal) > total)) {
    document.getElementById('reexamError').textContent = `Must be between 0 and ${total}.`;
    valid = false;
  }
  if (!valid) return;

  // Disable button while saving
  const btn = document.getElementById('saveMarksBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

  const data = new FormData();
  data.append('action',          'save_marks');
  data.append('student_user_id', userId);
  data.append('exam_marks',      examVal);
  data.append('reexam_marks',    reexamVal);
  data.append('total_exam_marks', total);

  fetch(window.location.pathname, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save Marks';

      if (res.success) {
        // Update table cells without reload
        const examBadge = examVal !== ''
          ? `<span class="badge bg-primary">${parseFloat(examVal).toFixed(1)}/${total}</span>`
          : '<span class="text-muted">—</span>';

        const reexamBadge = reexamVal !== ''
          ? `<span class="badge bg-warning text-dark">${parseFloat(reexamVal).toFixed(1)}/${total}</span>`
          : '<span class="text-muted">—</span>';

        document.getElementById('exam-cell-'   + userId).innerHTML = examBadge;
        document.getElementById('reexam-cell-' + userId).innerHTML = reexamBadge;

        // Close modal
        // Hide modal — compatible with Bootstrap 4 and 5
        if (typeof bootstrap !== 'undefined') {
          bootstrap.Modal.getInstance(document.getElementById('marksModal')).hide();
        } else {
          $('#marksModal').modal('hide');
        }

        // Show success alert
        const alert = document.getElementById('saveAlert');
        alert.className = 'alert alert-success';
        alert.innerHTML = '<i class="bi bi-check-circle me-2"></i>' + res.message;
        alert.classList.remove('d-none');
        setTimeout(() => alert.classList.add('d-none'), 3500);
      } else {
        // Show server-side error inside modal
        document.getElementById('examError').textContent = res.message;
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save Marks';
      document.getElementById('examError').textContent = 'Network error. Please try again.';
    });
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>