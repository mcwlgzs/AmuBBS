<?php
/**
 * 附件模型（attachments 表）
 *
 * 注意：后台附件管理**不过滤** deleted_at（沿用原行为：软删掉的行也照样列出来给管理员清理），
 * 所以这里不套基类的 notDeleted()，删除走的是硬删除。
 */

namespace App\Models;

use Core\Database;

class Attachment extends Model
{
    protected static string $table = 'attachments';

    /** 后台是硬删除（列表里连软删的也一起清） */
    protected static ?string $softDeleteColumn = null;

    /**
     * 后台列表（页面与 JSON API 共用同一份查询）
     *
     * @param string $search 文件名关键字（内部转义 LIKE 通配符）
     * @param string $type   image | file | 空
     * @return array{rows: array, total: int}
     */
    public static function adminList(string $search = '', string $type = '', int $page = 1, int $limit = 20): array
    {
        [$where, $params] = self::buildWhere($search, $type);

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM attachments a {$where}",
            $params
        )['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT a.*, u.username
             FROM attachments a
             LEFT JOIN users u ON a.user_id = u.id
             {$where}
             ORDER BY a.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, max(0, ($page - 1) * $limit)])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * 顶部统计：占用空间 / 图片数 / 文件数
     *
     * @return array{totalSize: int, imageCount: int, fileCount: int}
     */
    public static function adminStats(): array
    {
        $size  = Database::fetchOne("SELECT SUM(filesize) as s FROM attachments");
        $image = Database::fetchOne("SELECT COUNT(*) as c FROM attachments WHERE is_image = 1");
        $file  = Database::fetchOne("SELECT COUNT(*) as c FROM attachments WHERE is_image = 0");

        return [
            'totalSize'  => (int)($size['s'] ?? 0),
            'imageCount' => (int)($image['c'] ?? 0),
            'fileCount'  => (int)($file['c'] ?? 0),
        ];
    }

    /**
     * 单条附件（删除前要拿文件路径与显示名）
     */
    public static function findFresh(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM attachments WHERE id = ?", [$id]);

        return $row ?: null;
    }

    public static function remove(int $id): int
    {
        return Database::execute("DELETE FROM attachments WHERE id = ?", [$id]);
    }

    /**
     * 新增附件记录（图片与通用文件上传共用）
     */
    public static function create(
        int $userId,
        string $filename,
        string $filepath,
        int $filesize,
        string $mimetype,
        int $createdAt
    ): int {
        Database::execute(
            "INSERT INTO attachments (user_id, filename, filepath, filesize, mimetype, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $filename, $filepath, $filesize, $mimetype, $createdAt]
        );

        return Database::lastInsertId();
    }

    /** 下载计数 +1 */
    public static function incrementDownloads(int $id): int
    {
        return Database::execute("UPDATE attachments SET downloads = downloads + 1 WHERE id = ?", [$id]);
    }

    /** 某主题的附件列表 */
    public static function listByThread(int $threadId): array
    {
        return Database::fetchAll(
            "SELECT * FROM attachments WHERE thread_id = ? ORDER BY created_at ASC",
            [$threadId]
        );
    }

    /**
     * 把一批临时附件关联到主题/回复
     *
     * 只关联属于当前用户且尚未关联（thread_id = 0）的，防止劫持他人附件。
     *
     * @param int[] $attachIds
     */
    public static function associateToThread(array $attachIds, int $threadId, int $userId, int $postId = 0): int
    {
        $attachIds = array_values(array_filter(array_map('intval', $attachIds)));
        if (empty($attachIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($attachIds), '?'));
        $params = array_merge([$threadId, $postId, $userId], $attachIds);

        return Database::execute(
            "UPDATE attachments SET thread_id = ?, post_id = ?
             WHERE user_id = ? AND thread_id = 0 AND id IN ({$placeholders})",
            $params
        );
    }

    /**
     * 超过阈值仍未关联的临时附件（清理用）
     */
    public static function orphansBefore(int $threshold): array
    {
        return Database::fetchAll(
            "SELECT id, filepath FROM attachments WHERE thread_id = 0 AND post_id = 0 AND created_at < ?",
            [$threshold]
        );
    }

    /**
     * 按 id 批量硬删除（临时附件清理用；物理文件由调用方删）
     *
     * @param int[] $ids
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Database::execute("DELETE FROM attachments WHERE id IN ({$placeholders})", $ids);
    }

    /**
     * 软删除某用户的全部附件（删用户级联用，物理文件由定时任务清理）
     */
    public static function softDeleteByUser(int $userId, int $deletedAt): int
    {
        return Database::execute(
            "UPDATE attachments SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL",
            [$deletedAt, $userId]
        );
    }

    /**
     * 后台筛选条件（只在这里拼 SQL 片段，调用方传原始搜索词）
     *
     * @return array{0: string, 1: array}
     */
    private static function buildWhere(string $search, string $type): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND a.filename LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        if ($type === 'image') {
            $where .= ' AND a.is_image = 1';
        } elseif ($type === 'file') {
            $where .= ' AND a.is_image = 0';
        }

        return [$where, $params];
    }
}
