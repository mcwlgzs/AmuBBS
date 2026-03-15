        </div>
    </main>

    <!-- 移动端底部导航栏 -->
    <nav class="mobile-bottom-nav">
        <a href="/" class="mobile-nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <?php if (isset($_SESSION['user_id'])): ?>
        <a href="/forums" class="mobile-nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            <span>分类</span>
        </a>
        <a href="/messages" class="mobile-nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span>私信</span>
        </a>
        <a href="/profile" class="mobile-nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>我的</span>
        </a>
        <?php else: ?>
        <a href="/forums" class="mobile-nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            <span>分类</span>
        </a>
        <a href="/login" class="mobile-nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span>私信</span>
        </a>
        <a href="/login" class="mobile-nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>我的</span>
        </a>
        <?php endif; ?>
    </nav>

    <?php include APP_PATH . 'resources/views/components/confirm-modal.php'; ?>
    <?php include APP_PATH . 'resources/views/components/auth-modal.php'; ?>

    <!-- 阅读进度条 -->
    <div class="reading-progress" id="readingProgress"></div>

    <!-- 返回顶部 -->
    <div x-data="backToTop()" class="back-to-top" :class="{ visible: visible }" @click="scrollTop()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
    </div>

    <!-- 暗色模式切换 -->
    <div x-data="darkMode()" class="theme-toggle" @click="toggle()" :title="dark ? '切换亮色' : '切换暗色'">
        <svg x-show="!dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg x-show="dark" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </div>

    <script src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/toastify.min.js"></script>
    <script src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/lazyload.min.js"></script>
    <script src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/autosize.min.js"></script>
    <script src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/timeago.min.js"></script>
    <!-- app.js 已移至 head 用 defer 加载，确保在 Alpine 之前执行 -->

    <footer class="site-footer">
        <div class="container">
            <?php include APP_PATH . 'resources/views/components/friend-links.php'; ?>
            <div class="footer-inner" style="border-top:1px solid var(--border-light);padding-top:8px;">
                <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($_siteName ?? 'AMuBBS') ?> &middot; Powered by AMuBBS<?php
                    $_icp = '';
                    try { $_icp = $_settings['icp_number'] ?? ''; } catch (\Throwable $e) { error_log('[footer] icp: ' . $e->getMessage()); }
                    if ($_icp): ?> &middot; <?= htmlspecialchars($_icp) ?><?php endif; ?>
                </span>
                <span class="footer-perf">
                    <?php
                    $_startTime = $_SERVER['REQUEST_START_TIME'] ?? ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
                    $_elapsed = substr(microtime(true) - $_startTime, 0, 5);
                    $_sqlCount = 0;
                    try { $_sqlCount = count(\Core\Database::getQueryLog()); } catch (\Throwable $e) { error_log('[footer] queryLog: ' . $e->getMessage()); }
                    ?>
                    Processed: <b><?= htmlspecialchars($_elapsed) ?></b>s, SQL: <b><?= (int)$_sqlCount ?></b>
                </span>
            </div>
        </div>
    </footer>
</body>
</html>
