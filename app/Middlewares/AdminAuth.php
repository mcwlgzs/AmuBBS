<?php
/**
 * 后台管理员验证中间件
 * 双层认证：Session group_id + AdminToken
 */

namespace App\Middlewares;

use App\Services\SettingSvc;
use Core\AdminToken;

class AdminAuth implements Middleware
{
    public function handle(callable $next): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $groupId = (int) ($_SESSION['group_id'] ?? 0);

        // 第一层：Session 管理员身份检查
        if ($userId <= 0 || $groupId !== \App\Services\PermissionSvc::ADMIN_GROUP_ID) {
            $this->deny('需要管理员权限');
            return;
        }

        // IP 白名单检查
        $bindIp = SettingSvc::get('admin_bind_ip', '');
        if ($bindIp !== '') {
            $allowedIps = array_map('trim', explode(',', $bindIp));
            $clientIp = \Core\Helper::clientIp();
            if (!in_array($clientIp, $allowedIps, true)) {
                $this->deny('您的 IP 不在后台访问白名单中');
                return;
            }
        }

        // 第二层：AdminToken 验证
        $tokenResult = AdminToken::verify();

        if (!$tokenResult['valid']) {
            if ($tokenResult['reason'] === 'no_token') {
                // 首次进入后台需要重新验证身份，不自动签发 token
                // 通过 session 标记来判断是否刚完成过登录验证
                if (!empty($_SESSION['admin_verified']) && $_SESSION['admin_verified'] > time() - 300) {
                    AdminToken::generate($userId);
                    unset($_SESSION['admin_verified']);
                } else {
                    $this->deny('请重新登录以进入管理后台');
                    return;
                }
            } elseif ($tokenResult['reason'] === 'expired') {
                // token 过期，强制重新登录而非自动续期
                AdminToken::clear();
                $this->deny('管理会话已过期，请重新登录');
                return;
            } else {
                // 签名无效、IP/UA 变更等安全问题
                $this->deny('管理会话无效，请重新登录');
                return;
            }
        } else {
            // token 有效，检查是否需要续期
            if ($tokenResult['needs_renew']) {
                AdminToken::generate($userId);
            }

            // 验证 token 中的 user_id 与 session 一致
            if ($tokenResult['user_id'] !== $userId) {
                AdminToken::clear();
                $this->deny('管理会话无效');
                return;
            }
        }

        $next();
    }

    private function deny(string $message): void
    {
        if ($this->isAjaxRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 管理会话失效时跳转登录页，而非首页
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '/admin';
        header('Location: /login');
        exit;
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
