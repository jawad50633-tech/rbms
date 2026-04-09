<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_TEACHER);
$db   = getDB();
$user = currentUser();

// Get teacher's assigned class — ENFORCED SERVER SIDE
$teacherRow = $db->prepare('SELECT class_id FROM users WHERE id=? AND role="teacher"');
$teacherRow->execute([$user['id']]);
$teacherRow  = $teacherRow->fetch();
$myClassId   = $teacherRow['class_id'] ?? null;

if (!$myClassId) {
    setFlash('error', 'You have not been assigned to a class yet. Contact the Super Admin.');
    header('Location: teacher_dashboard.php');
    exit;
}

// Class info
$classInfo = $db->prepare('SELECT * FROM classes WHERE id=?');
$classInfo->execute([$myClassId]);
$classInfo = $classInfo->fetch();

// Students IN teacher's class only — never shows other classes
$search  = trim($_GET['search'] ?? '');
$where   = 'WHERE s.class_id = ? AND u.status = \'active\'';
$params  = [$myClassId];
if ($search) {
    $where   .= ' AND (u.full_name LIKE ? OR s.roll_number LIKE ? OR s.father_name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$students = $db->prepare(
    "SELECT u.id, u.full_name, u.email,
            s.roll_number, s.father_name, s.photo, s.date_of_birth, s.phone,
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
<div class="content-card mb-4 p-4" style="background:linear-gradient(135deg,#1e3a5f,#0f172a);border:none">
  <div class="d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <div style="width:48px;height:48px;background:rgba(59,130,246,.25);border-radius:12px;display:flex;align-items:center;justify-content:center">
        <i class="bi bi-diagram-3-fill text-primary fs-5"></i>
      </div>
      <div>
        <div style="color:#94a3b8;font-size:.75rem;font-weight:600;text-transform:uppercase">Your Class</div>
        <div style="color:#fff;font-size:1.2rem;font-weight:700">
          <?= e($classInfo['name']) ?>
          <?= $classInfo['section'] ? '<span style="color:#60a5fa">(' . e($classInfo['section']) . ')</span>' : '' ?>
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
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
      </div>
      <?php if ($search): ?>
      <div class="col-auto">
        <a href="teacher_students.php" class="btn btn-outline-secondary">Clear</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Student List -->
<div class="content-card">
  <div class="table-responsive">
    <table class="table table-custom">
      <thead>
        <tr><th>Photo</th><th>Student</th><th>Father</th><th>Roll No.</th><th>Phone</th><th>Submissions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
        <tr>
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
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
        <tr>
          <td colspan="6" class="text-center text-muted py-4">No students in your class yet.</td>
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
