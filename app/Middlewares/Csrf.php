<?php
/**
 * CSRF 防护中间件
 */

namespace App\Middlewares;

class Csrf implements Middleware
{
    public function handle(callable $next): void
    {
        // GET/HEAD/OPTIONS 请求不需要 CSRF 验证
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) {
            $next();
            return;
        }

        // API 路由：仅当携带 Bearer Token 时跳过 CSRF 验证
        // 防止攻击者构造表单 POST 到 /api/ 路径绕过 CSRF
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (str_starts_with($path, '/api/')) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (stripos($auth, 'Bearer ') === 0 && strlen($auth) > 7) {
                $next();
                return;
            }
            // 无 Bearer Token 的 /api/ 请求继续走 CSRF 验证
        }

        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'CSRF 验证失败，请刷新页面重试',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 表单提交轮换 token 防止重放；AJAX 请求不轮换，避免后续请求 403
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
        if (!$isAjax) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $next();
    }

    /**
     * 生成 CSRF Token
     */
    public static function generateToken(): string
    {
        // 确保 session 已启动（首次匿名 GET 可能被 startSession 跳过）
        \Core\Bootstrap::ensureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * 获取 CSRF Token 的 HTML hidden input
     */
    public static function tokenField(): string
    {
        $token = self::generateToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * 获取 CSRF Token 的 meta 标签（供 JS 使用）
     */
    public static function tokenMeta(): string
    {
        $token = self::generateToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
