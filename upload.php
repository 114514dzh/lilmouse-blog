<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
header('Content-Type: application/json; charset=utf-8');
if (!auth_user()) { echo json_encode(['ok' => false, 'error' => '请先登录']); exit; }
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => '会话过期']); exit; }
if (empty($_FILES['file'])) { echo json_encode(['ok' => false, 'error' => '没有收到文件']); exit; }
$f = $_FILES['file'];
// 先判 PHP 层错误：超过 ini 上限时 $_FILES 的 size/type 均不可信，必须优先处理
switch ($f['error']) {
    case UPLOAD_ERR_OK: break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        echo json_encode(['ok' => false, 'error' => '图片超过 5MB 上限']); exit;
    case UPLOAD_ERR_NO_FILE:
        echo json_encode(['ok' => false, 'error' => '没有收到文件']); exit;
    default:
        echo json_encode(['ok' => false, 'error' => '上传失败，请重试']); exit;
}
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$ext = $allowed[$f['type']] ?? null;
if (!$ext) { echo json_encode(['ok' => false, 'error' => '仅支持 JPG/PNG/GIF/WebP']); exit; }
if ($f['size'] > 5 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => '图片超过 5MB 上限']); exit; }
// 服务端真身校验（不信任浏览器上报的 Content-Type）
$real = @getimagesize($f['tmp_name']);
if (!$real || empty($allowed[$real['mime']]) || $allowed[$real['mime']] !== $ext) {
    echo json_encode(['ok' => false, 'error' => '文件不是有效的图片']); exit;
}
$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$name = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { echo json_encode(['ok' => false, 'error' => '保存失败']); exit; }
echo json_encode(['ok' => true, 'url' => 'uploads/' . $name]);
