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
     *
     * 浏览器直接访问（Accept 含 text/html 且不含 application/json、非 htmx、非 XHR）时
     * 渲染主题化错误页，避免 /thread/999999 这类页面把一段 JSON 甩给用户。
     */
    protected function error(string $message, int $code = 400): void
    {
        if ($this->wantsHtmlError()) {
            $this->renderErrorPage($message, $code);
            return;
        }

        $this->json([
            'code' => 1,
            'msg' => $message,
            'success' => false,
            'message' => $message,
            'timestamp' => time(),
        ], $code);
    }

    /**
     * 当前请求是否应该收到 HTML 错误页（而不是 JSON）
     */
    private function wantsHtmlError(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }
        if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
            return false;
        }
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return false;
        }

        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

        // 浏览器：text/html,application/xhtml+xml,... ；curl 默认 */*；API 客户端多为 application/json
        return str_contains($accept, 'text/html') && !str_contains($accept, 'application/json');
    }

    /**
     * 渲染主题化错误页（resources/views/404.php，支持 $errorCode/$errorTitle/$errorMessage）
     */
    private function renderErrorPage(string $message, int $code): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');

        $viewFile = APP_PATH . 'resources/views/404.php';
        if (!file_exists($viewFile)) {
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            exit;
        }

        $errorCode = $code;
        $errorTitle = match ($code) {
            403 => '没有访问权限',
            404 => '页面未找到',
            429 => '请求过于频繁',
            default => '请求无法完成',
        };
        $errorMessage = $message;

        require $viewFile;
        exit;
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

    // ==================== htmx 支持 ====================
    //
    // 前后台共用同一套约定：
    //   - 变更操作用 htmx 表单/按钮提交，服务端只回一个片段，页面不整页刷新
    //   - 提示通过 HX-Trigger 响应头回传，由各自主框架统一监听后弹 toast
    //     （前台 frontFlash / 后台 adminFlash）
    //   - 普通（非 htmx）请求仍走原有 JSON 响应，旧调用方不受影响

    /**
     * 是否为 htmx 发起的请求
     */
    protected function isHtmx(): bool
    {
        return !empty($_SERVER['HTTP_HX_REQUEST']);
    }

    /**
     * 读取请求参数
     *
     * 兼容两种来源，让同一个接口既能被 htmx 表单提交调用，也不破坏原有 JSON 调用方：
     *   - htmx 表单（application/x-www-form-urlencoded）→ $_POST
     *   - 旧 JSON 接口（application/json）→ php://input
     */
    protected function input(): array
    {
        return !empty($_POST) ? $_POST : $this->getJsonInput();
    }

    /**
     * 给 htmx 响应附带一条前台提示（toast）
     *
     * ⚠️ 这里不能加 JSON_UNESCAPED_UNICODE：HTTP 头字段按 ISO-8859-1 逐字节解码，
     * UTF-8 中文直接写进头里，浏览器读到的就是「å·²åéç» 2 ä½ç¨æ·」这种乱码。
     * 保持 json_encode 默认的 \uXXXX 转义，头里只有 ASCII，前端 JSON.parse 后中文正常。
     */
    protected function frontFlash(string $message, string $type = 'success'): void
    {
        header('HX-Trigger: ' . json_encode([
            'frontFlash' => ['type' => $type, 'message' => $message],
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * htmx 变更操作的统一响应
     *
     * htmx 请求：带一条提示，并渲染刷新后的片段（列表随之更新，不整页刷新）
     * 普通请求：保持原有 JSON 响应
     *
     * @param callable():void $fragment 渲染刷新后的片段
     * @param bool            $flash    成功时是否弹提示（有些动作片段本身已经说明了结果）
     */
    protected function respondFragment(bool $ok, string $message, callable $fragment, bool $flash = true): void
    {
        if (!$this->isHtmx()) {
            $ok ? $this->success($message) : $this->error($message);
            return;
        }

        if ($flash) {
            $this->frontFlash($message, $ok ? 'success' : 'danger');
        }

        if ($ok) {
            $fragment();
            return;
        }

        // 失败时不要替换目标：否则会把用户正在看的片段冲成空
        http_response_code(422);
        header('HX-Reswap: none');
    }

    /**
     * 「做完就整页重载」这类动作的 htmx 响应
     *
     * 有些页面（比如任务中心）领取成功后整页数据都得跟着变，
     * 与其再拼一堆片段，不如让前端刷新一次——等价于原实现的 location.reload()。
     */
    protected function respondRefresh(bool $ok, string $message): void
    {
        if (!$this->isHtmx()) {
            $ok ? $this->success($message) : $this->error($message);
            return;
        }

        $this->frontFlash($message, $ok ? 'success' : 'danger');

        if ($ok) {
            header('HX-Refresh: true');
            return;
        }

        http_response_code(422);
        header('HX-Reswap: none');
    }

    /**
     * htmx 表单提交的统一响应
     *
     * 成功：htmx 请求回 HX-Redirect 跳转（表单不整页刷新、也不替换任何片段），
     *       普通请求保持原 JSON 响应（$extra 里可以带 thread_id 之类的旧字段）。
     * 失败：htmx 请求回 422 + HX-Reswap: none，页面停在原地、用户已填内容不丢，
     *       原因通过 frontFlash 弹 toast；普通请求保持原 JSON 错误。
     *
     * @param array<string,mixed> $extra 仅用于非 htmx 的 JSON 响应，补充兼容字段
     */
    protected function respondSubmit(bool $ok, string $message, string $redirectUrl = '', array $extra = []): void
    {
        if (!$this->isHtmx()) {
            if ($ok) {
                $this->json(array_merge([
                    'code'    => 0,
                    'msg'     => $message,
                    'success' => true,
                    'message' => $message,
                ], $extra));
                return;
            }
            $this->error($message);
            return;
        }

        $this->frontFlash($message, $ok ? 'success' : 'danger');

        if ($ok) {
            if ($redirectUrl !== '') {
                // 过滤换行符防止 header injection
                header('HX-Redirect: ' . str_replace(["\r", "\n"], '', $redirectUrl));
            }
            return;
        }

        http_response_code(422);
        header('HX-Reswap: none');
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
