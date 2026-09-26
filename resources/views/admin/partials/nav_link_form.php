<?php
/**
 * 导航链接表单（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 变量：$link（null 表示新增）, $isEdit, $categories, $selectedCategory
 *
 * ?category_id=N 用于新增时预选分类（编辑时以链接自身的分类为准）。
 *
 * 保存成功后 AdminUi.closeLayerAndReload('navLinkTable<分类 id>')：
 * 先刷新外层导航页里该分类的链接表，再关掉弹层。
 */

$isEdit     = $isEdit ?? false;
$l          = is_array($link ?? null) ? $link : [];
$categories = is_array($categories ?? null) ? $categories : [];
$selected   = (int)($selectedCategory ?? 0);

$action = $isEdit ? '/admin/nav-links/update' : '/admin/nav-links/create';
$reloadTableId = 'navLinkTable' . $selected;

$v = static function (string $key, string $default = '') use ($l): string {
    return htmlspecialchars((string)($l[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};
?>

<form class="layui-form" id="navLinkForm" lay-filter="navLinkForm" action="">

  <input type="hidden" name="id" value="<?= (int)($l['id'] ?? 0) ?>">

  <div class="layui-form-item">
    <label class="layui-form-label required">所属分类</label>
    <div class="layui-input-block">
      <select name="category_id" lay-verify="required">
        <option value="">请选择分类</option>
        <?php foreach ($categories as $cat): ?>
          <?php $cid = (int)($cat['id'] ?? 0); ?>
          <option value="<?= $cid ?>" <?= $selected === $cid ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label required">链接名称</label>
    <div class="layui-input-block">
      <input type="text" name="name" class="layui-input" maxlength="50"
             lay-verify="required" placeholder="请输入链接名称" value="<?= $v('name') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label required">URL</label>
    <div class="layui-input-block">
      <input type="text" name="url" class="layui-input" maxlength="500"
             lay-verify="required|url" placeholder="https://" value="<?= $v('url') ?>">
      <div class="layui-word-aux" style="padding-left:0">必须以 http:// 或 https:// 开头。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">描述</label>
    <div class="layui-input-block">
      <input type="text" name="description" class="layui-input" maxlength="255"
             placeholder="简短描述（可选）" value="<?= $v('description') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">图标 URL</label>
    <div class="layui-input-block">
      <input type="text" name="icon" class="layui-input" maxlength="500"
             placeholder="图标图片地址（可选）" value="<?= $v('icon') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">排序</label>
    <div class="layui-input-inline" style="width:140px">
      <input type="number" name="rank" class="layui-input" min="0" value="<?= (int)($l['rank'] ?? 0) ?>">
    </div>
    <div class="layui-form-mid layui-word-aux">数字越大越靠前</div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block" style="margin-left:0">
      <button class="layui-btn" lay-submit lay-filter="navLinkFormSubmit">保存</button>
      <button type="button" class="layui-btn layui-btn-primary" id="navLinkFormCancel">取消</button>
    </div>
  </div>

</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  form.on('submit(navLinkFormSubmit)', function () {
    AdminUi.post('<?= $action ?>', $('#navLinkForm').serialize(), function () {
      // 刷新外层该分类的链接表（原表单若改了分类，刷新的是原分类，重新打开即可看到新归属）
      AdminUi.closeLayerAndReload('<?= $reloadTableId ?>');
    });
    return false;
  });

  $('#navLinkFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
