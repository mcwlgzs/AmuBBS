<?php
$pageTitle = '发新帖 - ' . htmlspecialchars($forum['name'] ?? '');
$pageCss = ['thread'];
$_captchaRequired = \App\Services\CaptchaSvc::isRequired('thread');
include APP_PATH . 'resources/views/layout/header.php';

/**
 * 发新帖
 *
 * 提交走 htmx（hx-post + hx-swap="none"），页面里不再有 Alpine：
 *   - 成功：服务端回 HX-Redirect，跳到新帖
 *   - 失败：服务端回 422 + HX-Trigger: frontFlash 弹提示，页面不动、已填内容不丢
 *   - 长度校验交给 required/minlength，规则与服务端 ThreadSvc 保持一致
 *   - 标签点选 / 草稿自动保存 / 验证码回填由 app.js 按 data-* 声明接管
 */
?>
<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/forum/<?= (int)$forum['id'] ?>"><?= htmlspecialchars($forum['name'] ?? '') ?></a> <span class="breadcrumb-sep">/</span>
    <span>发新帖</span>
</div>

<div class="card">
    <div class="section-title">发新帖</div>
    <form hx-post="/thread/create" hx-swap="none" hx-indicator="this"
          hx-disabled-elt="#threadCreateSubmit"
          data-draft-key="draft_create_<?= (int)$forum['id'] ?>">
        <input type="hidden" name="forum_id" value="<?= (int)$forum['id'] ?>">
        <?php if ($_captchaRequired): ?>
        <input type="hidden" name="captcha_id" value="">
        <input type="hidden" name="captcha_answer" value="">
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label" for="threadTitle">标题</label>
            <input type="text" id="threadTitle" name="title" placeholder="帖子标题（至少2个字符）"
                   class="form-input" required minlength="2" maxlength="120" autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="threadTags">标签</label>
            <input type="text" id="threadTags" name="tags" placeholder="多个标签用逗号分隔" class="form-input" data-tags-input>
            <?php if (!empty($tagGroups)): ?>
            <?php foreach ($tagGroups as $group): ?>
            <div style="margin-top:8px;">
                <span style="font-size:12px;color:var(--text-muted);margin-right:6px;"><?= htmlspecialchars($group['name']) ?>：</span>
                <?php foreach ($group['tags'] as $t): ?>
                <span class="tag tag-clickable" style="cursor:pointer;font-size:12px;" data-tag-add="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['name']) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php elseif (!empty($allTags)): ?>
            <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">
                <?php foreach ($allTags as $t): ?>
                <span class="tag tag-clickable" style="cursor:pointer;font-size:12px;" data-tag-add="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['name']) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="post-content">内容</label>
            <textarea id="post-content" name="content" rows="14" class="form-textarea" required minlength="5"
                      placeholder="支持 Markdown：**粗体**、*斜体*、`代码`、```代码块```" style="resize:vertical;"></textarea>
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="submit" id="threadCreateSubmit" class="btn btn-primary">
                <span class="hx-idle">发布帖子</span>
                <span class="hx-busy">发布中...</span>
            </button>
            <a href="/forum/<?= (int)$forum['id'] ?>" class="btn btn-ghost">取消</a>
            <?php if ($_captchaRequired): ?>
            <div class="captcha-widget" data-captcha-scene="thread"></div>
            <?php endif; ?>
            <span data-draft-hint style="display:none;font-size:12px;color:var(--text-muted);">草稿已自动保存</span>
        </div>
    </form>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
