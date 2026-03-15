<?php
/**
 * 日志监听器 - 记录关键业务操作
 */

namespace App\Listeners;

use App\Events\Events;
use Core\Event;

class LogListener
{
    /**
     * 注册所有日志监听器
     */
    public static function register(): void
    {
        Event::listen(Events::USER_REGISTERED, [self::class, 'onUserRegistered']);
        Event::listen(Events::USER_LOGGED_IN, [self::class, 'onUserLoggedIn']);
        Event::listen(Events::USER_LOGGED_OUT, [self::class, 'onUserLoggedOut']);
        Event::listen(Events::THREAD_CREATED, [self::class, 'onThreadCreated']);
        Event::listen(Events::THREAD_UPDATED, [self::class, 'onThreadUpdated']);
        Event::listen(Events::THREAD_DELETED, [self::class, 'onThreadDeleted']);
        Event::listen(Events::POST_CREATED, [self::class, 'onPostCreated']);

        // 管理操作日志
        Event::listen(Events::ADMIN_USER_UPDATED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_USER_DELETED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_FORUM_CREATED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_FORUM_UPDATED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_FORUM_DELETED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_THREAD_DELETED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_THREAD_TOPPED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_THREAD_HIGHLIGHTED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_SETTINGS_SAVED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_IP_BLOCKED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_IP_UNBLOCKED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_SENSITIVE_WORD_ADDED, [self::class, 'onAdminAction']);
        Event::listen(Events::ADMIN_CACHE_CLEARED, [self::class, 'onAdminAction']);
    }

    public static function onUserRegistered(array $data): void
    {
        self::log('用户注册', "用户 {$data['username']}(ID:{$data['user_id']}) 注册成功");
    }

    public static function onUserLoggedIn(array $data): void
    {
        self::log('用户登录', "用户 {$data['username']}(ID:{$data['user_id']}) 从 {$data['ip']} 登录");
    }

    public static function onThreadCreated(array $data): void
    {
        self::log('发帖', "用户 ID:{$data['user_id']} 在板块 {$data['forum_id']} 发帖 ID:{$data['thread_id']}");
    }

    public static function onPostCreated(array $data): void
    {
        self::log('回复', "用户 ID:{$data['user_id']} 回复帖子 {$data['thread_id']}，回复 ID:{$data['post_id']}");
    }

    public static function onThreadDeleted(array $data): void
    {
        self::log('删帖', "用户 ID:{$data['user_id']} 删除帖子 ID:{$data['thread_id']}");
    }

    public static function onUserLoggedOut(array $data): void
    {
        self::log('用户登出', "用户 {$data['username']}(ID:{$data['user_id']}) 登出");
    }

    public static function onThreadUpdated(array $data): void
    {
        self::log('编辑帖子', "用户 ID:{$data['user_id']} 编辑帖子 ID:{$data['thread_id']}");
    }

    public static function onAdminAction(array $data): void
    {
        $action = $data['action'] ?? '未知操作';
        $detail = $data['detail'] ?? '';
        $adminId = $data['admin_id'] ?? ($_SESSION['user_id'] ?? 0);
        self::log("管理操作:{$action}", "管理员 ID:{$adminId} {$detail}");

        // 同时写入数据库日志表
        try {
            \Core\Database::execute(
                "INSERT INTO logs (user_id, action, target_type, target_id, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $adminId,
                    $action,
                    $data['target_type'] ?? '',
                    $data['target_id'] ?? 0,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    time(),
                ]
            );
        } catch (\Throwable $e) {
            // 日志写入失败不影响业务
        }
    }

    private static function log(string $type, string $message): void
    {
        $logFile = APP_PATH . 'storage/logs/' . date('Y-m-d') . '.log';
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $type, $message);
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
