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
                <?php
                // 今日新帖（主题 + 回复）。$todayStats 由 IndexController 取好并缓存（runtime:today，60 秒），
                // 不额外增加查询；这个格子补上后正好填满四列网格（.site-info-stats 是 repeat(4,1fr)）。
                $todayTotal = (int)($todayStats['threads'] ?? 0) + (int)($todayStats['posts'] ?? 0);
                ?>
                <div class="site-info-stat stat-today">
                    <span class="site-info-stat-value"><?= number_format($todayTotal) ?></span>
                    <span class="site-info-stat-label">今日</span>
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
        <?php /* 关闭公告只记 localStorage（访客没有账号），由 app.js 的 [data-ann-hide] 处理；
                 「查看全部」用原生 <details>，展开/收起不需要任何 JS */ ?>
        <div class="announcement-wrap" data-ann-wrap data-ann-hide-mins="<?= (int)$annHideMins ?>">
            <?php foreach ($annVisible as $i => $a): ?>
            <?php include APP_PATH . 'resources/views/index/_announcement_item.php'; ?>
            <?php endforeach; ?>
            <?php if (!empty($annExtra)): ?>
            <details class="announcement-more">
                <summary class="announcement-toggle">
                    <span class="ann-more-closed">查看全部 <?= (int)$annTotal ?> 条公告</span>
                    <span class="ann-more-open">收起公告</span>
                </summary>
                <?php foreach ($annExtra as $a): ?>
                <?php include APP_PATH . 'resources/views/index/_announcement_item.php'; ?>
                <?php endforeach; ?>
            </details>
            <?php endif; ?>
        </div>
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
                                <?php $badgeThread = $thread; include APP_PATH . 'resources/views/components/thread-badges.php'; ?>
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
        <?php include APP_PATH . 'resources/views/index/_checkin.php'; ?>
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

        <!-- 新注册用户 -->
        <?php include APP_PATH . 'resources/views/components/sidebar-new-users.php'; ?>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
