<?php require_once __DIR__ . '/lib/db.php'; require_once __DIR__ . '/lib/markdown.php';
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$board = in_array($_GET['board'] ?? '', ['blog', 'diary']) ? $_GET['board'] : '';
$conds = []; $params = [];
if ($q !== '') { $conds[] = "(p.title LIKE ? OR p.content LIKE ?)"; $like = '%' . $q . '%'; $params = [$like, $like]; }
if ($board !== '') { $conds[] = "p.type = ?"; $params[] = $board; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$st = db()->prepare("SELECT COUNT(*) c FROM posts p $where");
$st->execute($params);
$total = (int)$st->fetch()['c'];
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;
$sql = "SELECT p.id, p.title, p.content, p.created_at, p.pinned, p.tags, p.type, COALESCE(u.username,'匿名') AS author FROM posts p LEFT JOIN users u ON u.id = p.user_id $where ORDER BY p.pinned DESC, p.created_at DESC LIMIT $perPage OFFSET $offset";
$st = db()->prepare($sql); $st->execute($params); $posts = $st->fetchAll();
$qurl = ($q !== '' ? '&q=' . urlencode($q) : '') . ($board !== '' ? '&board=' . $board : '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $q !== '' ? '搜索「' . htmlspecialchars($q) . '」· ' : '' ?>Lilmouse 的小角落</title>
<link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="alternate" type="application/rss+xml" title="RSS" href="rss.php">
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?>" defer></script>
</head>
<body>
<header class="site-header">
  <div class="wrap">
    <a class="logo" href="/">
      <img class="logo-avatar" src="img/avatar.jpg" alt="Lilmouse">
      <span>Lilmouse</span>
    </a>
  </div>
</header>
<main class="wrap">
  <section class="hero">
    <img class="hero-avatar" src="img/avatar.jpg" alt="Lilmouse">
    <h1>Lilmouse</h1>
    <p class="slogan">一块属于自己的小角落</p>
    <form class="search-box" method="get" action="/">
      <?php if ($board !== ''): ?><input type="hidden" name="board" value="<?= htmlspecialchars($board) ?>"><?php endif; ?>
      <input type="search" name="q" placeholder="搜索文章…（当前：<?= $board === 'diary' ? '日记' : ($board === 'blog' ? '博客' : '全部') ?>）" value="<?= htmlspecialchars($q) ?>">
      <button class="btn" type="submit">搜索</button>
    </form>
  </section>
<nav class="board-tabs">
  <a href="/<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="<?= $board === '' ? 'active' : '' ?>">全部</a>
  <a href="/?board=blog<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="<?= $board === 'blog' ? 'active' : '' ?>">📝 博客</a>
  <a href="/?board=diary<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="<?= $board === 'diary' ? 'active' : '' ?>">📖 日记</a>
</nav>
<?php if ($q !== ''): ?>
  <p class="flash">搜索「<?= htmlspecialchars($q) ?>」：找到 <?= $total ?> 篇</p>
<?php endif; ?>
<?php if (!$posts): ?>
  <div class="empty-state">
    <h1><?= $q !== '' ? '没有找到相关文章' : '这里还很安静' ?></h1>
    <p><?= $q !== '' ? '换个关键词试试？' : '还没有任何文章。' ?></p>
  </div>
<?php else: ?>
  <section class="post-list">
  <?php foreach ($posts as $p):
      $date = date('Y 年 n 月 j 日', strtotime($p['created_at']));
      $tags = array_filter(array_map('trim', explode(',', $p['tags'] ?? ''))); ?>
    <article class="post-card">
      <h2><a href="post.php?id=<?= (int)$p['id'] ?>"><?= $p['pinned'] ? '📌 ' : '' ?><?= htmlspecialchars($p['title']) ?></a></h2>
      <div class="meta"><span class="board-badge board-<?= htmlspecialchars($p['type'] ?? 'blog') ?>"><?= ($p['type'] ?? 'blog') === 'diary' ? '📖 日记' : '📝 博客' ?></span><span class="author-tag"><?= htmlspecialchars($p['author']) ?></span><time datetime="<?= htmlspecialchars($p['created_at']) ?>"><?= $date ?></time></div>
      <p class="excerpt"><?= htmlspecialchars(md_excerpt($p['content'])) ?></p>
      <?php if ($tags): ?><div class="tags"><?php foreach ($tags as $t): ?><span class="tag">#<?= htmlspecialchars($t) ?></span><?php endforeach; ?></div><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </section>
  <?php if ($pages > 1): ?>
  <nav class="pagination">
    <?php if ($page > 1): ?><a class="btn ghost" href="?page=<?= $page - 1 ?><?= $qurl ?>">← 上一页</a><?php else: ?><span></span><?php endif; ?>
    <span class="page-info"><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn ghost" href="?page=<?= $page + 1 ?><?= $qurl ?>">下一页 →</a><?php else: ?><span></span><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
</main>
<footer class="site-footer">
  <div class="wrap">© 2026 Lilmouse · <a href="rss.php">RSS 订阅</a></div>
</footer>
<nav class="bottom-nav">
  <a class="nav-item active" href="/">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
    <span>首页</span>
  </a>
  <a class="nav-fab" href="admin.php?action=new" title="发布新文章">
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
  </a>
  <a class="nav-item" href="admin.php">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
    <span>我的</span>
  </a>
</nav>
</body>
</html>
