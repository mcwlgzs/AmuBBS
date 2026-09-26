<?php
/**
 * 第三方登录关联模型（social_logins 表）
 *
 * ⚠️ 这张表原先只出现在 SocialLoginService 的 SQL 里，install/database.sql 从未建它 ——
 *    任何一次社交登录都会直接抛 1146。本次随迁移一起补进了安装 SQL。
 */

namespace App\Models;

use Core\Database;

class SocialLogin extends Model
{
    protected static string $table = 'social_logins';

    /** 本表没有软删除列（解绑即删行） */
    protected static ?string $softDeleteColumn = null;

    /**
     * 按平台 + 平台用户标识找关联记录
     */
    public static function findByProvider(string $provider, string $openId): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM social_logins WHERE provider = ? AND open_id = ?",
            [$provider, $openId]
        );

        return $row ?: null;
    }

    /** 同步平台侧的昵称与头像 */
    public static function updateProfile(int $id, string $nickname, string $avatar): int
    {
        return Database::execute(
            "UPDATE social_logins SET nickname = ?, avatar = ? WHERE id = ?",
            [$nickname, $avatar, $id]
        );
    }

    public static function create(
        int $userId,
        string $provider,
        string $openId,
        string $nickname,
        string $avatar,
        int $createdAt
    ): int {
        Database::execute(
            "INSERT INTO social_logins (user_id, provider, open_id, nickname, avatar, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $provider, $openId, $nickname, $avatar, $createdAt]
        );

        return Database::lastInsertId();
    }
}
