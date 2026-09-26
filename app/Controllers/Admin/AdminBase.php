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
     * 渲染后台页面（layuimini / iframe 子页面）
     *
     * 每个后台页面都是一个**独立文档**，在外壳的 iframe 里打开，
     * 所以这里直接输出「子页面框架 + 正文」，不再有 htmx 片段分支。
     * 外壳（tab / 菜单 / 顶栏）由 /admin 这条路由单独渲染 admin/layout，
     * 两者互不影响：刷新子页面不会重建外壳，切换 tab 也不会重复请求页面。
     *
     * 页面标题优先用控制器给的 $data['pageTitle']；
     * 没给就从 config/admin_nav.php 里按 $currentPage 取 —— 菜单与标题同一个数据源，
     * 不会出现「菜单叫帖子管理、标题叫别的」。
     */
    protected function renderAdmin(string $view, array $data = [], string $currentPage = ''): void
    {
        if ($currentPage === '') {
            $currentPage = basename($view);
        }

        if (empty($data['pageTitle'])) {
            $nav = require APP_PATH . 'config/admin_nav.php';
            foreach ($nav['groups'] as $group) {
                if (isset($group['pages'][$currentPage])) {
                    $data['pageTitle'] = $group['pages'][$currentPage]['title'];
                    break;
                }
            }
        }

        ob_start();
        $this->render($view, $data);
        $content = (string)ob_get_clean();

        $this->render('admin/layout_child', array_merge($data, [
            'content' => $content,
        ]));
    }

    // isHtmx() / input() 由父类 App\Controllers\Base 提供，前后台共用同一套判定

    /**
     * 给 htmx 响应附带一条提示（**遗留路径，后台自己不再走这里**）
     *
     * 后台已改为 layuimini + iframe 子页面，不再有 htmx 片段替换，
     * 也没有任何视图监听 HX-Trigger / adminFlash 了 —— 现在提示统一由
     * AdminUi（public/assets/js/admin-layui.js）读 {code,msg} 自己弹 layer.msg。
     * 保留它是为了兼容仍然带 HX-Request 头的外部调用方。
     */
    protected function htmxFlash(string $message, string $type = 'success'): void
    {
        // ⚠️ 响应头不是 UTF-8 通道：HTTP 头字段按 ISO-8859-1（逐字节 → U+00..FF）解码，
        // 把 UTF-8 中文直接写进头里，浏览器/htmx 读到的是「å·²åéç» 2 ä½ç¨æ·」这种乱码。
        // 这里刻意不加 JSON_UNESCAPED_UNICODE，让 json_encode 把非 ASCII 转成 \uXXXX，
        // 头里只留纯 ASCII，前端 JSON.parse 之后仍是正确的中文。
        header('HX-Trigger: ' . json_encode([
            'adminFlash'      => ['type' => $type, 'message' => $message],
            // 让前端关掉可能打开的 modal（表单提交成功后自动收起）
            'adminCloseModal' => true,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * 变更操作（增/删/改）的统一响应
     *
     * 后台的请求都走 JSON 分支：AdminUi.post() 发的是 X-Requested-With
     * 而不是 HX-Request，所以 isHtmx() 为 false，返回 {code,msg}，
     * 由前端弹 layer.msg 并自己 table.reload()。
     * htmx 分支（带提示 + 重渲染片段）是 layuimini 之前那版架构的遗留。
     *
     * @param callable():void $refresh 渲染刷新后的页面片段（仅 htmx 分支使用）
     */
    protected function respondMutation(bool $ok, string $message, callable $refresh): void
    {
        if ($this->isHtmx()) {
            $this->htmxFlash($message, $ok ? 'success' : 'danger');
            $refresh();
            return;
        }

        if ($ok) {
            $this->success($message);
        } else {
            $this->error($message);
        }
    }

    /**
     * 表格数据 JSON 响应：{code:0, msg, data, count}
     *
     * 这正是 layui table 的数据协议 —— table.render 的 url 直接吃这个格式，
     * 所以后台所有列表页都靠它取数，返回结构不能改。
     */
    protected function jsonTable(array $data = [], int $count = 0, string $msg = 'success'): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'msg' => $msg, 'data' => $data, 'count' => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 表格数据 JSON 错误响应（code 非 0）
     */
    protected function jsonTableError(string $msg, int $code = 1): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => $code, 'msg' => $msg, 'data' => [], 'count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
