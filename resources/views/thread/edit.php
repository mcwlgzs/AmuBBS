<?php $pageTitle = '编辑帖子'; $pageCss = ['thread']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/forum/<?= (int)$forum['id'] ?>"><?= htmlspecialchars($forum['name'] ?? '') ?></a> <span class="breadcrumb-sep">/</span>
    <a href="/thread/<?= (int)$thread['id'] ?>"><?= htmlspecialchars($thread['title']) ?></a> <span class="breadcrumb-sep">/</span>
    <span>编辑</span>
</div>

<div class="card" x-data="editThread()">
    <div class="section-title">编辑帖子</div>
    <form @submit.prevent="submit">
        <div class="form-group">
            <label class="form-label">标题</label>
            <input type="text" x-model="form.title" placeholder="帖子标题（至少2个字符）" class="form-input" required>
        </div>
        <div class="form-group">
            <label class="form-label">标签</label>
            <input type="text" x-model="form.tags" placeholder="多个标签用逗号分隔" class="form-input">
            <?php if (!empty($allTags)): ?>
            <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">
                <?php foreach ($allTags as $t): ?>
                <span class="tag tag-clickable" style="cursor:pointer;font-size:12px;" @click="addTag('<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')"><?= htmlspecialchars($t['name']) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label class="form-label">内容</label>
            <?php $editorRows = 14; $editorPlaceholder = '帖子内容（至少5个字符）'; $editorModel = 'form.content'; $editorMode = 'full'; include APP_PATH . 'resources/views/components/tinymce-editor.php'; ?>
        </div>
        <?php include APP_PATH . 'resources/views/components/alert.php'; ?>
        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn btn-primary" :disabled="loading">
                <span x-show="!loading">保存修改</span>
                <span x-show="loading">保存中...</span>
            </button>
            <a href="/thread/<?= (int)$thread['id'] ?>" class="btn btn-ghost">取消</a>
            <span x-show="draftSaved" style="font-size:12px;color:var(--text-muted);">草稿已自动保存</span>
        </div>
    </form>
</div>

<script>
function editThread() {
    const draftKey = 'draft_edit_<?= (int)$thread['id'] ?>';
    const saved = JSON.parse(localStorage.getItem(draftKey) || 'null');
    return {
        form: {
            thread_id: '<?= (int)$thread['id'] ?>',
            title: saved?.title || <?= json_encode($thread['title'], JSON_HEX_TAG | JSON_HEX_APOS) ?>,
            content: saved?.content || <?= json_encode($thread['content_fmt'] ?: \Core\Markdown::parse($thread['content']), JSON_HEX_TAG | JSON_HEX_APOS) ?>,
            tags: saved?.tags || <?= json_encode(implode(',', array_map(fn($t) => $t['name'], $tags ?? [])), JSON_HEX_TAG | JSON_HEX_APOS) ?>
        },
        errorMessage: '', successMessage: '', loading: false,
        draftSaved: false,
        init() {
            this.$watch('form', () => {
                localStorage.setItem(draftKey, JSON.stringify({ title: this.form.title, content: this.form.content, tags: this.form.tags }));
                this.draftSaved = true;
            });
        },
        clearDraft() { localStorage.removeItem(draftKey); },
        addTag(name) {
            const current = this.form.tags ? this.form.tags.split(',').map(s => s.trim()).filter(Boolean) : [];
            if (!current.includes(name)) { current.push(name); this.form.tags = current.join(','); }
        },
        getTextLength() {
            const tmp = document.createElement('div');
            tmp.innerHTML = this.form.content;
            return (tmp.textContent || tmp.innerText || '').trim().length;
        },
        async submit() {
            this.errorMessage = ''; this.successMessage = '';
            if (this.form.title.length < 2) { toast('标题至少2个字符', 'error'); return; }
            if (this.getTextLength() < 5) { toast('内容至少5个字符', 'error'); return; }
            this.loading = true;
            try {
                const data = await App.post('/thread/edit', this.form, { silent: true });
                if (data.success) { this.clearDraft(); toast('保存成功', 'success'); setTimeout(() => location.href = '/thread/' + this.form.thread_id, 800); }
                else { toast(data.error || data.message || '保存失败', 'error'); }
            } finally { this.loading = false; }
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
