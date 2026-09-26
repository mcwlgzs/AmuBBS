<?php
/**
 * 示例插件
 *
 * 演示插件系统的两种原语：
 *   - add_action()   监听「某件事发生了」
 *   - add_filter()   修改「某个值」，改完继续往下传
 *
 * 钩子名字就是 App\Events\Events 里的常量值，例如：
 *   Events::THREAD_CREATED  === 'thread.created'
 *   Events::USER_REGISTERED === 'user.registered'
 * 也就是说核心已经在派发的事件，插件全部可以直接监听，无需改动核心代码。
 * （Events 里 30 个常量全部有派发点，`php scripts/check_events.php` 会守住这一点）
 *
 * ⚠️ 载荷形状：核心用 Event::dispatch(Events::X, [...]) 触发，等价于
 *    do_action('x', [...]) —— 整个数组是「一个」参数，所以回调签名要写 function (array $data)。
 */

namespace Plugins\Example;

use Core\PluginInterface;

class Plugin implements PluginInterface
{
    /**
     * 注册钩子：插件启用后每次请求都会调用
     */
    public function register(): void
    {
        // ---- Action：监听核心已有事件，不关心返回值 ----
        add_action('thread.created', function (array $data) {
            error_log('[示例插件] 有新帖发布: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }, 10);

        add_action('user.registered', function (array $data) {
            error_log('[示例插件] 有新用户注册: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }, 10);

        // ---- Filter：修改核心传下来的值 ----
        // 给帖子标题加后缀（核心在 ThreadSvc 里调用了 apply_filters('thread.title', ...)）
        add_filter('thread.title', function (string $title): string {
            return $title . ' [示例插件]';
        }, 10);

        // 也可以拦截内容，例如做敏感词替换、加免责声明
        add_filter('post.content', function (string $content): string {
            return $content;
        }, 10);
    }

    /**
     * 可选生命周期：首次启用时调用（建表、写默认配置等）
     * 不实现也不会报错。
     */
    public function install(): void
    {
        error_log('[示例插件] 已安装');
    }

    /**
     * 可选生命周期：卸载时调用（清理数据）
     */
    public function uninstall(): void
    {
        error_log('[示例插件] 已卸载');
    }
}
