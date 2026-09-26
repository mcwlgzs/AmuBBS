<?php
/**
 * 首页公告条目（前 3 条常显区 + 「查看全部」折叠区共用）
 *
 * 公告现在只有「内容」这一个文本来源 —— 后台表单已经不再收集标题，
 * 所以这里直接显示 content（htmlspecialchars + CSS pre-wrap，换行按原样渲染）。
 * 老数据 content 为空时回退到 title，避免历史公告渲染成空条目。
 *
 * 注意：`.announcement-text` 用的是 `white-space: pre-wrap`（换行按原样渲染），
 * 所以下面这个 div 里**不能有模板缩进/换行**，否则它们会变成可见的空格，
 * 把正文从「原来标题的位置」推到一个新行、还缩进一大截。
 *
 * @var array $a 公告行：id / title / content / url / type
 */
$annText = trim((string)($a['content'] ?? ''));
if ($annText === '') {
    $annText = trim((string)($a['title'] ?? ''));
}
$annTypeClass = (int)$a['type'] === 2 ? 'ann-urgent' : ((int)$a['type'] === 1 ? 'ann-important' : '');
?>
<div class="announcement-item ann-id-<?= (int)$a['id'] ?> <?= $annTypeClass ?>" data-ann-item>
    <div class="announcement-icon">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </div>
    <div class="announcement-text"><?php if ($a['url']): ?><a href="<?= htmlspecialchars($a['url']) ?>" rel="noopener noreferrer"><?= htmlspecialchars($annText) ?></a><?php else: ?><?= htmlspecialchars($annText) ?><?php endif; ?></div>
    <button type="button" class="announcement-close" data-ann-hide="<?= (int)$a['id'] ?>" title="关闭公告">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
</div>
