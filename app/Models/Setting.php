<?php
/**
 * 系统设置模型（settings 表）
 *
 * settings 是「键 => 值」的扁平表，业务上到处都要读它（SettingSvc 带缓存读、后台设置页读、
 * 用户设置页按前缀读）。读写都收敛到这里，业务层只负责缓存与校验。
 */

namespace App\Models;

use Core\Database;

class Setting extends Model
{
    protected static string $table = 'settings';

    /** settings 没有 deleted_at，也没有 id 主键（主键是 key） */
    protected static ?string $softDeleteColumn = null;

    /**
     * 全部设置（键 => 值）
     */
    public static function all(): array
    {
        $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        return $settings;
    }

    /**
     * 按前缀取设置（例如 user_ 开头的用户相关设置）
     */
    public static function allWithPrefix(string $prefix): array
    {
        $rows = Database::fetchAll(
            "SELECT `key`, `value` FROM settings WHERE `key` LIKE ?",
            [addcslashes($prefix, '%_\\') . '%']
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        return $settings;
    }

    /**
     * 写入单个设置（存在则更新）
     */
    public static function set(string $key, string $value): void
    {
        self::setMany([$key => $value]);
    }

    /**
     * 批量写入设置（一条 SQL 一条记录，冲突则更新）
     *
     * 后台设置页一次会改十几个键，逐个 INSERT ... ON DUPLICATE KEY UPDATE 就够了，
     * 这里不再额外合并成多值 INSERT，保持可读性与参数绑定简单。
     *
     * @param array<string, string> $values
     */
    public static function setMany(array $values): void
    {
        if (empty($values)) {
            return;
        }

        $now = time();
        foreach ($values as $key => $value) {
            Database::execute(
                "INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = ?, `updated_at` = ?",
                [(string)$key, (string)$value, $now, (string)$value, $now]
            );
        }
    }
}
