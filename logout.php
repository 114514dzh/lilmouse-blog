<?php
require __DIR__ . '/lib/auth.php';
// 仅 POST + CSRF 才登出（防 img 标签骚扰式踢下线）
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    auth_logout();
}
header('Location: admin.php');
exit;
