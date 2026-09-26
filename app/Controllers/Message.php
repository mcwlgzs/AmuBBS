<?php
/**
 * 私信控制器
 */

namespace App\Controllers;

use App\Models\Blacklist;
use App\Models\Message as MessageModel;
use App\Models\Notification;
use App\Models\User;
use App\Services\SensitiveWordService;
use Core\Cache;

class Message extends Base
{
    /**
     * 会话列表
     */
    public function index(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();

        // 获取所有会话（按最新消息排序）
        $conversations = MessageModel::conversations($userId, 50);

        // 获取对方用户信息与未读数（各一条批量 SQL，避免 N+1）
        $otherUserIds = array_unique(array_column($conversations, 'other_user_id'));
        $otherUsers = [];
        foreach (User::getBasicsByIds($otherUserIds) as $u) {
            $otherUsers[(int)$u['id']] = $u;
        }
        $unreadCounts = MessageModel::unreadCountsBySender($userId, $otherUserIds);

        foreach ($conversations as &$conv) {
            $conv['other_user'] = $otherUsers[$conv['other_user_id']] ?? null;
            $conv['unread'] = $unreadCounts[(int)$conv['other_user_id']] ?? 0;
        }
        unset($conv);

        $this->render('message/index', [
            'conversations' => $conversations,
        ]);
    }

    /**
     * 对话详情
     */
    public function conversation(string $otherUserId): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $otherUserId = (int)$otherUserId;

        $otherUser = User::getBasicsById($otherUserId);

        if (!$otherUser) {
            $this->redirect('/messages');
            return;
        }

        // 标记为已读
        MessageModel::markReadFrom($otherUserId, $userId);

        // 获取消息列表
        $messages = MessageModel::between($userId, $otherUserId, 100);

        $now = time();
        foreach ($messages as &$m) {
            $m['can_recall'] = ((int)$m['from_user_id'] === $userId && empty($m['is_recalled']) && ($now - (int)$m['created_at']) <= 120);
        }
        unset($m);

        $this->render('message/conversation', [
            'otherUser' => $otherUser,
            'messages' => $messages,
        ]);
    }

    /**
     * 发送私信
     */
    public function send(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();

        // 频率限制：10秒内只能发一条私信
        $floodKey = "flood:message:{$userId}";
        if (Cache::get($floodKey) !== null) {
            $this->respondFragment(false, '发送过于频繁，请稍后再试', static function (): void {});
            return;
        }

        $toUserId = (int)($_POST['to_user_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($toUserId <= 0) {
            $this->respondFragment(false, '请指定收信人', static function (): void {});
            return;
        }

        if ($toUserId === $userId) {
            $this->respondFragment(false, '不能给自己发私信', static function (): void {});
            return;
        }

        if (empty($content)) {
            $this->respondFragment(false, '请输入消息内容', static function (): void {});
            return;
        }

        if (mb_strlen($content) > 2000) {
            $this->respondFragment(false, '消息内容不能超过 2000 字', static function (): void {});
            return;
        }

        // 验证收信人存在
        if (!User::getBasicsById($toUserId)) {
            $this->respondFragment(false, '用户不存在', static function (): void {});
            return;
        }

        // 黑名单检查：任一方拉黑则禁止发私信
        if (Blacklist::isEitherBlocked($userId, $toUserId)) {
            $this->respondFragment(false, '无法向该用户发送私信', static function (): void {});
            return;
        }

        // 敏感词过滤
        $filter = SensitiveWordService::filter($content);
        if ($filter['blocked']) {
            $this->respondFragment(false, '消息包含违禁词', static function (): void {});
            return;
        }
        $content = $filter['text'];

        // 落库 + 取 id 由模型在一个事务里完成（lastInsertId 必须和 INSERT 同连接）
        $messageId = MessageModel::create($userId, $toUserId, $content);

        // 发送通知
        $username = User::getUsername($userId);
        Notification::notify(
            $toUserId,
            $userId,
            'message',
            ($username !== '' ? $username : '用户') . ' 给你发了一条私信',
            mb_substr($content, 0, 50),
            'message',
            0
        );

        Cache::set($floodKey, time(), 10);
        if (!$this->isHtmx()) {
            $this->success('发送成功');
            return;
        }

        // htmx：直接回新消息那一行，前端 append 到 #messageList（不整页刷新，也不弹提示）
        $this->renderMessageRow($messageId);
    }

    /**
     * 撤回消息（仅限发送者，2分钟内）
     */
    public function recall(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $msgId = (int)($_POST['message_id'] ?? 0);

        if ($msgId <= 0) {
            $this->respondFragment(false, '参数错误', static function (): void {});
            return;
        }

        $msg = MessageModel::findForRecall($msgId);

        if (!$msg) {
            $this->respondFragment(false, '消息不存在', static function (): void {});
            return;
        }

        if ((int)$msg['from_user_id'] !== $userId) {
            $this->respondFragment(false, '只能撤回自己的消息', static function (): void {});
            return;
        }

        if (!empty($msg['is_recalled'])) {
            $this->respondFragment(false, '消息已撤回', static function (): void {});
            return;
        }

        // 2分钟内可撤回
        if (time() - (int)$msg['created_at'] > 120) {
            $this->respondFragment(false, '超过2分钟无法撤回', static function (): void {});
            return;
        }

        MessageModel::recall($msgId);

        if (!$this->isHtmx()) {
            $this->success('已撤回');
            return;
        }

        // htmx：回替换后的那一行（撤回后 is_recalled=1，片段自己会显示「消息已撤回」），
        // 所以不需要再弹提示
        $this->respondFragment(true, '已撤回', function () use ($msgId): void {
            $this->renderMessageRow($msgId);
        }, false);
    }

    /**
     * 渲染单条私信（片段）
     *
     * 发送成功和撤回成功都复用它：一个 append 到消息列表末尾，一个替换原来那一行。
     */
    private function renderMessageRow(int $messageId): void
    {
        $selfId = $this->getCurrentUserId();
        $msg = MessageModel::findRow($messageId);

        if (!$msg) {
            return;
        }

        $msg['can_recall'] = ((int)$msg['from_user_id'] === $selfId
            && empty($msg['is_recalled'])
            && (time() - (int)$msg['created_at']) <= 120);

        $this->render('message/_row', ['msg' => $msg, 'selfId' => $selfId]);
    }
}
