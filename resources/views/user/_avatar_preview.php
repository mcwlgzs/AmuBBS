<?php
/**
 * 头像预览图（片段）
 *
 * 上传成功后服务端回这一个 <img>，htmx 用它替换掉旧的 #avatarPreview（outerHTML）。
 * 这样前端不用写任何「上传完再改 src」的 JS。
 *
 * 依赖变量：$avatarUrl
 */
?>
<img id="avatarPreview" src="<?= htmlspecialchars($avatarUrl) ?>" alt="头像" class="avatar-lg">
