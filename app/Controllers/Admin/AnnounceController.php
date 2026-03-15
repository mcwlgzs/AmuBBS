<?php
/**
 * 后台 - 公告管理
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Cache;

class AnnounceController extends AdminBase
{
    public function announcements(): void
    {
        $this->requireAdmin();
        $this->render('admin/announcements', ['pageTitle' => '公告管理']);
    }

    /**
     * 公告列表 API
     */
    public function announcementsApi(): void
    {
        $this->requireAdmin();
        $announcements = Database::fetchAll("SELECT * FROM announcements ORDER BY `rank` DESC, id DESC");
        $this->layuiJson($announcements, count($announcements));
    }

    public function announcementCreate(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        if ($title === '') { $this->error('公告标题不能为空'); return; }

        $url = trim($input['url'] ?? '');
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
            $this->error('链接格式不正确，请使用 http:// 或 https:// 开头的地址');
            return;
        }

        $now = time();
        Database::execute("INSERT INTO announcements (title, content, url, type, is_enabled, `rank`, start_at, end_at, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)", [
            $title,
            trim($input['content'] ?? ''),
            $url,
            (int)($input['type'] ?? 0),
            (int)($input['rank'] ?? 0),
            !empty($input['start_at']) ? strtotime($input['start_at']) : null,
            !empty($input['end_at']) ? strtotime($input['end_at']) : null,
            $_SESSION['user_id'] ?? 0,
            $now, $now,
        ]);
        Cache::delete('announcements:active');
        $this->success('公告已创建');
    }

    public function announcementUpdate(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        $title = trim($input['title'] ?? '');
        if ($id <= 0 || $title === '') { $this->error('参数错误'); return; }

        $url = trim($input['url'] ?? '');
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
            $this->error('链接格式不正确，请使用 http:// 或 https:// 开头的地址');
            return;
        }

        Database::execute("UPDATE announcements SET title=?, content=?, url=?, type=?, `rank`=?, start_at=?, end_at=?, updated_at=? WHERE id=?", [
            $title,
            trim($input['content'] ?? ''),
            $url,
            (int)($input['type'] ?? 0),
            (int)($input['rank'] ?? 0),
            !empty($input['start_at']) ? strtotime($input['start_at']) : null,
            !empty($input['end_at']) ? strtotime($input['end_at']) : null,
            time(), $id,
        ]);
        Cache::delete('announcements:active');
        $this->success('公告已更新');
    }

    public function announcementToggle(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        $enabled = (int)($input['is_enabled'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }
        Database::execute("UPDATE announcements SET is_enabled=?, updated_at=? WHERE id=?", [$enabled, time(), $id]);
        Cache::delete('announcements:active');
        $this->success($enabled ? '已启用' : '已禁用');
    }

    public function announcementDelete(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }
        Database::execute("DELETE FROM announcements WHERE id=?", [$id]);
        Cache::delete('announcements:active');
        $this->success('公告已删除');
    }
}
