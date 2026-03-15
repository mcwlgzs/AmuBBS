<?php
/**
 * 事件调度器
 */

namespace Core;

class Event
{
    private static array $listeners = [];

    /**
     * 注册事件监听器
     */
    public static function listen(string $event, callable $listener, int $priority = 0): void
    {
        self::$listeners[$event][] = [
            'callback' => $listener,
            'priority' => $priority,
        ];
    }

    /**
     * 触发事件
     */
    public static function dispatch(string $event, array $data = []): void
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }

        // 按优先级排序（高优先级先执行）
        $listeners = self::$listeners[$event];
        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        foreach ($listeners as $listener) {
            $result = call_user_func($listener['callback'], $data);
            // 如果监听器返回 false，停止传播
            if ($result === false) {
                break;
            }
        }
    }

    /**
     * 检查事件是否有监听器
     */
    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    /**
     * 移除事件的所有监听器
     */
    public static function remove(string $event): void
    {
        unset(self::$listeners[$event]);
    }

    /**
     * 清除所有监听器
     */
    public static function clear(): void
    {
        self::$listeners = [];
    }
}
