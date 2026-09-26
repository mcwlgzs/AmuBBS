<?php
/**
 * 敏感词模型（sensitive_words 表）
 *
 * 前台过滤在 SensitiveWordService（负责缓存与匹配策略），
 * 本模型只管这张表的取数与落库；缓存失效由 SensitiveWordService::clearCache() 负责，
 * 避免同一个缓存 key 出现两个主人。
 */

namespace App\Models;

use Core\Database;

class SensitiveWord extends Model
{
    protected static string $table = 'sensitive_words';

    /** 本表没有软删除列（硬删除） */
    protected static ?string $softDeleteColumn = null;

    /**
     * 后台列表（页面与 JSON API 共用）
     *
     * @return array{rows: array, total: int}
     */
    public static function adminList(string $search = '', int $page = 1, int $limit = 20): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND word LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM sensitive_words {$where}",
            $params
        )['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT * FROM sensitive_words {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, max(0, ($page - 1) * $limit)])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** word 上有唯一索引，先查再插才不会抛 1062 */
    public static function existsByWord(string $word): bool
    {
        return Database::fetchOne("SELECT id FROM sensitive_words WHERE word = ? LIMIT 1", [$word]) !== null;
    }

    /**
     * 前台过滤用的全量词表（高等级排前面，替换时优先按 level 处理）
     */
    public static function allForFilter(): array
    {
        return Database::fetchAll(
            "SELECT word, replacement, level FROM sensitive_words ORDER BY level DESC"
        );
    }

    public static function create(string $word, string $replacement, int $level): int
    {
        Database::execute(
            "INSERT INTO sensitive_words (word, replacement, level, created_at) VALUES (?, ?, ?, ?)",
            [$word, $replacement, $level, time()]
        );

        return Database::lastInsertId();
    }

    public static function remove(int $id): int
    {
        return Database::execute("DELETE FROM sensitive_words WHERE id = ?", [$id]);
    }
}
