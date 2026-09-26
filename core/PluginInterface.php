<?php
/**
 * 插件契约
 *
 * 插件只需要实现 register()，在里面用 add_action() / add_filter() 挂载钩子。
 *
 * install()   可选，首次启用时调用一次（建表、写默认配置等）
 * uninstall() 可选，卸载时调用（清理数据）
 *
 * 生命周期方法不写进接口是有意的：绝大多数插件不需要它们，
 * 强制实现只会让每个插件都多两段空方法。
 */

namespace Core;

interface PluginInterface
{
    /**
     * 注册钩子
     * 插件被启用后，每次请求都会调用一次
     */
    public function register(): void;
}
