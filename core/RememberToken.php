<?php
/**
 * Remember Me 持久登录 Token 管理
 * 比 Xiuno 的 xn_encrypt 对称加密方案更安全：
 * - 使用 HMAC-SHA256 生成 token
 * - 数据库存 token hash，cookie 存明文 token
 * - 密码修改后自动失效（token 包含密码哈希片段）
 * - 使用 hash_equals() 时间安全比较
 */

namespace Core;

class RememberToken
{
    private const COOKIE_NAME = 'amubbs_remember';
    private const TOKEN_LIFETIME = 30 * 86400; // 30 天

    /**
     * 生成并设置 remember token
     */
    public static function generate(int $userId, string $passwordHash): void
    {
        $appKey = self::getAppKey();
        $time = time();
        $random = bin2hex(random_bytes(16));
        // 使用密码哈希的 HMAC 而非原始片段，避免泄露密码哈希
        $pwCheck = hash_hmac('sha256', $passwordHash, $appKey . ':pw_check');
        $pwFragment = substr($pwCheck, 0, 16);

        $payload = "{$userId}:{$time}:{$random}:{$pwFragment}";
        $signature = hash_hmac('sha256', $payload, $appKey);
        $token = base64_encode("{$payload}:{$signature}");

        // 数据库存 token 的 hash（即使数据库泄露也无法伪造 cookie）
        $tokenHash = hash('sha256', $token);
        Database::execute(
            "UPDATE users SET remember_token = ?, updated_at = ? WHERE id = ?",
            [$tokenHash, $time, $userId]
        );

        // 设置 cookie
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => $time + self::TOKEN_LIFETIME,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * 验证 remember token，成功返回用户信息，失败返回 null
     */
    public static function verify(): ?array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (empty($token)) {
            return null;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            self::clear();
            return null;
        }

        $parts = explode(':', $decoded);
        // payload: userId:time:random:pwFragment:signature
        if (count($parts) !== 5) {
            self::clear();
            return null;
        }

        [$userId, $time, $random, $pwFragment, $signature] = $parts;
        $userId = (int)$userId;
        $time = (int)$time;

        // 检查是否过期
        if (time() - $time > self::TOKEN_LIFETIME) {
            self::clear();
            return null;
        }

        // 验证签名
        $appKey = self::getAppKey();
        $payload = "{$userId}:{$time}:{$random}:{$pwFragment}";
        $expectedSig = hash_hmac('sha256', $payload, $appKey);
        if (!hash_equals($expectedSig, $signature)) {
            self::clear();
            return null;
        }

        // 查询用户
        $user = Database::fetchOne(
            "SELECT id, username, password, group_id, avatar, remember_token, deleted_at FROM users WHERE id = ?",
            [$userId]
        );

        if (!$user || $user['deleted_at'] !== null) {
            self::clear();
            return null;
        }

        // 验证密码片段（密码修改后 token 自动失效，时间安全比较；复用上方 $appKey）
        $expectedPwFragment = substr(hash_hmac('sha256', $user['password'], $appKey . ':pw_check'), 0, 16);
        if (!hash_equals($expectedPwFragment, $pwFragment)) {
            self::clear();
            return null;
        }

        // 验证 token hash
        $tokenHash = hash('sha256', $token);
        if (empty($user['remember_token']) || !hash_equals($user['remember_token'], $tokenHash)) {
            self::clear();
            return null;
        }

        unset($user['password'], $user['remember_token']);
        return $user;
    }

    /**
     * 清除 remember token（登出时调用）
     */
    public static function clear(int $userId = 0): void
    {
        // 清除 cookie
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // 清除数据库 token
        if ($userId > 0) {
            Database::execute(
                "UPDATE users SET remember_token = NULL, updated_at = ? WHERE id = ?",
                [time(), $userId]
            );
        }
    }

    /**
     * 获取应用密钥
     */
    private static function getAppKey(): string
    {
        $key = env('APP_KEY', '');
        if (empty($key)) {
            // 回退：组合多个环境因素降低可预测性
            $dbPass = env('DB_PASSWORD', '');
            $dbName = env('DB_DATABASE', '');
            if ($dbPass === '' && $dbName === '') {
                throw new \RuntimeException('请在 .env 中配置 APP_KEY');
            }
            $key = hash('sha256', $dbPass . ':' . $dbName . ':amubbs_remember');
        }
        return $key;
    }
}
