<?php
/**
 * 事件分发器
 * 支持精确匹配和通配符监听
 */

namespace Core;

class EventDispatcher
{
    private array $listeners = [];
    private array $wildcards = [];
    private array $sorted = [];

    /**
     * 注册事件监听器
     */
    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][$priority][] = $listener;
        unset($this->sorted[$event]); // 注册新监听器时清除排序缓存
    }

    /**
     * 注册通配符监听器
     */
    public function listenWildcard(string $pattern, callable $listener): void
    {
        $this->wildcards[$pattern][] = $listener;
    }

    /**
     * 触发事件
     */
    public function dispatch(string $event, mixed $payload = null): void
    {
        // 触发精确匹配的监听器（排序结果缓存，避免每次 dispatch 重排）
        if (isset($this->listeners[$event])) {
            if (!isset($this->sorted[$event])) {
                krsort($this->listeners[$event]);
                $this->sorted[$event] = true;
            }

            foreach ($this->listeners[$event] as $priorityListeners) {
                foreach ($priorityListeners as $listener) {
                    $listener($payload);
                }
            }
        }

        // 触发通配符监听器
        foreach ($this->wildcards as $pattern => $listeners) {
            if ($this->matchWildcard($pattern, $event)) {
                foreach ($listeners as $listener) {
                    $listener($payload);
                }
            }
        }
    }

    /**
     * 触发事件（对象形式）
     */
    public function fire(object $event): void
    {
        $this->dispatch(get_class($event), $event);
    }

    /**
     * 移除事件监听器
     */
    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
    }

    /**
     * 通配符匹配
     */
    private function matchWildcard(string $pattern, string $event): bool
    {
        $regex = str_replace('\*', '.*', preg_quote($pattern, '/'));
        return preg_match('/^' . $regex . '$/', $event) === 1;
    }

    /**
     * 获取所有监听器
     */
    public function getListeners(string $event = null): array
    {
        if ($event === null) {
            return $this->listeners;
        }

        return $this->listeners[$event] ?? [];
    }
}
