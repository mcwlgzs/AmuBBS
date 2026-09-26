<div class="dropdown" data-dropdown>
    <button class="dropdown-toggle" type="button" data-dropdown-toggle>
        <img src="<?= htmlspecialchars($_userAvatar) ?>" alt="" class="avatar-xs">
        <span class="hide-mobile"><?= htmlspecialchars(($_SESSION['nickname'] ?? '') ?: ($_SESSION['username'] ?? '')) ?></span>
    </button>
    <div class="dropdown-menu" data-dropdown-menu hidden>
        <a href="/profile" class="dropdown-item">个人中心</a>
        <a href="/user/<?= (int)$_SESSION['user_id'] ?>" class="dropdown-item show-desktop-only">我的主页</a>
        <a href="/favorites" class="dropdown-item show-desktop-only">我的收藏</a>
        <a href="/tasks" class="dropdown-item show-desktop-only">任务中心</a>
        <?php if (($_SESSION['group_id'] ?? 0) == \App\Services\PermissionSvc::ADMIN_GROUP_ID): ?>
        <div class="dropdown-divider"></div>
        <a href="/admin" class="dropdown-item">后台管理</a>
        <?php endif; ?>
        <div class="dropdown-divider"></div>
        <?php /* 退出登录是 POST（GET 会 405）：走 htmx，成功后由服务端回 HX-Redirect */ ?>
        <a href="#" class="dropdown-item" hx-post="/logout" hx-swap="none">退出登录</a>
    </div>
</div>
