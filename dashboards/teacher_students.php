<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// ── Get all classes assigned to this teacher ─────────────
$myClassRows = $db->prepare(
    'SELECT c.id, c.name, c.section
     FROM teacher_classes tc
     JOIN classes c ON c.id = tc.class_id
     WHERE tc.teacher_id = ?
     ORDER BY c.name, c.section'
);
$myClassRows->execute([$user['id']]);
$myClassRows = $myClassRows->fetchAll();
$myClassIds  = array_column($myClassRows, 'id');

if (empty($myClassIds)) {
    setFlash('error', 'You have not been assigned to any class yet. Contact the Super Admin.');
    header('Location: teacher_dashboard.php'); exit;
}

// ── Active class tab ──────────────────────────────────────
// Default to first class, or whichever is in ?class_id=
$activeClassId = (int)($_GET['class_id'] ?? $myClassIds[0]);
if (!in_array($activeClassId, $myClassIds)) {
    $activeClassId = $myClassIds[0];
}

// ── Handle marks save (AJAX POST) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_marks') {
    header('Content-Type: application/json');

    $studentUserId  = (int)($_POST['student_user_id'] ?? 0);
    $examMarks      = $_POST['exam_marks']   !== '' ? (float)$_POST['exam_marks']   : null;
    $reexamMarks    = $_POST['reexam_marks'] !== '' ? (float)$_POST['reexam_marks'] : null;
    $totalExamMarks = (float)($_POST['total_exam_marks'] ?? 100);

    if ($examMarks === null) {
        echo json_encode(['success' => false, 'message' => 'Exam marks are required.']); exit;
    }
    if ($examMarks < 0 || $examMarks > $totalExamMarks) {
        echo json_encode(['success' => false, 'message' => "Exam marks must be between 0 and {$totalExamMarks}."]); exit;
    }
    if ($reexamMarks !== null && ($reexamMarks < 0 || $reexamMarks > $totalExamMarks)) {
        echo json_encode(['success' => false, 'message' => "Re-exam marks must be between 0 and {$totalExamMarks}."]); exit;
    }

    // Security: student must belong to one of teacher's classes
    if ($myClassIds) {
        $in    = implode(',', array_fill(0, count($myClassIds), '?'));
        $check = $db->prepare("SELECT s.id FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ? AND s.class_id IN ($in) AND u.status = 'active'");
        $check->execute(array_merge([$studentUserId], $myClassIds));
        if (!$check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Student not found in your classes.']); exit;
        }
    }

    $db->prepare('UPDATE students SET exam_marks=?, reexam_marks=?, total_exam_marks=? WHERE user_id=?')
       ->execute([$examMarks, $reexamMarks, $totalExamMarks, $studentUserId]);

    echo json_encode(['success' => true, 'message' => 'Marks saved.']); exit;
}

// ── Class info for active tab ─────────────────────────────
$classInfo = $db->prepare('SELECT * FROM classes WHERE id=?');
$classInfo->execute([$activeClassId]);
$classInfo = $classInfo->fetch();

// ── Students in active class ──────────────────────────────
$search = trim($_GET['search'] ?? '');
$where  = 'WHERE s.class_id = ? AND u.status = \'active\'';
$params = [$activeClassId];
if ($search) {
    $where   .= ' AND (u.full_name LIKE ? OR s.roll_number LIKE ? OR s.father_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$students = $db->prepare(
    "SELECT u.id, u.full_name, u.email,
            s.roll_number, s.father_name, s.photo,
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

// Total per class for tabs
$classCounts = [];
if ($myClassIds) {
    $in = implode(',', array_fill(0, count($myClassIds), '?'));
    $cc = $db->prepare("SELECT class_id, COUNT(*) AS cnt FROM students WHERE class_id IN ($in) GROUP BY class_id");
    $cc->execute($myClassIds);
    foreach ($cc->fetchAll() as $r) $classCounts[$r['class_id']] = $r['cnt'];
}

renderHeader('My Students', 'students');
?>

<!-- Class Tabs -->
<div class="content-card mb-4">
  <div style="padding:0 8px">
    <ul class="nav" style="border-bottom:none;gap:4px;">
      <?php foreach ($myClassRows as $cls): ?>
      <li class="nav-item">
        <a href="teacher_students.php?class_id=<?= $cls['id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>"
           class="nav-link d-flex align-items-center gap-2"
           style="font-size:.85rem;font-weight:600;padding:10px 16px;border-radius:8px;
                  <?= $cls['id'] === $activeClassId
                      ? 'background:#3b82f6;color:#fff;'
                      : 'color:#64748b;' ?>">
          <i class="bi bi-diagram-3"></i>
          <?= e($cls['name']) ?><?= $cls['section'] ? ' (' . e($cls['section']) . ')' : '' ?>
          <span class="badge <?= $cls['id'] === $activeClassId ? 'bg-white text-primary' : 'bg-primary' ?>"
                style="font-size:.7rem">
            <?= $classCounts[$cls['id']] ?? 0 ?>
          </span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<!-- Class banner -->
<div class="content-card mb-4 p-4" style="background:linear-gradient(135deg,#1e3a5f,#0f172a);border:none">
  <div class="d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <div style="width:48px;height:48px;background:rgba(59,130,246,.25);border-radius:12px;
                  display:flex;align-items:center;justify-content:center">
        <i class="bi bi-diagram-3-fill text-primary fs-5"></i>
      </div>
      <div>
        <div style="color:#94a3b8;font-size:.75rem;font-weight:600;text-transform:uppercase">Active Class</div>
        <div style="color:#fff;font-size:1.2rem;font-weight:700">
          <?= e($classInfo['name']) ?>
          <?= $classInfo['section'] ? '<span style="color:#60a5fa">(' . e($classInfo['section']) . ')</span>' : '' ?>
        </div>
      </div>
    </div>
    <div style="color:#fff;font-size:2rem;font-weight:700;text-align:right">
      <?= count($students) ?>
      <div style="font-size:.7rem;color:#94a3b8;font-weight:400">Students</div>
    </div>
  </div>
</div>

<!-- Search -->
<div class="content-card mb-3">
  <div class="card-body-custom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="class_id" value="<?= $activeClassId ?>">
      <div class="col-md-6">
        <input type="text" class="form-control" name="search"
               placeholder="Search name, roll number, father name…"
               value="<?= e($search) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
      </div>
      <?php if ($search): ?>
      <div class="col-auto">
        <a href="teacher_students.php?class_id=<?= $activeClassId ?>" class="btn btn-outline-secondary">Clear</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<div id="saveAlert" class="alert d-none mb-3" role="alert"></div>

<!-- Student Table -->
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Photo</th><th>Student</th><th>Father</th><th>Roll No.</th>
          <th>Phone</th><th>Submissions</th><th>Exam Marks</th><th>Re-Exam</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s):
          $total  = (float)($s['total_exam_marks'] ?? 100);
          $exam   = $s['exam_marks']   !== null ? (float)$s['exam_marks']   : null;
          $reexam = $s['reexam_marks'] !== null ? (float)$s['reexam_marks'] : null;
        ?>
        <tr id="row-<?= $s['id'] ?>">
          <td>
            <?php if ($s['photo'] && file_exists(UPLOAD_PHOTOS . $s['photo'])): ?>
              <img src="<?= BASE_URL ?>/uploads/photos/<?= e($s['photo']) ?>" class="table-avatar">
            <?php else: ?>
              <div class="table-avatar-placeholder"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="fw-600 small"><?= e($s['full_name']) ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= e($s['email']) ?></div>
          </td>
          <td class="small text-muted"><?= e($s['father_name'] ?? '—') ?></td>
          <td><code class="small"><?= e($s['roll_number'] ?? '—') ?></code></td>
          <td class="small text-muted"><?= e($s['phone'] ?? '—') ?></td>
          <td>
            <span class="badge bg-<?= $s['submission_count'] > 0 ? 'success' : 'secondary' ?> rounded-pill">
              <?= (int)$s['submission_count'] ?> submitted
            </span>
          </td>
          <td class="small" id="exam-cell-<?= $s['id'] ?>">
            <?php if ($exam !== null): ?>
              <span class="badge bg-primary"><?= number_format($exam, 1) ?>/<?= (int)$total ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small" id="reexam-cell-<?= $s['id'] ?>">
            <?php if ($reexam !== null): ?>
              <span class="badge bg-warning text-dark"><?= number_format($reexam, 1) ?>/<?= (int)$total ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <button type="button" class="btn btn-sm btn-outline-info btn-marks"
                    data-uid="<?= $s['id'] ?>"
                    data-name="<?= htmlspecialchars($s['full_name'], ENT_QUOTES) ?>"
                    data-exam="<?= $exam   !== null ? $exam   : '' ?>"
                    data-reexam="<?= $reexam !== null ? $reexam : '' ?>"
                    data-total="<?= $total ?>">
              <i class="bi bi-pencil-square me-1"></i>Marks
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No students in this class yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Marks Modal (unchanged) -->
<div class="modal fade" id="marksModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
    <div class="modal-content" style="background:#0f172a;border:1px solid #1e3a5f;border-radius:16px">
      <div class="modal-header" style="border-bottom:1px solid #1e3a5f">
        <div>
          <h6 class="modal-title text-white mb-0"><i class="bi bi-mortarboard me-2 text-info"></i>Enter Marks</h6>
          <div id="modalStudentName" style="font-size:.75rem;color:#94a3b8;margin-top:2px"></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" id="modalStudentId">
        <input type="hidden" id="modalTotal">
        <div class="mb-3">
          <label class="form-label small text-secondary">Total / Out of</label>
          <select class="form-select form-select-sm" id="modalTotalSelect"
                  onchange="document.getElementById('modalTotal').value=this.value;updateMaxHints();"
                  style="background:#1e293b;border-color:#334155;color:#e2e8f0;max-width:120px">
            <option value="50">50</option><option value="100" selected>100</option>
            <option value="150">150</option><option value="200">200</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small text-secondary">Exam Marks <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <input type="number" id="modalExamMarks" class="form-control" placeholder="e.g. 78"
                   min="0" step="0.5" style="background:#1e293b;border-color:#334155;color:#e2e8f0">
            <span class="input-group-text" id="examMaxHint" style="background:#1e293b;border-color:#334155;color:#64748b">/ 100</span>
          </div>
          <div id="examError" class="text-danger mt-1" style="font-size:.75rem"></div>
        </div>
        <div class="mb-1">
          <label class="form-label small text-secondary">Re-Exam Marks <span class="badge bg-secondary ms-1" style="font-size:.65rem">Optional</span></label>
          <div class="input-group input-group-sm">
            <input type="number" id="modalReexamMarks" class="form-control" placeholder="Leave blank if not taken"
                   min="0" step="0.5" style="background:#1e293b;border-color:#334155;color:#e2e8f0">
            <span class="input-group-text" id="reexamMaxHint" style="background:#1e293b;border-color:#334155;color:#64748b">/ 100</span>
          </div>
          <div id="reexamError" class="text-danger mt-1" style="font-size:.75rem"></div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid #1e3a5f">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm px-4" id="saveMarksBtn" onclick="saveMarks()">
          <i class="bi bi-floppy me-1"></i>Save Marks
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.btn-marks');
  if (!btn) return;
  document.getElementById('modalStudentId').value         = btn.dataset.uid;
  document.getElementById('modalStudentName').textContent = btn.dataset.name;
  document.getElementById('modalTotal').value             = btn.dataset.total || 100;
  document.getElementById('modalExamMarks').value         = btn.dataset.exam;
  document.getElementById('modalReexamMarks').value       = btn.dataset.reexam;
  document.getElementById('examError').textContent        = '';
  document.getElementById('reexamError').textContent      = '';
  const sel = document.getElementById('modalTotalSelect');
  sel.value = btn.dataset.total;
  if (!sel.value) sel.value = 100;
  updateMaxHints();
  new bootstrap.Modal(document.getElementById('marksModal')).show();
});

function updateMaxHints() {
  const t = document.getElementById('modalTotal').value || 100;
  document.getElementById('examMaxHint').textContent   = '/ ' + t;
  document.getElementById('reexamMaxHint').textContent = '/ ' + t;
  document.getElementById('modalExamMarks').max   = t;
  document.getElementById('modalReexamMarks').max = t;
}

function saveMarks() {
  const userId    = document.getElementById('modalStudentId').value;
  const examVal   = document.getElementById('modalExamMarks').value.trim();
  const reexamVal = document.getElementById('modalReexamMarks').value.trim();
  const total     = parseFloat(document.getElementById('modalTotal').value) || 100;
  document.getElementById('examError').textContent   = '';
  document.getElementById('reexamError').textContent = '';
  let valid = true;
  if (!examVal) { document.getElementById('examError').textContent = 'Exam marks are required.'; valid=false; }
  else if (parseFloat(examVal)<0||parseFloat(examVal)>total) { document.getElementById('examError').textContent=`Must be 0–${total}.`; valid=false; }
  if (reexamVal && (parseFloat(reexamVal)<0||parseFloat(reexamVal)>total)) { document.getElementById('reexamError').textContent=`Must be 0–${total}.`; valid=false; }
  if (!valid) return;
  const btn = document.getElementById('saveMarksBtn');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
  const data = new FormData();
  data.append('action','save_marks'); data.append('student_user_id',userId);
  data.append('exam_marks',examVal); data.append('reexam_marks',reexamVal);
  data.append('total_exam_marks',total);
  fetch(window.location.pathname+'?class_id=<?= $activeClassId ?>',{method:'POST',body:data})
    .then(r=>r.json()).then(res=>{
      btn.disabled=false; btn.innerHTML='<i class="bi bi-floppy me-1"></i>Save Marks';
      if (res.success) {
        const eb = examVal ? `<span class="badge bg-primary">${parseFloat(examVal).toFixed(1)}/${total}</span>` : '<span class="text-muted">—</span>';
        const rb = reexamVal ? `<span class="badge bg-warning text-dark">${parseFloat(reexamVal).toFixed(1)}/${total}</span>` : '<span class="text-muted">—</span>';
        document.getElementById('exam-cell-'+userId).innerHTML   = eb;
        document.getElementById('reexam-cell-'+userId).innerHTML = rb;
        bootstrap.Modal.getInstance(document.getElementById('marksModal')).hide();
        const al = document.getElementById('saveAlert');
        al.className='alert alert-success'; al.innerHTML='<i class="bi bi-check-circle me-2"></i>'+res.message;
        al.classList.remove('d-none'); setTimeout(()=>al.classList.add('d-none'),3500);
      } else { document.getElementById('examError').textContent = res.message; }
    }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="bi bi-floppy me-1"></i>Save Marks'; document.getElementById('examError').textContent='Network error.'; });
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>
