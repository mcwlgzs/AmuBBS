<?php
/**
 * 后台 - 等级设置
 */

namespace App\Controllers\Admin;

use App\Models\UserLevel;
use App\Services\LevelSvc;

class LevelController extends AdminBase
{
    public function levels(): void
    {
        $this->requireAdmin();
        $this->renderLevelsPage();
    }

    /**
     * 等级数据（页面与 JSON API 共用同一份查询逻辑）
     */
    private function fetchLevels(): array
    {
        return UserLevel::all();
    }

    /**
     * 渲染等级设置页面片段（GET 与增删改后的刷新共用同一个渲染路径）
     */
    private function renderLevelsPage(): void
    {
        $this->renderAdmin('admin/levels', [
            'pageTitle' => '等级设置',
            'rows'      => $this->fetchLevels(),
        ], 'levels');
    }

    /**
     * 等级列表 API（保留，供外部 AJAX 调用）
     */
    public function levelsApi(): void
    {
        $this->requireAdmin();

        $levels = $this->fetchLevels();
        $this->jsonTable($levels, count($levels));
    }

    /**
     * 等级表单片段（layer iframe 弹层用）
     *
     * ?id=0 或省略 → 新增；?id=N → 编辑
     */
    public function levelForm(): void
    {
        $this->requireAdmin();

        $id = max(0, (int)($_GET['id'] ?? 0));
        $level = null;

        if ($id > 0) {
            $level = UserLevel::findFresh($id);
            if (!$level) {
                http_response_code(404);
                $this->renderAdmin('admin/partials/error', ['pageTitle' => '等级不存在', 'message' => '等级不存在']);
                return;
            }
        }

        $this->renderAdmin('admin/partials/level_form', [
            'pageTitle' => $level !== null ? '编辑等级' : '添加等级',
            'level'     => $level,
            'isEdit'    => $level !== null,
        ], 'levels');
    }

    public function levelSave(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);
        $level = (int)($input['level'] ?? 0);
        $name = trim($input['name'] ?? '');
        $minCredits = (int)($input['min_credits'] ?? 0);
        $color = trim($input['color'] ?? '#999999');
        $icon = trim($input['icon'] ?? '');

        if ($name === '') {
            $this->respondMutation(false, '等级名称不能为空', fn() => $this->renderLevelsPage());
            return;
        }

        // 颜色格式校验（支持 #rgb / #rrggbb / var(--x)）
        if ($color !== '' && !preg_match('/^(#[0-9a-fA-F]{3,6}|var\(--[a-zA-Z0-9_-]+\))$/', $color)) {
            $this->respondMutation(false, '颜色格式不正确', fn() => $this->renderLevelsPage());
            return;
        }

        // 图标：允许 emoji 或简短文本（项目自带惯例就是 emoji，如 VipSvc 的 🥈🥇💎👑）
        // 长度限制即可，输出时一律 htmlspecialchars，不存在注入风险
        if ($icon !== '' && mb_strlen($icon) > 8) {
            $this->respondMutation(false, '图标最多 8 个字符', fn() => $this->renderLevelsPage());
            return;
        }

        if ($level < 0 || $level > 99) {
            $this->respondMutation(false, '等级值必须在 0-99 之间', fn() => $this->renderLevelsPage());
            return;
        }
        if ($minCredits < 0) {
            $this->respondMutation(false, '最低积分不能为负数', fn() => $this->renderLevelsPage());
            return;
        }

        // 等级唯一性校验
        $dup = UserLevel::levelTakenByOther($level, $id);
        if ($dup) {
            $this->respondMutation(false, '该等级编号已被占用', fn() => $this->renderLevelsPage());
            return;
        }

        UserLevel::save($id, $level, $name, $minCredits, $color, $icon);

        LevelSvc::clearCache();

        $this->respondMutation(
            true,
            $id > 0 ? "已更新等级「{$name}」" : "已创建等级「{$name}」",
            fn() => $this->renderLevelsPage()
        );
    }

    public function levelDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderLevelsPage());
            return;
        }

        UserLevel::remove($id);
        LevelSvc::clearCache();

        $this->respondMutation(true, '等级已删除', fn() => $this->renderLevelsPage());
    }
}
