<?php
/**
 * 后台 - 插件管理
 *
 * 为什么需要这一页：插件启用状态存在 storage/plugin_config/plugins.json。
 * 目标部署环境是共享虚拟主机（没有 shell、不能跑命令），
 * 如果只能靠手改这个 JSON，插件系统对普通站长就等于不存在。
 * 这里把「启用 / 停用 / 卸载」做成三个按钮，由 AdminUi 发 AJAX 调用。
 *
 * 生命周期：enable() 首次启用会调插件的 install()，uninstall() 会调 uninstall()，
 * 两个方法都是可选的（见 Core\PluginInterface 的说明）。
 */

namespace App\Controllers\Admin;

use Core\Bootstrap;
use Core\PluginManager;

class PluginController extends AdminBase
{
    public function plugins(): void
    {
        $this->requireAdmin();
        $this->renderPluginsPage();
    }

    /**
     * 取本次请求的插件管理器
     *
     * Bootstrap 启动时已经建好一个（含本次请求真正加载的实例），优先复用它；
     * 极端情况下（plugins/ 目录不存在等）兜底新建一个，保证页面还能打开。
     */
    private function manager(): PluginManager
    {
        $pm = Bootstrap::getInstance()->getPluginManager();

        return $pm ?? new PluginManager(APP_PATH . 'plugins', APP_PATH . 'storage/plugin_config/');
    }

    /**
     * 渲染插件列表片段（GET 与增删改后的刷新共用同一条渲染路径）
     */
    private function renderPluginsPage(): void
    {
        $pm = $this->manager();

        $this->renderAdmin('admin/plugins', [
            'pageTitle' => '插件管理',
            'plugins'   => $pm->all(),
            'loaded'    => array_keys($pm->getLoaded()),
        ], 'plugins');
    }

    /**
     * 启用 / 停用
     *
     * 只改状态文件：本次请求已经启动完毕，插件从下一个请求开始生效，
     * 页面上会明确提示这一点，避免管理员以为按钮没起作用。
     */
    public function pluginToggle(): void
    {
        $this->requireAdmin();

        $input   = $this->input();
        $name    = trim((string)($input['name'] ?? ''));
        $enabled = (string)($input['enabled'] ?? '') === '1';

        $pm = $this->manager();
        if ($name === '' || !isset($pm->all()[$name])) {
            $this->respondMutation(false, '插件不存在', fn() => $this->renderPluginsPage());
            return;
        }

        $ok = $enabled ? $pm->enable($name) : $pm->disable($name);

        if ($ok) {
            $msg = $enabled ? "已启用插件「{$name}」，从下一个请求开始生效" : "已停用插件「{$name}」";
        } else {
            $msg = '状态文件写入失败，请检查 storage/plugin_config/ 是否可写';
        }

        $this->respondMutation($ok, $msg, fn() => $this->renderPluginsPage());
    }

    /**
     * 卸载：调插件的 uninstall() 并清掉启用状态（不删插件文件）
     */
    public function pluginUninstall(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $name  = trim((string)($input['name'] ?? ''));

        $pm = $this->manager();
        if ($name === '' || !isset($pm->all()[$name])) {
            $this->respondMutation(false, '插件不存在', fn() => $this->renderPluginsPage());
            return;
        }

        $ok = $pm->uninstall($name);

        $this->respondMutation(
            $ok,
            $ok ? "已卸载插件「{$name}」，插件文件保留，可重新启用" : '卸载失败',
            fn() => $this->renderPluginsPage()
        );
    }
}
