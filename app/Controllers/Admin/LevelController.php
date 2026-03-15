<?php
/**
 * 后台 - 等级设置
 */

namespace App\Controllers\Admin;

use Core\Database;

class LevelController extends AdminBase
{
    public function levels(): void
    {
        $this->requireAdmin();
        $this->render('admin/levels', ['pageTitle' => '等级设置']);
    }

    /**
     * 等级列表 API
     */
    public function levelsApi(): void
    {
        $this->requireAdmin();
        $levels = Database::fetchAll("SELECT * FROM user_levels ORDER BY level ASC");
        $this->layuiJson($levels, count($levels));
    }

    public function levelSave(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        $level = (int)($input['level'] ?? 0);
        $name = trim($input['name'] ?? '');
        $minCredits = (int)($input['min_credits'] ?? 0);
        $color = trim($input['color'] ?? '#999999');
        $icon = trim($input['icon'] ?? '');

        if ($name === '') {
            $this->error('等级名称不能为空');
            return;
        }

        // 颜色格式校验
        if ($color !== '' && !preg_match('/^(#[0-9a-fA-F]{3,6}|var\(--[a-zA-Z0-9_-]+\))$/', $color)) {
            $this->error('颜色格式不正确');
            return;
        }

        // 图标格式校验：只允许 CSS class 名或空
        if ($icon !== '' && !preg_match('/^[a-zA-Z0-9_ -]+$/', $icon)) {
            $this->error('图标格式不正确');
            return;
        }

        if ($level < 0 || $level > 99) {
            $this->error('等级值必须在 0-99 之间');
            return;
        }
        if ($minCredits < 0) {
            $this->error('最低积分不能为负数');
            return;
        }

        // 等级唯一性校验
        $dup = Database::fetchOne("SELECT id FROM user_levels WHERE level = ? AND id != ?", [$level, $id]);
        if ($dup) {
            $this->error('该等级编号已被占用');
            return;
        }

        if ($id > 0) {
            Database::execute("UPDATE user_levels SET level = ?, name = ?, min_credits = ?, color = ?, icon = ? WHERE id = ?",
                [$level, $name, $minCredits, $color, $icon, $id]);
        } else {
            Database::execute("INSERT INTO user_levels (level, name, min_credits, color, icon) VALUES (?, ?, ?, ?, ?)",
                [$level, $name, $minCredits, $color, $icon]);
        }

        \App\Services\LevelSvc::clearCache();
        $this->success($id > 0 ? '等级已更新' : '等级已创建');
    }

    public function levelDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        Database::execute("DELETE FROM user_levels WHERE id = ?", [$id]);
        \App\Services\LevelSvc::clearCache();
        $this->success('等级已删除');
    }
}
