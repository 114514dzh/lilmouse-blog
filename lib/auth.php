<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ratelimit.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 2592000,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function auth_pass_path(): string { return __DIR__ . '/../data/config.php'; }
function auth_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $user = null;
    if ($user === null) {
        $st = db()->prepare("SELECT id, username, created_at, last_seen_comment_id FROM users WHERE id=?");
        $st->execute([(int)$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
    }
    return $user;
}
function auth_register(string $username, string $pass): array {
    $username = trim($username);
    if (mb_strlen($username) < 2) return [false, '用户名至少 2 个字符'];
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]+$/u', $username)) return [false, '用户名只能含字母、数字、下划线、中文和连字符'];
    if (strlen($pass) < 8) return [false, '密码至少 8 位'];
    $st = db()->prepare("SELECT id FROM users WHERE username=?");
    $st->execute([$username]);
    if ($st->fetch()) return [false, '用户名已被占用'];
    $st = db()->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $st->execute([$username, password_hash($pass, PASSWORD_DEFAULT)]);
    return [true, '注册成功'];
}
function auth_login(string $username, string $pass): bool {
    $st = db()->prepare("SELECT * FROM users WHERE username=?");
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($pass, $u['password'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        return true;
    }
    return false;
}
function auth_logout(): void {
    unset($_SESSION['uid']);
    session_destroy();
}
// 老单密码迁移：users 表为空且存在旧密码时，创建 Lilmouse 账号
function migrate_legacy(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (auth_user()) return;
    if (!file_exists(auth_pass_path())) return;
    $n = (int)db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
    if ($n > 0) return;
    $cfg = @require auth_pass_path();
    if (is_array($cfg) && isset($cfg['password']) && $cfg['password']) {
        $st = db()->prepare("INSERT OR IGNORE INTO users (username, password) VALUES ('Lilmouse', ?)");
        $st->execute([$cfg['password']]);
    }
}
