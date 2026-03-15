<?php $pageTitle = '个人中心'; $pageCss = ['auth', 'index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>个人中心</span>
</div>

<!-- 用户信息卡片 -->
<div class="card profile-card">
    <div class="profile-header">
        <div class="profile-avatar-wrap">
            <img src="<?= htmlspecialchars($user['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="头像" class="avatar-lg">
        </div>
        <div class="profile-info">
            <div class="profile-name-row">
                <h2<?= \App\Services\UserSvc::nicknameStyle($user) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($user)) ?></h2>
                <span class="profile-uid" style="font-size:12px;color:var(--text-muted);margin-left:4px;">UID: <?= (int)$user['id'] ?></span>
                <?php if (!empty($user['nickname'])): ?>
                <span class="profile-username">@<?= htmlspecialchars($user['username']) ?></span>
                <?php endif; ?>
                <span class="profile-group"><?= htmlspecialchars($user['group_name'] ?? '普通用户') ?></span>
                <?= \App\Services\LevelSvc::getLevelBadge($user['credits'] ?? 0) ?>
                <?= \App\Services\VipSvc::getVipBadge((int)$user['id']) ?>
            </div>
            <?php if (!empty($user['signature'])): ?>
            <div class="profile-signature"><?= htmlspecialchars($user['signature']) ?></div>
            <?php endif; ?>
            <div class="profile-stats-row">
                <div class="profile-stat-item">
                    <strong><?= number_format($user['thread_count'] ?? 0) ?></strong>
                    <span>主题</span>
                </div>
                <div class="profile-stat-item">
                    <strong><?= number_format($user['post_count'] ?? 0) ?></strong>
                    <span>评论</span>
                </div>
                <div class="profile-stat-item">
                    <strong><?= number_format($user['credits'] ?? 0) ?></strong>
                    <span>积分</span>
                </div>
                <div class="profile-stat-item">
                    <strong><?= date('Y-m-d', $user['created_at']) ?></strong>
                    <span>注册</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 等级进度条 -->
    <?php if (isset($user['level_info'])): ?>
    <?php $levelInfo = $user['level_info']; ?>
    <div class="profile-level-bar">
        <div class="profile-level-info">
            <span>
                <?php if ($levelInfo['next_level']): ?>
                距离 <strong style="color:<?= htmlspecialchars($levelInfo['next_level']['color']) ?>;"><?= htmlspecialchars($levelInfo['next_level']['name']) ?></strong> 还需 <strong><?= number_format($levelInfo['credits_needed']) ?></strong> 积分
                <?php else: ?>
                已达到最高等级
                <?php endif; ?>
            </span>
            <span class="profile-level-pct"><?= (int)$levelInfo['progress'] ?>%</span>
        </div>
        <div class="profile-level-track">
            <div class="profile-level-fill" style="background:<?= htmlspecialchars($user['level_color']) ?>;width:<?= (int)$levelInfo['progress'] ?>%;"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 快捷入口 -->
<div class="profile-shortcuts">
    <a href="/tasks" class="profile-shortcut-item">
        <div class="shortcut-icon" style="color:var(--primary);background:rgba(59,130,246,.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        </div>
        <span>任务中心</span>
    </a>
    <a href="/vip" class="profile-shortcut-item">
        <div class="shortcut-icon" style="color:var(--warning);background:rgba(245,158,11,.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
        </div>
        <span>VIP 会员</span>
    </a>
    <a href="/favorites" class="profile-shortcut-item">
        <div class="shortcut-icon" style="color:var(--danger);background:rgba(239,68,68,.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </div>
        <span>我的收藏</span>
    </a>
    <a href="/navigation" class="profile-shortcut-item">
        <div class="shortcut-icon" style="color:var(--info);background:rgba(6,182,212,.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
        </div>
        <span>网址导航</span>
    </a>
    <a href="/user/blacklist" class="profile-shortcut-item">
        <div class="shortcut-icon" style="color:var(--danger);background:rgba(239,68,68,.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
        </div>
        <span>黑名单</span>
    </a>
</div>

<!-- 标签页 -->
<div class="card" x-data="{ tab: 'threads' }">
    <div class="tabs">
        <button class="tab-item" :class="{ active: tab === 'threads' }" @click="tab = 'threads'">我的帖子</button>
        <button class="tab-item" :class="{ active: tab === 'replies' }" @click="tab = 'replies'">我的评论</button>
        <button class="tab-item" :class="{ active: tab === 'favorites' }" @click="tab = 'favorites'">我的收藏</button>
        <button class="tab-item" :class="{ active: tab === 'credits' }" @click="tab = 'credits'">积分记录</button>
        <button class="tab-item" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">修改资料</button>
        <button class="tab-item" :class="{ active: tab === 'password' }" @click="tab = 'password'">修改密码</button>
    </div>

    <!-- 我的帖子 -->
    <div class="tab-panel" :class="{ active: tab === 'threads' }">
        <?php if (empty($threads)): ?>
            <?php $emptyIcon = 'post'; $emptyText = '暂无帖子'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
        <?php else: ?>
            <?php foreach ($threads as $thread): ?>
            <div class="thread-flow-item">
                <div class="thread-flow-body">
                    <a href="/thread/<?= (int)$thread['id'] ?>" class="thread-flow-title"><?= htmlspecialchars($thread['title']) ?></a>
                    <div class="thread-flow-meta">
                        <span class="timeago" datetime="<?= date('c', $thread['created_at']) ?>"><?= date('Y-m-d H:i', $thread['created_at']) ?></span>
                        <span>浏览 <?= number_format($thread['views']) ?></span>
                        <span>评论 <?= number_format($thread['reply_count'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 我的回复 -->
    <div class="tab-panel" :class="{ active: tab === 'replies' }">
        <?php if (empty($replies ?? [])): ?>
            <?php $emptyIcon = 'reply'; $emptyText = '暂无评论'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
        <?php else: ?>
            <?php foreach ($replies as $reply): ?>
            <div class="thread-flow-item">
                <div class="thread-flow-body">
                    <a href="/thread/<?= (int)$reply['thread_id'] ?>" class="thread-flow-title">评论于：<?= htmlspecialchars($reply['thread_title'] ?? '') ?></a>
                    <div class="thread-flow-meta"><span class="timeago" datetime="<?= date('c', $reply['created_at']) ?>"><?= date('Y-m-d H:i', $reply['created_at']) ?></span></div>
                    <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;"><?= htmlspecialchars(mb_substr(strip_tags($reply['content']), 0, 80)) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 我的收藏 -->
    <div class="tab-panel" :class="{ active: tab === 'favorites' }">
        <?php if (empty($favorites)): ?>
            <?php $emptyIcon = 'post'; $emptyText = '暂无收藏'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
        <?php else: ?>
            <?php foreach ($favorites as $fav): ?>
            <div class="thread-flow-item">
                <div class="thread-flow-body">
                    <a href="/thread/<?= (int)$fav['id'] ?>" class="thread-flow-title"><?= htmlspecialchars($fav['title']) ?></a>
                    <div class="thread-flow-meta">
                        <span<?= \App\Services\UserSvc::nicknameStyle($fav) ?>><?= htmlspecialchars(($fav['nickname'] ?? '') ?: ($fav['username'] ?? '')) ?></span>
                        <span>收藏于 <?= date('Y-m-d H:i', $fav['favorited_at']) ?></span>
                        <span>浏览 <?= number_format($fav['views'] ?? 0) ?></span>
                        <span>评论 <?= number_format($fav['reply_count'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <p style="text-align:center;padding:12px 0 4px;"><a href="/favorites" class="btn btn-ghost btn-sm">查看全部收藏</a></p>
        <?php endif; ?>
    </div>

    <!-- 积分记录 -->
    <div class="tab-panel" :class="{ active: tab === 'credits' }">
        <div style="text-align:center;padding:32px 16px;">
            <div style="font-size:14px;color:var(--text-muted);margin-bottom:16px;">当前积分</div>
            <div style="font-size:36px;font-weight:700;color:var(--primary);margin-bottom:24px;"><?= number_format($user['credits'] ?? 0) ?></div>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a href="/user/credit-logs" class="btn btn-primary">查看积分记录</a>
                <a href="/user/credit-ranking" class="btn btn-ghost">积分排行榜</a>
                <a href="/user/levels" class="btn btn-ghost">等级体系</a>
            </div>
        </div>
    </div>

    <!-- 修改资料 -->
    <div class="tab-panel" :class="{ active: tab === 'settings' }" x-data="profileForm()">
        <form @submit.prevent="submitProfile" style="max-width:480px;">
            <div class="form-group">
                <label class="form-label">头像</label>
                <div style="display:flex;align-items:center;gap:12px;">
                    <img id="avatarPreview" src="<?= htmlspecialchars($user['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-lg">
                    <label class="btn btn-ghost btn-sm" style="cursor:pointer;">
                        选择图片
                        <input type="file" accept="image/*" style="display:none;" @change="uploadAvatar($event)">
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">昵称 <span style="color:var(--text-muted);font-weight:normal;">(可选，2-20个字符)</span></label>
                <input type="text" x-model="profile.nickname" class="form-input" placeholder="留空则显示用户名" maxlength="20">
            </div>
            <div class="form-group">
                <label class="form-label">邮箱</label>
                <input type="email" x-model="profile.email" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">个性签名</label>
                <input type="text" x-model="profile.signature" class="form-input" placeholder="最多200个字符" maxlength="200">
            </div>
            <button type="submit" class="btn btn-primary" :disabled="profileLoading">
                <span x-show="!profileLoading">保存</span>
                <span x-show="profileLoading">保存中...</span>
            </button>
        </form>
    </div>

    <!-- 修改密码 -->
    <div class="tab-panel" :class="{ active: tab === 'password' }" x-data="passwordForm()">
        <form @submit.prevent="submitPassword" style="max-width:480px;">
            <div class="form-group">
                <label class="form-label">原密码</label>
                <input type="password" x-model="pw.old_password" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">新密码</label>
                <input type="password" x-model="pw.new_password" class="form-input" placeholder="至少6个字符" required>
            </div>
            <div class="form-group">
                <label class="form-label">确认新密码</label>
                <input type="password" x-model="pw.confirm_password" class="form-input" required>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="pwLoading">
                <span x-show="!pwLoading">修改密码</span>
                <span x-show="pwLoading">提交中...</span>
            </button>
        </form>
    </div>
</div>

<script>
function profileForm() {
    return {
        profile: <?= json_encode(['nickname' => $user['nickname'] ?? '', 'email' => $user['email'] ?? '', 'signature' => $user['signature'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        profileError: '', profileSuccess: '', profileLoading: false,
        async uploadAvatar(e) {
            const file = e.target.files[0];
            if (!file) return;
            const fd = new FormData(); fd.append('avatar', file);
            const data = await App.upload('/user/avatar', fd, { silent: true });
            if (data.success) { document.getElementById('avatarPreview').src = data.data.avatar; toast('头像上传成功', 'success'); }
            else { toast(data.message, 'error'); }
        },
        async submitProfile() {
            this.profileError = ''; this.profileSuccess = ''; this.profileLoading = true;
            const data = await App.post('/user/profile', this.profile, { silent: true });
            if (data.success) { toast(data.message, 'success'); } else { toast(data.message, 'error'); }
            this.profileLoading = false;
        }
    }
}
function passwordForm() {
    return {
        pw: { old_password: '', new_password: '', confirm_password: '' },
        pwError: '', pwSuccess: '', pwLoading: false,
        async submitPassword() {
            this.pwError = ''; this.pwSuccess = '';
            if (this.pw.new_password.length < 6) { toast('新密码至少6个字符', 'error'); return; }
            if (this.pw.new_password !== this.pw.confirm_password) { toast('两次密码不一致', 'error'); return; }
            this.pwLoading = true;
            const data = await App.post('/user/password', this.pw, { silent: true });
            if (data.success) { this.pw = { old_password: '', new_password: '', confirm_password: '' }; toast(data.message, 'success'); }
            else { toast(data.message, 'error'); }
            this.pwLoading = false;
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
