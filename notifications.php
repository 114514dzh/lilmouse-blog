<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/markdown.php';

$user = auth_user();

// ---- 未读数接口（浮球轮询）----
if (($_GET['action'] ?? '') === 'count') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$user) { echo json_encode(['guest' => true]); exit; }
    $uid = (int)$user['id'];
    $st = db()->prepare("SELECT COUNT(*) c FROM comments c JOIN posts p ON p.id = c.post_id
                         WHERE p.user_id = ? AND c.id > ? AND (c.user_id IS NULL OR c.user_id != ?)");
    $st->execute([$uid, (int)($user['last_seen_comment_id'] ?? 0), $uid]);
    echo json_encode(['count' => (int)$st->fetch()['c']]);
    exit;
}

// ---- 消息中心页 ----
if (!$user) { header('Location: admin.php?to=' . urlencode('/notifications.php')); exit; }
$uid = (int)$user['id'];

$st = db()->prepare("SELECT c.id, c.post_id, c.content, c.created_at,
        COALESCE(u.username, c.author) AS display_name,
        p.title AS post_title, p.type AS post_type
    FROM comments c
    JOIN posts p ON p.id = c.post_id
    LEFT JOIN users u ON u.id = c.user_id
    WHERE p.user_id = ? AND (c.user_id IS NULL OR c.user_id != ?)
    ORDER BY c.id DESC
    LIMIT 50");
$st->execute([$uid, $uid]);
$items = $st->fetchAll();

// 已读水位 = 我名下文章收到的最新一条"他人评论"的 id
$mst = db()->prepare("SELECT COALESCE(MAX(c.id), 0) m FROM comments c JOIN posts p ON p.id = c.post_id
                      WHERE p.user_id = ? AND (c.user_id IS NULL OR c.user_id != ?)");
$mst->execute([$uid, $uid]);
$waterline = (int)$mst->fetch()['m'];
db()->prepare("UPDATE users SET last_seen_comment_id = ? WHERE id = ?")->execute([$waterline, $uid]);

function rel_time(string $dt): string {
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    $d = time() - $ts;
    if ($d < 60) return '刚刚';
    if ($d < 3600) return floor($d / 60) . ' 分钟前';
    if ($d < 86400) return floor($d / 3600) . ' 小时前';
    if ($d < 86400 * 30) return floor($d / 86400) . ' 天前';
    return date('Y-m-d', $ts);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>消息中心 · Lilmouse 的小角落</title>
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
  <div class="msg-page">
    <div class="msg-head">
      <h1>🔔 消息中心</h1>
      <span class="msg-sub">别人给你的文章留言会出现在这里</span>
    </div>
    <?php if (!$items): ?>
      <div class="empty-state">
        <h1>还没有收到留言</h1>
        <p>多发文章，让大家认识你吧～</p>
        <a class="btn" href="admin.php?action=new">＋ 写新文章</a>
      </div>
    <?php else: ?>
      <section class="msg-list">
        <?php foreach ($items as $m):
            $unread = (int)$m['id'] > (int)($user['last_seen_comment_id'] ?? 0);
            $excerpt = mb_substr(trim(strip_tags(md_to_html($m['content']))), 0, 60);
        ?>
        <a class="msg-item" href="post.php?id=<?= (int)$m['post_id'] ?>#comments">
          <span class="msg-avatar"><?= htmlspecialchars(mb_substr($m['display_name'], 0, 1)) ?></span>
          <span class="msg-body">
            <span class="msg-line1">
              <?php if ($unread): ?><i class="msg-dot"></i><?php endif; ?>
              <strong><?= htmlspecialchars($m['display_name']) ?></strong>
              评论了你的<?= ($m['post_type'] ?? 'blog') === 'diary' ? '日记' : '博客' ?>
            </span>
            <span class="msg-post">《<?= htmlspecialchars($m['post_title']) ?>》</span>
            <span class="msg-excerpt"><?= htmlspecialchars($excerpt) ?></span>
            <time class="msg-time"><?= rel_time($m['created_at']) ?></time>
          </span>
        </a>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>
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
  <a class="nav-item active" href="admin.php">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
    <span>我的</span>
  </a>
</nav>
</body>
</html>
