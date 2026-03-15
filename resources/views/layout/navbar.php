<!-- 顶部导航栏 -->
<nav class="navbar">
    <div class="navbar-inner">
        <div class="navbar-left">
            <a href="/" class="navbar-brand"><?= htmlspecialchars($_siteName) ?></a>
            <div class="navbar-nav hide-mobile">
                <a href="/" class="nav-link">首页</a>
                <a href="/moments" class="nav-link">动态</a>
                <a href="/navigation" class="nav-link">导航</a>
                <?php if (\App\Services\VipSvc::isEnabled()): ?><a href="/vip" class="nav-link">VIP</a><?php endif; ?>
                <a href="/user/credit-ranking" class="nav-link">排行</a>
            </div>
        </div>

        <div class="navbar-search">
            <form action="/search" method="GET" class="search-form">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" name="q" placeholder="搜索帖子、用户、标签..." class="search-input" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            </form>
        </div>

        <div class="navbar-right">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="/thread/create" class="nav-icon" title="发帖">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </a>
                <?php include __DIR__ . '/../components/notification-panel.php'; ?>
                <a href="/messages" class="nav-icon hide-mobile" title="私信">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </a>
                <?php include __DIR__ . '/../components/user-dropdown.php'; ?>
            <?php else: ?>
                <a href="/login" class="nav-link-light" @click="if(window.innerWidth>=768){$event.preventDefault();$dispatch('auth-show','login')}">登录</a>
                <a href="/register" class="nav-btn-post" @click="if(window.innerWidth>=768){$event.preventDefault();$dispatch('auth-show','register')}">注册</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
