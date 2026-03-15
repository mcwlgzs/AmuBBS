<?php
/**
 * 侧边栏 - 最新注册用户
 */
try {
    $_newUsers = \Core\Cache::get('sidebar:new_users', function () {
        return \Core\Database::fetchAll(
            "SELECT id, username, nickname, avatar, nickname_color, created_at FROM users WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 10"
        );
    }, 300);
} catch (\Throwable $e) { $_newUsers = []; }
if (!empty($_newUsers)):
?>
<div class="card">
    <div class="section-title"><span><svg style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;color:#8b5cf6;" viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>新注册用户</span></div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
        <?php foreach ($_newUsers as $_nu): ?>
        <a href="/user/<?= (int)$_nu['id'] ?>" title="<?= htmlspecialchars(($_nu['nickname'] ?? '') ?: ($_nu['username'] ?? '')) ?>" style="text-align:center;text-decoration:none;">
            <img src="<?= htmlspecialchars($_nu['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" style="width:36px;height:36px;border-radius:50%;display:block;margin:0 auto 2px;">
            <span style="font-size:11px;display:block;max-width:48px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"<?= \App\Services\UserSvc::nicknameStyle($_nu) ?>><?= htmlspecialchars(($_nu['nickname'] ?? '') ?: ($_nu['username'] ?? '')) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
