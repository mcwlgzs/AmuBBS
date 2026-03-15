<?php
/**
 * 实用工具函数集
 * 提供人性化日期显示、内容摘要生成、CDN-aware IP 检测等
 */

namespace Core;

class Helper
{
    /**
     * 人性化时间显示（参考 Xiuno 的 humandate）
     * 将时间戳转为"刚刚"、"5分钟前"、"3小时前"、"昨天 14:30"等
     */
    public static function humanDate(int $timestamp): string
    {
        $now = time();
        $diff = $now - $timestamp;

        if ($diff < 0) {
            return date('Y-m-d', $timestamp);
        }

        if ($diff < 60) {
            return '刚刚';
        }

        if ($diff < 3600) {
            return (int)($diff / 60) . ' 分钟前';
        }

        if ($diff < 86400) {
            return (int)($diff / 3600) . ' 小时前';
        }

        if ($diff < 172800) {
            return '昨天 ' . date('H:i', $timestamp);
        }

        if ($diff < 259200) {
            return '前天 ' . date('H:i', $timestamp);
        }

        // 今年内显示 月-日 时:分
        if (date('Y', $timestamp) === date('Y', $now)) {
            return date('n月j日 H:i', $timestamp);
        }

        // 跨年显示完整日期
        return date('Y-n-j', $timestamp);
    }

    /**
     * 生成内容摘要（参考 Xiuno 的 content_brief）
     * 去除 HTML/Markdown 标记，截取指定长度
     */
    public static function brief(string $content, int $length = 200): string
    {
        // 去除 HTML 标签
        $text = strip_tags($content);

        // 去除 Markdown 常见标记
        $text = preg_replace('/!\[.*?\]\(.*?\)/', '', $text);   // 图片
        $text = preg_replace('/\[.*?\]\(.*?\)/', '', $text);    // 链接
        $text = preg_replace('/```[\s\S]*?```/', '', $text);    // 代码块
        $text = preg_replace('/[#*`~>|_\-=+]/', '', $text);    // 标记符号

        // 合并多余空白
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '...';
    }

    /**
     * 获取客户端真实 IP（CDN-aware）
     * 仅当 REMOTE_ADDR 在可信代理列表中时才信任代理头部，防止伪造
     *
     * 支持：Cloudflare、阿里云 CDN、腾讯云 CDN、AWS CloudFront 等
     * 配置项 trusted_proxies：逗号分隔的可信代理 IP/CIDR，如 "127.0.0.1,10.0.0.0/8,172.16.0.0/12"
     */
    public static function clientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 检查 REMOTE_ADDR 是否为可信代理
        if (self::isTrustedProxy($remoteAddr)) {
            // 可信代理头部，按优先级排列
            $headers = [
                'HTTP_CF_CONNECTING_IP',     // Cloudflare
                'HTTP_ALI_CDN_REAL_IP',      // 阿里云 CDN
                'HTTP_X_REAL_IP',            // Nginx 反代 / 腾讯云 CDN
                'HTTP_X_FORWARDED_FOR',      // 通用代理头
            ];

            foreach ($headers as $header) {
                $ip = $_SERVER[$header] ?? '';
                if ($ip === '') {
                    continue;
                }

                // X-Forwarded-For 可能包含多个 IP，取第一个（最远端客户端）
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
    }

    /**
     * 判断 IP 是否为可信代理
     * 支持精确 IP 和 CIDR 匹配
     */
    private static function isTrustedProxy(string $ip): bool
    {
        // 本地回环始终可信
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        // 从配置读取可信代理列表（缓存在静态变量中避免重复解析）
        static $proxies = null;
        if ($proxies === null) {
            $raw = \App\Services\SettingSvc::get('trusted_proxies', '');
            $proxies = $raw !== '' ? array_map('trim', explode(',', $raw)) : [];
        }

        // 未配置可信代理时，不信任任何代理头部
        if (empty($proxies)) {
            return false;
        }

        foreach ($proxies as $proxy) {
            if ($proxy === $ip) {
                return true;
            }
            // CIDR 匹配
            if (str_contains($proxy, '/') && self::ipInCidr($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查 IP 是否在 CIDR 范围内
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int)$bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) === 4 ? 32 : 128;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $mask = str_repeat("\xff", (int)($bits / 8));
        if ($bits % 8 > 0) {
            $mask .= chr(0xff << (8 - $bits % 8));
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /**
     * 格式化文件大小
     */
    public static function fileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /**
     * 数字人性化显示（1234 → 1.2K，12345 → 1.2W）
     */
    public static function humanNumber(int $num): string
    {
        if ($num < 1000) {
            return (string)$num;
        }
        if ($num < 10000) {
            return round($num / 1000, 1) . 'K';
        }
        return round($num / 10000, 1) . 'W';
    }

    /**
     * 安全截断 UTF-8 字符串
     */
    public static function truncate(string $str, int $length, string $suffix = '...'): string
    {
        if (mb_strlen($str) <= $length) {
            return $str;
        }
        return mb_substr($str, 0, $length) . $suffix;
    }
}
