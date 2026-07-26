<?php
/**
 * includes/header.php
 * Call renderHeader($title, $activePage) to output full <head> + sidebar shell.
 * Requires: config.php already included, requireLogin() already called.
 */

function renderHeader(string $title = 'Dashboard', string $activePage = ''): void {
    $user    = currentUser();
    $role    = $user['role'];
    $flash   = getFlash();

    // Build nav links per role
    $navLinks = getNavLinks($role, $activePage);

    $roleBadgeColor = [
        'super_admin' => 'danger',
        'admin'       => 'primary',
        'teacher'     => 'success',
        'student'     => 'info',
    ][$role] ?? 'secondary';

    $roleLabel = ucwords(str_replace('_', ' ', $role));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> — <?= APP_NAME ?></title>

  <!-- Bootstrap 5.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <!-- Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root {
      --sidebar-bg:      #0a0e17;
      --sidebar-hover:   #1a2130;
      --sidebar-active:  #3b82f6;
      --sidebar-width:   260px;
      --topbar-height:   60px;
      --body-bg:         #05070c;
      --card-radius:     14px;
      --text-muted:      #8b96a8;

      /* Dark theme surfaces */
      --surface:         #10151f;
      --surface-alt:     #161c29;
      --surface-border:  #232b3a;
      --surface-border-soft: #1b2130;
      --text-primary:    #e7ebf3;
      --text-secondary:  #a3adc2;
    }

    * { box-sizing: border-box; }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--body-bg);
      color: var(--text-primary);
      margin: 0;
      overflow-x: hidden;
    }

    ::selection { background: rgba(59,130,246,.35); color: #fff; }

    ::-webkit-scrollbar { width: 10px; height: 10px; }
    ::-webkit-scrollbar-track { background: var(--body-bg); }
    ::-webkit-scrollbar-thumb { background: #263042; border-radius: 20px; }
    ::-webkit-scrollbar-thumb:hover { background: #324058; }

    /* ── Sidebar ── */
    #sidebar {
      position: fixed;
      top: 0; left: 0;
      width: var(--sidebar-width);
      height: 100vh;
      background: var(--sidebar-bg);
      display: flex;
      flex-direction: column;
      z-index: 1040;
      transition: transform .3s ease;
      overflow-y: auto;
    }

    .sidebar-brand {
      padding: 20px 24px;
      border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .sidebar-brand h5 {
      color: #fff;
      font-weight: 700;
      font-size: 1rem;
      margin: 0;
      letter-spacing: .5px;
    }

    .sidebar-brand small {
      color: #94a3b8;
      font-size: .72rem;
    }

    .sidebar-user {
      padding: 16px 24px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .sidebar-user .avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--sidebar-active);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      color: #fff;
      font-size: .9rem;
      flex-shrink: 0;
    }

    .sidebar-user .user-info p {
      margin: 0;
      color: #fff;
      font-size: .82rem;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 160px;
    }

    .sidebar-user .user-info small {
      color: #94a3b8;
      font-size: .7rem;
    }

    .sidebar-nav {
      flex: 1;
      padding: 12px 0;
    }

    .nav-section-title {
      color: #475569;
      font-size: .65rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 16px 24px 4px;
    }

    .sidebar-nav a {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 24px;
      color: #94a3b8;
      text-decoration: none;
      font-size: .84rem;
      font-weight: 500;
      border-left: 3px solid transparent;
      transition: all .2s;
    }

    .sidebar-nav a:hover {
      background: var(--sidebar-hover);
      color: #fff;
    }

    .sidebar-nav a.active {
      background: rgba(59,130,246,.12);
      color: #60a5fa;
      border-left-color: var(--sidebar-active);
    }

    .sidebar-nav a i {
      font-size: 1rem;
      width: 20px;
      text-align: center;
    }

    .sidebar-footer {
      padding: 16px 24px;
      border-top: 1px solid rgba(255,255,255,.08);
    }

    .sidebar-footer a {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #ef4444;
      text-decoration: none;
      font-size: .84rem;
      font-weight: 500;
      transition: color .2s;
    }

    .sidebar-footer a:hover { color: #fca5a5; }

    /* ── Main Wrapper ── */
    #main-wrapper {
      margin-left: var(--sidebar-width);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ── Top Bar ── */
    #topbar {
      position: sticky;
      top: 0;
      background: var(--surface);
      height: var(--topbar-height);
      display: flex;
      align-items: center;
      padding: 0 28px;
      border-bottom: 1px solid var(--surface-border);
      z-index: 1030;
      gap: 16px;
    }

    #topbar .page-title {
      font-size: 1rem;
      font-weight: 600;
      color: var(--text-primary);
      margin: 0;
      flex: 1;
    }

    #topbar .topbar-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    #topbar .topbar-right .text-muted { color: var(--text-secondary) !important; }

    .sidebar-toggle {
      display: none;
      background: none;
      border: none;
      font-size: 1.3rem;
      color: var(--text-secondary);
      cursor: pointer;
      padding: 4px;
    }

    /* ── Content ── */
    #page-content {
      flex: 1;
      padding: 28px;
    }

    /* ── Stat Cards ── */
    .stat-card {
      background: var(--surface);
      border-radius: var(--card-radius);
      padding: 24px;
      border: 1px solid var(--surface-border);
      transition: box-shadow .25s, transform .25s, border-color .25s;
    }

    .stat-card:hover {
      box-shadow: 0 8px 28px rgba(0,0,0,.45);
      transform: translateY(-2px);
      border-color: #2d3852;
    }

    .stat-card .stat-icon {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }

    .stat-card .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: var(--text-primary);
      line-height: 1.1;
    }

    .stat-card .stat-label {
      color: var(--text-muted);
      font-size: .8rem;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: .5px;
    }

    /* ── Content Cards ── */
    .content-card {
      background: var(--surface);
      border-radius: var(--card-radius);
      border: 1px solid var(--surface-border);
      overflow: hidden;
      color: var(--text-primary);
    }

    .content-card .card-header-custom {
      padding: 16px 24px;
      border-bottom: 1px solid var(--surface-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .content-card .card-header-custom h6 {
      margin: 0;
      font-weight: 600;
      font-size: .9rem;
      color: var(--text-primary);
    }

    .content-card .card-body-custom {
      padding: 24px;
    }

    /* ── Tables ── */
    .table-custom {
      font-size: .84rem;
      margin: 0;
      color: var(--text-primary);
    }

    .table-custom thead th {
      background: var(--surface-alt);
      color: var(--text-secondary);
      font-weight: 600;
      font-size: .74rem;
      text-transform: uppercase;
      letter-spacing: .5px;
      border-bottom: 1px solid var(--surface-border);
      padding: 12px 16px;
    }

    .table-custom tbody td {
      padding: 12px 16px;
      vertical-align: middle;
      border-bottom: 1px solid var(--surface-border-soft);
      color: var(--text-primary);
      background: transparent;
    }

    .table-custom tbody tr:last-child td { border-bottom: none; }
    .table-custom tbody tr:hover td { background: rgba(255,255,255,.03); }

    /* Bootstrap table base override (any plain .table used inside dark cards) */
    .content-card .table,
    .content-card table {
      color: var(--text-primary);
    }
    .content-card .table > :not(caption) > * > * {
      background-color: transparent;
      color: var(--text-primary);
      border-bottom-color: var(--surface-border-soft);
    }

    /* ── Badges ── */
    .badge-role {
      font-size: .7rem;
      padding: 4px 10px;
      border-radius: 20px;
      font-weight: 600;
    }

    /* ── Forms ── */
    .form-label { font-size: .84rem; font-weight: 600; color: var(--text-secondary); }
    .form-control, .form-select {
      font-size: .88rem;
      border-radius: 8px;
      border: 1px solid var(--surface-border);
      background-color: var(--surface-alt);
      color: var(--text-primary);
    }
    .form-control::placeholder { color: #6b7690; }
    .form-control:focus, .form-select:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59,130,246,.18);
      background-color: var(--surface-alt);
      color: var(--text-primary);
    }
    .form-control:disabled, .form-select:disabled,
    .form-control[readonly] {
      background-color: #0d121c;
      color: var(--text-muted);
    }
    .form-select option { background: var(--surface-alt); color: var(--text-primary); }
    .input-group-text {
      background-color: var(--surface-alt);
      border: 1px solid var(--surface-border);
      color: var(--text-secondary);
    }
    .form-check-input { background-color: var(--surface-alt); border-color: var(--surface-border); }
    .form-check-input:checked { background-color: #3b82f6; border-color: #3b82f6; }
    hr { border-color: var(--surface-border); opacity: 1; }

    /* ── Buttons ── */
    .btn { border-radius: 8px; font-size: .84rem; font-weight: 500; }
    .btn-sm { padding: 4px 10px; font-size: .78rem; }
    .btn-light { background-color: var(--surface-alt); border-color: var(--surface-border); color: var(--text-primary); }
    .btn-light:hover { background-color: #1d2434; border-color: var(--surface-border); color: var(--text-primary); }
    .btn-outline-secondary { color: var(--text-secondary); border-color: var(--surface-border); }
    .btn-outline-secondary:hover { background-color: var(--surface-alt); color: var(--text-primary); border-color: var(--surface-border); }

    /* ── Alerts ── */
    .alert { border-radius: 10px; font-size: .85rem; }
    .alert-success { background: rgba(16,185,129,.12); color: #6ee7b7; border-color: rgba(16,185,129,.3); }
    .alert-danger  { background: rgba(239,68,68,.12); color: #fca5a5; border-color: rgba(239,68,68,.3); }
    .alert-warning { background: rgba(245,158,11,.12); color: #fcd34d; border-color: rgba(245,158,11,.3); }
    .alert-info    { background: rgba(6,182,212,.12); color: #67e8f9; border-color: rgba(6,182,212,.3); }
    .btn-close { filter: invert(1) grayscale(1) brightness(1.8); }

    /* ── Avatar in table ── */
    .table-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      object-fit: cover;
    }

    .table-avatar-placeholder {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--surface-alt);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: .78rem;
      font-weight: 700;
      color: var(--text-secondary);
      border: 1px solid var(--surface-border);
    }

    /* ── Misc text helpers used across pages ── */
    .text-dark { color: var(--text-primary) !important; }
    .text-muted { color: var(--text-muted) !important; }
    .text-secondary { color: var(--text-secondary) !important; }
    a { color: #60a5fa; }
    a:hover { color: #93c5fd; }
    .bg-white { background-color: var(--surface-alt) !important; }
    .dropdown-menu {
      background-color: var(--surface-alt);
      border: 1px solid var(--surface-border);
    }
    .dropdown-item { color: var(--text-primary); }
    .dropdown-item:hover, .dropdown-item:focus { background-color: var(--surface-border-soft); color: var(--text-primary); }
    .modal-content { background-color: var(--surface); color: var(--text-primary); border: 1px solid var(--surface-border); }
    .modal-header, .modal-footer { border-color: var(--surface-border); }
    .nav-tabs { border-color: var(--surface-border); }
    .nav-tabs .nav-link { color: var(--text-secondary); }
    .nav-tabs .nav-link.active { background: var(--surface-alt); color: var(--text-primary); border-color: var(--surface-border) var(--surface-border) var(--surface); }
    .pagination .page-link { background-color: var(--surface-alt); border-color: var(--surface-border); color: var(--text-primary); }
    .pagination .page-item.active .page-link { background-color: #3b82f6; border-color: #3b82f6; }
    .pagination .page-item.disabled .page-link { background-color: var(--surface); color: var(--text-muted); }

    /* ── Bootstrap utility overrides used throughout the dashboards ── */
    .bg-light { background-color: var(--surface-alt) !important; }
    .bg-body { background-color: var(--body-bg) !important; }
    table.table-light thead th,
    .table-light { background-color: var(--surface-alt) !important; color: var(--text-primary) !important; }
    .table-striped > tbody > tr:nth-of-type(odd) > * { background-color: rgba(255,255,255,.02); color: var(--text-primary); }
    .card { background-color: var(--surface); border-color: var(--surface-border); color: var(--text-primary); }
    .card-header, .card-footer { background-color: var(--surface-alt); border-color: var(--surface-border); }
    .list-group-item { background-color: var(--surface); border-color: var(--surface-border-soft); color: var(--text-primary); }
    .list-group-item.active { background-color: rgba(59,130,246,.15); border-color: #3b82f6; color: #93c5fd; }
    .border { border-color: var(--surface-border) !important; }
    .border-top { border-top-color: var(--surface-border) !important; }
    .border-bottom { border-bottom-color: var(--surface-border) !important; }
    code { background: var(--surface-alt); color: #f0abfc; padding: 1px 5px; border-radius: 4px; }
    .accordion-item { background-color: var(--surface); border-color: var(--surface-border); }
    .accordion-button { background-color: var(--surface-alt); color: var(--text-primary); }
    .accordion-button:not(.collapsed) { background-color: rgba(59,130,246,.12); color: #93c5fd; }
    .accordion-body { color: var(--text-secondary); }

    /* ── Responsive ── */
    @media (max-width: 991px) {
      #sidebar {
        transform: translateX(-100%);
      }
      #sidebar.show {
        transform: translateX(0);
      }
      #main-wrapper {
        margin-left: 0;
      }
      .sidebar-toggle {
        display: block;
      }
    }
  </style>
</head>
<body>

<!-- ════════════════════════════════
     SIDEBAR
════════════════════════════════ -->
<div id="sidebar">

  <div class="sidebar-brand">
    <h5><i class="bi bi-mortarboard-fill me-2" style="color:#3b82f6"></i><?= APP_NAME ?></h5>
    <small>Management Portal</small>
  </div>

  <div class="sidebar-user">
    <div class="avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
    <div class="user-info">
      <p><?= e($user['name']) ?></p>
      <small><span class="badge bg-<?= $roleBadgeColor ?> badge-role"><?= $roleLabel ?></span></small>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($navLinks as $section => $links): ?>
      <div class="nav-section-title"><?= $section ?></div>
      <?php foreach ($links as $link): ?>
        <a href="<?= $link['url'] ?>"
           class="<?= ($activePage === $link['key']) ? 'active' : '' ?>">
          <i class="bi bi-<?= $link['icon'] ?>"></i>
          <?= $link['label'] ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= BASE_URL ?>/logout.php">
      <i class="bi bi-box-arrow-left"></i> Logout
    </a>
  </div>
</div>

<!-- ════════════════════════════════
     MAIN WRAPPER
════════════════════════════════ -->
<div id="main-wrapper">

  <!-- Top Bar -->
  <div id="topbar">
    <button class="sidebar-toggle" onclick="toggleSidebar()">
      <i class="bi bi-list"></i>
    </button>
    <h6 class="page-title"><?= e($title) ?></h6>
    <div class="topbar-right">
      <span class="text-muted small d-none d-md-inline">
        <?= date('l, d M Y') ?>
      </span>
    </div>
  </div>

  <!-- Flash Message -->
  <?php if ($flash): ?>
  <div class="px-4 pt-3">
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Page Content starts here — closed by renderFooter() -->
  <div id="page-content">

<?php
} // end renderHeader()


function getNavLinks(string $role, string $activePage): array {
    $base = BASE_URL . '/dashboards/';

    $links = [];

    if ($role === ROLE_SUPER_ADMIN) {
        $links['Main'] = [
            ['key' => 'dashboard',  'label' => 'Dashboard',       'icon' => 'speedometer2',   'url' => $base . 'superadmin_dashboard.php'],
            ['key' => 'users',      'label' => 'User Management',  'icon' => 'people-fill',    'url' => $base . 'superadmin_users.php'],
            ['key' => 'classes',    'label' => 'Classes',          'icon' => 'diagram-3-fill', 'url' => $base . 'superadmin_classes.php'],
        ];
        $links['Panels'] = [
            ['key' => 'students',      'label' => 'Students (Admissions)', 'icon' => 'person-badge-fill', 'url' => $base . 'admin_students.php'],
            ['key' => 'assignments',   'label' => 'Assignments',           'icon' => 'journal-text',      'url' => $base . 'teacher_assignments.php'],
            ['key' => 'submissions',   'label' => 'All Submissions',       'icon' => 'cloud-upload-fill', 'url' => $base . 'superadmin_submissions.php'],
            ['key' => 'fees',          'label' => 'Fees Manager',          'icon' => 'cash-coin',         'url' => $base . 'superadmin_fees.php'],
            ['key' => 'fees_audit',    'label' => 'Fees Audit',            'icon' => 'shield-lock-fill',  'url' => $base . 'admin_fees_audit.php'],
        ];
        $links['System'] = [
            ['key' => 'activity', 'label' => 'Activity Log', 'icon' => 'clock-history', 'url' => $base . 'superadmin_activity.php'],
            ['key' => 'blog',     'label' => 'Blog Manager',  'icon' => 'file-richtext', 'url' => $base . 'superadmin_blog.php'],
        ];
    }

    if ($role === ROLE_ADMIN) {
        $links['Main'] = [
            ['key' => 'dashboard', 'label' => 'Dashboard',     'icon' => 'speedometer2',       'url' => $base . 'admin_dashboard.php'],
            ['key' => 'students',  'label' => 'Students',       'icon' => 'person-badge-fill',  'url' => $base . 'admin_students.php'],
        ];
        $links['Fees'] = [
            ['key' => 'fees', 'label' => 'Collect Fees', 'icon' => 'cash-coin', 'url' => $base . 'admin_fees.php'],
        ];
    }

    if ($role === ROLE_TEACHER) {
        $links['Main'] = [
            ['key' => 'dashboard',   'label' => 'Dashboard',    'icon' => 'speedometer2',      'url' => $base . 'teacher_dashboard.php'],
            ['key' => 'students',    'label' => 'My Students',  'icon' => 'people-fill',       'url' => $base . 'teacher_students.php'],
            ['key' => 'assignments', 'label' => 'Assignments',  'icon' => 'journal-text',      'url' => $base . 'teacher_assignments.php'],
            ['key' => 'submissions', 'label' => 'Submissions',  'icon' => 'cloud-upload-fill', 'url' => $base . 'teacher_submissions.php'],
        ];
    }

    if ($role === ROLE_STUDENT) {
        $links['Main'] = [
            ['key' => 'dashboard',   'label' => 'Dashboard',      'icon' => 'speedometer2',      'url' => $base . 'student_dashboard.php'],
            ['key' => 'assignments', 'label' => 'My Assignments',  'icon' => 'journal-text',      'url' => $base . 'student_assignments.php'],
            ['key' => 'submissions', 'label' => 'My Submissions',  'icon' => 'cloud-upload-fill', 'url' => $base . 'student_submissions.php'],
        ];
    }

    return $links;
}