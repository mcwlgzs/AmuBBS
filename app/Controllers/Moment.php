<?php
/**
 * 动态控制器
 */

namespace App\Controllers;

class Moment extends Base
{
    /**
     * 动态列表页
     */
    public function index(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = \App\Services\MomentSvc::getList($page, 20);

        // 批量检查当前用户点赞状态
        $userId = $this->getCurrentUserId();
        if ($userId && !empty($result['moments'])) {
            $momentIds = array_column($result['moments'], 'id');
            $placeholders = implode(',', array_fill(0, count($momentIds), '?'));
            $likedRows = \Core\Database::fetchAll(
                "SELECT moment_id FROM moment_likes WHERE moment_id IN ({$placeholders}) AND user_id = ?",
                array_merge($momentIds, [$userId])
            );
            $likedMap = array_flip(array_column($likedRows, 'moment_id'));
            foreach ($result['moments'] as &$m) {
                $m['is_liked'] = isset($likedMap[$m['id']]);
            }
            unset($m);
        }

        $this->render('moment/index', [
            'moments' => $result['moments'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * 发布动态
     */
    public function create(): void
    {
        $this->requireLogin();
        $content = trim($_POST['content'] ?? '');
        $images = $_POST['images'] ?? [];

        if (is_string($images)) {
            $images = array_filter(explode(',', $images));
        }

        // 验证图片 URL 为站内上传路径（与 MomentSvc 保持一致）
        $images = array_filter($images, function($url) {
            return preg_match('#^/uploads/images/\d{4}/\d{2}/img_[a-f0-9]+\.(jpg|jpeg|png|gif|webp)$#i', $url);
        });
        $images = array_slice(array_values($images), 0, 9);

        try {
            $momentId = \App\Services\MomentSvc::create($this->getCurrentUserId(), $content, $images);
            $this->success('发布成功', ['moment_id' => $momentId]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 点赞
     */
    public function like(): void
    {
        $this->requireLogin();
        $momentId = (int)($_POST['moment_id'] ?? 0);
        try {
            $result = \App\Services\MomentSvc::toggleLike($momentId, $this->getCurrentUserId());
            $this->json(['success' => true, 'liked' => $result['liked'], 'likes' => $result['likes']]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 评论
     */
    public function comment(): void
    {
        $this->requireLogin();
        $momentId = (int)($_POST['moment_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $replyUserId = (int)($_POST['reply_user_id'] ?? 0);

        try {
            $commentId = \App\Services\MomentSvc::comment($momentId, $this->getCurrentUserId(), $content, $replyUserId);
            $this->success('评论成功', ['comment_id' => $commentId]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 删除动态
     */
    public function delete(): void
    {
        $this->requireLogin();
        $momentId = (int)($_POST['moment_id'] ?? 0);
        try {
            \App\Services\MomentSvc::delete($momentId, $this->getCurrentUserId());
            $this->success('已删除');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }
}
