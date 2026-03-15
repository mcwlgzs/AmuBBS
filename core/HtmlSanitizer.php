<?php
/**
 * HTML 白名单过滤器（XSS 防护）
 * 参考 XiunoBBS xn_html_safe() 实现
 * 只允许安全的标签、属性和 CSS 属性通过
 */

namespace Core;

class HtmlSanitizer
{
    /** 允许的标签及其允许的属性 */
    private const ALLOWED_TAGS = [
        'a'          => ['href', 'target', 'rel', 'title', 'class'],
        'img'        => ['src', 'alt', 'width', 'height', 'loading', 'class', 'style'],
        'p'          => ['class', 'style'],
        'br'         => [],
        'hr'         => [],
        'h1'         => ['class'], 'h2' => ['class'], 'h3' => ['class'],
        'h4'         => ['class'], 'h5' => ['class'], 'h6' => ['class'],
        'strong'     => [], 'b' => [],
        'em'         => [], 'i' => ['class'],
        'u'          => [],
        'del'        => [], 's' => [],
        'blockquote' => ['class'],
        'pre'        => ['class'],
        'code'       => ['class'],
        'ul'         => [], 'ol' => [], 'li' => [],
        'table'      => ['class', 'style'],
        'thead'      => [], 'tbody' => [], 'tr' => [],
        'th'         => ['style', 'colspan', 'rowspan'],
        'td'         => ['style', 'colspan', 'rowspan'],
        'div'        => ['class', 'style'],
        'span'       => ['class', 'style'],
        'sup'        => [], 'sub' => [],
        'details'    => [], 'summary' => [],
    ];

    /** 允许的 CSS 属性 */
    private const ALLOWED_CSS = [
        'color', 'background-color', 'background',
        'font-size', 'font-weight', 'font-style', 'font-family',
        'text-align', 'text-decoration', 'text-indent',
        'line-height', 'letter-spacing', 'word-spacing',
        'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
        'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
        'border', 'border-radius', 'border-collapse',
        'width', 'max-width', 'height', 'max-height',
        'display', 'float', 'clear', 'overflow',
        'vertical-align', 'white-space',
        'list-style', 'list-style-type',
    ];

    /** 允许的 URL 协议 */
    private const ALLOWED_PROTOCOLS = ['http', 'https', 'mailto'];

    /**
     * 过滤 HTML，只保留白名单中的标签和属性
     */
    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // 移除 NUL 字节
        $html = str_replace("\0", '', $html);

        // 移除所有 <script>、<style>、<iframe>、<object>、<embed>、<form> 及其内容
        $html = preg_replace('/<(script|style|iframe|object|embed|form|applet)\b[^>]*>.*?<\/\1>/is', '', $html);
        // 移除未闭合的危险标签
        $html = preg_replace('/<(script|style|iframe|object|embed|form|applet)\b[^>]*\/?>/i', '', $html);

        // 移除事件属性 on*（在原始标签上操作，避免 html_entity_decode 引入新 HTML 结构）
        $html = preg_replace_callback('/<[^>]+>/i', function ($m) {
            $tag = $m[0];
            // 先在原始标签上移除明文 on* 属性
            $tag = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $tag);
            $tag = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $tag);
            // 再检测实体编码的 on* 属性（如 o&#110;click），若存在则整个标签视为危险，移除所有属性
            $decoded = html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/\s+on\w+\s*=/i', $decoded)) {
                // 标签中仍有编码绕过的事件属性，剥离所有属性只保留标签名
                $tag = preg_replace('/^(<\/?\w+)\b[^>]*?(\/?>)/', '$1$2', $tag);
            }
            return $tag;
        }, $html);

        // 使用 DOMDocument 解析并过滤
        if (class_exists('DOMDocument')) {
            return self::sanitizeWithDom($html);
        }

        // 降级：正则过滤
        return self::sanitizeWithRegex($html);
    }

    /**
     * 使用 DOMDocument 进行精确过滤
     */
    private static function sanitizeWithDom(string $html): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        // 抑制 HTML5 标签警告
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__root__');
        if (!$root) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }

        self::filterNode($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    /**
     * 递归过滤 DOM 节点
     */
    private static function filterNode(\DOMNode $node): void
    {
        $toRemove = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);

                if (!isset(self::ALLOWED_TAGS[$tagName])) {
                    // 不允许的标签：保留子节点文本，移除标签
                    $toRemove[] = $child;
                    continue;
                }

                // 过滤属性
                $allowedAttrs = self::ALLOWED_TAGS[$tagName];
                $attrsToRemove = [];
                foreach ($child->attributes as $attr) {
                    $attrName = strtolower($attr->nodeName);
                    if (!in_array($attrName, $allowedAttrs, true)) {
                        $attrsToRemove[] = $attr->nodeName;
                    } else {
                        // 验证属性值
                        $val = $attr->nodeValue;
                        if ($attrName === 'href' || $attrName === 'src') {
                            if (!self::isSafeUrl($val)) {
                                $attrsToRemove[] = $attr->nodeName;
                            }
                        } elseif ($attrName === 'style') {
                            $safe = self::sanitizeCss($val);
                            if ($safe === '') {
                                $attrsToRemove[] = $attr->nodeName;
                            } else {
                                $attr->nodeValue = $safe;
                            }
                        }
                    }
                }
                foreach ($attrsToRemove as $an) {
                    $child->removeAttribute($an);
                }

                // 强制 a 标签安全属性
                if ($tagName === 'a') {
                    $child->setAttribute('rel', 'noopener nofollow');
                    if (!$child->getAttribute('target')) {
                        $child->setAttribute('target', '_blank');
                    }
                }

                // 递归处理子节点
                self::filterNode($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $toRemove[] = $child;
            }
        }

        // 移除不允许的标签（保留其文本内容）
        foreach ($toRemove as $rm) {
            if ($rm->nodeType === XML_ELEMENT_NODE) {
                // 将子节点移到父节点
                while ($rm->firstChild) {
                    $node->insertBefore($rm->firstChild, $rm);
                }
            }
            $node->removeChild($rm);
        }
    }

    /**
     * 过滤 CSS 属性
     */
    private static function sanitizeCss(string $css): string
    {
        // 移除 expression()、url()（除了安全的 url）
        $css = preg_replace('/expression\s*\(/i', '', $css);

        $parts = explode(';', $css);
        $safe = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            $kv = explode(':', $part, 2);
            if (count($kv) !== 2) continue;

            $prop = strtolower(trim($kv[0]));
            $val = trim($kv[1]);

            if (!in_array($prop, self::ALLOWED_CSS, true)) continue;

            // 移除 url() 中的 javascript:
            if (preg_match('/url\s*\(/i', $val)) continue;

            $safe[] = $prop . ':' . $val;
        }

        return implode(';', $safe);
    }

    /**
     * 检查 URL 是否安全
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || $url[0] === '#') return true;
        // 允许相对路径，但阻止 protocol-relative URL（//evil.com）
        if ($url[0] === '/') return !isset($url[1]) || $url[1] !== '/';

        // 检测 javascript: 等危险协议（包括各种编码绕过）
        $decoded = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $decoded = urldecode($decoded);
        $decoded = preg_replace('/[\s\x00-\x1f]+/', '', $decoded);

        if (preg_match('/^(javascript|vbscript|data|blob):/i', $decoded)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === false) return true;

        return in_array(strtolower($scheme), self::ALLOWED_PROTOCOLS, true);
    }

    /**
     * 正则降级过滤（无 DOMDocument 时使用）
     * 保留白名单标签及其允许的属性
     */
    private static function sanitizeWithRegex(string $html): string
    {
        $allowedTagNames = array_keys(self::ALLOWED_TAGS);
        $tagPattern = implode('|', $allowedTagNames);

        // 移除所有不在白名单中的标签，白名单标签只保留允许的属性
        $html = preg_replace_callback('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/i', function ($m) use ($tagPattern) {
            $tag = strtolower($m[1]);
            if (!preg_match('/^(' . $tagPattern . ')$/i', $tag)) {
                return '';
            }
            // 闭合标签
            if (str_starts_with($m[0], '</')) {
                return '</' . $tag . '>';
            }
            // 自闭合标签
            $selfClosing = in_array($tag, ['br', 'hr', 'img'], true);
            $allowedAttrs = self::ALLOWED_TAGS[$tag] ?? [];
            if (empty($allowedAttrs)) {
                return $selfClosing ? '<' . $tag . ' />' : '<' . $tag . '>';
            }
            // 提取并过滤属性（支持双引号、单引号、无引号三种格式）
            $attrStr = $m[2] ?? '';
            $safeAttrs = [];
            if (preg_match_all('/\s+([a-zA-Z][a-zA-Z0-9-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']*))/i', $attrStr, $attrMatches, PREG_SET_ORDER)) {
                foreach ($attrMatches as $am) {
                    $attrName = strtolower($am[1]);
                    $attrVal = ($am[2] ?? '') !== '' ? $am[2] : (($am[3] ?? '') !== '' ? $am[3] : ($am[4] ?? ''));
                    if (!in_array($attrName, $allowedAttrs, true)) {
                        continue;
                    }
                    // href/src 使用完整的 isSafeUrl 检查（含实体解码+多协议检测）
                    if (in_array($attrName, ['href', 'src'], true)) {
                        if (!self::isSafeUrl($attrVal)) {
                            continue;
                        }
                    }
                    // style 属性过滤
                    if ($attrName === 'style') {
                        $attrVal = self::sanitizeCssRegex($attrVal);
                        if ($attrVal === '') continue;
                    }
                    $safeAttrs[] = $attrName . '="' . htmlspecialchars($attrVal, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
            $attrOutput = !empty($safeAttrs) ? ' ' . implode(' ', $safeAttrs) : '';
            return $selfClosing ? '<' . $tag . $attrOutput . ' />' : '<' . $tag . $attrOutput . '>';
        }, $html);

        return $html;
    }

    /**
     * 正则降级的 CSS 属性过滤
     */
    private static function sanitizeCssRegex(string $css): string
    {
        $safe = [];
        $parts = explode(';', $css);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $colonPos = strpos($part, ':');
            if ($colonPos === false) continue;
            $prop = strtolower(trim(substr($part, 0, $colonPos)));
            $val = trim(substr($part, $colonPos + 1));
            if (in_array($prop, self::ALLOWED_CSS, true) && !preg_match('/expression|url|javascript/i', $val)) {
                $safe[] = $prop . ':' . $val;
            }
        }
        return implode(';', $safe);
    }
}
