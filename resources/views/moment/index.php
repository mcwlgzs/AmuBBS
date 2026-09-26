<?php $pageTitle = '动态'; $pageCss = ['index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>动态</span>
</div>

<div class="home-layout">
    <div class="home-main">
        <!-- 发布动态 -->
        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="card">
            <div class="section-title">发布动态</div>
            <?php /* 发布成功由服务端回 HX-Refresh 整页刷新（和迁移前的 location.reload 等价） */ ?>
            <form hx-post="/moments/create" hx-swap="none" hx-indicator="this" hx-disabled-elt="#momentSubmit">
                <textarea name="content" id="momentContent" class="form-input" rows="3"
                          placeholder="分享你的想法..." maxlength="1000" style="resize:vertical;" required></textarea>

                <?php /* 上传成功时服务端只回一个缩略图（内含隐藏的 images[] 字段），append 到这里 */ ?>
                <div class="moment-thumbs" id="momentThumbs"></div>

                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;">
                    <div style="display:flex;gap:8px;">
                        <label class="btn btn-ghost btn-sm" style="cursor:pointer;gap:4px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            图片
                            <input type="file" name="image[]" accept="image/*" multiple style="display:none;"
                                   hx-post="/thread/upload-image" hx-encoding="multipart/form-data" hx-trigger="change"
                                   hx-target="#momentThumbs" hx-swap="beforeend">
                        </label>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:12px;color:var(--text-muted);"
                              data-count-for="#momentContent" data-count-max="1000">0/1000</span>
                        <button type="submit" id="momentSubmit" class="btn btn-primary btn-sm">
                            <span class="hx-idle">发布</span>
                            <span class="hx-busy">发布中...</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- 动态列表 -->
        <?php if (empty($moments)): ?>
        <div class="card">
            <?php $emptyIcon = 'post'; $emptyText = '暂无动态，快来发布第一条吧'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
        </div>
        <?php else: ?>
            <?php foreach ($moments as $moment): ?>
            <?php $momentId = (int)$moment['id']; ?>
            <div class="card moment-item" id="moment-<?= $momentId ?>">
                <div class="moment-header">
                    <a href="/user/<?= (int)$moment['user_id'] ?>">
                        <img src="<?= htmlspecialchars($moment['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm" loading="lazy">
                    </a>
                    <div class="moment-author">
                        <a href="/user/<?= (int)$moment['user_id'] ?>" class="moment-author-name"<?= \App\Services\UserSvc::nicknameStyle($moment) ?>>
                            <?= htmlspecialchars(($moment['nickname'] ?? '') ?: $moment['username']) ?>
                            <?= \App\Services\LevelSvc::getLevelBadge($moment['credits'] ?? 0) ?>
                        </a>
                        <span class="moment-time timeago" datetime="<?= date('c', $moment['created_at']) ?>"><?= date('Y-m-d H:i', $moment['created_at']) ?></span>
                    </div>
                    <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_id'] == $moment['user_id'] || ($_SESSION['group_id'] ?? 1) >= 2)): ?>
                    <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;font-size:12px;color:var(--text-muted);"
                            hx-post="/moments/delete" hx-vals='{"moment_id":<?= $momentId ?>}'
                            hx-swap="none" hx-confirm="确定删除？">删除</button>
                    <?php endif; ?>
                </div>

                <div class="moment-content"><?= nl2br(htmlspecialchars($moment['content'])) ?></div>

                <?php if (!empty($moment['images'])): ?>
                <div class="moment-images moment-images-<?= min(count($moment['images']), 3) ?>">
                    <?php foreach ($moment['images'] as $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="" class="moment-img" loading="lazy">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php include APP_PATH . 'resources/views/moment/_actions.php'; ?>

                <?php
                /* 评论区：顶层评论 + 其回复（只有一层嵌套）。
                   整块交给 _comments.php 渲染——「查看全部 N 条评论」的 htmx 片段与整页共用同一份模板。
                   没有评论时加 is-empty（CSS 隐藏），这样 htmx beforeend 追加第一条评论后能自动显形。 */
                $comments        = $moment['comments'] ?? [];
                $commentTotal    = (int)($moment['comment_total'] ?? $moment['comment_count'] ?? 0);
                $commentsHasMore = !empty($moment['comments_has_more']);
                $expanded        = false;
                ?>
                <div class="moment-comments<?= $comments ? '' : ' is-empty' ?>" id="momentComments-<?= $momentId ?>"><?php include APP_PATH . 'resources/views/moment/_comments.php'; ?></div>

                <?php if (isset($_SESSION['user_id'])): ?>
                <?php /* 顶层评论框：默认隐藏，点「评论」按钮由 [data-toggle-target] 显示；回车即提交 */ ?>
                <div class="moment-comment-form" id="momentCommentForm-<?= $momentId ?>" hidden>
                    <div data-reply-indicator hidden style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">
                        回复 <span data-reply-name></span>
                        <a href="javascript:;" data-reply-cancel style="margin-left:6px;color:var(--primary);">取消</a>
                    </div>
                    <form hx-post="/moments/comment" hx-target="#momentComments-<?= $momentId ?>" hx-swap="beforeend"
                          hx-indicator="this" data-reply-form-target>
                        <input type="hidden" name="moment_id" value="<?= $momentId ?>">
                        <input type="hidden" name="reply_user_id" value="" data-reply-input>
                        <input type="text" name="content" class="form-input" placeholder="写评论..." maxlength="500" required>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
            <?php
                $paginationUrl = '/moments?page={page}';
                $paginationPage = $page;
                $paginationTotal = $totalPages;
                include APP_PATH . 'resources/views/layout/pagination.php';
            ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="home-sidebar">
        <?php include APP_PATH . 'resources/views/components/sidebar-checkin-rank.php'; ?>
        <?php include APP_PATH . 'resources/views/components/sidebar-active-users.php'; ?>
        <?php include APP_PATH . 'resources/views/components/sidebar-credit-rank.php'; ?>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
