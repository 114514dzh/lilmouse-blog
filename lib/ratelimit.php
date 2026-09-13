<?php
// 登录限流：服务端文件计数（无扩展依赖），"IP+用户名" 与 "IP" 双维度
// 原实现把失败次数存在 $_SESSION，清掉 cookie 即归零，形同虚设；改存服务端文件。
// 用 flock 做原子读改写，避免并发下丢计数。
if (!defined('RL_WINDOW'))   define('RL_WINDOW', 120);   // 统计窗口（秒）
if (!defined('RL_USER_MAX')) define('RL_USER_MAX', 5);   // 同一 IP+用户名 的失败上限
if (!defined('RL_IP_MAX'))   define('RL_IP_MAX', 15);    // 同一 IP 的总失败上限

function rl_dir(): string {
    $d = __DIR__ . '/../data/rl';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
function rl_path(string $key): string {
    return rl_dir() . '/' . hash('sha256', $key) . '.json';
}
function rl_count(string $file): int {
    $raw = @file_get_contents($file);
    if ($raw === false) return 0;
    $d = json_decode($raw, true);
    if (!is_array($d) || !is_array($d['fails'] ?? null)) return 0;
    $now = time(); $n = 0;
    foreach ($d['fails'] as $t) if ($now - (int)$t < RL_WINDOW) $n++;
    return $n;
}
function rl_add(string $file, int $cap): void {
    $fh = @fopen($file, 'c+');
    if (!$fh) return;
    if (flock($fh, LOCK_EX)) {
        $d = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($d) || !is_array($d['fails'] ?? null)) $d = ['fails' => []];
        $now = time(); $fails = [];
        foreach ($d['fails'] as $t) if ($now - (int)$t < RL_WINDOW) $fails[] = (int)$t;
        $fails[] = $now;
        // 只留窗口内的记录，并设上限防文件膨胀
        if (count($fails) > $cap * 2) $fails = array_slice($fails, -$cap * 2);
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode(['fails' => $fails]));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

function login_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
function login_locked(string $ip, string $user): bool {
    if (rl_count(rl_path('ip:' . $ip)) >= RL_IP_MAX) return true;
    return rl_count(rl_path('u:' . $ip . '|' . mb_strtolower($user))) >= RL_USER_MAX;
}
function login_record_fail(string $ip, string $user): void {
    rl_add(rl_path('ip:' . $ip), RL_IP_MAX);
    rl_add(rl_path('u:' . $ip . '|' . mb_strtolower($user)), RL_USER_MAX);
}
function login_clear(string $ip, string $user): void {
    @unlink(rl_path('u:' . $ip . '|' . mb_strtolower($user)));
}
