<?php
/**
 * 安全工具类
 */

namespace Core;

class Security
{
    /**
     * XSS 过滤 - 清理用户输入
     */
    public static function clean(string $input): string
    {
        // 去除 NULL 字节
        $input = str_replace("\0", '', $input);
        // HTML 实体编码
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 批量清理数组
     */
    public static function cleanArray(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $cleaned[$key] = self::clean($value);
            } elseif (is_array($value)) {
                $cleaned[$key] = self::cleanArray($value);
            } else {
                $cleaned[$key] = $value;
            }
        }
        return $cleaned;
    }

    /**
     * 验证是否为安全的整数 ID
     */
    public static function safeInt($value): int
    {
        return max(0, (int) $value);
    }

    /**
     * 设置安全响应头
     */
    public static function setSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self';");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        // HTTPS 环境下启用 HSTS
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * 检查请求来源（防止外部 POST）
     */
    public static function checkReferer(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (empty($referer)) {
            return false;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $refererHost = parse_url($referer, PHP_URL_HOST);

        return $refererHost === $host;
    }
}
