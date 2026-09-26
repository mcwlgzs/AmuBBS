<?php
/**
 * WordPress 风格钩子函数（全局函数，面向插件作者）
 *
 * 这里刻意做成全局函数而不是 Core\Event 的静态方法：
 * 任何写过 WordPress 插件的人都能零成本上手，不需要先读框架文档。
 *
 * 用法：
 *   add_action('thread.created', function (array $data) {
 *       // 帖子发布后做点什么；$data = ['thread_id'=>, 'forum_id'=>, 'user_id'=>, 'username'=>]
 *   });
 *
 *   add_filter('thread.title', fn (string $title) => $title . ' - 赞助商');
 *
 * ⚠️ 核心业务代码是用 Event::dispatch(Events::X, [...]) 触发事件的，
 *    等价于 do_action(Events::X, [...]) —— 也就是「一个数组参数」，
 *    所以 action 回调签名应写成 function (array $data)，而不是 function ($threadId)。
 *    过滤器则是「值在前，附加参数在后」，见 apply_filters 的签名。
 *
 * 优先级：数字越小越先执行，默认 10（核心自带监听器注册在优先级 0）。
 */

use Core\Event;

if (!function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10): void
    {
        Event::addAction($tag, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void
    {
        Event::doAction($tag, ...$args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10): void
    {
        Event::addFilter($tag, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, ...$args): mixed
    {
        return Event::applyFilters($tag, $value, ...$args);
    }
}

if (!function_exists('remove_action')) {
    /**
     * 移除回调；$callback 传 null 表示清空该 tag 下的全部回调
     */
    function remove_action(string $tag, ?callable $callback = null, ?int $priority = null): void
    {
        Event::removeAction($tag, $callback, $priority);
    }
}

if (!function_exists('remove_filter')) {
    /**
     * 移除过滤器；$callback 传 null 表示清空该 tag 下的全部过滤器
     */
    function remove_filter(string $tag, ?callable $callback = null, ?int $priority = null): void
    {
        Event::removeFilter($tag, $callback, $priority);
    }
}

if (!function_exists('has_action')) {
    function has_action(string $tag): bool
    {
        return Event::hasAction($tag);
    }
}

if (!function_exists('has_filter')) {
    function has_filter(string $tag): bool
    {
        return Event::hasFilter($tag);
    }
}
