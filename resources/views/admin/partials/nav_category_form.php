<?php
/**
 * 导航分类表单（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 变量：$category（null 表示新增）, $isEdit
 *
 * 图标沿用项目惯例用 emoji（旧版挂的 EmojiPicker 在新后台里并没有加载，
 * 属于已失效的引用，这里换成 emoji 快捷选择，不依赖任何图标字体）。
 *
 * 保存成功后 AdminUi.closeLayerAndReload('navCategoryTable')：
 * 先刷新外层导航页的分类表，再关掉弹层。
 */

$isEdit   = $isEdit ?? false;
$c        = is_array($category ?? null) ? $category : [];
$action   = $isEdit ? '/admin/nav-categories/update' : '/admin/nav-categories/create';

$v = static function (string $key, string $default = '') use ($c): string {
    return htmlspecialchars((string)($c[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

$emojiChoices = ['📁', '🔗', '⭐', '🔥', '📌', '🎯', '🛠️', '📢', '💬', '🎮', '📚', '🎵', '🖼️', '🧭', '🚀', '💡'];
?>

<form class="layui-form" id="navCategoryForm" lay-filter="navCategoryForm" action="">

  <input type="hidden" name="id" value="<?= (int)($c['id'] ?? 0) ?>">

  <div class="layui-form-item">
    <label class="layui-form-label required">分类名称</label>
    <div class="layui-input-block">
      <input type="text" name="name" class="layui-input" maxlength="50"
             lay-verify="required" placeholder="请输入分类名称" value="<?= $v('name') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">图标</label>
    <div class="layui-input-block">
      <input type="text" name="icon" id="navCatIcon" class="layui-input" maxlength="8"
             placeholder="点击下方快捷选择" value="<?= $v('icon') ?>">
      <div style="margin-top:8px">
        <?php foreach ($emojiChoices as $e): ?>
          <button type="button" class="layui-btn layui-btn-primary layui-btn-xs admin-emoji-pick"
                  data-target="navCatIcon"
                  data-emoji="<?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?>"><?= $e ?></button>
        <?php endforeach; ?>
        <button type="button" class="layui-btn layui-btn-primary layui-btn-xs admin-emoji-pick admin-emoji-clear"
                data-target="navCatIcon">清空</button>
      </div>
      <div class="layui-word-aux" style="padding-left:0">分类图标，一般用 emoji。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">排序</label>
    <div class="layui-input-inline" style="width:140px">
      <input type="number" name="rank" class="layui-input" min="0" value="<?= (int)($c['rank'] ?? 0) ?>">
    </div>
    <div class="layui-form-mid layui-word-aux">数字越大越靠前</div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block" style="margin-left:0">
      <button class="layui-btn" lay-submit lay-filter="navCategoryFormSubmit">保存</button>
      <button type="button" class="layui-btn layui-btn-primary" id="navCategoryFormCancel">取消</button>
    </div>
  </div>

</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  // emoji 快捷选择（页面内自包含，不依赖后台全局脚本）
  $('.admin-emoji-pick').on('click', function () {
    var $btn = $(this);
    var $input = $('#' + $btn.data('target'));
    if (!$input.length) { return; }

    $input.val($btn.hasClass('admin-emoji-clear') ? '' : ($btn.data('emoji') || ''));
    $input.focus();
  });

  form.on('submit(navCategoryFormSubmit)', function () {
    AdminUi.post('<?= $action ?>', $('#navCategoryForm').serialize(), function () {
      AdminUi.closeLayerAndReload('navCategoryTable');
    });
    return false;
  });

  $('#navCategoryFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
