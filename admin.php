<?php
require __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/markdown.php';
migrate_legacy();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">'; }
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }

$action = $_GET['action'] ?? 'list';
$msg = '';
$user = auth_user();
$pageTitle = $user ? ($action === 'new' ? '写新文章' : ($action === 'edit' ? '编辑文章' : '我的')) : '登录';

// ---- 注册 ----
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) $msg = '会话过期，请刷新后重试';
    else {
        [$ok, $m] = auth_register($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($ok) { auth_login(trim($_POST['username']), $_POST['password']); $msg = '注册成功，已自动登录 🎉'; }
        else $msg = $m;
    }
}

// ---- 登录 ----
if (!$user && $action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginUser = trim((string)($_POST['username'] ?? ''));
    $ip = login_ip();
    // 限流计数改存服务端文件：原实现存 $_SESSION，清掉 cookie 即归零，形同虚设
    // 另外原实现在锁定时 sleep(2)，配合 pm.max_children=5 可被用来占满 FPM 池，故移除
    if (login_locked($ip, $loginUser)) {
        $msg = '尝试次数过多，请 2 分钟后再试';
    }
    elseif (auth_login($loginUser, $_POST['password'] ?? '')) {
        login_clear($ip, $loginUser);
        $to = trim((string)($_POST['redirect'] ?? 'admin.php'));
        // 只允许站内相对路径（白名单字符集），杜绝 //evil.com、/\evil.com 等开放重定向
        if (!preg_match('#^/(?!/)[A-Za-z0-9._~\-/?&=%]*$#', $to)) $to = 'admin.php';
        header('Location: ' . $to); exit;
    }
    else {
        login_record_fail($ip, $loginUser);
        $msg = '用户名或密码错误';
    }
}

// ---- 已登录操作 ----
if ($user) {
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) { auth_logout(); header('Location: admin.php'); exit; }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) $msg = '会话过期，请刷新后重试';
        else {
            $title = trim($_POST['title'] ?? '');
            $content = $_POST['content'] ?? '';
            $id = (int)($_POST['id'] ?? 0);
            $tags = trim((string)($_POST['tags'] ?? ''));
            if (str_contains($tags, '#')) {
                // hashtag 风格：#生活 #技术 → 生活,技术
                preg_match_all('/#([^\s#,，]+)/u', $tags, $tagMatches);
                $tagRest = str_replace('#', '', preg_replace('/#[^\s#,，]+/u', '', $tags));
                $tagPlain = array_filter(array_map('trim', explode(',', $tagRest)));
                $tags = implode(',', array_values(array_unique(array_filter(array_merge($tagMatches[1], $tagPlain)))));
            } else {
                // 兼容旧格式：逗号分隔
                $tags = implode(',', array_filter(array_map('trim', explode(',', $tags))));
            }
            $pinned = isset($_POST['pinned']) ? 1 : 0;
            $type = (($_POST['type'] ?? '') === 'diary') ? 'diary' : 'blog';
            if (mb_strlen($title) > 120) $msg = '标题太长（最多 120 字）';
            elseif (strlen($content) > 204800) $msg = '文章太长（超过 200KB）';
            elseif ($title === '') $msg = '标题不能为空';
            else {
                if ($id > 0) {
                    $st = db()->prepare("UPDATE posts SET title=?, content=?, tags=?, pinned=?, type=?, updated_at=datetime('now','localtime') WHERE id=? AND user_id=?");
                    $st->execute([$title, $content, $tags, $pinned, $type, $id, $user['id']]);
                    $msg = $st->rowCount() ? '已保存 ✓' : '无权修改这篇文章';
                } else {
                    $st = db()->prepare("INSERT INTO posts (title, content, tags, pinned, type, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $st->execute([$title, $content, $tags, $pinned, $type, $user['id']]);
                    header('Location: post.php?id=' . (int)db()->lastInsertId()); exit;
                }
            }
        }
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) { $msg = '会话过期，请刷新后重试'; $action = 'list'; }
        else {
            $id = (int)($_POST['id'] ?? 0);
            // 先确认文章确实属于当前用户，再连同评论一起删除。
            // 此前只删 posts，评论会残留成孤儿数据（post_id 指向已不存在的文章），
            // 既不可见也无法清理。用事务保证两者一致。
            $st = db()->prepare("SELECT id FROM posts WHERE id=? AND user_id=?");
            $st->execute([$id, $user['id']]);
            if ($st->fetch()) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM comments WHERE post_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM posts WHERE id=? AND user_id=?")->execute([$id, $user['id']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                }
            }
            header('Location: admin.php?msg=deleted'); exit;
        }
    }

    if ($action === 'comments') {
        $pid = (int)($_GET['post_id'] ?? 0);
        $st = db()->prepare("SELECT id, title FROM posts WHERE id=? AND user_id=?");
        $st->execute([$pid, $user['id']]);
        $cpost = $st->fetch();
        if (!$cpost) { $msg = '文章不存在或无权管理'; $action = 'list'; }
        else {
            $cs = db()->prepare("SELECT c.*, COALESCE(u.username, c.author) AS display_name FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.post_id=? ORDER BY c.id DESC");
            $cs->execute([$pid]);
            $postComments = $cs->fetchAll();
        }
    }

    if ($action === 'delcomment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cid = (int)($_POST['id'] ?? 0);
        $back = 'admin.php?action=comments&post_id=' . (int)($_POST['post_id'] ?? 0);
        if (!csrf_ok()) { header('Location: ' . $back); exit; }
        $st = db()->prepare("SELECT c.id FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.id=? AND p.user_id=?");
        $st->execute([$cid, $user['id']]);
        if ($st->fetch()) db()->prepare("DELETE FROM comments WHERE id=?")->execute([$cid]);
        header('Location: ' . $back); exit;
    }

    if ($action === 'edit' || $action === 'new') {
        $id = (int)($_GET['id'] ?? 0);
        $post = null;
        if ($id > 0) {
            $st = db()->prepare("SELECT * FROM posts WHERE id=? AND user_id=?");
            $st->execute([$id, $user['id']]);
            $post = $st->fetch();
        }
        if ($action === 'edit' && !$post) { $msg = '文章不存在或无权编辑'; $action = 'list'; }
    }

}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> · Lilmouse 的小角落</title>
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
<?php if ($msg): ?><p class="flash"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><p class="flash">已删除</p><?php endif; ?>

<?php if (!$user && $action === 'register'): ?>
  <div class="login-box">
    <h1>注册账号</h1>
    <p class="hint">所有账号平等，注册即可发文</p>
    <form method="post" action="admin.php?action=register">
      <?= csrf_field() ?>
      <input type="text" name="username" placeholder="用户名（字母/数字/中文）" required minlength="2" autocomplete="username">
      <input type="password" name="password" placeholder="密码（至少 8 位）" required minlength="8" autocomplete="new-password">
      <button class="btn" type="submit">注册</button>
    </form>
    <p class="hint" style="margin-top:16px">已有账号？<a href="admin.php" class="switch-link">去登录</a></p>
  </div>

<?php elseif (!$user): ?>
  <div class="login-box">
    <h1>登录</h1>
    <p class="hint">登录后写文章</p>
    <form method="post" action="admin.php?action=login">
      <?= csrf_field() ?>
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['to'] ?? 'admin.php') ?>">
      <input type="text" name="username" placeholder="用户名" required autocomplete="username">
      <input type="password" name="password" placeholder="密码" required autocomplete="current-password">
      <button class="btn" type="submit">登录</button>
    </form>
    <p class="hint" style="margin-top:16px">没有账号？<a href="admin.php?action=register" class="switch-link">注册一个</a></p>
  </div>

<?php elseif ($action === 'comments'): ?>
  <div class="admin-list">
    <h1>评论管理</h1>
    <p class="empty">文章：《<?= htmlspecialchars($cpost['title']) ?>》</p>
    <a class="btn ghost" href="admin.php">返回文章列表</a>
    <?php if (!$postComments): ?>
      <p class="empty">这篇文章还没有评论。</p>
    <?php else: ?>
      <?php foreach ($postComments as $c): ?>
        <div class="comment comment-manage">
          <div class="comment-head"><span class="comment-author"><?= htmlspecialchars($c['display_name']) ?></span><time><?= htmlspecialchars($c['created_at']) ?></time></div>
          <div class="comment-body"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
          <form method="post" action="admin.php?action=delcomment" class="inline-form" onsubmit="return confirm('删除这条评论？')">
            <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="post_id" value="<?= (int)$cpost['id'] ?>">
            <button class="link-danger" type="submit">删除</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php elseif ($action === 'edit' || $action === 'new'): ?>
  <div class="editor">
    <div class="editor-head">
      <h1><?= $action === 'edit' ? '编辑文章' : '写新文章' ?></h1>
      <a class="btn ghost btn-sm" href="admin.php">← 返回列表</a>
    </div>
    <form method="post" action="admin.php?action=save" class="editor-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)($post['id'] ?? 0) ?>">
      <div class="editor-card">
        <input type="text" id="post-title-input" name="title" class="editor-title" placeholder="输入标题…" value="<?= htmlspecialchars($post['title'] ?? '') ?>" required>
        <textarea name="content" id="md-input" class="editor-body" rows="14" placeholder="用 Markdown 写正文…&#10;&#10;支持：## 标题、**加粗**、`代码`、代码块、- 列表、> 引用、[链接](url)"><?= htmlspecialchars($post['content'] ?? '') ?></textarea>
        <div class="editor-meta">
          <span class="meta-label">板块</span>
          <div class="board-select" role="radiogroup" aria-label="选择板块">
            <label class="board-option"><input type="radio" name="type" value="blog" <?= ($post['type'] ?? 'blog') !== 'diary' ? 'checked' : '' ?>> 📝 博客</label>
            <label class="board-option"><input type="radio" name="type" value="diary" <?= ($post['type'] ?? '') === 'diary' ? 'checked' : '' ?>> 📖 日记</label>
          </div>
          <input type="text" name="tags" class="editor-tags" maxlength="200" placeholder="# 添加标签，如：#生活 #技术" value="<?= htmlspecialchars(implode(' ', array_map(fn($t) => '#' . $t, array_filter(array_map('trim', explode(',', $post['tags'] ?? '')))))) ?>">
        </div>
      </div>
      <div class="editor-tools">
        <div class="tools-left">
          <button class="btn" type="submit"><?= $action === 'edit' ? '💾 保存修改' : '🚀 发布' ?></button>
          <button class="btn ghost" type="button" id="preview-btn">👁 预览</button>
          <button class="btn ghost" type="button" id="upload-btn">🖼 图片</button>
          <input type="file" id="upload-file" accept="image/*" hidden>
          <label class="pinned-box"><input type="checkbox" name="pinned" <?= !empty($post['pinned']) ? 'checked' : '' ?>> 📌 置顶</label>
        </div>
        <div class="tools-right">
          <?php if ($action === 'edit'): ?>
            <form method="post" action="admin.php?action=delete" class="inline-form" onsubmit="return confirm('确定删除这篇文章？')">
              <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
              <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
              <button class="btn danger ghost" type="submit">删除</button>
            </form>
          <?php endif; ?>
          <a class="btn ghost btn-sm" href="admin.php">返回列表</a>
        </div>
      </div>
    </form>
    <div id="preview-box" class="preview-box" hidden>
      <h2>预览</h2>
      <div id="preview-content" class="post-body"></div>
    </div>
  </div>

<?php else:
    $mine = db()->prepare("SELECT id, title, type, created_at FROM posts WHERE user_id=? ORDER BY created_at DESC");
    $mine->execute([$user['id']]);
    $myPosts = $mine->fetchAll();
    $count = count($myPosts); ?>
  <div class="admin-list">
    <div class="me-card">
      <div class="me-avatar"><?= htmlspecialchars(mb_substr($user['username'], 0, 1)) ?></div>
      <div class="me-info">
        <h2><?= htmlspecialchars($user['username']) ?></h2>
        <p>加入于 <?= date('Y 年 n 月 j 日', strtotime($user['created_at'])) ?> · 文章 <?= $count ?> 篇</p>
      </div>
      <form method="post" action="admin.php?action=logout" style="display:inline"><?= csrf_field() ?><button class="btn ghost" type="submit">退出登录</button></form>
    </div>
    <h1 style="margin-top:32px">我的文章</h1>
    <a class="btn" href="admin.php?action=new">＋ 写新文章</a>
    <?php if (!$myPosts): ?>
      <p class="empty">还没有文章，点上面按钮写下第一篇吧。</p>
    <?php else: ?>
      <table>
        <thead><tr><th>标题</th><th>板块</th><th>发布时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($myPosts as $p):
            $d = date('Y-m-d H:i', strtotime($p['created_at'])); ?>
          <tr>
            <td><a href="post.php?id=<?= (int)$p['id'] ?>" target="_blank"><?= htmlspecialchars($p['title']) ?></a></td>
            <td class="board-cell"><?= ($p['type'] ?? 'blog') === 'diary' ? '📖 日记' : '📝 博客' ?></td>
            <td><?= $d ?></td>
            <td class="ops">
              <a href="admin.php?action=edit&id=<?= (int)$p['id'] ?>">编辑</a>
              <form method="post" action="admin.php?action=delete" class="inline-form" onsubmit="return confirm('确定删除这篇文章？')">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="link-danger" type="submit">删除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
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
