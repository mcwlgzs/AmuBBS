<?php
/**
 * 自动头像服务 - 用户注册时自动分配随机头像
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class AutoAvatarService
{
    /**
     * 为用户分配随机头像
     *
     * @param int $userId 用户 ID
     * @return void
     */
    public static function assignRandomAvatar(int $userId): void
    {
        if (!self::isEnabled()) {
            return;
        }

        // 获取用户信息
        $user = Database::fetchOne("SELECT id, avatar FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return;
        }

        // 检查是否需要覆盖已有头像
        $overwrite = self::shouldOverwriteExisting();
        if (!$overwrite && !empty($user['avatar']) && $user['avatar'] !== '') {
            // 检查是否为默认头像
            $defaultAvatar = SettingSvc::get('user_default_avatar', '') ?: '/assets/images/default-avatar.png';
            $isDefault = ($user['avatar'] === '/assets/images/default-avatar.png' || $user['avatar'] === $defaultAvatar);
            if (!$isDefault) {
                return; // 已有自定义头像，不覆盖
            }
        }

        // 获取可用头像列表
        $avatars = self::getAvatarList();
        if (empty($avatars)) {
            return;
        }

        // 随机选择一个头像
        $chosen = $avatars[array_rand($avatars)];
        $avatarUrl = '/assets/avatars/' . $chosen;

        // 更新用户头像
        Database::execute("UPDATE users SET avatar = ? WHERE id = ?", [$avatarUrl, $userId]);
        
        // 清除缓存
        Cache::delete("user:profile:{$userId}");
        UserSvc::clearEntityCache($userId);
    }

    /**
     * 获取可用头像列表
     *
     * @return array 头像文件名数组
     */
    public static function getAvatarList(): array
    {
        $avatarDir = APP_PATH . 'public/assets/avatars/';
        
        if (!is_dir($avatarDir)) {
            return [];
        }

        $files = scandir($avatarDir);
        if ($files === false) {
            return [];
        }

        $avatars = [];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts, true)) {
                $avatars[] = $file;
            }
        }

        return $avatars;
    }

    /**
     * 检查自动头像功能是否启用
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return SettingSvc::getBool('auto_avatar_enabled', false);
    }

    /**
     * 检查是否应该覆盖已有头像
     *
     * @return bool
     */
    public static function shouldOverwriteExisting(): bool
    {
        return SettingSvc::getBool('auto_avatar_overwrite', false);
    }
}
