<?php $pageTitle = ''; $pageCss = ['index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<!-- 三栏布局 -->
<div class="home-layout">
    <!-- 左栏：板块导航 + 站点信息 -->
    <div class="home-sidebar-left">
        <!-- 站点信息 -->
        <div class="card card-site-info">
            <div class="site-info-body">
                <h5 class="site-info-name"><?= htmlspecialchars($_siteName) ?></h5>
                <?php if (!empty($_siteDesc)): ?>
                <div class="site-info-desc"><?= htmlspecialchars($_siteDesc) ?></div>
                <?php endif; ?>
            </div>
            <div class="site-info-stats">
                <div class="site-info-stat stat-threads">
                    <span class="site-info-stat-value"><?= number_format($stats['threads'] ?? 0) ?></span>
                    <span class="site-info-stat-label">主题</span>
                </div>
                <div class="site-info-stat stat-posts">
                    <span class="site-info-stat-value"><?= number_format($stats['posts'] ?? 0) ?></span>
                    <span class="site-info-stat-label">回帖</span>
                </div>
                <div class="site-info-stat stat-users">
                    <span class="site-info-stat-value"><?= number_format($stats['users'] ?? 0) ?></span>
                    <span class="site-info-stat-label">会员</span>
                </div>
                <div class="site-info-stat stat-online">
                    <span class="site-info-stat-value"><?= number_format($onlineSummary['total'] ?? 0) ?></span>
                    <span class="site-info-stat-label">在线</span>
                </div>
            </div>
        </div>

        <!-- 板块列表 -->
        <?php if (!empty($forums)): ?>
        <div class="card">
            <div class="section-title with-bar">板块导航</div>
            <?php foreach ($forums as $f): ?>
            <a href="/forum/<?= (int)$f['id'] ?>" class="forum-card">
                <div class="forum-icon"><?= htmlspecialchars(mb_substr($f['name'], 0, 1)) ?></div>
                <div class="forum-card-body">
                    <span class="forum-card-name"><?= htmlspecialchars($f['name']) ?></span>
                    <div class="forum-card-desc"><?= number_format($f['thread_count'] ?? 0) ?> 主题</div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 热门标签 -->
        <?php if (!empty($popularTags)): ?>
        <div class="card">
            <div class="section-title">热门标签</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                <?php foreach ($popularTags as $tag): ?>
                <a href="/tag/<?= urlencode($tag['name']) ?>" class="tag tag-clickable"><?= htmlspecialchars($tag['name']) ?> <small style="opacity:.5;margin-left:2px;"><?= (int)$tag['thread_count'] ?></small></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <!-- 中栏：帖子列表 -->
    <div class="home-main">
        <!-- 公告栏 -->
        <?php if (!empty($announcements)): ?>
        <?php
            $annTotal = count($announcements);
            $annVisible = array_slice($announcements, 0, 3);
            $annExtra = array_slice($announcements, 3);
            $annHideMins = intval($_settings['announcement_hide_duration'] ?? 60);
        ?>
        <script>
        (function(){
            var m=<?= (int)$annHideMins ?>,d,now=Date.now(),s=document.createElement('style'),r=[];
            try{d=JSON.parse(localStorage.getItem('ann_hidden')||'{}');}catch(e){d={};}
            for(var id in d){if(m>0&&(now-d[id])<m*60000)r.push('.ann-id-'+id);}
            if(r.length){s.textContent=r.join(',')+'{display:none!important}';document.head.appendChild(s);}
        })();
        </script>
        <div class="announcement-wrap" x-data="annWrap(<?= $annHideMins ?>)" x-init="init()">
            <?php foreach ($annVisible as $i => $a): ?>
            <div class="announcement-item ann-id-<?= (int)$a['id'] ?> <?= $a['type'] == 2 ? 'ann-urgent' : ($a['type'] == 1 ? 'ann-important' : '') ?>"
                 x-show="!isHidden(<?= (int)$a['id'] ?>)" x-transition>
                <div class="announcement-icon">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </div>
                <div class="announcement-text">
                    <?php if ($a['url']): ?><a href="<?= htmlspecialchars($a['url']) ?>"><?= htmlspecialchars($a['title']) ?></a>
                    <?php else: ?><?= htmlspecialchars($a['title']) ?><?php endif; ?>
                </div>
                <button class="announcement-close" @click="hide(<?= (int)$a['id'] ?>)" title="关闭公告">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <?php endforeach; ?>
            <?php if (!empty($annExtra)): ?>
            <template x-if="expanded">
                <div>
                    <?php foreach ($annExtra as $a): ?>
                    <div class="announcement-item ann-id-<?= (int)$a['id'] ?> <?= $a['type'] == 2 ? 'ann-urgent' : ($a['type'] == 1 ? 'ann-important' : '') ?>"
                         x-show="!isHidden(<?= (int)$a['id'] ?>)" x-transition>
                        <div class="announcement-icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        </div>
                        <div class="announcement-text">
                            <?php if ($a['url']): ?><a href="<?= htmlspecialchars($a['url']) ?>"><?= htmlspecialchars($a['title']) ?></a>
                            <?php else: ?><?= htmlspecialchars($a['title']) ?><?php endif; ?>
                        </div>
                        <button class="announcement-close" @click="hide(<?= (int)$a['id'] ?>)" title="关闭公告">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </template>
            <div class="announcement-more">
                <button class="announcement-toggle" @click="expanded = !expanded" x-text="expanded ? '收起公告' : '查看全部 <?= (int)$annTotal ?> 条公告'"></button>
            </div>
            <?php endif; ?>
        </div>
        <script>
        function annWrap(hideMins) {
            return {
                expanded: false,
                hideMins: hideMins,
                hidden: {},
                init() {
                    try {
                        const data = JSON.parse(localStorage.getItem('ann_hidden') || '{}');
                        const now = Date.now();
                        // 清理过期记录
                        for (const [id, ts] of Object.entries(data)) {
                            if (this.hideMins > 0 && (now - ts) < this.hideMins * 60000) {
                                this.hidden[id] = ts;
                            }
                        }
                        localStorage.setItem('ann_hidden', JSON.stringify(this.hidden));
                    } catch(e) {}
                },
                isHidden(id) {
                    const ts = this.hidden[id];
                    if (!ts) return false;
                    if (this.hideMins <= 0) return false;
                    return (Date.now() - ts) < this.hideMins * 60000;
                },
                hide(id) {
                    this.hidden[id] = Date.now();
                    try { localStorage.setItem('ann_hidden', JSON.stringify(this.hidden)); } catch(e) {}
                }
            };
        }
        </script>
        <?php endif; ?>

        <!-- 板块导航条 -->
        <?php if (!empty($forums)): ?>
        <div class="forum-nav-bar">
            <?php foreach ($forums as $f): ?>
            <a href="/forum/<?= (int)$f['id'] ?>" class="forum-nav-item"><?= htmlspecialchars($f['name']) ?></a>
            <?php endforeach; ?>
            <a href="/forum/all" class="forum-nav-item">全部</a>
        </div>
        <?php endif; ?>

        <!-- 帖子列表卡片 -->
        <div class="card card-threadlist">
            <div class="card-tab-header">
                <div class="tab-nav">
                    <a href="/" class="tab-nav-item <?= empty($_GET['tab']) || $_GET['tab'] === 'latest' ? 'active' : '' ?>">最新主题</a>
                    <a href="/?tab=hot" class="tab-nav-item <?= ($_GET['tab'] ?? '') === 'hot' ? 'active' : '' ?>">热门</a>
                    <a href="/?tab=highlight" class="tab-nav-item <?= ($_GET['tab'] ?? '') === 'highlight' ? 'active' : '' ?>">精华</a>
                </div>
            </div>
            <div class="card-list-body">
                <?php
                $tab = $_GET['tab'] ?? 'latest';
                if (!in_array($tab, ['latest', 'hot', 'highlight'], true)) $tab = 'latest';
                $displayThreads = $latestThreads;
                if ($tab === 'hot') $displayThreads = !empty($hotThreads) ? $hotThreads : $latestThreads;
                if ($tab === 'highlight') $displayThreads = !empty($featuredThreads) ? $featuredThreads : [];
                ?>
                <?php if (empty($displayThreads)): ?>
                    <?php $emptyIcon = 'post'; $emptyText = '暂无帖子，快来发布第一个帖子吧'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
                <?php else: ?>
                    <?php foreach ($displayThreads as $thread): ?>
                    <div class="thread-item">
                        <a href="/user/<?= (int)($thread['user_id'] ?? 0) ?>" class="thread-item-avatar">
                            <img src="<?= htmlspecialchars($thread['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm" loading="lazy">
                        </a>
                        <div class="thread-item-body">
                            <div class="thread-item-title">
                                <a href="/thread/<?= (int)$thread['id'] ?>"><?= htmlspecialchars($thread['title']) ?></a>
                                <?php if ($thread['is_top'] ?? false): ?><span class="tag tag-top" title="置顶"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg></span><?php endif; ?>
                                <?php if ($thread['is_highlight'] ?? false): ?><span class="tag tag-highlight" title="精华"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span><?php endif; ?>
                                <?php if ($thread['is_locked'] ?? false): ?><span class="tag tag-locked" title="已锁定"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span><?php endif; ?>
                            </div>
                            <div class="thread-item-meta">
                                <a href="/user/<?= (int)($thread['user_id'] ?? 0) ?>" class="thread-item-author"<?= \App\Services\UserSvc::nicknameStyle($thread) ?>><?= htmlspecialchars(($thread['nickname'] ?? '') ?: ($thread['username'] ?? '')) ?></a>
                                <span class="thread-item-time timeago" datetime="<?= date('c', $thread['created_at']) ?>"><?= date('Y-m-d H:i', $thread['created_at']) ?></span>
                                <?php if (!empty($thread['forum_name'])): ?>
                                <a href="/forum/<?= (int)$thread['forum_id'] ?>" class="thread-item-forum"><?= htmlspecialchars($thread['forum_name']) ?></a>
                                <?php endif; ?>
                                <?php if (!empty($thread['tags'])): ?>
                                    <?php foreach (array_slice($thread['tags'], 0, 2) as $t): ?>
                                    <a href="/tag/<?= urlencode($t['name']) ?>" class="thread-item-forum" style="background:var(--primary-light);color:var(--primary);"><?= htmlspecialchars($t['name']) ?></a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="thread-item-stats">
                            <span title="评论"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;opacity:.4;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> <?= number_format($thread['reply_count'] ?? 0) ?></span>
                            <span title="浏览"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;opacity:.4;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <?= number_format($thread['views'] ?? 0) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($displayThreads)): ?>
            <div class="card-list-footer">
                <?php
                    $paginationUrl = $tab === 'latest' ? '/?page={page}' : "/?tab={$tab}&page={page}";
                    $paginationPage = $page ?? 1;
                    if ($tab === 'hot') {
                        $paginationTotal = $totalHotPages ?? 1;
                    } elseif ($tab === 'highlight') {
                        $paginationTotal = $totalFeaturedPages ?? 1;
                    } else {
                        $paginationTotal = $totalThreadPages ?? 1;
                    }
                    include APP_PATH . 'resources/views/layout/pagination.php';
                ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 右栏 -->
    <div class="home-sidebar">
        <!-- 签到 -->
        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="card checkin-card" x-cloak x-data="{ checkedIn: <?= $checkedIn ? 'true' : 'false' ?>, credits: <?= (int)($checkinCredits ?? 0) ?>, days: <?= (int)($checkinDays ?? 0) ?>, loading: false }">
            <template x-if="!checkedIn">
                <div class="checkin-inner">
                    <div class="checkin-icon">📅</div>
                    <div class="checkin-text">
                        <div class="checkin-label">每日签到</div>
                        <div class="checkin-hint">签到领取积分奖励</div>
                    </div>
                    <button class="btn btn-primary btn-sm" :disabled="loading" @click="
                        loading = true;
                        App.post('/checkin', {}, {silent:true})
                        .then(d => { if(d.success) { checkedIn = true; credits = d.data.credits; days = d.data.consecutive_days; } })
                        .finally(() => loading = false)
                    ">
                        <span x-show="!loading">签到</span>
                        <span x-show="loading">...</span>
                    </button>
                </div>
            </template>
            <template x-if="checkedIn">
                <div class="checkin-inner checkin-done">
                    <div class="checkin-icon">✅</div>
                    <div class="checkin-text">
                        <div class="checkin-label">今日已签到</div>
                        <div class="checkin-hint" x-show="credits">+<span x-text="credits"></span> 积分，连续 <span x-text="days"></span> 天</div>
                    </div>
                </div>
            </template>
        </div>
        <?php endif; ?>

        <!-- 今日热帖 -->
        <?php if (!empty($hotThreads)): ?>
        <div class="card">
            <div class="section-title"><span><svg style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;color:#ef4444;" viewBox="0 0 20 20" fill="currentColor"><path d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-2 1-3 .5 1.5 1 2 1 3a3 3 0 01-.38 1.62z"/></svg>今日热帖</span></div>
            <?php foreach ($hotThreads as $i => $hot): ?>
            <div class="hot-item">
                <span class="hot-rank <?= $i < 3 ? 'top3' : '' ?>"><?= $i + 1 ?></span>
                <a href="/thread/<?= (int)$hot['id'] ?>" class="hot-title"><?= htmlspecialchars($hot['title']) ?></a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 签到排行 -->
        <?php include APP_PATH . 'resources/views/components/sidebar-checkin-rank.php'; ?>

        <!-- 积分排行 -->
        <?php include APP_PATH . 'resources/views/components/sidebar-credit-rank.php'; ?>

        <!-- 在线用户 -->
        <?php include APP_PATH . 'resources/views/components/sidebar-online-users.php'; ?>

        <!-- 新注册用户 -->
        <?php include APP_PATH . 'resources/views/components/sidebar-new-users.php'; ?>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
