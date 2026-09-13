<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/markdown.php';
require_once __DIR__ . '/lib/auth.php';
$user = auth_user();
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT p.*, COALESCE(u.username,'匿名') AS author FROM posts p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) { http_response_code(404); die('<h1 style="text-align:center;margin-top:20vh">404 · 文章不存在</h1><p style="text-align:center"><a href="/">返回首页</a></p>'); }
// 浏览量节流：同一会话 10 分钟内不重复计数
$_SESSION['vw'] = $_SESSION['vw'] ?? [];
if (!isset($_SESSION['vw'][$id]) || time() - $_SESSION['vw'][$id] > 600) {
    $_SESSION['vw'][$id] = time();
    if (count($_SESSION['vw']) > 50) $_SESSION['vw'] = array_slice($_SESSION['vw'], -50, null, true);
    db()->prepare("UPDATE posts SET views = views + 1 WHERE id = ?")->execute([$id]);
}
$html = md_to_html($post['content']);
$date = date('Y 年 n 月 j 日', strtotime($post['created_at']));
$type = ($post['type'] ?? 'blog') === 'diary' ? 'diary' : 'blog';
$tags = array_filter(array_map('trim', explode(',', $post['tags'] ?? '')));
// 评论列表
$cs = db()->prepare("SELECT c.author, c.content, c.created_at, COALESCE(u.username, c.author) AS display_name FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.post_id = ? ORDER BY c.id ASC");
$cs->execute([$id]);
$comments = $cs->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($post['title']) ?> · Lilmouse</title>
<link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
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
  <article class="post-full">
    <h1 class="post-title"><?= $post['pinned'] ? '📌 ' : '' ?><?= htmlspecialchars($post['title']) ?></h1>
    <div class="meta"><span class="author-tag"><?= htmlspecialchars($post['author']) ?></span><time datetime="<?= htmlspecialchars($post['created_at']) ?>"><?= $date ?></time><span class="board-badge board-<?= $type ?>"><?= $type === 'diary' ? '📖 日记' : '📝 博客' ?></span><span class="views">👁 <?= (int)$post['views'] ?></span></div>
    <?php if ($tags): ?><div class="tags"><?php foreach ($tags as $t): ?><span class="tag">#<?= htmlspecialchars($t) ?></span><?php endforeach; ?></div><?php endif; ?>
    <div class="post-body"><?= $html ?></div>
    <section class="comments">
      <h2>评论 (<?= count($comments) ?>)</h2>
      <?php if (!$comments): ?><p class="empty">还没有评论，来抢沙发。</p><?php else: ?>
        <?php foreach ($comments as $c): ?>
          <div class="comment">
            <div class="comment-head"><span class="comment-author"><?= htmlspecialchars($c['display_name']) ?></span><time><?= htmlspecialchars($c['created_at']) ?></time></div>
            <div class="comment-body"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($user): ?>
      <form class="comment-form" method="post" action="comment.php">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?? '' ?>">
        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
        <p class="comment-as">以 <strong><?= htmlspecialchars($user['username']) ?></strong> 的身份评论</p>
        <textarea name="content" rows="3" placeholder="说点什么…" maxlength="500" required></textarea>
        <button class="btn" type="submit">发表评论</button>
      </form>
      <?php else: ?>
      <p class="empty">想评论？先 <a href="admin.php?to=<?= htmlspecialchars(urlencode('/post.php?id=' . $post['id'])) ?>">登录</a>（评论一律使用账户名，不可冒充）</p>
      <?php endif; ?>
    </section>
    <p class="back-link"><a href="/">← 返回首页</a></p>
  </article>
</main>
<footer class="site-footer">
  <div class="wrap">© 2026 Lilmouse · 手写 PHP 博客</div>
</footer>
<nav class="bottom-nav">
  <a class="nav-item" href="/">
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
