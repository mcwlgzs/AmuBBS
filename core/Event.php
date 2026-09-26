<?php
/**
 * 事件与钩子调度器
 *
 * 一套系统同时提供两种原语（WordPress 风格）：
 *   - Action（动作）  ：do_action('tag', ...$args)            —— 通知某件事发生了，不关心返回值
 *   - Filter（过滤器）：apply_filters('tag', $value, ...$args) —— 修改一个值并继续传递
 *
 * 兼容旧 API：listen() / dispatch() 仍然可用，dispatch() 等价于 do_action()。
 * 因此已有的 45 处 Event::dispatch(Events::X, [...]) 调用点会自动成为插件钩子，
 * 不需要改动任何业务代码。
 *
 * 优先级：数字越小越先执行（WordPress 约定），默认 10。
 */

namespace Core;

class Event
{
    /** @var array<string, array<int, list<callable>>> tag => priority => callbacks */
    private static array $listeners = [];

    /** @var array<string, int> 每个 tag 已被触发的次数 */
    private static array $fired = [];

    // ------------------------------------------------------------------
    // Action
    // ------------------------------------------------------------------

    public static function addAction(string $tag, callable $callback, int $priority = 10): void
    {
        self::$listeners[$tag][$priority][] = $callback;
    }

    public static function doAction(string $tag, ...$args): void
    {
        self::$fired[$tag] = (self::$fired[$tag] ?? 0) + 1;

        foreach (self::callbacks($tag) as $callback) {
            // 回调显式返回 false 时中断后续传播（保持与旧 dispatch 行为一致）
            if ($callback(...$args) === false) {
                break;
            }
        }
    }

    // ------------------------------------------------------------------
    // Filter
    // ------------------------------------------------------------------

    public static function addFilter(string $tag, callable $callback, int $priority = 10): void
    {
        self::$listeners[$tag][$priority][] = $callback;
    }

    public static function applyFilters(string $tag, mixed $value, ...$args): mixed
    {
        foreach (self::callbacks($tag) as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // 向后兼容的旧 API
    // ------------------------------------------------------------------

    /**
     * 向后兼容的旧 API（listen / dispatch）
     *
     * 默认优先级是 0，比 add_action 的默认 10 更靠前：
     * 核心自带的监听器（LogListener、自动头像等）都是通过 listen() 注册的，
     * 这样它们永远先于插件回调执行；插件要抢先可以显式传更小的优先级（负数也可以）。
     */
    public static function listen(string $event, callable $listener, int $priority = 0): void
    {
        self::addAction($event, $listener, $priority);
    }

    public static function dispatch(string $event, array $data = []): void
    {
        self::doAction($event, $data);
    }

    public static function hasListeners(string $event): bool
    {
        return self::hasAction($event);
    }

    // ------------------------------------------------------------------
    // 查询与维护
    // ------------------------------------------------------------------

    public static function hasAction(string $tag): bool
    {
        return !empty(self::$listeners[$tag]);
    }

    /**
     * 本实现里 Action 与 Filter 共用同一份存储，因此两者等价
     */
    public static function hasFilter(string $tag): bool
    {
        return self::hasAction($tag);
    }

    /**
     * 该 tag 至今被触发过几次（WordPress 的 did_action）
     */
    public static function didAction(string $tag): int
    {
        return self::$fired[$tag] ?? 0;
    }

    public static function removeAction(string $tag, ?callable $callback = null, ?int $priority = null): void
    {
        self::removeCallback($tag, $callback, $priority);
    }

    public static function removeFilter(string $tag, ?callable $callback = null, ?int $priority = null): void
    {
        self::removeCallback($tag, $callback, $priority);
    }

    /**
     * 移除某个 tag 下的全部回调
     */
    public static function remove(string $tag): void
    {
        unset(self::$listeners[$tag]);
    }

    public static function clear(): void
    {
        self::$listeners = [];
        self::$fired = [];
    }

    /**
     * 列出所有已注册钩子的 tag（后台展示 / 调试用）
     *
     * @return string[]
     */
    public static function tags(): array
    {
        return array_keys(self::$listeners);
    }

    /**
     * 某个 tag 下注册了多少个回调
     */
    public static function count(string $tag): int
    {
        $total = 0;
        foreach (self::$listeners[$tag] ?? [] as $callbacks) {
            $total += count($callbacks);
        }

        return $total;
    }

    private static function removeCallback(string $tag, ?callable $callback, ?int $priority): void
    {
        if (!isset(self::$listeners[$tag])) {
            return;
        }

        // 不指定回调 = 清空整个 tag
        if ($callback === null) {
            unset(self::$listeners[$tag]);
            return;
        }

        foreach (self::$listeners[$tag] as $p => $callbacks) {
            if ($priority !== null && $p !== $priority) {
                continue;
            }

            self::$listeners[$tag][$p] = array_values(array_filter(
                $callbacks,
                static fn($cb) => $cb !== $callback
            ));

            if (empty(self::$listeners[$tag][$p])) {
                unset(self::$listeners[$tag][$p]);
            }
        }

        if (empty(self::$listeners[$tag])) {
            unset(self::$listeners[$tag]);
        }
    }

    /**
     * 按优先级升序展开回调（数字小 = 先执行）
     *
     * @return list<callable>
     */
    private static function callbacks(string $tag): array
    {
        if (empty(self::$listeners[$tag])) {
            return [];
        }

        $byPriority = self::$listeners[$tag];
        ksort($byPriority);

        $flat = [];
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $cb) {
                $flat[] = $cb;
            }
        }

        return $flat;
    }
}
