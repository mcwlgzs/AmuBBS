<?php
/**
 * 基础控制器
 */

namespace App\Controllers;

use Core\Cache;

class Base
{
    /**
     * 渲染视图
     */
    protected function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = APP_PATH . 'resources/views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("视图文件不存在: {$viewFile}");
        }

        require $viewFile;
    }

    /**
     * 渲染片段缓存（侧边栏、组件等）
     * 模板中使用: $this->renderCached('components/sidebar-credit-rank', [], 'sidebar:credit', 300)
     */
    protected function renderCached(string $view, array $data = [], string $cacheKey = '', int $ttl = 300): void
    {
        if ($cacheKey === '') {
            $cacheKey = 'frag:' . $view;
        }

        $html = Cache::get($cacheKey);
        if ($html !== null && is_string($html)) {
            echo $html;
            return;
        }

        ob_start();
        $this->render($view, $data);
        $html = ob_get_clean();

        if ($html !== false && strlen($html) > 0) {
            Cache::set($cacheKey, $html, $ttl);
        }
    }

    /**
     * JSON 响应
     */
    protected function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 成功响应（兼容 layui code:0 和旧版 success:true 双格式）
     */
    protected function success(string $message, $data = null): void
    {
        $this->json([
            'code' => 0,
            'msg' => $message,
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    /**
     * 错误响应（兼容 layui code:1 和旧版 success:false 双格式）
     */
    protected function error(string $message, int $code = 400): void
    {
        $this->json([
            'code' => 1,
            'msg' => $message,
            'success' => false,
            'message' => $message,
            'timestamp' => time(),
        ], $code);
    }

    /**
     * 重定向
     */
    protected function redirect(string $url): void
    {
        // 过滤换行符防止 header injection
        $url = str_replace(["\r", "\n"], '', $url);
        // 防止 Open Redirect：只允许站内相对路径或同域名
        if (!str_starts_with($url, '/') && !str_starts_with($url, './')) {
            $url = '/';
        }
        header("Location: {$url}");
        exit;
    }

    /**
     * 获取当前用户ID
     */
    protected function getCurrentUserId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    /**
     * 检查是否登录
     */
    protected function isLoggedIn(): bool
    {
        return $this->getCurrentUserId() > 0;
    }

    /**
     * 要求登录
     */
    protected function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            $this->error('请先登录', 401);
            exit;
        }
    }

    /**
     * 分页参数计算
     */
    protected function paginate(int $total, int $perPage = 20): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        return [
            'page' => $page,
            'perPage' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * 获取 JSON 请求体
     */
    protected function getJsonInput(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    /**
     * 转义 LIKE 通配符
     */
    protected static function escapeLike(string $str): string
    {
        return addcslashes($str, '%_\\');
    }
}
