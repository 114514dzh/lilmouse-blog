<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { header('Location: /'); exit; }

// 评论实名制：必须登录，昵称强制使用账户名（不信任客户端提交的任何名字）
$user = auth_user();
if (!$user) { header('Location: admin.php'); exit; }

$postId = (int)($_POST['post_id'] ?? 0);
$content = trim((string)($_POST['content'] ?? ''));
$back = 'post.php?id=' . $postId . '#comments';

// 文章必须存在
$st = db()->prepare("SELECT id FROM posts WHERE id = ?");
$st->execute([$postId]);
if (!$st->fetch()) { header('Location: /'); exit; }

// 内容校验
if ($content === '' || mb_strlen($content) > 500) { header('Location: ' . $back); exit; }

// 防刷屏：同一会话 5 秒内只能发一条
$last = (int)($_SESSION['last_comment'] ?? 0);
if (time() - $last < 5) { header('Location: ' . $back); exit; }
$_SESSION['last_comment'] = time();

// author 仅作历史快照冗余；展示层以 user_id 实时关联账户名
$st = db()->prepare("INSERT INTO comments (post_id, author, user_id, content) VALUES (?, ?, ?, ?)");
$st->execute([$postId, $user['username'], (int)$user['id'], $content]);
header('Location: ' . $back);
exit;
