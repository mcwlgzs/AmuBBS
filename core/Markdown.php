<?php
/**
 * 轻量级 Markdown 解析器
 * 支持：标题、粗体、斜体、行内代码、代码块、链接、图片、引用、列表、分割线、@提醒
 * 输出经过 XSS 过滤，安全可用
 */

namespace Core;

class Markdown
{
    /** 内容过滤器（插件可注册） */
    private static array $filters = [];

    /**
     * 注册内容过滤器，在 HTML 输出后执行
     */
    public static function addFilter(callable $filter): void
    {
        self::$filters[] = $filter;
    }

    public static function parse(string $text): string
    {
        // 统一换行符（在转义之前处理）
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 使用随机 token 作为占位符，防止用户构造假占位符导致内容混淆
        $token = bin2hex(random_bytes(8));

        // 先提取代码块（在 htmlspecialchars 之前，保留原始内容）
        $codeBlocks = [];
        $text = preg_replace_callback('/```[ \t]*(\w*)[ \t]*\n(.*?)\n?```/s', function ($m) use (&$codeBlocks, $token) {
            $id = '%%CB' . $token . count($codeBlocks) . '%%';
            $lang = $m[1] ? ' class="language-' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '"' : '';
            // 代码块内容单独转义
            $code = htmlspecialchars(trim($m[2]), ENT_QUOTES, 'UTF-8');
            $codeBlocks[$id] = '<pre><code' . $lang . '>' . $code . '</code></pre>';
            return "\n" . $id . "\n";
        }, $text);

        // 提取行内代码（在 htmlspecialchars 之前）
        $inlineCodes = [];
        $text = preg_replace_callback('/`([^`\n]+?)`/', function ($m) use (&$inlineCodes, $token) {
            $id = '%%IC' . $token . count($inlineCodes) . '%%';
            $inlineCodes[$id] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
            return $id;
        }, $text);

        // 转义剩余 HTML 防止 XSS
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // 按空行分段
        $blocks = preg_split('/\n{2,}/', $text);
        $html = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;

            // 代码块占位符
            if (preg_match('/^%%CB[a-f0-9]+\d+%%$/', $block)) {
                $html[] = $block;
                continue;
            }

            // 标题 h1-h6
            if (preg_match('/^(#{1,6})\s+(.+)$/', $block, $m)) {
                $level = strlen($m[1]);
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            // 分割线
            if (preg_match('/^[-*_]{3,}$/', $block)) {
                $html[] = '<hr>';
                continue;
            }

            // 引用块
            if (preg_match('/^&gt;\s/', $block)) {
                $lines = explode("\n", $block);
                $quoteLines = [];
                foreach ($lines as $line) {
                    $quoteLines[] = self::inline(preg_replace('/^&gt;\s?/', '', $line));
                }
                $html[] = '<blockquote>' . implode("<br>\n", $quoteLines) . '</blockquote>';
                continue;
            }

            // 无序列表
            if (preg_match('/^[-*+]\s/', $block)) {
                $lines = explode("\n", $block);
                $items = [];
                foreach ($lines as $line) {
                    if (preg_match('/^[-*+]\s+(.+)/', $line, $m)) {
                        $items[] = '<li>' . self::inline($m[1]) . '</li>';
                    }
                }
                if ($items) {
                    $html[] = '<ul>' . implode("\n", $items) . '</ul>';
                    continue;
                }
            }

            // 有序列表
            if (preg_match('/^\d+\.\s/', $block)) {
                $lines = explode("\n", $block);
                $items = [];
                foreach ($lines as $line) {
                    if (preg_match('/^\d+\.\s+(.+)/', $line, $m)) {
                        $items[] = '<li>' . self::inline($m[1]) . '</li>';
                    }
                }
                if ($items) {
                    $html[] = '<ol>' . implode("\n", $items) . '</ol>';
                    continue;
                }
            }

            // 普通段落（可能包含多行）
            $lines = explode("\n", $block);
            $pLines = [];
            foreach ($lines as $line) {
                // 处理行内标题（段落内出现标题）
                if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                    if ($pLines) {
                        $html[] = '<p>' . implode("<br>\n", $pLines) . '</p>';
                        $pLines = [];
                    }
                    $level = strlen($m[1]);
                    $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                } else {
                    $pLines[] = self::inline($line);
                }
            }
            if ($pLines) {
                $html[] = '<p>' . implode("<br>\n", $pLines) . '</p>';
            }
        }

        $result = implode("\n", $html);

        // 还原代码块和行内代码
        $result = strtr($result, $codeBlocks);
        $result = strtr($result, $inlineCodes);

        // HTML 白名单过滤（防止存储型 XSS）
        $result = HtmlSanitizer::sanitize($result);

        // 执行插件注册的内容过滤器
        foreach (self::$filters as $filter) {
            $result = $filter($result);
        }

        return $result;
    }

    /**
     * 渲染内容：优先使用预渲染的 HTML，否则解析原始 Markdown
     * 始终应用插件过滤器（确保插件对已有内容也生效）
     */
    public static function render(?string $contentFmt, string $contentRaw): string
    {
        $html = ($contentFmt !== null && $contentFmt !== '') ? $contentFmt : self::parse($contentRaw);
        // 对预渲染内容也应用 sanitize + 过滤器
        if ($contentFmt !== null && $contentFmt !== '') {
            $html = \Core\HtmlSanitizer::sanitize($html);
            foreach (self::$filters as $filter) {
                $html = $filter($html);
            }
        }
        return $html;
    }

    /**
     * 处理行内元素：粗体、斜体、删除线、链接、图片、@提醒
     */
    private static function inline(string $text): string
    {
        // 图片 ![alt](url) — 已被 htmlspecialchars 转义，匹配转义后的格式
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            function ($m) {
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                if (!self::isSafeUrl($url)) return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
                return '<img src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy" class="lazy" style="max-width:100%;border-radius:4px;">';
            },
            $text
        );

        // 链接 [text](url)
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($m) {
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                if (!self::isSafeUrl($url)) return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
                return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
            },
            $text
        );

        // 粗斜体 ***text*** 或 ___text___
        $text = preg_replace('/\*{3}(.+?)\*{3}/', '<strong><em>$1</em></strong>', $text);

        // 粗体 **text**
        $text = preg_replace('/\*{2}(.+?)\*{2}/', '<strong>$1</strong>', $text);

        // 斜体 *text*
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // 删除线 ~~text~~
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);

        // @提醒（支持中文、字母、数字、下划线）
        $text = preg_replace_callback('/@([\w\x{4e00}-\x{9fff}]+)/u', function ($m) {
            $encoded = rawurlencode($m[1]);
            $display = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            return '<a href="/user/' . $encoded . '" class="at-mention">@' . $display . '</a>';
        }, $text);

        return $text;
    }

    /**
     * 检查 URL 是否安全（防止 javascript: 等协议注入）
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        // 允许相对路径、http、https、mailto
        if ($url === '' || $url[0] === '#') return true;
        // 允许单斜杠相对路径，拦截 // 开头的 protocol-relative URL
        if ($url[0] === '/') return !isset($url[1]) || $url[1] !== '/';
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null) return true; // 无协议，相对路径
        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }
}
