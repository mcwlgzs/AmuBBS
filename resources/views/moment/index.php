<?php $pageTitle = '动态'; $pageCss = ['index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>动态</span>
</div>

<div class="home-layout">
    <div class="home-main">
        <!-- 发布动态 -->
        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="card" x-data="momentForm()">
            <div class="section-title">发布动态</div>
            <form @submit.prevent="submit">
                <textarea x-ref="input" x-model="content" class="form-input" rows="3" placeholder="分享你的想法..." maxlength="1000" style="resize:vertical;"></textarea>
                <div x-show="images.length > 0" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                    <template x-for="(img, i) in images" :key="i">
                        <div style="position:relative;width:80px;height:80px;">
                            <img :src="img" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius);">
                            <button type="button" @click="images.splice(i, 1)" style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:var(--danger);color:#fff;border:none;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>
                        </div>
                    </template>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;">
                    <div style="display:flex;gap:8px;">
                        <label class="btn btn-ghost btn-sm" style="cursor:pointer;gap:4px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            图片
                            <input type="file" accept="image/*" multiple style="display:none;" @change="uploadImages($event)">
                        </label>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:12px;color:var(--text-muted);" x-text="content.length + '/1000'"></span>
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="loading || content.length < 1">
                            <span x-show="!loading">发布</span>
                            <span x-show="loading">发布中...</span>
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
            <div class="card moment-item" x-data="{ liked: <?= !empty($moment['is_liked']) ? 'true' : 'false' ?>, likes: <?= (int)($moment['likes'] ?? 0) ?>, showComments: false, commentText: '', commentLoading: false, replyUserId: 0, replyName: '' }">
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
                    <button class="btn btn-ghost btn-sm" style="margin-left:auto;font-size:12px;color:var(--text-muted);" @click="
                        App.confirmPost('/moments/delete', {moment_id:'<?= (int)$moment['id'] ?>'}, '确定删除？').then(d => { if(d && d.success) location.reload(); })
                    ">删除</button>
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

                <div class="moment-actions">
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="moment-action-btn" :class="{ 'is-liked': liked }" @click="
                        App.post('/moments/like', {moment_id:'<?= (int)$moment['id'] ?>'}, {silent:true}).then(d=>{ if(d.success){ liked=d.liked; likes=d.likes; } })
                    ">
                        <svg viewBox="0 0 24 24" :fill="liked ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <span x-text="likes || ''"></span>
                    </button>
                    <button class="moment-action-btn" @click="showComments = !showComments; if(showComments){ replyUserId=0; replyName=''; }">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <span><?= (int)($moment['comment_count'] ?? 0) ?: '' ?></span>
                    </button>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--text-muted);">
                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;vertical-align:middle;opacity:.4;"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <?= (int)($moment['likes'] ?? 0) ?: '' ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- 评论区 -->
                <?php if (!empty($moment['comments'])): ?>
                <div class="moment-comments">
                    <?php foreach ($moment['comments'] as $c): ?>
                    <div class="moment-comment">
                        <a href="javascript:;" class="moment-comment-author"<?= \App\Services\UserSvc::nicknameStyle($c) ?> <?php if (isset($_SESSION['user_id'])): ?>@click="replyUserId=<?= (int)$c['user_id'] ?>; replyName='<?= htmlspecialchars(($c['nickname'] ?? '') ?: $c['username'], ENT_QUOTES) ?>'; showComments=true; $nextTick(()=>$refs.commentInput_<?= (int)$moment['id'] ?>?.focus())"<?php endif; ?>><?= htmlspecialchars(($c['nickname'] ?? '') ?: $c['username']) ?></a>
                        <?php if (!empty($c['reply_username'])): ?>
                        <span style="color:var(--text-muted);">回复</span>
                        <a href="/user/<?= (int)$c['reply_user_id'] ?>" class="moment-comment-author"<?= \App\Services\UserSvc::nicknameStyle(['nickname_color' => $c['reply_nickname_color'] ?? null]) ?>><?= htmlspecialchars(($c['reply_nickname'] ?? '') ?: $c['reply_username']) ?></a>
                        <?php endif; ?>
                        <span class="moment-comment-text"><?= htmlspecialchars($c['content']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- 评论输入 -->
                <?php if (isset($_SESSION['user_id'])): ?>
                <div x-show="showComments" x-cloak class="moment-comment-form">
                    <div x-show="replyUserId > 0" style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">
                        回复 <span x-text="replyName"></span>
                        <a href="javascript:;" @click="replyUserId=0;replyName=''" style="margin-left:6px;color:var(--primary);">取消</a>
                    </div>
                    <input type="text" x-model="commentText" x-ref="commentInput_<?= (int)$moment['id'] ?>" class="form-input" :placeholder="replyUserId > 0 ? '回复 ' + replyName + '...' : '写评论...'" maxlength="500" @keydown.enter="
                        if(commentText.trim().length < 1) return;
                        commentLoading = true;
                        App.post('/moments/comment', {moment_id:'<?= (int)$moment['id'] ?>',content:commentText,reply_user_id:replyUserId}, {silent:true})
                        .then(d=>{ if(d.success){ location.reload(); } else { toast(d.message,'error'); } })
                        .finally(()=>commentLoading=false)
                    ">
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

<script>
function momentForm() {
    return {
        content: '', images: [], loading: false,
        async uploadImages(e) {
            const files = e.target.files;
            if (!files.length) return;
            for (let f of files) {
                if (this.images.length >= 9) break;
                const fd = new FormData(); fd.append('image', f);
                const data = await App.upload('/thread/upload-image', fd, { silent: true });
                if (data.success) this.images.push(data.data.url);
                else toast(data.message || '上传失败', 'error');
            }
            e.target.value = '';
        },
        async submit() {
            if (this.content.length < 1) return;
            this.loading = true;
            const data = await App.post('/moments/create', { content: this.content, images: this.images.join(',') }, { silent: true });
            if (data.success) { toast('发布成功', 'success'); setTimeout(() => location.reload(), 600); }
            else toast(data.message || '发布失败', 'error');
            this.loading = false;
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
