<?php
/**
 * 登录验证中间件
 */

namespace App\Middlewares;

class Auth implements Middleware
{
    public function handle(callable $next): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            // API/AJAX 请求返回 JSON 401
            if ($this->isAjaxRequest()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '请先登录',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 普通页面请求重定向到登录页
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
            $redirect = urlencode($currentUrl);
            header("Location: /login?redirect={$redirect}");
            exit;
        }

        // 每 5 分钟从 DB 刷新 group_id，防止管理员修改用户组后 session 过期不同步
        $lastRefresh = (int)($_SESSION['_group_refreshed_at'] ?? 0);
        if (time() - $lastRefresh > 300) {
            $row = \Core\Cache::get("user:group:{$userId}", function () use ($userId) {
                return \Core\Database::fetchOne("SELECT group_id FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
            }, 300);
            if ($row) {
                $_SESSION['group_id'] = (int)$row['group_id'];
            } else {
                // 用户已被删除，清除 session
                session_destroy();
                header('Location: /login');
                exit;
            }
            $_SESSION['_group_refreshed_at'] = time();
        }

        $next();
    }

    private function isAjaxRequest(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['HTTP_ACCEPT'])
            && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
            || (!empty($_SERVER['CONTENT_TYPE'])
            && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));
    }
}
