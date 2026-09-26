<?php
/**
 * 后台 - 标签分类管理
 */

namespace App\Controllers\Admin;

use App\Models\Forum;
use App\Models\TagCategory;

class TagController extends AdminBase
{
    public function tagCategories(): void
    {
        $this->requireAdmin();
        $this->renderTagCategoriesPage();
    }

    /**
     * 标签分类数据（页面与 JSON API 共用同一份查询逻辑）
     */
    private function fetchTagCategories(): array
    {
        try {
            return TagCategory::adminList();
        } catch (\Throwable $e) {
            error_log('[Admin:Tag] fetchTagCategories failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 渲染标签分类页面片段（GET 与增删后的刷新共用同一个渲染路径）
     */
    private function renderTagCategoriesPage(): void
    {
        $this->renderAdmin('admin/tag_categories', [
            'pageTitle' => '标签分类',
            'forums'    => Forum::getOptions(),
            'rows'      => $this->fetchTagCategories(),
        ], 'tag-categories');
    }

    /**
     * 标签分类列表 API（保留，供外部 AJAX 调用）
     */
    public function tagCategoriesApi(): void
    {
        $this->requireAdmin();

        $rows = $this->fetchTagCategories();
        $this->jsonTable($rows, count($rows));
    }

    public function tagCategoryCreate(): void
    {
        $this->requireAdmin();

        $input     = $this->input();
        $name      = trim($input['name'] ?? '');
        $forumId   = (int)($input['forum_id'] ?? 0);
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($name === '') {
            $this->respondMutation(false, '分类名称不能为空', fn() => $this->renderTagCategoriesPage());
            return;
        }

        TagCategory::createCategory($name, $forumId, $sortOrder);
        $this->respondMutation(true, "已添加分类「{$name}」", fn() => $this->renderTagCategoriesPage());
    }

    public function tagCategoryDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderTagCategoriesPage());
            return;
        }

        TagCategory::deleteCategory($id);
        $this->respondMutation(true, '已删除分类', fn() => $this->renderTagCategoriesPage());
    }
}
