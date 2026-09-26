<?php
/**
 * 动态控制器
 *
 * 分层：数据访问在 App\Models\Moment / MomentComment / MomentLike；
 * 频率限制、内容校验、敏感词、黑名单、通知、积分奖励、权限判断这些
 * 「一次请求的编排」集中在这里（目标架构就是 Controller + Model 两层）。
 *
 * 说明：原 App\Services\MomentSvc 已删除；图片地址校验原本在控制器和 Service 里
 * 各写了一遍，现在只保留这里的这一份。
 */

namespace App\Controllers;

use App\Models\Moment as MomentModel;
use App\Models\MomentComment;
use App\Models\MomentLike;

class Moment extends Base
{
    /** 发布动态的频率限制（秒） */
    private const FLOOD_MOMENT = 30;

    /** 发表评论的频率限制（秒） */
    private const FLOOD_COMMENT = 10;

    /** 站内图片地址白名单格式 */
    private const IMAGE_PATH_PATTERN = '#^/uploads/images/\d{4}/\d{2}/img_[a-f0-9]+\.(jpg|jpeg|png|gif|webp)$#i';

    /**
     * 动态列表页
     */
    public function index(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = MomentModel::getList($page, 20);

        // 批量标注当前用户的点赞状态（一次查询，不逐条查）
        $userId = $this->getCurrentUserId();
        if ($userId && !empty($result['moments'])) {
            $liked = MomentLike::likedMomentIds(array_column($result['moments'], 'id'), $userId);
            $likedMap = array_flip($liked);
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

        $userId = $this->getCurrentUserId();
        $content = trim($_POST['content'] ?? '');
        $images = $_POST['images'] ?? [];

        if (is_string($images)) {
            $images = array_filter(explode(',', $images));
        }

        try {
            // 频率限制：30 秒内只能发一条
            $floodKey = "flood:moment:{$userId}";
            if (\Core\Cache::get($floodKey) !== null) {
                throw new \RuntimeException('发布过于频繁，请稍后再试');
            }

            if (mb_strlen($content) < 1) {
                throw new \RuntimeException('内容不能为空');
            }
            if (mb_strlen($content) > 1000) {
                throw new \RuntimeException('内容不能超过1000字');
            }

            // 敏感词过滤
            $filter = \App\Services\SensitiveWordService::filter($content);
            if ($filter['blocked']) {
                throw new \RuntimeException('内容包含违禁词');
            }
            $content = $filter['text'];

            // 图片只允许站内上传路径，最多 9 张
            $validImages = [];
            foreach (array_slice(is_array($images) ? $images : [], 0, 9) as $img) {
                if (is_string($img) && preg_match(self::IMAGE_PATH_PATTERN, $img)) {
                    $validImages[] = $img;
                }
            }
            $imagesJson = !empty($validImages) ? json_encode($validImages) : null;

            $momentId = MomentModel::create($userId, $content, $imagesJson);

            // 积分奖励（失败不影响发布）
            $creditSvc = new \App\Services\CreditSvc();
            $creditSvc->addCredits($userId, 2, 'moment', '发布动态', 'moment', $momentId);

            \Core\Cache::set($floodKey, time(), self::FLOOD_MOMENT);

            // 发布后列表、侧栏统计都要变，直接让前端整页刷新
            $this->respondRefresh(true, '发布成功');
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
        }
    }

    /**
     * 点赞 / 取消点赞
     */
    public function like(): void
    {
        $this->requireLogin();

        $userId = $this->getCurrentUserId();
        $momentId = (int)($_POST['moment_id'] ?? 0);

        try {
            $ownerId = MomentModel::ownerId($momentId);
            if ($ownerId === null) {
                throw new \RuntimeException('动态不存在或已删除');
            }

            $liked = MomentLike::toggle($momentId, $userId);

            // 通知放事务外，且失败不影响点赞
            if ($liked && $ownerId !== $userId) {
                $me = \App\Models\User::findById($userId);
                \App\Models\Notification::notify(
                    $ownerId,
                    $userId,
                    'like',
                    ($me['username'] ?? '用户') . ' 赞了你的动态',
                    '',
                    'moment',
                    $momentId
                );
            }

            $likes = MomentModel::likeCount($momentId);

            if (!$this->isHtmx()) {
                $this->json([
                    'success' => true,
                    'liked' => $liked,
                    'likes' => $likes,
                ]);
                return;
            }

            // 按钮状态本身就是反馈，不用再弹提示
            $this->respondFragment(true, '', function () use ($momentId, $liked, $likes): void {
                $this->renderActions($momentId, $liked, $likes);
            }, false);
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
        }
    }

    /**
     * 发表评论
     */
    public function comment(): void
    {
        $this->requireLogin();

        $userId = $this->getCurrentUserId();
        $momentId = (int)($_POST['moment_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $replyUserId = (int)($_POST['reply_user_id'] ?? 0);

        try {
            // 频率限制：10 秒内只能发一条
            $floodKey = "flood:moment_comment:{$userId}";
            if (\Core\Cache::get($floodKey) !== null) {
                throw new \RuntimeException('评论过于频繁，请稍后再试');
            }

            if (mb_strlen($content) < 1 || mb_strlen($content) > 500) {
                throw new \RuntimeException('评论内容1-500字');
            }

            $filter = \App\Services\SensitiveWordService::filter($content);
            if ($filter['blocked']) {
                throw new \RuntimeException('评论包含违禁词');
            }
            $content = $filter['text'];

            $ownerId = MomentModel::ownerId($momentId);
            if ($ownerId === null) {
                throw new \RuntimeException('动态不存在');
            }

            // 动态作者拉黑了你则禁止评论
            if (\App\Models\Blacklist::isBlocked($ownerId, $userId)) {
                throw new \RuntimeException('无法评论该动态');
            }

            // 回复目标不存在时按「评论动态」处理
            if ($replyUserId > 0 && !\App\Models\User::exists($replyUserId)) {
                $replyUserId = 0;
            }

            // 所属顶层评论：只允许一层嵌套——回复子评论会归一到同一个顶层评论，
            // 非法/不存在的 parent_id 一律退回 0（按顶层评论处理）
            $parentId = MomentComment::normalizeParentId($momentId, (int)($_POST['parent_id'] ?? 0));

            $commentId = MomentComment::create($momentId, $userId, $replyUserId, $content, $parentId);

            // 通知放事务外
            $notifyUserId = $replyUserId ?: $ownerId;
            if ($notifyUserId !== $userId) {
                $me = \App\Models\User::findById($userId);
                \App\Models\Notification::notify(
                    $notifyUserId,
                    $userId,
                    'moment_comment',
                    ($me['username'] ?? '用户') . ($parentId > 0 && $replyUserId ? ' 回复了你的评论' : ' 评论了你的动态'),
                    mb_substr($content, 0, 50),
                    'moment',
                    $momentId
                );
            }

            \Core\Cache::set($floodKey, time(), self::FLOOD_COMMENT);

            if (!$this->isHtmx()) {
                $this->success('评论成功', ['comment_id' => $commentId, 'parent_id' => $parentId]);
                return;
            }

            // 只回新评论那一行，前端按层级决定是 beforeend 到线程回复区还是评论区（新评论可见本身就是反馈，不弹提示）
            $comment = MomentComment::getById($commentId);
            if ($comment) {
                if ($parentId > 0) {
                    // 回复：追加到所属顶层评论的 .moment-replies 里
                    $this->render('moment/_reply', [
                        'r' => $comment,
                        'momentId' => $momentId,
                        'parentId' => $parentId,
                    ]);
                    return;
                }

                $comment['replies'] = [];
                $comment['replies_total'] = 0;
                $comment['replies_truncated'] = false;
                $this->render('moment/_comment', ['c' => $comment, 'momentId' => $momentId]);
            }
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
        }
    }

    /**
     * 展开某条动态的全部评论（「查看全部 N 条评论」）
     *
     * 回的是评论容器内容片段（moment/_comments），htmx 用 innerHTML 替换整个
     * #momentComments-{id}，于是顶层评论与子回复的分组渲染和整页完全一致。
     */
    public function comments(): void
    {
        $momentId = (int)($_GET['moment_id'] ?? 0);

        $ownerId = MomentModel::ownerId($momentId);
        if ($ownerId === null) {
            $this->error('动态不存在或已删除', 404);
            return;
        }

        $threads = MomentComment::getThreadsByMoment($momentId);

        $this->render('moment/_comments', [
            'comments' => $threads['comments'],
            'momentId' => $momentId,
            'commentTotal' => $threads['total'],
            'commentsHasMore' => false,
            'expanded' => true,
        ]);
    }

    /**
     * 删除动态（作者本人或管理员/版主）
     */
    public function delete(): void
    {
        $this->requireLogin();

        $userId = $this->getCurrentUserId();
        $momentId = (int)($_POST['moment_id'] ?? 0);

        try {
            $ownerId = MomentModel::ownerId($momentId);
            if ($ownerId === null) {
                throw new \RuntimeException('动态不存在');
            }
            if ($ownerId !== $userId && !\App\Services\PermissionSvc::isAdminOrMod($userId)) {
                throw new \RuntimeException('没有权限');
            }

            MomentModel::softDelete($momentId);

            $this->respondRefresh(true, '已删除');
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
        }
    }

    /**
     * 渲染某条动态的点赞/评论按钮片段
     *
     * $liked / $likes 可以由调用方直接给出（刚点完赞），也可以从库里查（评论数等）。
     */
    private function renderActions(int $momentId, ?bool $liked = null, ?int $likes = null): void
    {
        $moment = MomentModel::findById($momentId);
        if (!$moment) {
            return;
        }

        $moment['likes'] = $likes ?? (int)($moment['likes'] ?? 0);
        $moment['is_liked'] = $liked ?? MomentLike::isLiked($momentId, $this->getCurrentUserId());

        $this->render('moment/_actions', ['moment' => $moment]);
    }
}
