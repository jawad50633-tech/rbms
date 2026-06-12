<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_STUDENT);
$db   = getDB();
$user = currentUser();

// Get student's class + exam marks + reexam marks
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
$assignments = $db->prepare(
    "SELECT a.*, u.full_name AS teacher_name,
            (SELECT id   FROM submissions WHERE assignment_id=a.id AND student_id=?) AS submitted,
            (SELECT marks FROM submissions WHERE assignment_id=a.id AND student_id=?) AS marks
     FROM assignments a
     JOIN users u ON u.id = a.teacher_id
     WHERE a.status='active' AND (a.class_id IS NULL OR a.class_id=?)
     ORDER BY a.due_date ASC"
);
$assignments->execute([$user['id'], $user['id'], $classId]);
$assignments = $assignments->fetchAll();

$pending   = array_filter($assignments, fn($a) => !$a['submitted']);
$submitted = array_filter($assignments, fn($a) =>  $a['submitted']);

// Exam data — pulled directly from the students row
$examMarks      = $studentInfo['exam_marks']       ?? null;   // e.g. 78.50
$reexamMarks    = $studentInfo['reexam_marks']      ?? null;   // e.g. 65.00
$totalExamMarks = $studentInfo['total_exam_marks']  ?? 100;    // denominator

// Dynamic exam scores (from exam_scores table — teacher-managed entries)
$dynExamScores = $db->prepare(
    'SELECT es.exam_name, es.marks_obtained, es.total_marks, es.exam_date,
            u2.full_name AS teacher_name
     FROM exam_scores es
     LEFT JOIN users u2 ON u2.id = es.teacher_id
     WHERE es.student_user_id = ?
     ORDER BY es.exam_date DESC, es.created_at DESC'
);
$dynExamScores->execute([$user['id']]);
$dynExamScores = $dynExamScores->fetchAll();

// Test scores
$testScores = $db->prepare(
    'SELECT ts.test_name, ts.marks_obtained, ts.total_marks, ts.test_date,
            u2.full_name AS teacher_name
     FROM test_scores ts
     LEFT JOIN users u2 ON u2.id = ts.teacher_id
     WHERE ts.student_user_id = ?
     ORDER BY ts.test_date DESC, ts.created_at DESC'
);
$testScores->execute([$user['id']]);
$testScores = $testScores->fetchAll();

// Helper: percentage → label + colour
function examGrade(float $pct): array {
    if ($pct >= 90) return ['A+', '#4ade80'];
    if ($pct >= 80) return ['A',  '#86efac'];
    if ($pct >= 70) return ['B',  '#60a5fa'];
    if ($pct >= 60) return ['C',  '#fbbf24'];
    if ($pct >= 50) return ['D',  '#fb923c'];
    return ['F', '#f87171'];
}

renderHeader('Student Dashboard', 'dashboard');
?>

<!-- Welcome Banner -->
<div class="content-card mb-4 p-4"
     style="background:linear-gradient(135deg,#1e3a5f,#0f172a);border:none">
  <div class="row align-items-center">
    <div class="col">
      <h4 class="text-white mb-1">
        Welcome back, <?= e(explode(' ', $user['name'])[0]) ?>! 👋
      </h4>
      <p class="text-secondary mb-0" style="color:#94a3b8!important">
        <?php if ($studentInfo && $studentInfo['class_name']): ?>
          Class: <strong style="color:#60a5fa">
            <?= e($studentInfo['class_name']) ?>
            <?= $studentInfo['section'] ? ' (' . e($studentInfo['section']) . ')' : '' ?>
          </strong> ·
          Roll: <strong style="color:#60a5fa">
            <?= e($studentInfo['roll_number'] ?? '—') ?>
          </strong>
        <?php else: ?>
          You are enrolled. Check your assignments below.
        <?php endif; ?>
      </p>
    </div>

    <!-- Stats counters -->
    <div class="col-auto">
      <div class="row g-2">
        <div class="col-auto text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#fff">
            <?= count($pending) ?>
          </div>
          <div style="font-size:.72rem;color:#94a3b8">Pending</div>
        </div>
        <div class="col-auto text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#4ade80">
            <?= count($submitted) ?>
          </div>
          <div style="font-size:.72rem;color:#94a3b8">Submitted</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ───────────────────────── EXAM MARKS SECTION ───────────────────────── -->
<div class="content-card mb-4">
  <div class="card-header-custom">
    <h6>
      <i class="bi bi-mortarboard me-2 text-info"></i>Exam Results
    </h6>
  </div>

  <div class="p-3">
    <div class="row g-3">

      <!-- ── Exam Marks card ── -->
      <div class="col-12 col-md-6">
        <div class="p-3 rounded-3 h-100"
             style="background:#0f172a;border:1px solid #1e3a5f">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="small fw-600" style="color:#94a3b8">
              <i class="bi bi-journal-check me-1 text-info"></i>Exam Marks
            </span>
            <?php if ($examMarks !== null): ?>
              <?php
                $pct = ($totalExamMarks > 0) ? ($examMarks / $totalExamMarks * 100) : 0;
                [$grade, $gradeColor] = examGrade($pct);
              ?>
              <span class="badge rounded-pill px-3"
                    style="background:<?= $gradeColor ?>22;
                           color:<?= $gradeColor ?>;
                           border:1px solid <?= $gradeColor ?>55;
                           font-size:.75rem">
                Grade <?= $grade ?>
              </span>
            <?php endif; ?>
          </div>

          <?php if ($examMarks !== null): ?>
            <div class="d-flex align-items-end gap-1 mb-2">
              <span style="font-size:2rem;font-weight:700;color:#fff;line-height:1">
                <?= number_format((float)$examMarks, 1) ?>
              </span>
              <span class="mb-1" style="color:#64748b;font-size:.9rem">
                / <?= (int)$totalExamMarks ?>
              </span>
            </div>
            <!-- Progress bar -->
            <div class="progress" style="height:6px;background:#1e293b;border-radius:99px">
              <div class="progress-bar"
                   role="progressbar"
                   style="width:<?= min(100, round($pct)) ?>%;
                          background:<?= $gradeColor ?>;
                          border-radius:99px"
                   aria-valuenow="<?= round($pct) ?>"
                   aria-valuemin="0" aria-valuemax="100">
              </div>
            </div>
            <div class="mt-1 text-end" style="font-size:.7rem;color:#64748b">
              <?= round($pct) ?>%
            </div>
          <?php else: ?>
            <div class="py-2">
              <i class="bi bi-clock me-1"></i>Not yet recorded
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Re-Exam Marks card ── -->
      <div class="col-12 col-md-6">
        <div class="p-3 rounded-3 h-100"
             style="background:#0f172a;border:1px solid #1e3a5f">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="small fw-600" style="color:#94a3b8">
              <i class="bi bi-arrow-repeat me-1 text-warning"></i>Re-Exam Marks
            </span>
            <?php if ($reexamMarks !== null): ?>
              <?php
                $rpct = ($totalExamMarks > 0) ? ($reexamMarks / $totalExamMarks * 100) : 0;
                [$rgrade, $rgradeColor] = examGrade($rpct);
              ?>
              <span class="badge rounded-pill px-3"
                    style="background:<?= $rgradeColor ?>22;
                           color:<?= $rgradeColor ?>;
                           border:1px solid <?= $rgradeColor ?>55;
                           font-size:.75rem">
                Grade <?= $rgrade ?>
              </span>
            <?php endif; ?>
          </div>

          <?php if ($reexamMarks !== null): ?>
            <div class="d-flex align-items-end gap-1 mb-2">
              <span style="font-size:2rem;font-weight:700;color:#fff;line-height:1">
                <?= number_format((float)$reexamMarks, 1) ?>
              </span>
              <span class="mb-1" style="color:#64748b;font-size:.9rem">
                / <?= (int)$totalExamMarks ?>
              </span>
            </div>
            <!-- Progress bar -->
            <div class="progress" style="height:6px;background:#1e293b;border-radius:99px">
              <div class="progress-bar"
                   role="progressbar"
                   style="width:<?= min(100, round($rpct)) ?>%;
                          background:<?= $rgradeColor ?>;
                          border-radius:99px"
                   aria-valuenow="<?= round($rpct) ?>"
                   aria-valuemin="0" aria-valuemax="100">
              </div>
            </div>
            <div class="mt-1 text-end" style="font-size:.7rem;color:#64748b">
              <?= round($rpct) ?>%
            </div>
          <?php else: ?>
            <div class="mb-1" style="color:#64748b;font-size:.9rem">
              <i class="bi bi-dash-circle me-1"></i>No re-exam taken
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /row -->
  </div>
</div>
<!-- ───────────────────────── END EXAM MARKS ───────────────────────── -->

<!-- ───────────────────── DYNAMIC EXAM SCORES TABLE ─────────────────────── -->
<?php if (!empty($dynExamScores)): ?>
<div class="content-card mb-4">
  <div class="card-header-custom">
    <h6>
      <i class="bi bi-journal-text me-2 text-info"></i>Exam Score Records
    </h6>
    <span class="badge bg-info text-dark"><?= count($dynExamScores) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Exam</th>
          <th>Marks</th>
          <th style="min-width:120px">Progress</th>
          <th>Grade</th>
          <th>Date</th>
          <th>Teacher</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dynExamScores as $es):
          $epct = $es['total_marks'] > 0 ? ($es['marks_obtained'] / $es['total_marks'] * 100) : 0;
          [$egrade, $ecolor] = examGrade($epct);
        ?>
        <tr>
          <td class="fw-600 small text-black"><?= e($es['exam_name']) ?></td>
          <td>
            <span class="badge bg-info text-dark">
              <?= number_format((float)$es['marks_obtained'], 1) ?>/<?= (int)$es['total_marks'] ?>
            </span>
          </td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1"
                   style="height:5px;background:#1e293b;border-radius:99px">
                <div class="progress-bar"
                     style="width:<?= min(100, round($epct)) ?>%;
                            background:<?= $ecolor ?>;border-radius:99px"></div>
              </div>
              <span style="font-size:.7rem;color:#94a3b8;min-width:32px">
                <?= round($epct) ?>%
              </span>
            </div>
          </td>
          <td>
            <span class="badge rounded-pill px-2"
                  style="background:<?= $ecolor ?>22;color:<?= $ecolor ?>;
                         border:1px solid <?= $ecolor ?>55;font-size:.72rem">
              <?= $egrade ?>
            </span>
          </td>
          <td class="small text-muted">
            <?= $es['exam_date'] ? formatDate($es['exam_date']) : '—' ?>
          </td>
          <td class="small text-muted"><?= e($es['teacher_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<!-- ─────────────────────── END DYNAMIC EXAM SCORES ──────────────────────── -->

<!-- ───────────────────────── TEST SCORES TABLE ──────────────────────────── -->
<div class="content-card mb-4">
  <div class="card-header-custom">
    <h6>
      <i class="bi bi-clipboard-check me-2 text-warning"></i>Test Scores
    </h6>
    <span class="badge bg-warning text-dark"><?= count($testScores) ?></span>
  </div>

  <?php if (!empty($testScores)): ?>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Test</th>
          <th>Marks</th>
          <th style="min-width:120px">Progress</th>
          <th>Grade</th>
          <th>Date</th>
          <th>Teacher</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($testScores as $ts):
          $tpct = $ts['total_marks'] > 0 ? ($ts['marks_obtained'] / $ts['total_marks'] * 100) : 0;
          [$tgrade, $tcolor] = examGrade($tpct);
        ?>
        <tr>
          <td class="fw-600 small text-black"><?= e($ts['test_name']) ?></td>
          <td>
            <span class="badge bg-warning text-dark">
              <?= number_format((float)$ts['marks_obtained'], 1) ?>/<?= (int)$ts['total_marks'] ?>
            </span>
          </td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1"
                   style="height:5px;background:#1e293b;border-radius:99px">
                <div class="progress-bar"
                     style="width:<?= min(100, round($tpct)) ?>%;
                            background:<?= $tcolor ?>;border-radius:99px"></div>
              </div>
              <span style="font-size:.7rem;color:#94a3b8;min-width:32px">
                <?= round($tpct) ?>%
              </span>
            </div>
          </td>
          <td>
            <span class="badge rounded-pill px-2"
                  style="background:<?= $tcolor ?>22;color:<?= $tcolor ?>;
                         border:1px solid <?= $tcolor ?>55;font-size:.72rem">
              <?= $tgrade ?>
            </span>
          </td>
          <td class="small text-muted">
            <?= $ts['test_date'] ? formatDate($ts['test_date']) : '—' ?>
          </td>
          <td class="small text-muted"><?= e($ts['teacher_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php else: ?>
  <div class="p-4 text-center" style="color:#64748b">
    <i class="bi bi-clipboard-x fs-2 d-block mb-2 opacity-50"></i>
    <div class="small">No test scores have been recorded yet.</div>
  </div>
  <?php endif; ?>
</div>
<!-- ─────────────────────────── END TEST SCORES ───────────────────────────── -->

<!-- Pending Assignments -->
<?php if (!empty($pending)): ?>
<div class="content-card mb-4">
  <div class="card-header-custom">
    <h6>
      <i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Assignments
    </h6>
    <span class="badge bg-warning text-dark"><?= count($pending) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Title</th><th>Teacher</th><th>Due Date</th>
          <th>Total Marks</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $a): ?>
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
              <a href="student_assignments.php?submit=<?= $a['id'] ?>"
                 class="btn btn-sm btn-primary">
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
    <h6>
      <i class="bi bi-check-circle me-2 text-success"></i>Submitted Assignments
    </h6>
    <span class="badge bg-success"><?= count($submitted) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr>
          <th>Title</th><th>Teacher</th><th>Due Date</th><th>Marks</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($submitted as $a): ?>
          <tr>
            <td class="fw-600 small"><?= e($a['title']) ?></td>
            <td class="small text-muted"><?= e($a['teacher_name']) ?></td>
            <td class="small">
              <?= $a['due_date'] ? formatDate($a['due_date']) : '—' ?>
            </td>
            <td>
              <?php if ($a['marks'] !== null): ?>
                <span class="badge bg-success">
                  <?= $a['marks'] ?>/<?= $a['total_marks'] ?>
                </span>
              <?php else: ?>
                <span class="badge bg-info text-dark">Awaiting Grade</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($submitted)): ?>
          <tr>
            <td colspan="4" class="text-center text-muted py-3">
              No submissions yet.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>