<?php
/**
 * 后台 - 回帖管理
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Cache;
use Core\Event;
use App\Events\Events;

class PostController extends AdminBase
{
    /**
     * 构建回帖列表查询条件
     */
    private function buildPostsWhere(): array
    {
        $search = trim($_GET['search'] ?? '');
        $username = trim($_GET['username'] ?? '');
        $threadId = (int)($_GET['thread_id'] ?? 0);
        $ip = trim($_GET['ip'] ?? '');

        $where = "WHERE p.deleted_at IS NULL";
        $params = [];

        if ($search !== '') {
            $where .= " AND p.content LIKE ?";
            $params[] = "%" . addcslashes($search, '%_\\') . "%";
        }
        if ($username !== '') {
            $where .= " AND p.username LIKE ?";
            $params[] = "%" . addcslashes($username, '%_\\') . "%";
        }
        if ($threadId > 0) {
            $where .= " AND p.thread_id = ?";
            $params[] = $threadId;
        }
        if ($ip !== '') {
            $where .= " AND p.user_ip LIKE ?";
            $params[] = "%" . addcslashes($ip, '%_\\') . "%";
        }

        return [$where, $params];
    }

    private function getPostsSortCol(): array
    {
        $sortBy = $_GET['sort'] ?? 'id';
        $sortDir = strtolower($_GET['dir'] ?? 'desc');
        $allowedSorts = [
            'id' => 'p.id',
            'created_at' => 'p.created_at',
        ];
        $sortCol = $allowedSorts[$sortBy] ?? 'p.id';
        if (!in_array($sortDir, ['asc', 'desc'], true)) $sortDir = 'desc';
        return [$sortCol, $sortDir];
    }

    public function posts(): void
    {
        $this->requireAdmin();
        $this->render('admin/posts', ['pageTitle' => '回帖管理']);
    }

    /**
     * 回帖列表 API
     */
    public function postsApi(): void
    {
        $this->requireAdmin();

        [$where, $params] = $this->buildPostsWhere();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM posts p {$where}", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        [$sortCol, $sortDir] = $this->getPostsSortCol();

        $posts = Database::fetchAll(
            "SELECT p.*, t.title as thread_title FROM posts p LEFT JOIN threads t ON p.thread_id = t.id {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($posts, $total);
    }

    public function postDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }

        $post = Database::fetchOne("SELECT thread_id, user_id FROM posts WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$post) { $this->error('回帖不存在'); return; }

        Database::beginTransaction();
        try {
            Database::execute("UPDATE posts SET deleted_at = ? WHERE id = ?", [time(), $id]);
            Database::execute("UPDATE threads SET reply_count = CASE WHEN reply_count > 0 THEN reply_count - 1 ELSE 0 END WHERE id = ?", [$post['thread_id']]);
            Database::execute("UPDATE users SET post_count = CASE WHEN post_count > 0 THEN post_count - 1 ELSE 0 END WHERE id = ?", [$post['user_id']]);

            $forumId = Database::fetchOne("SELECT forum_id FROM threads WHERE id = ?", [$post['thread_id']])['forum_id'] ?? 0;
            if ($forumId > 0) {
                Database::execute("UPDATE forums SET post_count = CASE WHEN post_count > 0 THEN post_count - 1 ELSE 0 END WHERE id = ?", [$forumId]);
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        Cache::delete("thread:{$post['thread_id']}");
        Event::dispatch(Events::ADMIN_THREAD_DELETED, [
            'action' => '删除回帖',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除回帖 ID:{$id}（帖子 ID:{$post['thread_id']}）",
            'target_type' => 'post',
            'target_id' => $id,
        ]);
        $this->success('回帖已删除');
    }

    public function postBatch(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        $action = $input['action'] ?? '';

        if (empty($ids) || !is_array($ids)) { $this->error('请选择回帖'); return; }
        $ids = array_map('intval', $ids);
        $count = 0;

        if ($action === 'delete') {
            // 批量查询所有待删除回帖（消除 N+1）
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $posts = Database::fetchAll("SELECT p.id, p.thread_id, p.user_id, t.forum_id FROM posts p LEFT JOIN threads t ON p.thread_id = t.id WHERE p.id IN ({$ph}) AND p.deleted_at IS NULL", $ids);
            if (empty($posts)) {
                $this->error('没有可删除的回帖');
                return;
            }

            // 聚合各维度的计数
            $threadDeltas = [];
            $userDeltas = [];
            $forumDeltas = [];
            $cacheKeys = [];
            foreach ($posts as $p) {
                $tid = (int)$p['thread_id'];
                $uid = (int)$p['user_id'];
                $fid = (int)($p['forum_id'] ?? 0);
                $threadDeltas[$tid] = ($threadDeltas[$tid] ?? 0) + 1;
                $userDeltas[$uid] = ($userDeltas[$uid] ?? 0) + 1;
                if ($fid > 0) $forumDeltas[$fid] = ($forumDeltas[$fid] ?? 0) + 1;
                $cacheKeys["thread:{$tid}"] = true;
                $cacheKeys["user:profile:{$uid}"] = true;
            }

            $now = time();
            Database::beginTransaction();
            try {
                // 批量软删除
                Database::execute("UPDATE posts SET deleted_at = ? WHERE id IN ({$ph}) AND deleted_at IS NULL", array_merge([$now], $ids));
                $count = count($posts);

                // 批量更新 thread reply_count
                foreach ($threadDeltas as $tid => $delta) {
                    Database::execute("UPDATE threads SET reply_count = CASE WHEN reply_count >= ? THEN reply_count - ? ELSE 0 END WHERE id = ?", [$delta, $delta, $tid]);
                }
                // 批量更新 user post_count
                foreach ($userDeltas as $uid => $delta) {
                    Database::execute("UPDATE users SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?", [$delta, $delta, $uid]);
                }
                // 批量更新 forum post_count
                foreach ($forumDeltas as $fid => $delta) {
                    Database::execute("UPDATE forums SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?", [$delta, $delta, $fid]);
                }
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                error_log('[Admin:Post] batch delete failed: ' . $e->getMessage());
                $this->error('批量删除失败，请重试');
                return;
            }

            // 缓存清理放在事务外
            foreach ($cacheKeys as $k => $_) {
                Cache::delete($k);
            }
            Event::dispatch(Events::ADMIN_THREAD_DELETED, [
                'action' => '批量删除回帖',
                'admin_id' => $_SESSION['user_id'],
                'detail' => "批量删除 {$count} 条回帖",
                'target_type' => 'post',
            ]);
            $this->success("已删除 {$count} 条回帖");
        } else {
            $this->error('未知操作');
            return;
        }
    }
}
