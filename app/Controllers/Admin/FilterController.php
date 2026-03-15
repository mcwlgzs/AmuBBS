<?php
/**
 * 后台 - 敏感词管理
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Event;
use App\Events\Events;

class FilterController extends AdminBase
{
    public function sensitiveWords(): void
    {
        $this->requireAdmin();
        $this->render('admin/sensitive_words', ['pageTitle' => '敏感词管理']);
    }

    /**
     * 敏感词列表 API
     */
    public function sensitiveWordsApi(): void
    {
        $this->requireAdmin();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $where = "WHERE 1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND word LIKE ?";
            $params[] = "%" . addcslashes($search, '%_\\') . "%";
        }

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM sensitive_words {$where}", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        $words = Database::fetchAll(
            "SELECT * FROM sensitive_words {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($words, $total);
    }

    public function sensitiveWordCreate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $word = trim($input['word'] ?? '');
        $replacement = trim($input['replacement'] ?? '***');
        $level = (int)($input['level'] ?? 1);

        if ($word === '') {
            $this->error('敏感词不能为空');
            return;
        }

        if ($level < 1 || $level > 2) $level = 1;
        if ($replacement === '') $replacement = '***';

        $exists = Database::fetchOne("SELECT id FROM sensitive_words WHERE word = ?", [$word]);
        if ($exists) {
            $this->error('该敏感词已存在');
            return;
        }

        Database::execute(
            "INSERT INTO sensitive_words (word, replacement, level, created_at) VALUES (?, ?, ?, ?)",
            [$word, $replacement, $level, time()]
        );

        \App\Services\SensitiveWordService::clearCache();
        Event::dispatch(Events::ADMIN_SENSITIVE_WORD_ADDED, [
            'action' => '添加敏感词',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "添加敏感词「{$word}」级别:{$level}",
            'target_type' => 'sensitive_word',
        ]);
        $this->success('添加成功');
    }

    public function sensitiveWordDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        Database::execute("DELETE FROM sensitive_words WHERE id = ?", [$id]);
        \App\Services\SensitiveWordService::clearCache();
        $this->success('删除成功');
    }
}
