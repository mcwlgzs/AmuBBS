<?php
$pageTitle = '编辑帖子';
$pageCss = ['thread'];
include APP_PATH . 'resources/views/layout/header.php';

/**
 * 编辑帖子
 *
 * 与发新帖同一套 htmx 表单约定（见 thread/create.php 顶部说明）。
 * data-draft-restore="always"：编辑页的草稿优先于服务端内容，
 * 保持和迁移前一致的行为（原来写的是 saved?.title || 服务端值）。
 */
$tagNames = implode(',', array_map(static fn($t) => $t['name'], $tags ?? []));
?>
<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/forum/<?= (int)$forum['id'] ?>"><?= htmlspecialchars($forum['name'] ?? '') ?></a> <span class="breadcrumb-sep">/</span>
    <a href="/thread/<?= (int)$thread['id'] ?>"><?= htmlspecialchars($thread['title']) ?></a> <span class="breadcrumb-sep">/</span>
    <span>编辑</span>
</div>

<div class="card">
    <div class="section-title">编辑帖子</div>
    <form hx-post="/thread/edit" hx-swap="none" hx-indicator="this"
          hx-disabled-elt="#threadEditSubmit"
          data-draft-key="draft_edit_<?= (int)$thread['id'] ?>"
          data-draft-restore="always">
        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">

        <div class="form-group">
            <label class="form-label" for="threadTitle">标题</label>
            <input type="text" id="threadTitle" name="title" class="form-input"
                   value="<?= htmlspecialchars($thread['title']) ?>"
                   placeholder="帖子标题（至少2个字符）" required minlength="2" maxlength="120" autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="threadTags">标签</label>
            <input type="text" id="threadTags" name="tags" class="form-input" data-tags-input
                   value="<?= htmlspecialchars($tagNames) ?>"
                   placeholder="多个标签用逗号分隔">
            <?php if (!empty($allTags)): ?>
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
                      placeholder="支持 Markdown：**粗体**、*斜体*、`代码`、```代码块```" style="resize:vertical;"><?= htmlspecialchars($thread['content']) ?></textarea>
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="submit" id="threadEditSubmit" class="btn btn-primary">
                <span class="hx-idle">保存修改</span>
                <span class="hx-busy">保存中...</span>
            </button>
            <a href="/thread/<?= (int)$thread['id'] ?>" class="btn btn-ghost">取消</a>
            <span data-draft-hint style="display:none;font-size:12px;color:var(--text-muted);">草稿已自动保存</span>
        </div>
    </form>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
