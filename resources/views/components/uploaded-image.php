<?php
/**
 * 已上传图片的缩略图（片段）
 *
 * 图片上传成功后由服务端回这一个片段：缩略图 + 「×」按钮 + 一个隐藏字段。
 * 这样表单不用维护任何前端数组——提交时浏览器自己把隐藏字段一起带上，
 * 点「×」把整个 wrapper 删掉，对应字段也就没了。
 *
 * 依赖变量：$url、$field（默认 images[]，即提交时的字段名）
 */
$field = $field ?? 'images[]';
?>
<div class="upload-thumb" data-upload-thumb>
    <img src="<?= htmlspecialchars($url) ?>" alt="">
    <input type="hidden" name="<?= htmlspecialchars($field) ?>" value="<?= htmlspecialchars($url) ?>">
    <button type="button" class="upload-thumb-remove" data-remove-thumb title="移除">&times;</button>
</div>
