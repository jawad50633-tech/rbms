<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// ── Helper: confirm student is in this teacher's class ────────────────────
function verifyStudentInClass(PDO $db, int $studentUserId, int $classId): bool {
    $st = $db->prepare(
        'SELECT 1 FROM students s
         JOIN users u ON u.id = s.user_id
         WHERE s.user_id = ? AND s.class_id = ? AND u.status = "active"'
    );
    $st->execute([$studentUserId, $classId]);
    return (bool) $st->fetch();
}

// ── Get teacher's assigned class ──────────────────────────────────────────
$teacherRow = $db->prepare('SELECT class_id FROM users WHERE id = ? AND role = "teacher"');
$teacherRow->execute([$user['id']]);
$teacherRow = $teacherRow->fetch();
$myClassId  = $teacherRow['class_id'] ?? null;

if (!$myClassId) {
    setFlash('error', 'You have not been assigned to a class yet. Contact the Super Admin.');
    header('Location: teacher_dashboard.php');
    exit;
}

// ── AJAX / POST handlers ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── Legacy single-record marks (kept for backward-compat) ─────────────
    if ($action === 'save_marks') {
        $studentUserId  = (int)($_POST['student_user_id']  ?? 0);
        $examMarks      = $_POST['exam_marks']   !== '' ? (float)$_POST['exam_marks']   : null;
        $reexamMarks    = $_POST['reexam_marks']  !== '' ? (float)$_POST['reexam_marks']  : null;
        $totalExamMarks = (float)($_POST['total_exam_marks'] ?? 100);

        if ($examMarks === null) {
            echo json_encode(['success' => false, 'message' => 'Exam marks are required.']); exit;
        }
        if ($examMarks < 0 || $examMarks > $totalExamMarks) {
            echo json_encode(['success' => false, 'message' => "Exam marks must be 0–{$totalExamMarks}."]); exit;
        }
        if ($reexamMarks !== null && ($reexamMarks < 0 || $reexamMarks > $totalExamMarks)) {
            echo json_encode(['success' => false, 'message' => "Re-exam marks must be 0–{$totalExamMarks}."]); exit;
        }
        if (!verifyStudentInClass($db, $studentUserId, $myClassId)) {
            echo json_encode(['success' => false, 'message' => 'Student not found in your class.']); exit;
        }
        $db->prepare('UPDATE students SET exam_marks=?, reexam_marks=?, total_exam_marks=? WHERE user_id=?')
           ->execute([$examMarks, $reexamMarks, $totalExamMarks, $studentUserId]);
        echo json_encode(['success' => true, 'message' => 'Marks saved.']); exit;
    }

    // ── GET exam scores ───────────────────────────────────────────────────
    if ($action === 'get_exam_scores') {
        $uid = (int)($_POST['student_user_id'] ?? 0);
        if (!verifyStudentInClass($db, $uid, $myClassId)) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']); exit;
        }
        $st = $db->prepare(
            'SELECT id,
                    exam_name       AS score_name,
                    marks_obtained,
                    total_marks,
                    exam_date       AS score_date
             FROM exam_scores
             WHERE student_user_id = ?
             ORDER BY exam_date DESC, created_at DESC'
        );
        $st->execute([$uid]);
        echo json_encode(['success' => true, 'scores' => $st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    // ── ADD exam score ─────────────────────────────────────────────────────
    if ($action === 'add_exam_score') {
        $uid        = (int)($_POST['student_user_id'] ?? 0);
        $name       = trim($_POST['score_name']      ?? '');
        $marks      = trim($_POST['marks_obtained']  ?? '');
        $total      = (float)($_POST['total_marks']  ?? 100);
        $date       = trim($_POST['score_date']      ?? '') ?: null;

        if (!verifyStudentInClass($db, $uid, $myClassId)) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']); exit;
        }
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Exam name is required.']); exit;
        }
        if ($marks === '' || !is_numeric($marks)) {
            echo json_encode(['success' => false, 'message' => 'Marks obtained are required.']); exit;
        }
        $marks = (float)$marks;
        if ($marks < 0 || $marks > $total) {
            echo json_encode(['success' => false, 'message' => "Marks must be 0–{$total}."]); exit;
        }
        $db->prepare(
            'INSERT INTO exam_scores (student_user_id, teacher_id, exam_name, marks_obtained, total_marks, exam_date)
             VALUES (?,?,?,?,?,?)'
        )->execute([$uid, $user['id'], $name, $marks, $total, $date]);
        echo json_encode(['success' => true, 'message' => 'Exam score added.', 'id' => (int)$db->lastInsertId()]); exit;
    }

    // ── DELETE exam score ──────────────────────────────────────────────────
    if ($action === 'delete_exam_score') {
        $scoreId = (int)($_POST['score_id'] ?? 0);
        $st = $db->prepare('DELETE FROM exam_scores WHERE id = ? AND teacher_id = ?');
        $st->execute([$scoreId, $user['id']]);
        echo json_encode($st->rowCount() > 0
            ? ['success' => true,  'message' => 'Score deleted.']
            : ['success' => false, 'message' => 'Not found or permission denied.']
        ); exit;
    }

    // ── GET test scores ───────────────────────────────────────────────────
    if ($action === 'get_test_scores') {
        $uid = (int)($_POST['student_user_id'] ?? 0);
        if (!verifyStudentInClass($db, $uid, $myClassId)) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']); exit;
        }
        $st = $db->prepare(
            'SELECT id,
                    test_name       AS score_name,
                    marks_obtained,
                    total_marks,
                    test_date       AS score_date
             FROM test_scores
             WHERE student_user_id = ?
             ORDER BY test_date DESC, created_at DESC'
        );
        $st->execute([$uid]);
        echo json_encode(['success' => true, 'scores' => $st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    // ── ADD test score ─────────────────────────────────────────────────────
    if ($action === 'add_test_score') {
        $uid   = (int)($_POST['student_user_id'] ?? 0);
        $name  = trim($_POST['score_name']      ?? '');
        $marks = trim($_POST['marks_obtained']  ?? '');
        $total = (float)($_POST['total_marks']  ?? 50);
        $date  = trim($_POST['score_date']      ?? '') ?: null;

        if (!verifyStudentInClass($db, $uid, $myClassId)) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']); exit;
        }
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Test name is required.']); exit;
        }
        if ($marks === '' || !is_numeric($marks)) {
            echo json_encode(['success' => false, 'message' => 'Marks obtained are required.']); exit;
        }
        $marks = (float)$marks;
        if ($marks < 0 || $marks > $total) {
            echo json_encode(['success' => false, 'message' => "Marks must be 0–{$total}."]); exit;
        }
        $db->prepare(
            'INSERT INTO test_scores (student_user_id, teacher_id, test_name, marks_obtained, total_marks, test_date)
             VALUES (?,?,?,?,?,?)'
        )->execute([$uid, $user['id'], $name, $marks, $total, $date]);
        echo json_encode(['success' => true, 'message' => 'Test score added.', 'id' => (int)$db->lastInsertId()]); exit;
    }

    // ── DELETE test score ──────────────────────────────────────────────────
    if ($action === 'delete_test_score') {
        $scoreId = (int)($_POST['score_id'] ?? 0);
        $st = $db->prepare('DELETE FROM test_scores WHERE id = ? AND teacher_id = ?');
        $st->execute([$scoreId, $user['id']]);
        echo json_encode($st->rowCount() > 0
            ? ['success' => true,  'message' => 'Score deleted.']
            : ['success' => false, 'message' => 'Not found or permission denied.']
        ); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}

// ── Class info ────────────────────────────────────────────────────────────
$classInfo = $db->prepare('SELECT * FROM classes WHERE id = ?');
$classInfo->execute([$myClassId]);
$classInfo = $classInfo->fetch();

// ── Students in teacher's class ───────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$where  = "WHERE s.class_id = ? AND u.status = 'active'";
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
             WHERE sub.student_id = u.id AND a.teacher_id = ?) AS submission_count,
            (SELECT COUNT(*) FROM exam_scores es
             WHERE es.student_user_id = u.id AND es.teacher_id = ?)  AS exam_score_count,
            (SELECT COUNT(*) FROM test_scores ts
             WHERE ts.student_user_id = u.id AND ts.teacher_id = ?)  AS test_score_count
     FROM users u
     JOIN students s ON s.user_id = u.id
     $where
     ORDER BY u.full_name ASC"
);
$students->execute(array_merge([$user['id'], $user['id'], $user['id']], $params));
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
          <th>Exam Scores</th>
          <th>Test Scores</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
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

          <!-- Exam Scores -->
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="badge rounded-pill bg-info text-dark"
                    id="exam-count-<?= $s['id'] ?>"
                    style="min-width:24px">
                <?= (int)$s['exam_score_count'] ?>
              </span>
              <button type="button"
                      class="btn btn-sm btn-outline-info btn-score"
                      data-type="exam"
                      data-uid="<?= $s['id'] ?>"
                      data-name="<?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                      title="Manage Exam Scores">
                <i class="bi bi-journal-text me-1"></i>Manage
              </button>
            </div>
          </td>

          <!-- Test Scores -->
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="badge rounded-pill bg-warning text-dark"
                    id="test-count-<?= $s['id'] ?>"
                    style="min-width:24px">
                <?= (int)$s['test_score_count'] ?>
              </span>
              <button type="button"
                      class="btn btn-sm btn-outline-warning btn-score"
                      data-type="test"
                      data-uid="<?= $s['id'] ?>"
                      data-name="<?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                      title="Manage Test Scores">
                <i class="bi bi-clipboard-check me-1"></i>Manage
              </button>
            </div>
          </td>

        </tr>
        <?php endforeach; ?>

        <?php if (empty($students)): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">
            No students in your class yet.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     SCORES MODAL  (shared for both Exam Scores and Test Scores)
═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="scoresModal" tabindex="-1"
     aria-labelledby="scoresModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"
       style="max-width:680px">
    <div class="modal-content"
         style="background:#0f172a;border:1px solid #1e3a5f;border-radius:16px">

      <!-- Header -->
      <div class="modal-header" style="border-bottom:1px solid #1e3a5f">
        <div>
          <h6 class="modal-title text-white mb-0" id="scoresModalLabel">
            <i class="bi bi-journal-text me-2" id="scoresModalIcon"></i>
            <span id="scoresModalTitle">Scores</span>
          </h6>
          <div id="scoresModalStudent"
               style="font-size:.75rem;color:#94a3b8;margin-top:2px"></div>
        </div>
        <button type="button" class="btn-close btn-close-white"
                data-bs-dismiss="modal"></button>
      </div>

      <!-- Body -->
      <div class="modal-body p-4">

        <!-- Existing scores list -->
        <div id="scoresListWrapper">
          <div class="text-center py-3">
            <span class="spinner-border spinner-border-sm text-primary"></span>
          </div>
        </div>

        <!-- Divider -->
        <hr style="border-color:#1e3a5f;margin:1.2rem 0">

        <!-- Add new score form -->
        <div class="p-3 rounded-3" style="background:#1e293b;border:1px solid #334155">
          <div class="fw-600 small mb-3" style="color:#e2e8f0">
            <i class="bi bi-plus-circle me-1 text-success"></i>
            Add New <span id="addFormLabel">Score</span>
          </div>

          <div class="row g-2">
            <!-- Score name -->
            <div class="col-12 col-md-4">
              <input type="text" id="newScoreName" class="form-control form-control-sm"
                     placeholder="e.g. Mid Term"
                     style="background:#0f172a;border-color:#475569;color:#e2e8f0">
              <div id="scoreNameError" class="text-danger mt-1" style="font-size:.72rem"></div>
            </div>
            <!-- Marks obtained -->
            <div class="col-6 col-md-2">
              <input type="number" id="newMarksObtained" class="form-control form-control-sm"
                     placeholder="Marks" min="0" step="0.5"
                     style="background:#0f172a;border-color:#475569;color:#e2e8f0">
              <div id="marksObtainedError" class="text-danger mt-1" style="font-size:.72rem"></div>
            </div>
            <!-- Total marks -->
            <div class="col-6 col-md-2">
              <input type="number" id="newTotalMarks" class="form-control form-control-sm"
                     placeholder="Total" min="1" step="1" value="100"
                     style="background:#0f172a;border-color:#475569;color:#e2e8f0">
            </div>
            <!-- Date -->
            <div class="col-12 col-md-2">
              <input type="date" id="newScoreDate" class="form-control form-control-sm"
                     style="background:#0f172a;border-color:#475569;color:#e2e8f0">
            </div>
            <!-- Add button -->
            <div class="col-12 col-md-2">
              <button type="button" class="btn btn-success btn-sm w-100" id="addScoreBtn"
                      onclick="submitAddScore()">
                <i class="bi bi-plus-lg me-1"></i>Add
              </button>
            </div>
          </div>
        </div>

      </div><!-- /modal-body -->

      <!-- Footer -->
      <div class="modal-footer" style="border-top:1px solid #1e3a5f">
        <small class="text-muted me-auto" id="scoresFooterNote"></small>
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">Close</button>
      </div>

    </div><!-- /modal-content -->
  </div><!-- /modal-dialog -->
</div><!-- /modal -->


<!-- ══════════════════════════  JAVASCRIPT  ══════════════════════════════ -->
<script>
// ── Module state ──────────────────────────────────────────────────────────
const scoreState = { type: 'exam', uid: null, name: '' };

// ── Helpers ───────────────────────────────────────────────────────────────
function esc(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function gradeInfo(pct) {
  if (pct >= 90) return { label: 'A+', color: '#4ade80' };
  if (pct >= 80) return { label: 'A',  color: '#86efac' };
  if (pct >= 70) return { label: 'B',  color: '#60a5fa' };
  if (pct >= 60) return { label: 'C',  color: '#fbbf24' };
  if (pct >= 50) return { label: 'D',  color: '#fb923c' };
  return            { label: 'F',  color: '#f87171' };
}

function showGlobalAlert(type, msg) {
  const el = document.getElementById('saveAlert');
  el.className = 'alert alert-' + type;
  el.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>${esc(msg)}`;
  el.classList.remove('d-none');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.add('d-none'), 4000);
}

// ── Open modal ────────────────────────────────────────────────────────────
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.btn-score');
  if (!btn) return;

  const type = btn.dataset.type;  // 'exam' | 'test'
  const uid  = btn.dataset.uid;
  const name = btn.dataset.name;

  const isExam = type === 'exam';
  scoreState.type = type;
  scoreState.uid  = uid;
  scoreState.name = name;

  // Modal header
  document.getElementById('scoresModalTitle').textContent =
    isExam ? 'Exam Scores' : 'Test Scores';
  document.getElementById('scoresModalIcon').className =
    isExam ? 'bi bi-mortarboard me-2 text-info'
           : 'bi bi-clipboard-check me-2 text-warning';
  document.getElementById('scoresModalStudent').textContent = name;
  document.getElementById('addFormLabel').textContent =
    isExam ? 'Exam Score' : 'Test Score';
  document.getElementById('scoresFooterNote').textContent =
    isExam ? 'Exam scores are visible on the student dashboard.'
           : 'Test scores are visible on the student dashboard.';

  // Reset add-form
  document.getElementById('newScoreName').placeholder =
    isExam ? 'e.g. Mid Term, Final' : 'e.g. Unit Test 1, Quiz';
  document.getElementById('newTotalMarks').value = isExam ? '100' : '50';
  ['newScoreName','newMarksObtained','newScoreDate'].forEach(id =>
    document.getElementById(id).value = '');
  ['scoreNameError','marksObtainedError'].forEach(id =>
    document.getElementById(id).textContent = '');

  loadScores();

  const modalEl = document.getElementById('scoresModal');
  if (typeof bootstrap !== 'undefined') {
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  } else { $('#scoresModal').modal('show'); }
});

// ── Load scores list ──────────────────────────────────────────────────────
function loadScores() {
  const wrapper = document.getElementById('scoresListWrapper');
  wrapper.innerHTML =
    '<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary"></span></div>';

  const fd = new FormData();
  fd.append('action',          scoreState.type === 'exam' ? 'get_exam_scores' : 'get_test_scores');
  fd.append('student_user_id', scoreState.uid);

  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) renderScoresList(res.scores);
      else wrapper.innerHTML =
        `<div class="text-danger small">${esc(res.message)}</div>`;
    })
    .catch(() => {
      wrapper.innerHTML = '<div class="text-danger small">Failed to load scores.</div>';
    });
}

// ── Render existing scores ────────────────────────────────────────────────
function renderScoresList(scores) {
  const wrapper = document.getElementById('scoresListWrapper');
  const label   = scoreState.type === 'exam' ? 'Exam' : 'Test';

  if (!scores.length) {
    wrapper.innerHTML =
      `<div class="text-center py-3" style="color:#64748b">
         <i class="bi bi-inbox fs-3 d-block mb-2"></i>
         No ${label.toLowerCase()} scores recorded yet.
       </div>`;
    return;
  }

  let html = `
    <div class="table-responsive">
      <table class="table table-sm mb-0" style="font-size:.82rem">
        <thead>
          <tr style="color:#94a3b8;border-color:#1e3a5f">
            <th>${label} Name</th>
            <th>Marks</th>
            <th style="min-width:110px">Progress</th>
            <th>Grade</th>
            <th>Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="scoresTableBody">`;

  scores.forEach(s => {
    const pct  = s.total_marks > 0 ? (s.marks_obtained / s.total_marks * 100) : 0;
    const gi   = gradeInfo(pct);
    const date = s.score_date || '—';
    html += `
      <tr style="border-color:#1e3a5f" id="score-row-${s.id}">
        <td class="text-white align-middle fw-600">${esc(s.score_name)}</td>
        <td class="align-middle">
          <span class="badge bg-primary">
            ${parseFloat(s.marks_obtained).toFixed(1)}/${parseFloat(s.total_marks).toFixed(0)}
          </span>
        </td>
        <td class="align-middle">
          <div class="d-flex align-items-center gap-2">
            <div class="progress flex-grow-1"
                 style="height:5px;background:#1e293b;border-radius:99px">
              <div class="progress-bar"
                   style="width:${Math.min(100,Math.round(pct))}%;
                          background:${gi.color};border-radius:99px"></div>
            </div>
            <span style="font-size:.7rem;color:#94a3b8;min-width:34px">
              ${pct.toFixed(0)}%
            </span>
          </div>
        </td>
        <td class="align-middle">
          <span class="badge rounded-pill px-2"
                style="background:${gi.color}22;color:${gi.color};
                       border:1px solid ${gi.color}55;font-size:.7rem">
            ${gi.label}
          </span>
        </td>
        <td class="align-middle text-muted small">${esc(date)}</td>
        <td class="align-middle text-end">
          <button class="btn btn-sm btn-outline-danger py-0 px-2"
                  style="font-size:.72rem"
                  onclick="deleteScore(${s.id})"
                  title="Delete">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`;
  });

  html += '</tbody></table></div>';
  wrapper.innerHTML = html;
}

// ── Add new score ─────────────────────────────────────────────────────────
function submitAddScore() {
  const name  = document.getElementById('newScoreName').value.trim();
  const marks = document.getElementById('newMarksObtained').value.trim();
  const total = parseFloat(document.getElementById('newTotalMarks').value) || 100;
  const date  = document.getElementById('newScoreDate').value;

  // Clear errors
  document.getElementById('scoreNameError').textContent    = '';
  document.getElementById('marksObtainedError').textContent = '';

  let valid = true;
  if (!name) {
    document.getElementById('scoreNameError').textContent =
      (scoreState.type === 'exam' ? 'Exam' : 'Test') + ' name is required.';
    valid = false;
  }
  if (!marks || isNaN(parseFloat(marks))) {
    document.getElementById('marksObtainedError').textContent = 'Marks are required.';
    valid = false;
  } else if (parseFloat(marks) < 0 || parseFloat(marks) > total) {
    document.getElementById('marksObtainedError').textContent = `Must be 0–${total}.`;
    valid = false;
  }
  if (!valid) return;

  const btn = document.getElementById('addScoreBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

  const fd = new FormData();
  fd.append('action',          scoreState.type === 'exam' ? 'add_exam_score' : 'add_test_score');
  fd.append('student_user_id', scoreState.uid);
  fd.append('score_name',      name);
  fd.append('marks_obtained',  marks);
  fd.append('total_marks',     total);
  fd.append('score_date',      date);

  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
      if (res.success) {
        // Update count badge in main table
        const countId = scoreState.type === 'exam'
          ? `exam-count-${scoreState.uid}`
          : `test-count-${scoreState.uid}`;
        const countEl = document.getElementById(countId);
        if (countEl) countEl.textContent = parseInt(countEl.textContent || 0) + 1;

        // Clear form
        document.getElementById('newScoreName').value    = '';
        document.getElementById('newMarksObtained').value = '';
        document.getElementById('newScoreDate').value    = '';

        // Reload list
        loadScores();
        showGlobalAlert('success', res.message);
      } else {
        document.getElementById('scoreNameError').textContent = res.message;
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
      document.getElementById('scoreNameError').textContent = 'Network error. Try again.';
    });
}

// ── Delete score ──────────────────────────────────────────────────────────
function deleteScore(scoreId) {
  if (!confirm('Delete this score record? This cannot be undone.')) return;

  const fd = new FormData();
  fd.append('action',   scoreState.type === 'exam' ? 'delete_exam_score' : 'delete_test_score');
  fd.append('score_id', scoreId);

  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        // Remove row from modal table
        const row = document.getElementById('score-row-' + scoreId);
        if (row) row.remove();

        // Check if table body is now empty
        const tbody = document.getElementById('scoresTableBody');
        if (tbody && !tbody.querySelector('tr')) loadScores();

        // Decrement count badge in main table
        const countId = scoreState.type === 'exam'
          ? `exam-count-${scoreState.uid}`
          : `test-count-${scoreState.uid}`;
        const countEl = document.getElementById(countId);
        if (countEl) {
          const val = parseInt(countEl.textContent || 1) - 1;
          countEl.textContent = Math.max(0, val);
        }

        showGlobalAlert('success', res.message);
      } else {
        showGlobalAlert('danger', res.message);
      }
    })
    .catch(() => showGlobalAlert('danger', 'Network error. Try again.'));
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>