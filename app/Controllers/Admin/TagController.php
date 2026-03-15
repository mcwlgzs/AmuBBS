<?php
/**
 * 后台 - 标签分类管理
 */

namespace App\Controllers\Admin;

use Core\Database;

class TagController extends AdminBase
{
    public function tagCategories(): void
    {
        $this->requireAdmin();
        $forums = Database::fetchAll("SELECT id, name FROM forums WHERE deleted_at IS NULL ORDER BY `rank` DESC");
        $this->render('admin/tag_categories', [
            'pageTitle' => '标签分类',
            'forums' => $forums,
        ]);
    }

    /**
     * 标签分类列表 API
     */
    public function tagCategoriesApi(): void
    {
        $this->requireAdmin();

        $categories = [];
        try {
            $categories = Database::fetchAll("
                SELECT tc.*, f.name as forum_name,
                    (SELECT COUNT(*) FROM tags WHERE category_id = tc.id) as tag_count
                FROM tag_categories tc
                LEFT JOIN forums f ON tc.forum_id = f.id
                ORDER BY tc.sort_order ASC, tc.id ASC
            ");
        } catch (\Throwable $e) {
            error_log('[Admin:Tag] categoriesApi query failed: ' . $e->getMessage());
        }

        $this->layuiJson($categories, count($categories));
    }

    public function tagCategoryCreate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $forumId = (int)($input['forum_id'] ?? 0);
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($name === '') {
            $this->error('分类名称不能为空');
            return;
        }

        \App\Services\TagSvc::createCategory($name, $forumId, $sortOrder);
        $this->success('添加成功');
    }

    public function tagCategoryDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        \App\Services\TagSvc::deleteCategory($id);
        $this->success('删除成功');
    }
}
