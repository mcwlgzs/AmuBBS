<?php
/**
 * 站点运行级别中间件
 * 0=关站维护（所有人不可访问）
 * 1=仅管理员可读写
 * 2=注册用户只读（禁止发帖/回复）
 * 3=注册用户可读写（游客不可访问）
 * 4=所有人只读（游客可浏览，禁止发帖/回复）
 * 5=完全开放
 */

namespace App\Middlewares;

use App\Services\SettingSvc;

class RunLevel implements Middleware
{
    /** 写操作路由前缀/路径 */
    private const WRITE_PATHS = [
        '/thread/create', '/thread/reply', '/thread/edit', '/thread/delete',
        '/thread/like', '/thread/favorite', '/thread/reward', '/thread/upload',
        '/thread/toggle-top', '/thread/toggle-highlight', '/thread/toggle-lock',
        '/thread/move', '/thread/buy-content', '/thread/upload-image',
        '/post/edit', '/post/delete',
        '/attachment/upload',
        '/checkin',
        '/user/avatar', '/user/profile', '/user/password', '/user/follow',
        '/user/blacklist', '/user/clear-history',
        '/messages/send', '/messages/recall',
        '/moments/create', '/moments/like', '/moments/comment', '/moments/delete',
        '/mod/batch',
        '/forum/moderators',
        '/notifications/read', '/notifications/delete',
        '/tasks/claim',
        '/vip/purchase',
        // RESTful API 写操作
        '/api/threads',
        '/api/notifications/read',
    ];

    public function handle(callable $next): void
    {
        $runLevel = SettingSvc::getInt('site_runlevel', 5);

        // 完全开放，直接放行
        if ($runLevel >= 5) {
            $next();
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $groupId = (int)($_SESSION['group_id'] ?? 0);

        // 管理员始终可访问
        if ($groupId === \App\Services\PermissionSvc::ADMIN_GROUP_ID) {
            $next();
            return;
        }

        // 允许访问静态资源、登录相关路由和基础服务路由
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $allowedPaths = [
            '/login', '/logout', '/register',
            '/forgot-password',
            '/captcha/',
            '/auth/callback/', '/auth/redirect/',
            '/api/auth/',  // API 认证路由始终放行
            '/assets/', '/uploads/', '/plugin-assets/',
            '/health', '/cron/',
            '/admin',  // 后台由 AdminAuth 中间件单独控制
        ];
        foreach ($allowedPaths as $allowed) {
            if (str_starts_with($path, $allowed)) {
                $next();
                return;
            }
        }

        $isWrite = $this->isWriteRequest($path);

        switch ($runLevel) {
            case 0: // 关站维护
            case 1: // 仅管理员（已在上面放行）
                $this->showMaintenance($runLevel);
                return;

            case 2: // 注册用户只读
                if ($userId <= 0) {
                    $this->showMaintenance($runLevel);
                    return;
                }
                if ($isWrite) {
                    $this->showReadOnly();
                    return;
                }
                break;

            case 3: // 注册用户可读写，游客不可访问
                if ($userId <= 0) {
                    $this->showMaintenance($runLevel);
                    return;
                }
                break;

            case 4: // 所有人只读
                if ($isWrite) {
                    $this->showReadOnly();
                    return;
                }
                break;
        }

        $next();
    }

    private function isWriteRequest(string $path): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }
        foreach (self::WRITE_PATHS as $wp) {
            if (str_starts_with($path, $wp)) {
                return true;
            }
        }
        return false;
    }

    private function showReadOnly(): void
    {
        $msg = '站点当前为只读模式，暂时无法发帖或回复';
        if ($this->isAjaxRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        $message = $msg;
        $pageType = 'readonly';
        $showLogin = false;
        include APP_PATH . 'resources/views/maintenance.php';
        exit;
    }

    private function showMaintenance(int $runLevel): void
    {
        $maintenanceMsg = SettingSvc::get('site_maintenance_msg', '站点维护中，请稍后再访问...');

        // 级别 2/3：仅限注册用户，游客应引导登录/注册
        if ($runLevel >= 2) {
            $maintenanceMsg = '本站仅对注册用户开放，请登录后访问';
        }

        // AJAX 请求返回 JSON
        if ($this->isAjaxRequest()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $maintenanceMsg,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(503);
        header('Retry-After: 3600');
        $message = $maintenanceMsg;
        $pageType = 'maintenance';
        // 级别 0/1 显示管理员登录，级别 2/3 显示普通登录/注册
        $showLogin = true;
        $showRegister = ($runLevel >= 2);
        include APP_PATH . 'resources/views/maintenance.php';
        exit;
    }

    private function isAjaxRequest(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['HTTP_ACCEPT'])
            && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    }
}
