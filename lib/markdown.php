<?php
// 手写 Markdown 解析器：标题/段落/代码块/行内代码/粗斜体/列表/引用/分隔线/链接/图片
// URL 协议白名单：拒绝 javascript:/data:/vbscript: 等危险协议
function md_safe_url(string $url): string {
    // 剥离控制字符：阻断 java\tscript: 等浏览器剥空白绕过手法
    $url = preg_replace('/[\x00-\x1f\x7f]/', '', trim($url));
    if ($url === '') return '#';
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*):#i', $url)) {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) return '#';
    }
    return $url;
}

function md_inline(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^\s()]*(?:\([^()]*\)[^\s()]*)*)\)/', function ($m) {
        return '<img src="' . md_safe_url($m[2]) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s()]*(?:\([^()]*\)[^\s()]*)*)\)/', function ($m) {
        return '<a href="' . md_safe_url($m[2]) . '">' . $m[1] . '</a>';
    }, $text);
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    return $text;
}

function md_to_html(string $md): string {
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $html = '';
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        // 代码块
        if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
            $buf = [];
            $i++;
            while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) { $buf[] = $lines[$i]; $i++; }
            $i++;
            $code = htmlspecialchars(implode("\n", $buf), ENT_QUOTES, 'UTF-8');
            $lang = $m[1] ? ' class="language-' . htmlspecialchars($m[1]) . '"' : '';
            $html .= "<pre><code$lang>$code</code></pre>\n";
            continue;
        }
        // 标题
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $lvl = strlen($m[1]);
            $html .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>\n";
            $i++;
            continue;
        }
        // 分隔线
        if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $html .= "<hr>\n";
            $i++;
            continue;
        }
        // 引用
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $buf = [];
            while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $mm)) { $buf[] = $mm[1]; $i++; }
            $parts = array_map('md_inline', $buf);
        $html .= "<blockquote>" . implode("<br>\n", $parts) . "</blockquote>\n";
            continue;
        }
        // 无序列表
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
            $html .= "<ul>\n";
            while ($i < $n && preg_match('/^\s*[-*+]\s+(.*)$/', $lines[$i], $mm)) {
                $html .= "<li>" . md_inline($mm[1]) . "</li>\n";
                $i++;
            }
            $html .= "</ul>\n";
            continue;
        }
        // 有序列表
        if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m)) {
            $html .= "<ol>\n";
            while ($i < $n && preg_match('/^\s*\d+[.)]\s+(.*)$/', $lines[$i], $mm)) {
                $html .= "<li>" . md_inline($mm[1]) . "</li>\n";
                $i++;
            }
            $html .= "</ol>\n";
            continue;
        }
        if (trim($line) === '') { $i++; continue; }
        // 段落
        $buf = [];
        while ($i < $n && trim($lines[$i]) !== ''
            && !preg_match('/^(#{1,6}\s|```|>\s?|[-*+]\s|\d+[.)]\s|(-{3,}|\*{3,}|_{3,})\s*$)/', $lines[$i])) {
            $buf[] = trim($lines[$i]);
            $i++;
        }
        $parts = array_map('md_inline', $buf);
        $html .= "<p>" . implode("<br>\n", $parts) . "</p>\n";
    }
    return $html;
}

function md_excerpt(string $md, int $len = 160): string {
    // 只转换前 800 字符生成摘要，避免大文章全量解析
    if (mb_strlen($md) > 800) $md = mb_substr($md, 0, 800);
    $text = strip_tags(md_to_html($md));
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text) > $len) $text = mb_substr($text, 0, $len) . '…';
    return $text;
}
