<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

requireLogin(ROLE_SUPER_ADMIN);

$db     = getDB();
$user   = currentUser();
$action = $_GET['action'] ?? 'list';   // list | new | edit | view
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Helper: generate unique slug ─────────────────────────────
function makeSlug(string $title, PDO $db, int $excludeId = 0): string {
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
    $slug = $base;
    $i    = 1;
    while (true) {
        $st = $db->prepare('SELECT id FROM blog_posts WHERE slug = ? AND id != ?');
        $st->execute([$slug, $excludeId]);
        if (!$st->fetchColumn()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

// ── POST handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['form_action'] ?? '';

    // ── Delete post ──
    if ($act === 'delete') {
        $id = (int)($_POST['post_id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]);
            logActivity($user['id'], 'Deleted blog post #' . $id, 'blog');
            setFlash('success', 'Post deleted.');
        }
        header('Location: superadmin_blog.php');
        exit;
    }

    // ── Delete category ──
    if ($act === 'delete_category') {
        $id = (int)($_POST['cat_id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM blog_categories WHERE id = ?')->execute([$id]);
            logActivity($user['id'], 'Deleted blog category #' . $id, 'blog');
            setFlash('success', 'Category deleted.');
        }
        header('Location: superadmin_blog.php?action=categories');
        exit;
    }

    // ── Save category ──
    if ($act === 'save_category') {
        $catId   = (int)($_POST['cat_id']   ?? 0);
        $catName = trim($_POST['cat_name']  ?? '');
        if ($catName === '') {
            setFlash('error', 'Category name is required.');
            header('Location: superadmin_blog.php?action=categories');
            exit;
        }
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $catName), '-'));
        if ($catId) {
            $db->prepare('UPDATE blog_categories SET name=?, slug=? WHERE id=?')
               ->execute([$catName, $slug, $catId]);
            setFlash('success', 'Category updated.');
        } else {
            try {
                $db->prepare('INSERT INTO blog_categories (name, slug) VALUES (?,?)')
                   ->execute([$catName, $slug]);
                setFlash('success', 'Category added.');
            } catch (PDOException $e) {
                setFlash('error', 'Slug conflict — try a different name.');
            }
        }
        logActivity($user['id'], ($catId ? 'Updated' : 'Created') . ' blog category: ' . $catName, 'blog');
        header('Location: superadmin_blog.php?action=categories');
        exit;
    }

    // ── Save post (create / update) ──
    if ($act === 'save_post') {
        $id         = (int)($_POST['post_id']     ?? 0);
        $title      = trim($_POST['title']        ?? '');
        $excerpt    = trim($_POST['excerpt']      ?? '');
        $body       = trim($_POST['body']         ?? '');
        $catId      = (int)($_POST['category_id'] ?? 0) ?: null;
        $status     = in_array($_POST['status'] ?? '', ['draft','published','archived'])
                        ? $_POST['status'] : 'draft';
        $cover      = trim($_POST['cover_image']  ?? '');

        if ($title === '' || $body === '') {
            setFlash('error', 'Title and body are required.');
            header('Location: superadmin_blog.php?action=' . ($id ? 'edit&id='.$id : 'new'));
            exit;
        }

        $slug      = makeSlug($title, $db, $id);
        $pubAt     = ($status === 'published') ? date('Y-m-d H:i:s') : null;

        if ($id) {
            // Keep original published_at if already published
            $orig = $db->prepare('SELECT status, published_at FROM blog_posts WHERE id=?');
            $orig->execute([$id]);
            $origRow = $orig->fetch();
            if ($origRow && $origRow['status'] === 'published') {
                $pubAt = $origRow['published_at'];
            }
            $db->prepare(
                'UPDATE blog_posts
                    SET title=?, slug=?, excerpt=?, body=?, category_id=?,
                        status=?, cover_image=?, published_at=?
                  WHERE id=?'
            )->execute([$title, $slug, $excerpt, $body, $catId, $status, $cover ?: null, $pubAt, $id]);
            logActivity($user['id'], 'Updated blog post: ' . $title, 'blog');
            setFlash('success', 'Post updated.');
            header('Location: superadmin_blog.php?action=edit&id=' . $id);
        } else {
            $db->prepare(
                'INSERT INTO blog_posts
                    (author_id, category_id, title, slug, excerpt, body, cover_image, status, published_at)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$user['id'], $catId, $title, $slug, $excerpt, $body, $cover ?: null, $status, $pubAt]);
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'Created blog post: ' . $title, 'blog');
            setFlash('success', 'Post created.');
            header('Location: superadmin_blog.php?action=edit&id=' . $newId);
        }
        exit;
    }
}

// ── Data fetch helpers ────────────────────────────────────────
$categories = $db->query('SELECT * FROM blog_categories ORDER BY name')->fetchAll();

$stats = [
    'total'     => $db->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn(),
    'published' => $db->query("SELECT COUNT(*) FROM blog_posts WHERE status='published'")->fetchColumn(),
    'draft'     => $db->query("SELECT COUNT(*) FROM blog_posts WHERE status='draft'")->fetchColumn(),
    'views'     => $db->query("SELECT SUM(views) FROM blog_posts")->fetchColumn() ?: 0,
];

// ── Single post for edit/view ─────────────────────────────────
$editPost = null;
if (in_array($action, ['edit','view']) && $postId) {
    $st = $db->prepare(
        'SELECT p.*, u.full_name AS author_name, c.name AS cat_name
           FROM blog_posts p
           LEFT JOIN users u ON p.author_id = u.id
           LEFT JOIN blog_categories c ON p.category_id = c.id
          WHERE p.id = ?'
    );
    $st->execute([$postId]);
    $editPost = $st->fetch();
    if (!$editPost) {
        setFlash('error', 'Post not found.');
        header('Location: superadmin_blog.php');
        exit;
    }
    // increment views on public view (not edit)
    if ($action === 'view') {
        $db->prepare('UPDATE blog_posts SET views = views + 1 WHERE id=?')->execute([$postId]);
    }
}

// ── Post list with search/filter ─────────────────────────────
$search   = trim($_GET['q']      ?? '');
$filterS  = $_GET['status']      ?? '';
$filterC  = (int)($_GET['cat']   ?? 0);
$page     = max(1, (int)($_GET['p'] ?? 1));
$perPage  = 12;
$offset   = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]  = '(p.title LIKE ? OR p.excerpt LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($filterS, ['draft','published','archived'])) {
    $where[]  = 'p.status = ?';
    $params[] = $filterS;
}
if ($filterC > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $filterC;
}
$whereStr = implode(' AND ', $where);

$totalRows = $db->prepare("SELECT COUNT(*) FROM blog_posts p WHERE $whereStr");
$totalRows->execute($params);
$totalCount = (int)$totalRows->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$postsSt = $db->prepare(
    "SELECT p.id, p.title, p.status, p.views, p.created_at, p.published_at,
            u.full_name AS author_name, c.name AS cat_name
       FROM blog_posts p
       LEFT JOIN users u ON p.author_id = u.id
       LEFT JOIN blog_categories c ON p.category_id = c.id
      WHERE $whereStr
      ORDER BY p.created_at DESC
      LIMIT $perPage OFFSET $offset"
);
$postsSt->execute($params);
$posts = $postsSt->fetchAll();

// ── Category being edited ─────────────────────────────────────
$editCat = null;
if ($action === 'categories' && isset($_GET['edit_cat'])) {
    $st = $db->prepare('SELECT * FROM blog_categories WHERE id=?');
    $st->execute([(int)$_GET['edit_cat']]);
    $editCat = $st->fetch();
}

// ─────────────────────────────────────────────────────────────
renderHeader('Blog Management', 'blog');
?>

<style>
/* ── Blog-specific styles ───────────────────────── */
.status-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    font-size: .71rem; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;
}
.status-published { background: #d1fae5; color: #065f46; }
.status-draft      { background: #fef3c7; color: #92400e; }
.status-archived   { background: #f1f5f9; color: #475569; }

.post-card {
    background: #fff; border-radius: 12px;
    border: 1px solid #e2e8f0;
    transition: box-shadow .2s, transform .2s;
    overflow: hidden; height: 100%;
    display: flex; flex-direction: column;
}
.post-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.09); transform: translateY(-2px); }
.post-card-cover {
    height: 130px; background: linear-gradient(135deg,#3b82f6 0%,#8b5cf6 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: 2.5rem; color: rgba(255,255,255,.4);
}
.post-card-body { padding: 16px; flex: 1; display: flex; flex-direction: column; }
.post-card-title { font-size: .9rem; font-weight: 700; color: #0f172a; margin-bottom: 6px; line-height: 1.35; }
.post-card-meta  { font-size: .74rem; color: #64748b; margin-bottom: 10px; }
.post-card-footer { margin-top: auto; padding-top: 10px; border-top: 1px solid #f1f5f9;
                    display: flex; align-items: center; justify-content: space-between; }

.filter-bar { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:14px 18px; margin-bottom:20px; }

.blog-editor textarea {
    font-family: 'JetBrains Mono','Fira Code','Courier New', monospace;
    font-size: .84rem; line-height: 1.7;
    resize: vertical; min-height: 340px;
}
.char-count { font-size: .72rem; color: #94a3b8; text-align: right; margin-top: 3px; }

.post-preview-body {
    line-height: 1.8; font-size: .92rem; color: #334155;
    white-space: pre-wrap; word-break: break-word;
}

.cat-badge {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    font-size: .7rem; font-weight: 600;
    background: #ede9fe; color: #5b21b6;
}

/* Tab nav override for blog sub-pages */
.blog-tabs .nav-link {
    color: #64748b; font-size: .84rem; font-weight: 500;
    border: none; border-bottom: 2px solid transparent;
    border-radius: 0; padding: 8px 16px;
}
.blog-tabs .nav-link.active { color: #3b82f6; border-bottom-color: #3b82f6; background: none; }
.blog-tabs .nav-link:hover  { color: #0f172a; background: #f8fafc; }
</style>

<!-- ── Stats row ────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $blogCards = [
    ['label'=>'Total Posts',      'value'=>$stats['total'],     'icon'=>'file-richtext-fill', 'color'=>'3b82f6','bg'=>'dbeafe'],
    ['label'=>'Published',        'value'=>$stats['published'], 'icon'=>'check-circle-fill',  'color'=>'10b981','bg'=>'d1fae5'],
    ['label'=>'Drafts',           'value'=>$stats['draft'],     'icon'=>'pencil-square',       'color'=>'f59e0b','bg'=>'fef3c7'],
    ['label'=>'Total Views',      'value'=>number_format($stats['views']),'icon'=>'eye-fill','color'=>'8b5cf6','bg'=>'ede9fe'],
    ['label'=>'Categories',       'value'=>count($categories),  'icon'=>'tags-fill',           'color'=>'ef4444','bg'=>'fee2e2'],
  ];
  foreach ($blogCards as $c): ?>
  <div class="col-6 col-md-4 col-xl">
    <div class="stat-card h-100">
      <div class="stat-icon mb-3" style="background:#<?= $c['bg'] ?>;color:#<?= $c['color'] ?>">
        <i class="bi bi-<?= $c['icon'] ?>"></i>
      </div>
      <div class="stat-value"><?= $c['value'] ?></div>
      <div class="stat-label mt-1"><?= $c['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Sub-nav tabs ──────────────────────────────────────────── -->
<div class="content-card mb-4">
  <div style="padding:0 8px">
    <ul class="nav blog-tabs">
      <li class="nav-item">
        <a class="nav-link <?= $action==='list' ? 'active' : '' ?>" href="superadmin_blog.php">
          <i class="bi bi-grid me-1"></i>All Posts
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $action==='new' ? 'active' : '' ?>" href="superadmin_blog.php?action=new">
          <i class="bi bi-plus-circle me-1"></i>New Post
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $action==='categories' ? 'active' : '' ?>" href="superadmin_blog.php?action=categories">
          <i class="bi bi-tags me-1"></i>Categories
        </a>
      </li>
    </ul>
  </div>
</div>

<?php /* ═══════════════════════════════════════════════════════
         PANEL: POST LIST
       ═══════════════════════════════════════════════════════ */ ?>
<?php if ($action === 'list'): ?>

<!-- Filter bar -->
<form method="get" action="superadmin_blog.php" class="filter-bar">
  <div class="row g-2 align-items-end">
    <div class="col-md-5">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Search posts…"
               value="<?= e($search) ?>">
      </div>
    </div>
    <div class="col-md-2">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach (['draft','published','archived'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterS===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="cat" class="form-select form-select-sm">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $filterC===$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button class="btn btn-primary btn-sm flex-grow-1">Filter</button>
      <?php if ($search || $filterS || $filterC): ?>
      <a href="superadmin_blog.php" class="btn btn-outline-secondary btn-sm">✕</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<!-- Results summary -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <span class="text-muted small">
    <?= $totalCount ?> post<?= $totalCount!=1?'s':'' ?> found
    <?= $search ? '· query: <strong>'.e($search).'</strong>' : '' ?>
  </span>
  <a href="superadmin_blog.php?action=new" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>New Post
  </a>
</div>

<?php if (empty($posts)): ?>
<div class="content-card text-center py-5">
  <i class="bi bi-file-richtext text-muted" style="font-size:3rem"></i>
  <p class="text-muted mt-3 mb-2">No posts found.</p>
  <a href="superadmin_blog.php?action=new" class="btn btn-primary btn-sm">Write Your First Post</a>
</div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($posts as $p): ?>
  <div class="col-md-6 col-xl-4">
    <div class="post-card">
      <div class="post-card-cover">
        <i class="bi bi-file-richtext"></i>
      </div>
      <div class="post-card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="status-pill status-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
          <?php if ($p['cat_name']): ?>
          <span class="cat-badge"><?= e($p['cat_name']) ?></span>
          <?php endif; ?>
        </div>
        <div class="post-card-title"><?= e($p['title']) ?></div>
        <div class="post-card-meta">
          <i class="bi bi-person me-1"></i><?= e($p['author_name']) ?>
          &nbsp;·&nbsp;
          <i class="bi bi-calendar3 me-1"></i><?= formatDate($p['created_at']) ?>
        </div>
        <div class="post-card-footer">
          <span class="text-muted small"><i class="bi bi-eye me-1"></i><?= number_format($p['views']) ?> views</span>
          <div class="d-flex gap-1">
            <a href="superadmin_blog.php?action=view&id=<?= $p['id'] ?>"
               class="btn btn-sm btn-outline-info" title="Preview"><i class="bi bi-eye"></i></a>
            <a href="superadmin_blog.php?action=edit&id=<?= $p['id'] ?>"
               class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Delete this post permanently?')">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-4 d-flex justify-content-center">
  <ul class="pagination pagination-sm mb-0">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?= $i===$page?'active':'' ?>">
      <a class="page-link"
         href="superadmin_blog.php?p=<?= $i ?>&q=<?= urlencode($search) ?>&status=<?= $filterS ?>&cat=<?= $filterC ?>">
        <?= $i ?>
      </a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; /* empty posts */ ?>

<?php /* ═══════════════════════════════════════════════════════
         PANEL: NEW / EDIT POST
       ═══════════════════════════════════════════════════════ */ ?>
<?php elseif ($action === 'new' || $action === 'edit'): ?>

<div class="content-card blog-editor">
  <div class="card-header-custom">
    <h6>
      <i class="bi bi-<?= $action==='new' ? 'plus-circle' : 'pencil-square' ?> me-2 text-primary"></i>
      <?= $action==='new' ? 'New Post' : 'Edit Post: ' . e($editPost['title']) ?>
    </h6>
    <?php if ($action === 'edit'): ?>
    <div class="d-flex gap-2">
      <a href="superadmin_blog.php?action=view&id=<?= $postId ?>" class="btn btn-sm btn-outline-info">
        <i class="bi bi-eye me-1"></i>Preview
      </a>
      <a href="superadmin_blog.php" class="btn btn-sm btn-outline-secondary">← All Posts</a>
    </div>
    <?php else: ?>
    <a href="superadmin_blog.php" class="btn btn-sm btn-outline-secondary">← All Posts</a>
    <?php endif; ?>
  </div>

  <div class="card-body-custom">
    <form method="post" action="superadmin_blog.php">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="form_action" value="save_post">
      <input type="hidden" name="post_id" value="<?= $postId ?>">

      <div class="row g-3">

        <!-- Title -->
        <div class="col-12">
          <label class="form-label">Post Title <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control"
                 placeholder="Enter post title…"
                 value="<?= e($editPost['title'] ?? '') ?>" required
                 oninput="updateCharCount(this,'title-count')">
          <div class="char-count" id="title-count"></div>
        </div>

        <!-- Excerpt -->
        <div class="col-12">
          <label class="form-label">Excerpt
            <span class="text-muted fw-400" style="font-size:.78rem">— short summary shown in listings</span>
          </label>
          <textarea name="excerpt" class="form-control" rows="2"
                    placeholder="Optional short summary…"
                    oninput="updateCharCount(this,'excerpt-count')"><?= e($editPost['excerpt'] ?? '') ?></textarea>
          <div class="char-count" id="excerpt-count"></div>
        </div>

        <!-- Body -->
        <div class="col-12">
          <label class="form-label">Content <span class="text-danger">*</span></label>
          <textarea name="body" class="form-control" rows="16"
                    placeholder="Write your post content here…"
                    required
                    oninput="updateCharCount(this,'body-count')"><?= e($editPost['body'] ?? '') ?></textarea>
          <div class="char-count" id="body-count"></div>
        </div>

        <!-- Sidebar: meta fields -->
        <div class="col-md-4">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-select">
            <option value="">— Uncategorised —</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"
              <?= (($editPost['category_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
              <?= e($c['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $v=>$l): ?>
            <option value="<?= $v ?>"
              <?= (($editPost['status'] ?? 'draft') === $v) ? 'selected' : '' ?>>
              <?= $l ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Cover Image URL
            <span class="text-muted fw-400" style="font-size:.78rem">optional</span>
          </label>
          <input type="url" name="cover_image" class="form-control"
                 placeholder="https://…"
                 value="<?= e($editPost['cover_image'] ?? '') ?>">
        </div>

        <!-- Action buttons -->
        <div class="col-12 d-flex gap-2 pt-2">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-floppy me-1"></i>
            <?= $action==='new' ? 'Create Post' : 'Save Changes' ?>
          </button>
          <a href="superadmin_blog.php" class="btn btn-outline-secondary">Cancel</a>
          <?php if ($action === 'edit'): ?>
          <form method="post" class="ms-auto d-inline"
                onsubmit="return confirm('Delete this post permanently?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="delete">
            <input type="hidden" name="post_id" value="<?= $postId ?>">
            <button class="btn btn-outline-danger">
              <i class="bi bi-trash me-1"></i>Delete Post
            </button>
          </form>
          <?php endif; ?>
        </div>

      </div><!-- /row -->
    </form>
  </div>
</div>

<script>
function updateCharCount(el, countId) {
    const len = el.value.length;
    const el2 = document.getElementById(countId);
    if (el2) el2.textContent = len + ' characters';
}
// Init on page load
document.querySelectorAll('[oninput^=updateChar]').forEach(el => {
    el.dispatchEvent(new Event('input'));
});
</script>

<?php /* ═══════════════════════════════════════════════════════
         PANEL: POST PREVIEW
       ═══════════════════════════════════════════════════════ */ ?>
<?php elseif ($action === 'view' && $editPost): ?>

<div class="content-card">
  <div class="card-header-custom">
    <h6><i class="bi bi-eye me-2 text-info"></i>Post Preview</h6>
    <div class="d-flex gap-2">
      <a href="superadmin_blog.php?action=edit&id=<?= $postId ?>" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-pencil me-1"></i>Edit
      </a>
      <a href="superadmin_blog.php" class="btn btn-sm btn-outline-secondary">← All Posts</a>
    </div>
  </div>
  <div class="card-body-custom" style="max-width:860px">

    <!-- Cover image (if set) -->
    <?php if ($editPost['cover_image']): ?>
    <img src="<?= e($editPost['cover_image']) ?>"
         alt="Cover" class="w-100 rounded mb-4"
         style="max-height:320px;object-fit:cover"
         onerror="this.style.display='none'">
    <?php endif; ?>

    <!-- Meta row -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <span class="status-pill status-<?= $editPost['status'] ?>"><?= ucfirst($editPost['status']) ?></span>
      <?php if ($editPost['cat_name']): ?>
      <span class="cat-badge"><?= e($editPost['cat_name']) ?></span>
      <?php endif; ?>
      <span class="text-muted small ms-auto">
        <i class="bi bi-person me-1"></i><?= e($editPost['author_name']) ?>
        &nbsp;·&nbsp;
        <i class="bi bi-calendar3 me-1"></i><?= formatDate($editPost['created_at']) ?>
        &nbsp;·&nbsp;
        <i class="bi bi-eye me-1"></i><?= number_format($editPost['views']) ?> views
      </span>
    </div>

    <!-- Title -->
    <h2 style="font-size:1.6rem;font-weight:700;color:#0f172a;margin-bottom:8px">
      <?= e($editPost['title']) ?>
    </h2>

    <!-- Excerpt -->
    <?php if ($editPost['excerpt']): ?>
    <p style="font-size:.95rem;color:#475569;font-style:italic;border-left:3px solid #3b82f6;padding-left:12px;margin-bottom:20px">
      <?= e($editPost['excerpt']) ?>
    </p>
    <?php endif; ?>

    <hr style="border-color:#e2e8f0">

    <!-- Body -->
    <div class="post-preview-body mt-4"><?= e($editPost['body']) ?></div>

  </div>
</div>

<?php /* ═══════════════════════════════════════════════════════
         PANEL: CATEGORIES
       ═══════════════════════════════════════════════════════ */ ?>
<?php elseif ($action === 'categories'): ?>

<div class="row g-4">

  <!-- Category list -->
  <div class="col-md-7">
    <div class="content-card">
      <div class="card-header-custom">
        <h6><i class="bi bi-tags me-2 text-primary"></i>All Categories</h6>
      </div>
      <?php
      $allCats = $db->query(
          'SELECT c.*, COUNT(p.id) AS post_count
             FROM blog_categories c
             LEFT JOIN blog_posts p ON p.category_id = c.id
             GROUP BY c.id
             ORDER BY c.name'
      )->fetchAll();
      ?>
      <?php if (empty($allCats)): ?>
      <div class="card-body-custom text-center text-muted small py-4">No categories yet.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-custom mb-0">
          <thead>
            <tr>
              <th>#</th><th>Name</th><th>Slug</th><th>Posts</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($allCats as $c): ?>
            <tr>
              <td class="text-muted small"><?= $c['id'] ?></td>
              <td class="fw-600"><?= e($c['name']) ?></td>
              <td><code class="small"><?= e($c['slug']) ?></code></td>
              <td><span class="badge bg-primary"><?= $c['post_count'] ?></span></td>
              <td>
                <div class="d-flex gap-1">
                  <a href="superadmin_blog.php?action=categories&edit_cat=<?= $c['id'] ?>"
                     class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete category? Posts will become uncategorised.')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="delete_category">
                    <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Add / Edit category form -->
  <div class="col-md-5">
    <div class="content-card">
      <div class="card-header-custom">
        <h6>
          <i class="bi bi-<?= $editCat ? 'pencil-square' : 'plus-circle' ?> me-2 text-success"></i>
          <?= $editCat ? 'Edit Category' : 'Add Category' ?>
        </h6>
        <?php if ($editCat): ?>
        <a href="superadmin_blog.php?action=categories" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <?php endif; ?>
      </div>
      <div class="card-body-custom">
        <form method="post" action="superadmin_blog.php">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="form_action" value="save_category">
          <input type="hidden" name="cat_id" value="<?= $editCat['id'] ?? 0 ?>">

          <div class="mb-3">
            <label class="form-label">Category Name <span class="text-danger">*</span></label>
            <input type="text" name="cat_name" class="form-control"
                   value="<?= e($editCat['name'] ?? '') ?>"
                   placeholder="e.g. Technology" required>
            <div class="form-text">Slug is auto-generated from the name.</div>
          </div>

          <button type="submit" class="btn btn-success btn-sm">
            <i class="bi bi-floppy me-1"></i><?= $editCat ? 'Update' : 'Add Category' ?>
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

<?php endif; /* end action panels */ ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
renderFooter();
?>