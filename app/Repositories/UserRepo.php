<?php
/**
 * 用户数据访问层
 */

namespace App\Repositories;

use Core\Database;
use Core\Cache;

class UserRepo
{
    private const FIND_BY_ID_SQL = "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL";
    private const GET_PROFILE_SQL = "SELECT u.*, g.name as group_name FROM users u LEFT JOIN user_groups g ON u.group_id = g.id WHERE u.id = ? AND u.deleted_at IS NULL";

    /**
     * 清除用户相关查询缓存
     */
    private function clearCache(int $userId): void
    {
        Cache::delete('dbq1:' . md5(self::FIND_BY_ID_SQL . serialize([$userId])));
        Cache::delete('dbq1:' . md5(self::GET_PROFILE_SQL . serialize([$userId])));
    }

    public function findById(int $id): ?array
    {
        return Database::fetchOneCached(self::FIND_BY_ID_SQL, [$id], 300);
    }

    public function findByUsername(string $username): ?array
    {
        return Database::fetchOne("
            SELECT * FROM users WHERE username = ? AND deleted_at IS NULL
        ", [$username]);
    }

    public function findByEmail(string $email): ?array
    {
        return Database::fetchOne("
            SELECT * FROM users WHERE email = ? AND deleted_at IS NULL
        ", [$email]);
    }

    public function findByNickname(string $nickname): ?array
    {
        return Database::fetchOne("
            SELECT * FROM users WHERE nickname = ? AND deleted_at IS NULL
        ", [$nickname]);
    }

    public function findByUsernameOrEmail(string $identity): ?array
    {
        return Database::fetchOne("
            SELECT * FROM users
            WHERE (username = ? OR email = ?)
            AND deleted_at IS NULL
        ", [$identity, $identity]);
    }

    public function create(string $username, string $email, string $hashedPassword, int $groupId = 1, int $credits = 0, ?string $nickname = null, string $avatar = '/assets/images/default-avatar.png'): int
    {
        Database::execute("
            INSERT INTO users (username, nickname, email, password, group_id, credits, avatar, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [$username, $nickname, $email, $hashedPassword, $groupId, $credits, $avatar, time()]);
        return Database::lastInsertId();
    }

    public function updateLoginInfo(int $userId, string $ip): int
    {
        $result = Database::execute("
            UPDATE users SET login_ip = ?, login_at = ? WHERE id = ?
        ", [$ip, time(), $userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function getProfile(int $userId): ?array
    {
        return Database::fetchOneCached(self::GET_PROFILE_SQL, [$userId], 300);
    }

    public function incrementThreadCount(int $userId): int
    {
        $result = Database::execute("
            UPDATE users SET thread_count = thread_count + 1 WHERE id = ?
        ", [$userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function incrementPostCount(int $userId): int
    {
        $result = Database::execute("
            UPDATE users SET post_count = post_count + 1 WHERE id = ?
        ", [$userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function updateAvatar(int $userId, string $avatar): int
    {
        $result = Database::execute("
            UPDATE users SET avatar = ? WHERE id = ?
        ", [$avatar, $userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function updateProfile(int $userId, string $email, string $signature, ?string $nickname = null): int
    {
        $result = Database::execute("
            UPDATE users SET email = ?, signature = ?, nickname = ?, updated_at = ? WHERE id = ?
        ", [$email, $signature, $nickname, time(), $userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function updatePassword(int $userId, string $hashedPassword): int
    {
        $result = Database::execute("
            UPDATE users SET password = ?, updated_at = ? WHERE id = ?
        ", [$hashedPassword, time(), $userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function decrementThreadCount(int $userId): int
    {
        $result = Database::execute("
            UPDATE users SET thread_count = CASE WHEN thread_count > 0 THEN thread_count - 1 ELSE 0 END WHERE id = ?
        ", [$userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function decrementPostCount(int $userId): int
    {
        $result = Database::execute("
            UPDATE users SET post_count = CASE WHEN post_count > 0 THEN post_count - 1 ELSE 0 END WHERE id = ?
        ", [$userId]);
        $this->clearCache($userId);
        return $result;
    }

    public function softDelete(int $userId): int
    {
        $result = Database::execute("
            UPDATE users SET deleted_at = ?, updated_at = ? WHERE id = ?
        ", [time(), time(), $userId]);
        $this->clearCache($userId);
        return $result;
    }
}
