<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $dir . '/blog.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA journal_mode=WAL");
        $pdo->exec("PRAGMA synchronous=NORMAL");
        $pdo->exec("PRAGMA cache_size=-16000");
        $pdo->exec("PRAGMA busy_timeout=3000");
        // 迁移只跑一次：以后新加字段/索引时，删除 data/.migrated 重新触发
        $migrated = __DIR__ . '/../data/.migrated';
        if (file_exists($migrated)) return $pdo;
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            user_id INTEGER,
            views INTEGER DEFAULT 0,
            pinned INTEGER DEFAULT 0,
            tags TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            author TEXT NOT NULL,
            user_id INTEGER,
            content TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )");
        // 老表没有 user_id 列时补充
        $cols = $pdo->query("PRAGMA table_info(posts)")->fetchAll();
        $have = array_column($cols, 'name');
        if (!in_array('user_id', $have)) $pdo->exec("ALTER TABLE posts ADD COLUMN user_id INTEGER");
        if (!in_array('views', $have)) $pdo->exec("ALTER TABLE posts ADD COLUMN views INTEGER DEFAULT 0");
        if (!in_array('pinned', $have)) $pdo->exec("ALTER TABLE posts ADD COLUMN pinned INTEGER DEFAULT 0");
        if (!in_array('tags', $have)) $pdo->exec("ALTER TABLE posts ADD COLUMN tags TEXT DEFAULT ''");
        if (!in_array('type', $have)) $pdo->exec("ALTER TABLE posts ADD COLUMN type TEXT DEFAULT 'blog'");
        $ccols = $pdo->query("PRAGMA table_info(comments)")->fetchAll();
        $chave = array_column($ccols, 'name');
        if (!in_array('user_id', $chave)) $pdo->exec("ALTER TABLE comments ADD COLUMN user_id INTEGER");
        $ucols = $pdo->query("PRAGMA table_info(users)")->fetchAll();
        $uhave = array_column($ucols, 'name');
        if (!in_array('last_seen_comment_id', $uhave)) $pdo->exec("ALTER TABLE users ADD COLUMN last_seen_comment_id INTEGER DEFAULT 0");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_created ON posts(pinned DESC, created_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_user ON posts(user_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_type ON posts(type, pinned DESC, created_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comments_post ON comments(post_id, id)");
        @touch($migrated);
    }
    return $pdo;
}
