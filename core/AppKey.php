<?php
/**
 * 应用密钥（APP_KEY）
 *
 * 用途：派生 remember-me / 后台会话 Token 的签名密钥。
 *
 * 以前的做法是「没有 APP_KEY 就用数据库凭据哈希代替」，这是很危险的：
 *   DB_PASSWORD/DB_DATABASE 属于部署信息，一旦泄露（或被猜到默认值），
 *   攻击者就能直接伪造长期有效的 remember-me / 后台 Token。
 * 现在改为：
 *   1) 优先用 .env 里的 APP_KEY；
 *   2) 没有就在 storage/app_key 里**自动生成**一个随机密钥（0600，'xb' 原子创建，多进程一致）；
 *   3) 连文件都写不了就抛异常 —— 绝不退回可预测的派生密钥。
 */

namespace Core;

class AppKey
{
    /** 密钥文件名 */
    private const FILE = 'storage/app_key';

    /** 进程内缓存 */
    private static ?string $key = null;

    /**
     * 取应用密钥；$context 用于派生互不相同的子密钥（remember / admin / ...）
     */
    public static function get(string $context = ''): string
    {
        $base = self::base();

        return $context === '' ? $base : hash_hmac('sha256', $context, $base);
    }

    private static function base(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $env = trim((string)env('APP_KEY', ''));
        // 过滤掉占位符（安装向导/示例配置可能留下这些值）
        if ($env !== '' && $env !== 'changeme' && !preg_match('/^(your|replace|please|xxx)/i', $env)) {
            return self::$key = $env;
        }

        $file = (defined('APP_PATH') ? APP_PATH : '') . self::FILE;

        $existing = self::readFile($file);
        if ($existing !== '') {
            return self::$key = $existing;
        }

        $generated = bin2hex(random_bytes(32));

        // 'xb' = 独占创建：并发首访时只有一个进程写得进去，其余进程重读它生成的值
        $fh = @fopen($file, 'xb');
        if ($fh !== false) {
            fwrite($fh, $generated);
            fclose($fh);
            @chmod($file, 0600);

            return self::$key = $generated;
        }

        $existing = self::readFile($file);
        if ($existing !== '') {
            return self::$key = $existing;
        }

        throw new \RuntimeException(
            '无法确定 APP_KEY：请在 .env 中显式配置 APP_KEY，或确保 ' . $file . ' 可写'
        );
    }

    private static function readFile(string $file): string
    {
        if (!is_readable($file)) {
            return '';
        }
        $value = trim((string)@file_get_contents($file));

        return strlen($value) >= 32 ? $value : '';
    }
}
