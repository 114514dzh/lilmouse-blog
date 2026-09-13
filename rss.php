<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/markdown.php';
header('Content-Type: application/rss+xml; charset=utf-8');
$board = in_array($_GET['board'] ?? '', ['blog', 'diary']) ? $_GET['board'] : '';
$st = db()->prepare("SELECT p.id, p.title, p.content, p.created_at, COALESCE(u.username,'匿名') AS author FROM posts p LEFT JOIN users u ON u.id=p.user_id " . ($board !== '' ? "WHERE p.type = ? " : "") . "ORDER BY p.created_at DESC LIMIT 20");
$board !== '' ? $st->execute([$board]) : $st->execute();
$posts = $st->fetchAll();
$base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0"><channel>';
echo '<title>Lilmouse 的小角落</title>';
echo '<link>' . $base . '/</link>';
echo '<description>一块属于自己的小角落</description>';
echo '<language>zh-cn</language>';
foreach ($posts as $p) {
    $link = $base . '/post.php?id=' . (int)$p['id'];
    echo '<item>';
    echo '<title>' . htmlspecialchars($p['title']) . '</title>';
    echo '<link>' . $link . '</link>';
    echo '<guid>' . $link . '</guid>';
    echo '<pubDate>' . date(DATE_RSS, strtotime($p['created_at'])) . '</pubDate>';
    echo '<author>' . htmlspecialchars($p['author']) . '</author>';
    echo '<description>' . htmlspecialchars(md_to_html($p['content'])) . '</description>';
    echo '</item>';
}
echo '</channel></rss>';
