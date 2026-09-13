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
// 失败原因经 err 参数回传，由 post.php 渲染提示。
// 此前各分支只做 302 跳转、不带任何信息，被拒的评论会静默消失，用户以为发成功了。
$back = 'post.php?id=' . $postId . '#comments';

// 文章必须存在
$st = db()->prepare("SELECT id FROM posts WHERE id = ?");
$st->execute([$postId]);
if (!$st->fetch()) { header('Location: /'); exit; }

// 内容校验
if ($content === '') { header('Location: ' . $back . '&err=empty'); exit; }
if (mb_strlen($content) > 500) { header('Location: ' . $back . '&err=long'); exit; }

// 防刷屏：同一会话、同一篇文章 5 秒内只能发一条
// 此前用单一 $_SESSION['last_comment'] 计数，不区分文章，
// 导致刚在 A 文章评论完，紧接着去 B 文章评论也会被静默丢弃。
$now = time();
$marks = $_SESSION['last_comment'] ?? [];
if (!is_array($marks)) $marks = [];
$last = (int)($marks[$postId] ?? 0);
if ($now - $last < 5) { header('Location: ' . $back . '&err=fast'); exit; }
// 只保留最近 50 篇的时间戳，避免会话数据无限增长
if (count($marks) > 50) $marks = array_slice($marks, -50, null, true);
$marks[$postId] = $now;
$_SESSION['last_comment'] = $marks;

// author 仅作历史快照冗余；展示层以 user_id 实时关联账户名
$st = db()->prepare("INSERT INTO comments (post_id, author, user_id, content) VALUES (?, ?, ?, ?)");
$st->execute([$postId, $user['username'], (int)$user['id'], $content]);
header('Location: ' . $back . '&ok=1');
exit;
