<?php
require __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/markdown.php';
header('Content-Type: text/html; charset=utf-8');

// 仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
// 防滥用：长度与文章一致（200KB），会话级 2 秒间隔
if (mb_strlen((string)($_POST['content'] ?? '')) > 204800) { http_response_code(413); exit; }
$last = (int)($_SESSION['last_preview'] ?? 0);
if (time() - $last < 2) { http_response_code(429); exit; }
$_SESSION['last_preview'] = time();

echo md_to_html((string)($_POST['content'] ?? ''));
