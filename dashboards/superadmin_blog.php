<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// ── Query params ─────────────────────────────────────────────
$filterCat  = trim($_GET['cat']    ?? '');
$search     = trim($_GET['q']      ?? '');
$slug       = trim($_GET['post']   ?? '');
$page       = max(1, (int)($_GET['p'] ?? 1));
$perPage    = 9;
$offset     = ($page - 1) * $perPage;

// ── Single post view ─────────────────────────────────────────
$singlePost = null;
if ($slug !== '') {
    $st = $db->prepare(
        'SELECT p.*, u.full_name AS author_name, c.name AS cat_name,
                    COALESCE(p.custom_author_name, u.full_name) AS display_author
           FROM blog_posts p
           LEFT JOIN users u ON p.author_id = u.id
           LEFT JOIN blog_categories c ON p.category_id = c.id
          WHERE p.slug = ? AND p.status = "published"'
    );
    $st->execute([$slug]);
    $singlePost = $st->fetch();
    if ($singlePost) {
        $db->prepare('UPDATE blog_posts SET views = views + 1 WHERE id=?')
           ->execute([$singlePost['id']]);
    }
}

// ── Blog list ─────────────────────────────────────────────────
$where  = ['p.status = "published"'];
$params = [];
if ($filterCat !== '') {
    $where[]  = 'c.name = ?';
    $params[] = $filterCat;
}
if ($search !== '') {
    $where[]  = '(p.title LIKE ? OR p.excerpt LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$whereStr = implode(' AND ', $where);

$totalCount = 0;
$posts      = [];
$categories = [];

if (!$singlePost) {
    $tc = $db->prepare("SELECT COUNT(*) FROM blog_posts p LEFT JOIN blog_categories c ON p.category_id = c.id WHERE $whereStr");
    $tc->execute($params);
    $totalCount = (int)$tc->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCount / $perPage));

    $st2 = $db->prepare(
        "SELECT p.id, p.title, p.slug, p.excerpt, p.views, p.published_at,
                     p.cover_image, p.cover_image_file,
                     COALESCE(p.custom_author_name, u.full_name) AS author_name, c.name AS cat_name
           FROM blog_posts p
           LEFT JOIN users u ON p.author_id = u.id
           LEFT JOIN blog_categories c ON p.category_id = c.id
          WHERE $whereStr
          ORDER BY p.published_at DESC
          LIMIT $perPage OFFSET $offset"
    );
    $st2->execute($params);
    $posts = $st2->fetchAll();

    // Categories with counts
    $categories = $db->query(
        'SELECT c.name, COUNT(p.id) AS cnt
           FROM blog_categories c
           JOIN blog_posts p ON p.category_id = c.id
          WHERE p.status = "published"
          GROUP BY c.id
          ORDER BY cnt DESC'
    )->fetchAll();
}

// ── Recent posts for sidebar ──────────────────────────────────
$recentPosts = $db->query(
    'SELECT p.title, p.slug, p.published_at, c.name AS cat_name
       FROM blog_posts p
       LEFT JOIN blog_categories c ON p.category_id = c.id
      WHERE p.status = "published"
      ORDER BY p.published_at DESC
      LIMIT 5'
)->fetchAll();

// ── Helpers ───────────────────────────────────────────────────
function e2(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function fmtDate(string $d): string {
    return $d ? date('d M Y', strtotime($d)) : '—';
}

function readTime(string $body): int {
    return max(1, (int)ceil(str_word_count($body) / 200));
}

// Category colour map matching AIFLA palette
$catAccent = [
    'Announcements' => ['bg'=>'#dbeafe','color'=>'#1d4ed8','bar'=>'#4a90e2'],
    'Academic'      => ['bg'=>'#ede9fe','color'=>'#6d28d9','bar'=>'#a78bfa'],
    'Events'        => ['bg'=>'#dcfce7','color'=>'#15803d','bar'=>'#22c55e'],
    'Technology'    => ['bg'=>'#ccfbf1','color'=>'#0d9488','bar'=>'#06b6d4'],
    'General'       => ['bg'=>'#fef3c7','color'=>'#b45309','bar'=>'#f59e0b'],
];
function catStyle(string $cat, array $map): array {
    return $map[$cat] ?? ['bg'=>'#f1f5f9','color'=>'#475569','bar'=>'#94a3b8'];
}

// Blog upload URL
define('UPLOAD_BLOG_URL', rtrim(str_replace('/rbms','',defined('BASE_URL')?BASE_URL:''),'/') . '/rbms/uploads/blog/');

// BASE_URL points to rbms/ — strip that to get the site root
$baseUrl = defined('BASE_URL') ? rtrim(str_replace('/rbms', '', BASE_URL), '/') : '';
$selfUrl  = $baseUrl . '/blog.php';

$pageTitle = $singlePost ? e2($singlePost['title']) . ' — AIFLA Blog' : 'Student Blog — AI Future Leaders Academy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?></title>
<link rel="icon" type="image/png" href="logo.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════════
   ROOT & RESET  — mirrors the main site exactly
════════════════════════════════════════════════════════════ */
:root {
  --navy:   #1a2540;
  --navy2:  #1e2d4f;
  --navy3:  #243058;
  --blue:   #4a90e2;
  --blue2:  #3a7fd5;
  --green:  #22c55e;
  --purple: #a78bfa;
  --orange: #f59e0b;
  --pink:   #ec4899;
  --teal:   #06b6d4;
  --bg:     #f0f2f8;
  --white:  #fff;
  --text:   #1a2540;
  --mid:    #4a5568;
  --dim:    #8896a8;
  --border: #dde3ef;
  --sh:     0 4px 24px rgba(26,37,64,.08);
  --sh2:    0 12px 40px rgba(26,37,64,.16);
  --r:      16px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--text);
  overflow-x: hidden;
  cursor: none;
}

/* ── Custom cursor (matches main site) ── */
.cursor      { width:10px;height:10px;background:var(--blue);border-radius:50%;position:fixed;pointer-events:none;z-index:99999;top:0;left:0;transition:transform .15s; }
.cursor-ring { width:30px;height:30px;border:2px solid rgba(74,144,226,.4);border-radius:50%;position:fixed;pointer-events:none;z-index:99998;top:0;left:0; }

/* ── Navbar ── (exact replica) ── */
nav {
  background: var(--navy);
  position: fixed; top:0; left:0; right:0;
  z-index: 1000; height: 64px;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 40px;
  box-shadow: 0 2px 20px rgba(26,37,64,.3);
}
.nav-logo { display:flex;align-items:center;gap:10px;cursor:pointer;text-decoration:none; }
.nav-logo-icon { width:40px;height:40px;border-radius:50%;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center; }
.nav-logo-icon img { width:100%;height:100%;object-fit:cover; }
.nav-logo-text { font-size:14px;font-weight:800;color:#fff;line-height:1.2; }
.nav-logo-sub  { font-size:10px;color:rgba(255,255,255,.45);letter-spacing:.5px; }
.nav-links { display:flex;align-items:center;gap:6px; }
.nav-link {
  padding:8px 14px;font-size:14px;font-weight:500;
  color:rgba(255,255,255,.6);cursor:pointer;border-radius:8px;
  transition:all .2s;text-decoration:none;
}
.nav-link:hover, .nav-link.active { background:rgba(255,255,255,.1);color:#fff; }
.btn-nav {
  padding:8px 18px;background:var(--blue);color:#fff;
  font-family:'Inter',sans-serif;font-size:13px;font-weight:700;
  border:none;border-radius:8px;cursor:pointer;transition:all .2s;text-decoration:none;
  display:inline-block;
}
.btn-nav:hover { background:var(--blue2);transform:translateY(-1px); }

/* Hamburger */
.nav-hamburger {
  display:none;flex-direction:column;justify-content:center;gap:5px;
  width:36px;height:36px;background:rgba(255,255,255,.08);border:none;
  border-radius:8px;cursor:pointer;padding:7px;z-index:1100;
}
.nav-hamburger span { display:block;height:2px;background:#fff;border-radius:2px;transition:all .3s; }
.nav-hamburger.open span:nth-child(1) { transform:translateY(7px) rotate(45deg); }
.nav-hamburger.open span:nth-child(2) { opacity:0;transform:scaleX(0); }
.nav-hamburger.open span:nth-child(3) { transform:translateY(-7px) rotate(-45deg); }
.mobile-menu {
  display:none;position:fixed;top:64px;left:0;right:0;
  background:var(--navy);z-index:999;padding:16px 20px 24px;
  box-shadow:0 8px 32px rgba(26,37,64,.4);
  border-top:1px solid rgba(255,255,255,.07);
  flex-direction:column;gap:4px;
}
.mobile-menu.open { display:flex; }
.mobile-menu .nav-link { padding:12px 16px;font-size:15px;border-radius:10px;color:rgba(255,255,255,.7); }

/* ── Page shell ── */
.page-content { padding-top: 64px; }

/* ── Ticker ── */
@keyframes ticker { from{transform:translateX(0)} to{transform:translateX(-50%)} }
.ticker-wrap  { background:var(--navy2);border-top:1px solid rgba(255,255,255,.06);padding:9px 0;overflow:hidden; }
.ticker-inner { display:flex;gap:56px;animation:ticker 28s linear infinite;white-space:nowrap; }
.ticker-item  { font-family:'Space Mono',monospace;font-size:11px;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:1.5px;display:flex;align-items:center;gap:10px; }
.ticker-dot   { color:var(--blue); }

/* ── Blog Hero ── */
.blog-hero {
  background: var(--navy);
  padding: 52px 60px 44px;
  position: relative;
  overflow: hidden;
}
.blog-hero::before {
  content:'';position:absolute;width:480px;height:480px;
  background:rgba(74,144,226,.07);border-radius:50%;
  top:-150px;right:-100px;pointer-events:none;
}
.blog-hero::after {
  content:'';position:absolute;width:280px;height:280px;
  background:rgba(167,139,250,.05);border-radius:50%;
  bottom:-100px;left:60px;pointer-events:none;
}
.blog-hero-inner { max-width:1280px;margin:0 auto;position:relative;z-index:1; }
.blog-hero-badge {
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);
  padding:5px 14px;border-radius:100px;font-size:12px;font-weight:600;
  color:rgba(255,255,255,.8);margin-bottom:20px;
}
.bpulse { width:6px;height:6px;background:var(--green);border-radius:50%;animation:bpulse 2s infinite; }
@keyframes bpulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.8)} }
.blog-hero-title {
  font-size: clamp(28px, 4vw, 52px);
  font-weight: 900;
  color: #fff;
  letter-spacing: -1.5px;
  line-height: 1.1;
  margin-bottom: 14px;
}
.blog-hero-title .hl {
  background: linear-gradient(90deg,#60b3ff,#a78bfa);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.blog-hero-sub { font-size:15px;color:rgba(255,255,255,.5);line-height:1.65;max-width:520px; }
.blog-hero-meta {
  display:flex;gap:28px;margin-top:28px;flex-wrap:wrap;
}
.hero-meta-item { text-align:left; }
.hero-meta-val { font-size:24px;font-weight:900;color:#fff; }
.hero-meta-lbl { font-size:11px;color:rgba(255,255,255,.4);font-weight:500;margin-top:2px; }
.hero-meta-div { width:1px;background:rgba(255,255,255,.12); }

/* ── Search bar in hero ── */
.hero-search-wrap {
  margin-top: 28px;
  max-width: 520px;
}
.hero-search {
  display:flex;gap:0;background:rgba(255,255,255,.1);
  border:1px solid rgba(255,255,255,.18);border-radius:12px;overflow:hidden;
  transition:border-color .2s,background .2s;
}
.hero-search:focus-within {
  background:rgba(255,255,255,.16);border-color:rgba(74,144,226,.6);
}
.hero-search input {
  flex:1;background:none;border:none;outline:none;padding:12px 16px;
  font-family:'Inter',sans-serif;font-size:14px;color:#fff;
}
.hero-search input::placeholder { color:rgba(255,255,255,.4); }
.hero-search button {
  padding:10px 18px;background:var(--blue);border:none;cursor:pointer;
  color:#fff;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;
  transition:background .2s;
}
.hero-search button:hover { background:var(--blue2); }

/* ── Layout ── */
.blog-layout {
  max-width: 1340px;
  margin: 0 auto;
  padding: 40px 60px 60px;
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 32px;
  align-items: start;
}

/* ── Filter pills ── */
.filter-row {
  display:flex;gap:8px;flex-wrap:wrap;margin-bottom:28px;
}
.filter-pill {
  padding:7px 16px;border-radius:100px;font-size:13px;font-weight:600;
  background:var(--white);color:var(--mid);border:1.5px solid var(--border);
  cursor:pointer;transition:all .2s;text-decoration:none;
}
.filter-pill:hover, .filter-pill.active {
  background:var(--blue);color:#fff;border-color:var(--blue);
}

/* ── Post grid ── */
.posts-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 18px;
}
.posts-grid.single { grid-template-columns: 1fr; }

/* ── Blog Card ── */
.blog-card {
  background: var(--white);
  border-radius: var(--r);
  box-shadow: var(--sh);
  overflow: hidden;
  transition: all .3s;
  cursor: pointer;
  border: 1.5px solid transparent;
  position: relative;
  text-decoration: none;
  display: block;
}
.blog-card:hover {
  transform: translateY(-5px);
  box-shadow: var(--sh2);
  border-color: var(--border);
}
.blog-card::before {
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  opacity:0;transition:opacity .3s;
}
.blog-card:hover::before { opacity:1; }
.blog-card-cover {
  height: 160px;
  display: flex;align-items:center;justify-content:center;
  font-size:3rem;position:relative;overflow:hidden;
}
.blog-card-cover-img {
  position:absolute;inset:0;width:100%;height:100%;object-fit:cover;
}
.blog-card-body { padding: 22px; }
.blog-cat-pill {
  display:inline-block;padding:3px 11px;border-radius:100px;
  font-size:11px;font-weight:700;margin-bottom:10px;
}
.blog-card-title {
  font-size:15px;font-weight:800;color:var(--text);
  margin-bottom:8px;line-height:1.4;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.blog-card-excerpt {
  font-size:13px;color:var(--mid);line-height:1.65;
  display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;
}
.blog-card-footer {
  padding:13px 22px;border-top:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
}
.blog-author { display:flex;align-items:center;gap:8px; }
.author-av {
  width:26px;height:26px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:10px;font-weight:700;color:#fff;flex-shrink:0;
}
.author-nm  { font-size:11px;font-weight:600;color:var(--dim); }
.read-meta  { display:flex;align-items:center;gap:10px; }
.read-link  { font-size:12px;font-weight:700;color:var(--blue); }
.views-lbl  { font-size:11px;color:var(--dim); }

/* Featured card (first post) spans both cols */
.blog-card.featured {
  grid-column: 1 / -1;
}
.blog-card.featured .blog-card-cover { height: 240px; }
.blog-card.featured .blog-card-title { font-size:20px; -webkit-line-clamp:2; }
.blog-card.featured .blog-card-excerpt { -webkit-line-clamp:3; }

/* ── Empty state ── */
.empty-state {
  grid-column: 1/-1;
  text-align:center;padding:60px 20px;
  color:var(--dim);
}
.empty-state .empty-icon { font-size:3.5rem;margin-bottom:14px; }
.empty-state p { font-size:15px;margin-bottom:16px; }

/* ── Pagination ── */
.pagination {
  display:flex;gap:6px;justify-content:flex-start;margin-top:28px;flex-wrap:wrap;
}
.page-btn {
  padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;
  background:var(--white);color:var(--mid);border:1.5px solid var(--border);
  cursor:pointer;transition:all .2s;text-decoration:none;
}
.page-btn:hover, .page-btn.active {
  background:var(--blue);color:#fff;border-color:var(--blue);
}
.page-btn.disabled { opacity:.4;pointer-events:none; }

/* ── Sidebar ── */
.sidebar { position:sticky;top:80px;display:flex;flex-direction:column;gap:20px; }
.sidebar-card {
  background:var(--white);border-radius:var(--r);
  box-shadow:var(--sh);padding:24px;
}
.sidebar-title {
  font-size:11px;font-weight:700;color:var(--dim);
  text-transform:uppercase;letter-spacing:1.5px;margin-bottom:16px;
  display:flex;align-items:center;gap:8px;
}
.sidebar-title::after {
  content:'';flex:1;height:1px;background:var(--border);
}

/* Category list */
.cat-list-item {
  display:flex;align-items:center;justify-content:space-between;
  padding:8px 0;border-bottom:1px solid var(--border);
  text-decoration:none;transition:all .2s;
}
.cat-list-item:last-child { border-bottom:none; }
.cat-list-item:hover .cat-list-name { color:var(--blue); }
.cat-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
.cat-list-name { font-size:13px;font-weight:600;color:var(--text);margin-left:10px;flex:1; }
.cat-count { font-size:11px;font-weight:700;color:var(--white);padding:2px 8px;border-radius:100px;background:var(--blue); }

/* Recent posts in sidebar */
.recent-item {
  display:flex;gap:10px;align-items:flex-start;padding:10px 0;
  border-bottom:1px solid var(--border);text-decoration:none;
  transition:all .2s;
}
.recent-item:last-child { border-bottom:none; }
.recent-item:hover .recent-title { color:var(--blue); }
.recent-num {
  width:28px;height:28px;border-radius:7px;background:var(--bg);
  display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:800;color:var(--mid);flex-shrink:0;
  margin-top:1px;
}
.recent-title { font-size:12px;font-weight:700;color:var(--text);line-height:1.45;margin-bottom:3px; }
.recent-date  { font-size:10px;color:var(--dim); }

/* Tags cloud */
.tag-cloud { display:flex;flex-wrap:wrap;gap:7px; }
.tag-chip {
  padding:5px 12px;border-radius:100px;font-size:11px;font-weight:700;
  background:var(--bg);color:var(--mid);border:1.5px solid var(--border);
  cursor:pointer;transition:all .2s;text-decoration:none;
}
.tag-chip:hover { background:var(--navy);color:#fff;border-color:var(--navy); }

/* ── SINGLE POST VIEW ── */
.post-hero {
  background: var(--navy);
  padding: 56px 60px 48px;
  position: relative; overflow:hidden;
}
.post-hero::before {
  content:'';position:absolute;width:560px;height:560px;
  background:rgba(74,144,226,.06);border-radius:50%;
  top:-180px;right:-120px;pointer-events:none;
}
.post-hero-inner { max-width:880px;margin:0 auto;position:relative;z-index:1; }
.back-link {
  display:inline-flex;align-items:center;gap:6px;
  font-size:13px;font-weight:600;color:rgba(255,255,255,.5);
  text-decoration:none;margin-bottom:20px;transition:color .2s;
}
.back-link:hover { color:#fff; }
.post-cat-badge {
  display:inline-block;padding:4px 14px;border-radius:100px;
  font-size:12px;font-weight:700;margin-bottom:16px;
}
.post-title {
  font-size: clamp(26px, 4vw, 48px);
  font-weight: 900; color: #fff;
  letter-spacing: -1.5px; line-height: 1.1;
  margin-bottom: 18px;
}
.post-meta-row {
  display:flex;gap:18px;flex-wrap:wrap;align-items:center;
  font-size:13px;color:rgba(255,255,255,.5);
}
.post-meta-row .sep { color:rgba(255,255,255,.2); }

.post-layout {
  max-width:1200px;margin:0 auto;
  padding:40px 60px 60px;
  display:grid;grid-template-columns:1fr 300px;gap:40px;align-items:start;
}
.post-cover-wrap {
  margin-bottom:32px;border-radius:var(--r);overflow:hidden;
  box-shadow:var(--sh2);
}
.post-cover-wrap img { width:100%;height:320px;object-fit:cover;display:block; }
.post-cover-emoji {
  width:100%;height:240px;background:linear-gradient(135deg,#1e2d4f,#243058);
  display:flex;align-items:center;justify-content:center;font-size:5rem;
  border-radius:var(--r);box-shadow:var(--sh2);margin-bottom:32px;
}
.post-body-card {
  background:var(--white);border-radius:var(--r);
  box-shadow:var(--sh);padding:40px 48px;
}
.post-excerpt {
  font-size:16px;color:var(--mid);font-style:italic;
  border-left:3px solid var(--blue);padding-left:16px;
  margin-bottom:28px;line-height:1.7;
}
.post-body {
  font-size:15px;color:#334155;line-height:1.85;
  white-space:pre-wrap;word-break:break-word;
}
.post-footer-row {
  display:flex;gap:10px;align-items:center;margin-top:32px;
  padding-top:24px;border-top:1px solid var(--border);
  flex-wrap:wrap;
}
.post-author-block {
  display:flex;align-items:center;gap:12px;flex:1;
}
.post-author-av {
  width:44px;height:44px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:16px;font-weight:800;color:#fff;flex-shrink:0;
  background:var(--blue);
}
.post-author-name { font-size:14px;font-weight:700;color:var(--text); }
.post-author-role { font-size:11px;color:var(--dim); }
.post-views { font-size:12px;color:var(--dim);margin-left:auto; }

/* Related posts */
.related-section { margin-top:32px; }
.related-grid { display:flex;flex-direction:column;gap:12px;margin-top:14px; }
.related-card {
  background:var(--white);border-radius:12px;padding:14px 16px;
  box-shadow:var(--sh);transition:all .25s;text-decoration:none;
  display:flex;gap:12px;align-items:center;
}
.related-card:hover { transform:translateX(4px);box-shadow:var(--sh2); }
.related-emoji { font-size:1.6rem;flex-shrink:0; }
.related-card-title { font-size:13px;font-weight:700;color:var(--text);margin-bottom:3px;line-height:1.35; }
.related-card-cat   { font-size:11px;color:var(--dim); }

/* ── Footer ── */
footer {
  background: var(--navy);
  padding: 44px 60px 24px;
}
.footer-grid {
  display:grid;grid-template-columns:1.8fr 1fr 1fr 1fr;
  gap:36px;max-width:1280px;margin:0 auto 36px;
}
.fbrand { font-size:15px;font-weight:800;color:#fff;margin-bottom:10px;display:flex;align-items:center;gap:8px; }
.fbrand-icon { width:32px;height:32px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.fbrand-icon img { width:100%;height:100%;object-fit:cover; }
.fdesc  { font-size:13px;color:rgba(255,255,255,.4);line-height:1.7; }
.fcol h4 { font-size:11px;font-weight:700;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:16px; }
.flink { display:block;font-size:13px;color:rgba(255,255,255,.5);cursor:pointer;margin-bottom:9px;transition:color .2s;text-decoration:none; }
.flink:hover { color:#fff; }
.footer-bottom {
  border-top:1px solid rgba(255,255,255,.07);padding-top:24px;
  max-width:1280px;margin:0 auto;
  display:flex;align-items:center;justify-content:space-between;
  font-size:12px;color:rgba(255,255,255,.3);
}

/* ── Reveal animation ── */
@keyframes fadeUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
.reveal { opacity:0;transform:translateY(22px);transition:opacity .6s ease,transform .6s ease; }
.reveal.visible { opacity:1;transform:translateY(0); }
.fade-in { animation:fadeUp .6s ease both; }

/* ── Responsive ── */
@media(max-width:1100px){
  .blog-layout, .post-layout { grid-template-columns:1fr;padding:32px 32px 48px; }
  .sidebar { position:static; }
  .posts-grid { grid-template-columns:1fr 1fr; }
  footer { padding:36px 32px 20px; }
  .footer-grid { grid-template-columns:1fr 1fr;gap:24px; }
}
@media(max-width:768px){
  body { cursor:auto; }
  .cursor,.cursor-ring { display:none; }
  nav { padding:0 16px;height:58px; }
  .nav-links { display:none; }
  .nav-hamburger { display:flex; }
  .page-content { padding-top:58px; }
  .blog-hero, .post-hero { padding:36px 20px 32px; }
  .blog-layout, .post-layout { padding:20px 16px 40px;gap:24px; }
  .posts-grid { grid-template-columns:1fr; }
  .blog-card.featured .blog-card-cover { height:180px; }
  .post-body-card { padding:24px 20px; }
  .post-cover-emoji { height:160px;font-size:3.5rem; }
  footer { padding:28px 16px 16px; }
  .footer-grid { grid-template-columns:1fr;gap:20px; }
  .footer-bottom { flex-direction:column;gap:6px;text-align:center; }
  .hero-search-wrap { max-width:100%; }
}
</style>
</head>
<body>

<div class="cursor" id="cursor"></div>
<div class="cursor-ring" id="cursor-ring"></div>

<!-- ════════════════════════════ NAV ════════════════════════════ -->
<nav>
  <a class="nav-logo" href="<?= $baseUrl ?>/index.html">
    <div class="nav-logo-icon"><img src="<?= $baseUrl ?>/logo.png" alt="AIFLA"></div>
    <div>
      <div class="nav-logo-text">AI Future Leaders Academy</div>
    </div>
  </a>
  <div class="nav-links">
    <a class="nav-link" href="<?= $baseUrl ?>/index.html">Home</a>
    <a class="nav-link active" href="<?= $selfUrl ?>">Blog</a>
    <a class="nav-link" href="<?= $baseUrl ?>/index.html#contact">Contact</a>
    <a class="btn-nav" href="<?= $baseUrl ?>/rbms/login.php">Student Login</a>
  </div>
  <button class="nav-hamburger" id="hamburger" onclick="toggleMenu()" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
</nav>
<div class="mobile-menu" id="mobile-menu">
  <a class="nav-link" href="<?= $baseUrl ?>/index.html" onclick="closeMenu()">Home</a>
  <a class="nav-link active" href="<?= $selfUrl ?>" onclick="closeMenu()">Blog</a>
  <a class="nav-link" href="<?= $baseUrl ?>/index.html#contact" onclick="closeMenu()">Contact</a>
  <a class="btn-nav" href="<?= $baseUrl ?>/rbms/login.php" style="margin-top:8px;text-align:center;border-radius:10px;padding:12px">Student Login</a>
</div>

<?php if ($singlePost): /* ══════════════ SINGLE POST VIEW ══════════════ */ ?>

<!-- Single post hero -->
<div class="page-content">
<div class="post-hero">
  <div class="post-hero-inner">
    <a href="<?= $selfUrl ?>" class="back-link">
      ← All Posts
    </a>
    <?php
      $cs = catStyle($singlePost['cat_name'] ?? '', $catAccent);
    ?>
    <?php if ($singlePost['cat_name']): ?>
    <div class="post-cat-badge" style="background:<?= $cs['bg'] ?>;color:<?= $cs['color'] ?>">
      <?= e2($singlePost['cat_name']) ?>
    </div>
    <?php endif; ?>
    <h1 class="post-title"><?= e2($singlePost['title']) ?></h1>
    <div class="post-meta-row">
      <span><?= e2($singlePost['author_name'] ?? 'AIFLA') ?></span>
      <span class="sep">·</span>
      <span><?= fmtDate($singlePost['published_at']) ?></span>
      <span class="sep">·</span>
      <span><?= readTime($singlePost['body']) ?> min read</span>
      <span class="sep">·</span>
      <span><?= number_format($singlePost['views']) ?> views</span>
    </div>
  </div>
</div>

<!-- Ticker -->
<div class="ticker-wrap">
  <div class="ticker-inner">
    <?php $items = ['Neural Networks','Computer Vision','Machine Learning','Reinforcement Learning','AI Ethics','LLMs and Agents','Robotics','Deep Learning'];
    foreach (array_merge($items,$items) as $item): ?>
    <span class="ticker-item"><span class="ticker-dot">◆</span><?= $item ?></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- Post body -->
<div class="post-layout">
  <div>
    <?php if ($singlePost['cover_image']): ?>
    <div class="post-cover-wrap fade-in">
      <img src="<?= e2($singlePost['cover_image']) ?>" alt="<?= e2($singlePost['title']) ?>"
           onerror="this.parentElement.style.display='none'">
    </div>
    <?php else: ?>
    <div class="post-cover-emoji fade-in">📝</div>
    <?php endif; ?>

    <div class="post-body-card fade-in" style="animation-delay:.1s">
      <?php if ($singlePost['excerpt']): ?>
      <p class="post-excerpt"><?= e2($singlePost['excerpt']) ?></p>
      <?php endif; ?>
      <div class="post-body"><?= e2($singlePost['body']) ?></div>

      <!-- Author footer -->
      <div class="post-footer-row">
        <?php
          $initials = strtoupper(substr($singlePost['author_name'] ?? 'A', 0, 1));
          $avColors = ['#4a90e2','#7c3aed','#16a34a','#d97706','#db2777','#0d9488'];
          $avColor  = $avColors[ord($initials) % count($avColors)];
        ?>
        <div class="post-author-block">
          <div class="post-author-av" style="background:<?= $avColor ?>"><?= $initials ?></div>
          <div>
            <div class="post-author-name"><?= e2($singlePost['display_author'] ?? $singlePost['author_name'] ?? 'AIFLA Team') ?></div>
            <div class="post-author-role">Published <?= fmtDate($singlePost['published_at']) ?></div>
          </div>
        </div>
        <div class="post-views">
          👁 <?= number_format($singlePost['views']) ?> reads
        </div>
      </div>
    </div>
  </div>

  <!-- Sidebar -->
  <aside class="sidebar">
    <!-- Recent posts -->
    <?php if ($recentPosts): ?>
    <div class="sidebar-card reveal">
      <div class="sidebar-title">Recent Posts</div>
      <?php foreach ($recentPosts as $i => $rp): ?>
      <a href="<?= $selfUrl ?>?post=<?= urlencode($rp['slug']) ?>" class="recent-item">
        <div class="recent-num"><?= $i + 1 ?></div>
        <div>
          <div class="recent-title"><?= e2($rp['title']) ?></div>
          <div class="recent-date"><?= fmtDate($rp['published_at']) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Categories -->
    <?php if ($categories): ?>
    <div class="sidebar-card reveal">
      <div class="sidebar-title">Categories</div>
      <?php foreach ($categories as $c):
        $cs2 = catStyle($c['name'], $catAccent);
      ?>
      <a href="<?= $selfUrl ?>?cat=<?= urlencode($c['name']) ?>" class="cat-list-item">
        <span class="cat-dot" style="background:<?= $cs2['bar'] ?>"></span>
        <span class="cat-list-name"><?= e2($c['name']) ?></span>
        <span class="cat-count"><?= $c['cnt'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Tags -->
    <div class="sidebar-card reveal">
      <div class="sidebar-title">Topics</div>
      <div class="tag-cloud">
        <?php foreach (['AI', 'Machine Learning', 'Deep Learning', 'NLP', 'Computer Vision', 'Ethics', 'Research', 'Projects', 'Python', 'PyTorch'] as $tag): ?>
        <a href="<?= $selfUrl ?>?q=<?= urlencode($tag) ?>" class="tag-chip"><?= $tag ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>
</div>

<?php else: /* ══════════════ POST LIST VIEW ══════════════ */ ?>

<!-- Blog Hero -->
<div class="page-content">
<div class="blog-hero">
  <div class="blog-hero-inner">
    <div class="blog-hero-badge fade-in">
      <span class="bpulse"></span>
      Student Knowledge Base
    </div>
    <h1 class="blog-hero-title fade-in" style="animation-delay:.1s">
      The <span class="hl">AIFLA Blog</span>
    </h1>
    <p class="blog-hero-sub fade-in" style="animation-delay:.2s">
      Published by students, for the world. Real learning, real experiments,
      real AI — from our community to yours.
    </p>
    <?php if ($totalCount > 0): ?>
    <div class="blog-hero-meta fade-in" style="animation-delay:.3s">
      <div class="hero-meta-item">
        <div class="hero-meta-val"><?= $totalCount ?></div>
        <div class="hero-meta-lbl">Posts</div>
      </div>
      <div class="hero-meta-div"></div>
      <div class="hero-meta-item">
        <div class="hero-meta-val"><?= count($categories) ?></div>
        <div class="hero-meta-lbl">Topics</div>
      </div>
      <div class="hero-meta-div"></div>
      <div class="hero-meta-item">
        <div class="hero-meta-val">
          <?php
            $totalViews = $db->query("SELECT SUM(views) FROM blog_posts WHERE status='published'")->fetchColumn();
            echo number_format($totalViews ?: 0);
          ?>
        </div>
        <div class="hero-meta-lbl">Total Reads</div>
      </div>
    </div>
    <?php endif; ?>
    <!-- Search -->
    <div class="hero-search-wrap fade-in" style="animation-delay:.4s">
      <form method="get" action="<?= $selfUrl ?>">
        <div class="hero-search">
          <input type="text" name="q" placeholder="Search posts, topics, authors…"
                 value="<?= e2($search) ?>">
          <button type="submit">Search →</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Ticker -->
<div class="ticker-wrap">
  <div class="ticker-inner">
    <?php $items = ['Neural Networks','Computer Vision','Machine Learning','Reinforcement Learning','AI Ethics','LLMs and Agents','Robotics','Deep Learning'];
    foreach (array_merge($items,$items) as $item): ?>
    <span class="ticker-item"><span class="ticker-dot">◆</span><?= $item ?></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- Main layout -->
<div class="blog-layout">
  <main>
    <!-- Filter row -->
    <?php if ($categories): ?>
    <div class="filter-row">
      <a href="<?= $selfUrl ?>"
         class="filter-pill <?= $filterCat === '' && $search === '' ? 'active' : '' ?>">
        All Posts
      </a>
      <?php foreach ($categories as $c): ?>
      <a href="<?= $selfUrl ?>?cat=<?= urlencode($c['name']) ?>"
         class="filter-pill <?= $filterCat === $c['name'] ? 'active' : '' ?>">
        <?= e2($c['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Results header -->
    <?php if ($search !== '' || $filterCat !== ''): ?>
    <div style="margin-bottom:16px;font-size:13px;color:var(--mid);">
      <?php if ($search !== ''): ?>
        Showing <strong><?= $totalCount ?></strong> result<?= $totalCount != 1 ? 's' : '' ?>
        for <strong>"<?= e2($search) ?>"</strong>
        — <a href="<?= $selfUrl ?>" style="color:var(--blue);">clear</a>
      <?php else: ?>
        <strong><?= $totalCount ?></strong> post<?= $totalCount != 1 ? 's' : '' ?>
        in <strong><?= e2($filterCat) ?></strong>
        — <a href="<?= $selfUrl ?>" style="color:var(--blue);">view all</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Posts grid -->
    <div class="posts-grid <?= empty($posts) ? 'single' : '' ?>">
      <?php if (empty($posts)): ?>
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <p><?= $search ? 'No posts match your search.' : 'No posts yet in this category.' ?></p>
          <a href="<?= $selfUrl ?>" class="filter-pill active">Back to All Posts</a>
        </div>
      <?php else:
        foreach ($posts as $idx => $p):
          $cs   = catStyle($p['cat_name'] ?? '', $catAccent);
          $featured = ($idx === 0 && $page === 1 && $filterCat === '' && $search === '');
      ?>
        <a href="<?= $selfUrl ?>?post=<?= urlencode($p['slug']) ?>"
           class="blog-card <?= $featured ? 'featured' : '' ?> reveal"
           style="border-top-color:<?= $cs['bar'] ?>">
          <!-- ::before gradient bar -->
          <style>
            .blog-card[href*="post=<?= urlencode($p['slug']) ?>"]:hover::before {
              background: linear-gradient(90deg, <?= $cs['bar'] ?>, <?= $cs['bg'] ?>);
            }
          </style>
          <div class="blog-card-cover" style="background:<?= $cs['bg'] ?>">
            <?php
            $cardCover = null;
            if (!empty($p['cover_image_file'])) $cardCover = UPLOAD_BLOG_URL . $p['cover_image_file'];
            elseif (!empty($p['cover_image']))  $cardCover = $p['cover_image'];
          ?>
          <?php if ($cardCover): ?>
            <img class="blog-card-cover-img"
                 src="<?= e2($cardCover) ?>"
                 alt="<?= e2($p['title']) ?>"
                 onerror="this.style.display='none'">
            <?php else: ?>
            <span style="font-size:<?= $featured ? '4rem' : '2.8rem' ?>">📝</span>
            <?php endif; ?>
          </div>
          <div class="blog-card-body">
            <?php if ($p['cat_name']): ?>
            <span class="blog-cat-pill" style="background:<?= $cs['bg'] ?>;color:<?= $cs['color'] ?>">
              <?= e2($p['cat_name']) ?>
            </span>
            <?php endif; ?>
            <div class="blog-card-title"><?= e2($p['title']) ?></div>
            <?php if ($p['excerpt']): ?>
            <div class="blog-card-excerpt"><?= e2($p['excerpt']) ?></div>
            <?php endif; ?>
          </div>
          <div class="blog-card-footer">
            <div class="blog-author">
              <?php
                $ini = strtoupper(substr($p['author_name'] ?? 'A', 0, 1));
                $avC = ['#4a90e2','#7c3aed','#16a34a','#d97706','#db2777'];
                $avCol = $avC[ord($ini) % count($avC)];
              ?>
              <div class="author-av" style="background:<?= $avCol ?>"><?= $ini ?></div>
              <span class="author-nm">
                <?= e2($p['author_name'] ?? 'AIFLA') ?> &middot; <?= fmtDate($p['published_at']) ?>
              </span>
            </div>
            <div class="read-meta">
              <span class="views-lbl">👁 <?= number_format($p['views']) ?></span>
              <span class="read-link">Read →</span>
            </div>
          </div>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <!-- Pagination -->
    <?php if (isset($totalPages) && $totalPages > 1): ?>
    <div class="pagination">
      <?php
        $qStr = ($search ? '&q='.urlencode($search) : '') . ($filterCat ? '&cat='.urlencode($filterCat) : '');
      ?>
      <a href="<?= $selfUrl ?>?p=<?= $page-1 ?><?= $qStr ?>"
         class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">← Prev</a>
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a href="<?= $selfUrl ?>?p=<?= $i ?><?= $qStr ?>"
         class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <a href="<?= $selfUrl ?>?p=<?= $page+1 ?><?= $qStr ?>"
         class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next →</a>
    </div>
    <?php endif; ?>
  </main>

  <!-- Sidebar -->
  <aside class="sidebar">
    <!-- Categories -->
    <?php if ($categories): ?>
    <div class="sidebar-card reveal">
      <div class="sidebar-title">Browse Topics</div>
      <?php foreach ($categories as $c):
        $cs2 = catStyle($c['name'], $catAccent);
      ?>
      <a href="<?= $selfUrl ?>?cat=<?= urlencode($c['name']) ?>" class="cat-list-item">
        <span class="cat-dot" style="background:<?= $cs2['bar'] ?>"></span>
        <span class="cat-list-name"><?= e2($c['name']) ?></span>
        <span class="cat-count"><?= $c['cnt'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent posts -->
    <?php if ($recentPosts): ?>
    <div class="sidebar-card reveal">
      <div class="sidebar-title">Recent Posts</div>
      <?php foreach ($recentPosts as $i => $rp): ?>
      <a href="<?= $selfUrl ?>?post=<?= urlencode($rp['slug']) ?>" class="recent-item">
        <div class="recent-num"><?= $i + 1 ?></div>
        <div>
          <div class="recent-title"><?= e2($rp['title']) ?></div>
          <div class="recent-date"><?= fmtDate($rp['published_at']) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Tag cloud -->
    <div class="sidebar-card reveal">
      <div class="sidebar-title">Quick Search</div>
      <div class="tag-cloud">
        <?php foreach (['AI', 'Machine Learning', 'Deep Learning', 'NLP', 'Computer Vision', 'Ethics', 'Research', 'Python', 'PyTorch', 'LangChain'] as $tag): ?>
        <a href="<?= $selfUrl ?>?q=<?= urlencode($tag) ?>" class="tag-chip"><?= $tag ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Academy CTA -->
    <div class="sidebar-card reveal" style="background:var(--navy);border:1px solid rgba(255,255,255,.08);">
      <div style="font-size:28px;margin-bottom:10px;">🤖</div>
      <div style="font-size:15px;font-weight:800;color:#fff;margin-bottom:8px;">Join AIFLA</div>
      <p style="font-size:12px;color:rgba(255,255,255,.5);line-height:1.65;margin-bottom:16px;">
        Become part of the next generation of AI leaders. Real projects, real mentorship.
      </p>
      <a href="<?= $baseUrl ?>/rbms/login.php"
         style="display:block;text-align:center;padding:10px;background:var(--blue);
                color:#fff;font-size:13px;font-weight:700;border-radius:9px;text-decoration:none;
                transition:background .2s;"
         onmouseover="this.style.background='#3a7fd5'"
         onmouseout="this.style.background='#4a90e2'">
        Student Portal →
      </a>
    </div>
  </aside>
</div>

<?php endif; /* end list/single switch */ ?>

<!-- ════════════════════════════ FOOTER ════════════════════════════ -->
<footer>
  <div class="footer-grid">
    <div>
      <div class="fbrand">
        <div class="fbrand-icon"><img src="<?= $baseUrl ?>/logo.png" alt="AIFLA"></div>
        AI Future Leaders Academy
      </div>
      <p class="fdesc">Where the next generation of AI practitioners, researchers, and builders come to grow.</p>
    </div>
    <div class="fcol">
      <h4>Explore</h4>
      <a class="flink" href="<?= $baseUrl ?>/index.html">Home</a>
      <a class="flink" href="<?= $selfUrl ?>">Student Blog</a>
      <a class="flink" href="<?= $baseUrl ?>/index.html#contact">Contact</a>
    </div>
    <div class="fcol">
      <h4>Programs</h4>
      <a class="flink" href="#">AI Foundations</a>
      <a class="flink" href="#">Computer Vision</a>
      <a class="flink" href="#">NLP &amp; LLMs</a>
      <a class="flink" href="#">Research Track</a>
    </div>
    <div class="fcol">
      <h4>Connect</h4>
      <a class="flink" href="#">GitHub</a>
      <a class="flink" href="#">LinkedIn</a>
      <a class="flink" href="#">YouTube</a>
      <a class="flink" href="#">Discord</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>&copy; 2026 AI Future Leaders Academy</span>
    <span>Empowering Tomorrow's AI Minds</span>
  </div>
</footer>

</div><!-- /page-content -->

<!-- ════════════════════════════ JS ════════════════════════════ -->
<script>
// Custom cursor
var cur  = document.getElementById('cursor');
var ring = document.getElementById('cursor-ring');
var mx=0,my=0,rx=0,ry=0;
document.addEventListener('mousemove',function(e){
  mx=e.clientX;my=e.clientY;
  cur.style.left=(mx-5)+'px';cur.style.top=(my-5)+'px';
});
(function ac(){
  rx+=(mx-rx)*.1;ry+=(my-ry)*.1;
  ring.style.left=(rx-15)+'px';ring.style.top=(ry-15)+'px';
  requestAnimationFrame(ac);
})();
document.querySelectorAll('button,a,.blog-card,.filter-pill,.page-btn,.tag-chip,.cat-list-item,.recent-item').forEach(function(el){
  el.addEventListener('mouseenter',function(){cur.style.transform='scale(2)';});
  el.addEventListener('mouseleave',function(){cur.style.transform='scale(1)';});
});

// Hamburger
function toggleMenu(){
  document.getElementById('hamburger').classList.toggle('open');
  document.getElementById('mobile-menu').classList.toggle('open');
}
function closeMenu(){
  document.getElementById('hamburger').classList.remove('open');
  document.getElementById('mobile-menu').classList.remove('open');
}

// Scroll reveal
var obs = new IntersectionObserver(function(entries){
  entries.forEach(function(e){
    if(e.isIntersecting) e.target.classList.add('visible');
  });
},{threshold:.08});
document.querySelectorAll('.reveal').forEach(function(el){ obs.observe(el); });

// Trigger already-visible elements
setTimeout(function(){
  document.querySelectorAll('.reveal').forEach(function(el,i){
    var rect = el.getBoundingClientRect();
    if(rect.top < window.innerHeight){
      setTimeout(function(){ el.classList.add('visible'); }, i*60);
    }
  });
},50);
</script>
</body>
</html>