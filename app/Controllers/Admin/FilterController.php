<?php
/**
 * 后台 - 敏感词管理
 */

namespace App\Controllers\Admin;

use App\Models\SensitiveWord;
use App\Services\SensitiveWordService;
use Core\Event;
use App\Events\Events;

class FilterController extends AdminBase
{
    public function sensitiveWords(): void
    {
        $this->requireAdmin();
        $this->renderSensitiveWordsPage();
    }

    /**
     * 敏感词数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchSensitiveWords(int $page, int $limit, string $search): array
    {
        return SensitiveWord::adminList($search, $page, $limit);
    }

    /**
     * 渲染敏感词页面片段（GET 与增删后的刷新共用同一个渲染路径）
     */
    private function renderSensitiveWordsPage(): void
    {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchSensitiveWords($page, $limit, $search);

        $this->renderAdmin('admin/sensitive_words', [
            'pageTitle' => '敏感词管理',
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => max(1, (int)ceil($result['total'] / $limit)),
            'search'    => $search,
        ], 'sensitive-words');
    }

    /**
     * 敏感词列表 API（保留，供外部 AJAX 调用）
     */
    public function sensitiveWordsApi(): void
    {
        $this->requireAdmin();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchSensitiveWords($page, $limit, $search);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function sensitiveWordCreate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $word = trim($input['word'] ?? '');
        $replacement = trim($input['replacement'] ?? '***');
        $level = (int)($input['level'] ?? 1);

        if ($word === '') {
            $this->respondMutation(false, '敏感词不能为空', fn() => $this->renderSensitiveWordsPage());
            return;
        }

        if ($level < 1 || $level > 2) $level = 1;
        if ($replacement === '') $replacement = '***';

        if (SensitiveWord::existsByWord($word)) {
            $this->respondMutation(false, '该敏感词已存在', fn() => $this->renderSensitiveWordsPage());
            return;
        }

        SensitiveWord::create($word, $replacement, $level);

        SensitiveWordService::clearCache();
        Event::dispatch(Events::ADMIN_SENSITIVE_WORD_ADDED, [
            'action' => '添加敏感词',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "添加敏感词「{$word}」级别:{$level}",
            'target_type' => 'sensitive_word',
        ]);

        $this->respondMutation(true, "已添加「{$word}」", fn() => $this->renderSensitiveWordsPage());
    }

    public function sensitiveWordDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderSensitiveWordsPage());
            return;
        }

        SensitiveWord::remove($id);
        SensitiveWordService::clearCache();
        $this->respondMutation(true, '已删除敏感词', fn() => $this->renderSensitiveWordsPage());
    }
}
