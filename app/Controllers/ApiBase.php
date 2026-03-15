<?php
/**
 * API 控制器基类
 * Token 认证 + JSON 响应
 */

namespace App\Controllers;

use Core\Database;

class ApiBase extends Base
{
    protected ?array $authUser = null;

    /** API Token 有效期（秒），默认 30 天 */
    private const TOKEN_TTL = 86400 * 30;

    /**
     * 验证 API Token
     * Token 格式: Bearer <token>
     * Token 存储在 users 表的 api_token 字段，基于 login_at 判断过期
     */
    protected function authenticate(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->apiError('未提供认证 Token', 401);
            return;
        }

        $token = $matches[1];
        $tokenHash = hash('sha256', $token);
        $user = Database::fetchOne(
            "SELECT id, username, email, group_id, credits, login_at FROM users WHERE api_token = ? AND deleted_at IS NULL",
            [$tokenHash]
        );

        if (!$user) {
            $this->apiError('Token 无效或已过期', 401);
            return;
        }

        // Token 过期检查
        $loginAt = (int)($user['login_at'] ?? 0);
        if ($loginAt > 0 && (time() - $loginAt) > self::TOKEN_TTL) {
            // 清除过期 token
            Database::execute("UPDATE users SET api_token = NULL WHERE id = ?", [$user['id']]);
            $this->apiError('Token 已过期，请重新登录', 401);
            return;
        }

        $this->authUser = $user;
    }

    /**
     * 可选认证（不强制）
     */
    protected function optionalAuth(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $tokenHash = hash('sha256', $matches[1]);
            $user = Database::fetchOne(
                "SELECT id, username, email, group_id, credits, login_at FROM users WHERE api_token = ? AND deleted_at IS NULL",
                [$tokenHash]
            );
            // 检查 token 是否过期
            if ($user) {
                $loginAt = (int)($user['login_at'] ?? 0);
                if ($loginAt > 0 && (time() - $loginAt) > self::TOKEN_TTL) {
                    return; // token 过期，视为未认证
                }
                $this->authUser = $user;
            }
        }
    }

    /**
     * API 成功响应
     */
    protected function apiSuccess($data = null, string $message = 'ok'): void
    {
        $this->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * API 错误响应
     */
    protected function apiError(string $message, int $httpCode = 400, int $code = -1): void
    {
        $this->json([
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $httpCode);
    }

    /**
     * 获取 JSON 请求体（继承自 Base）
     * @deprecated 使用父类 Base::getJsonInput() 即可
     */
    protected function getJsonInput(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    /**
     * 生成 API Token
     */
    protected function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
