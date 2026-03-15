<?php
/**
 * 后台管理基础控制器
 */

namespace App\Controllers\Admin;

use App\Controllers\Base;

class AdminBase extends Base
{
    /**
     * 检查管理员权限
     */
    protected function requireAdmin(): void
    {
        if (empty($_SESSION['user_id']) || (int)($_SESSION['group_id'] ?? 0) !== \App\Services\PermissionSvc::ADMIN_GROUP_ID) {
            // AJAX/JSON 请求返回 401，普通请求重定向到登录页
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
                $this->error('请先登录管理后台', 401);
                exit;
            }
            $this->redirect('/login');
        }
    }

    /**
     * layui table 标准 JSON 响应（code:0 表示成功）
     */
    protected function layuiJson(array $data = [], int $count = 0, string $msg = 'success'): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'msg' => $msg, 'data' => $data, 'count' => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * layui table 错误 JSON 响应
     */
    protected function layuiError(string $msg, int $code = 1): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => $code, 'msg' => $msg, 'data' => [], 'count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
