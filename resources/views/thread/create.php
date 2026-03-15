<?php
$pageTitle = '发新帖 - ' . htmlspecialchars($forum['name'] ?? '');
$pageCss = ['thread'];
$_captchaRequired = \App\Services\CaptchaSvc::isRequired('thread');
include APP_PATH . 'resources/views/layout/header.php';
?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/forum/<?= (int)$forum['id'] ?>"><?= htmlspecialchars($forum['name'] ?? '') ?></a> <span class="breadcrumb-sep">/</span>
    <span>发新帖</span>
</div>

<div class="card" x-data="createThread()">
    <div class="section-title">发新帖</div>
    <form @submit.prevent="submit">
        <div class="form-group">
            <label class="form-label">标题</label>
            <input type="text" x-model="form.title" placeholder="帖子标题（至少2个字符）" class="form-input" required>
        </div>
        <div class="form-group">
            <label class="form-label">标签</label>
            <input type="text" x-model="form.tags" placeholder="多个标签用逗号分隔" class="form-input">
            <?php if (!empty($tagGroups)): ?>
            <?php foreach ($tagGroups as $group): ?>
            <div style="margin-top:8px;">
                <span style="font-size:12px;color:var(--text-muted);margin-right:6px;"><?= htmlspecialchars($group['name']) ?>：</span>
                <?php foreach ($group['tags'] as $t): ?>
                <span class="tag tag-clickable" style="cursor:pointer;font-size:12px;" @click="addTag('<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')"><?= htmlspecialchars($t['name']) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php elseif (!empty($allTags)): ?>
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
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary" :disabled="loading">
                <span x-show="!loading">发布帖子</span>
                <span x-show="loading">发布中...</span>
            </button>
            <a href="/forum/<?= (int)$forum['id'] ?>" class="btn btn-ghost">取消</a>
            <?php if ($_captchaRequired): ?>
            <div class="captcha-widget" x-ref="captchaContainer" x-init="$nextTick(() => { _cw = new CaptchaWidget($refs.captchaContainer, { scene: 'thread', onVerified: (ok, id, ans) => { captchaId = id; captchaAnswer = ans; } }); })"></div>
            <?php endif; ?>
            <span x-show="draftSaved" style="font-size:12px;color:var(--text-muted);">草稿已自动保存</span>
        </div>
    </form>
</div>

<script>
function createThread() {
    const draftKey = 'draft_create_<?= (int)$forum['id'] ?>';
    const saved = JSON.parse(localStorage.getItem(draftKey) || 'null');
    return {
        form: {
            title: saved?.title || '',
            content: saved?.content || '',
            forum_id: '<?= (int)$forum['id'] ?>',
            tags: saved?.tags || ''
        },
        errorMessage: '', successMessage: '', loading: false,
        draftSaved: false,
        captchaId: '', captchaAnswer: '', _cw: null,
        init() {
            this.$watch('form', () => {
                if (this.form.title || this.form.content) {
                    localStorage.setItem(draftKey, JSON.stringify({ title: this.form.title, content: this.form.content, tags: this.form.tags }));
                    this.draftSaved = true;
                }
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
                const body = Object.assign({}, this.form);
                if (this.captchaId) { body.captcha_id = this.captchaId; body.captcha_answer = this.captchaAnswer; }
                const data = await App.post('/thread/create', body, { silent: true });
                if (data.success) { this.clearDraft(); toast('发帖成功', 'success'); setTimeout(() => location.href = '/thread/' + data.thread_id, 800); }
                else { toast(data.error || data.message || '发帖失败', 'error'); if (this._cw) this._cw.load(); }
            } finally { this.loading = false; }
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
