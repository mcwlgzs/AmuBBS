<?php
/**
 * 管理员双层认证 Token
 * 进入后台时生成独立 token，绑定 IP + UA，1 小时过期，30 分钟自动续期
 * 比 Xiuno 的方案增加了 UA 绑定和自动续期
 */

namespace Core;

class AdminToken
{
    private const COOKIE_NAME = 'amubbs_admin_token';
    private const TOKEN_LIFETIME = 3600;    // 1 小时
    private const RENEW_THRESHOLD = 1800;   // 30 分钟后自动续期

    /**
     * 生成 admin token 并设置 cookie
     */
    public static function generate(int $userId): string
    {
        $ip = Helper::clientIp();
        $time = time();
        $uaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $appKey = self::getAppKey();

        $payload = "{$userId}|{$time}|{$ip}|{$uaHash}";
        $signature = hash_hmac('sha256', $payload, $appKey);
        $token = base64_encode("{$payload}|{$signature}");

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => $time + self::TOKEN_LIFETIME,
            'path' => '/admin',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Strict',
        ]);

        return $token;
    }

    /**
     * 验证 admin token
     * 成功返回 ['valid' => true, 'user_id' => int, 'needs_renew' => bool]
     * 失败返回 ['valid' => false, 'reason' => string]
     */
    public static function verify(): array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (empty($token)) {
            return ['valid' => false, 'reason' => 'no_token'];
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            self::clear();
            return ['valid' => false, 'reason' => 'invalid_format'];
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            self::clear();
            return ['valid' => false, 'reason' => 'invalid_format'];
        }

        [$userId, $time, $ip, $uaHash, $signature] = $parts;
        $userId = (int)$userId;
        $time = (int)$time;

        // 检查过期
        if (time() - $time > self::TOKEN_LIFETIME) {
            self::clear();
            return ['valid' => false, 'reason' => 'expired'];
        }

        // 验证签名
        $appKey = self::getAppKey();
        $payload = "{$userId}|{$time}|{$ip}|{$uaHash}";
        $expectedSig = hash_hmac('sha256', $payload, $appKey);
        if (!hash_equals($expectedSig, $signature)) {
            self::clear();
            return ['valid' => false, 'reason' => 'invalid_signature'];
        }

        // UA 绑定验证
        $currentUaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!hash_equals($uaHash, $currentUaHash)) {
            self::clear();
            return ['valid' => false, 'reason' => 'ua_mismatch'];
        }

        // 可选 IP 绑定（非空即启用，检查 token 中的 IP 是否与当前 IP 一致）
        $enableIpBind = \App\Services\SettingSvc::get('admin_bind_ip', '');
        if ($enableIpBind !== '') {
            $currentIp = Helper::clientIp();
            if ($ip !== $currentIp) {
                self::clear();
                return ['valid' => false, 'reason' => 'ip_mismatch'];
            }
        }

        // 验证用户是否仍为管理员（缓存 300s，避免每次请求查 DB）
        $user = Cache::get("user:group:{$userId}", function () use ($userId) {
            return \Core\Database::fetchOne(
                "SELECT group_id FROM users WHERE id = ? AND deleted_at IS NULL",
                [$userId]
            );
        }, 300);
        if (!$user || (int)$user['group_id'] !== \App\Services\PermissionSvc::ADMIN_GROUP_ID) {
            self::clear();
            return ['valid' => false, 'reason' => 'not_admin'];
        }

        // 是否需要续期（超过 30 分钟）
        $needsRenew = (time() - $time) > self::RENEW_THRESHOLD;

        return [
            'valid' => true,
            'user_id' => $userId,
            'needs_renew' => $needsRenew,
        ];
    }

    /**
     * 续期 token
     */
    public static function renew(int $userId): void
    {
        self::generate($userId);
    }

    /**
     * 清除 admin token
     */
    public static function clear(): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/admin',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * 获取应用密钥
     */
    private static function getAppKey(): string
    {
        $key = env('APP_KEY', '');
        if (empty($key)) {
            $dbPass = env('DB_PASSWORD', '');
            $dbName = env('DB_DATABASE', '');
            if ($dbPass === '' && $dbName === '') {
                throw new \RuntimeException('请在 .env 中配置 APP_KEY');
            }
            $key = hash('sha256', $dbPass . ':' . $dbName . ':amubbs_admin');
        }
        return $key;
    }
}
