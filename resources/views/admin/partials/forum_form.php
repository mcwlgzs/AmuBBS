<?php
/**
 * 板块表单（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 这是「表单页」的参考实现，其余表单页（等级/用户组/公告/集群/导航/用户…）
 * 照这个形状写：
 *
 *   <form class="layui-form layuimini-form">   layui 表单
 *     <input type="hidden" name="id">          编辑时带上主键
 *     …字段，必填的加 lay-verify="required"…
 *     <button lay-submit lay-filter="xxxSubmit"> 保存
 *     <button id="xxxCancel">                    取消
 *   layui.use(['form']) → form.on('submit(xxxSubmit)') → AdminUi.post(...)
 *
 * 保存成功后调 AdminUi.closeLayerAndReload('列表页的表格 id')：
 * 先刷新外层列表的表格，再关掉自己 —— 这样用户看到的是最新数据。
 *
 * 变量：$forum（null = 新增）、$isEdit、$parents（上级板块选项）
 */

$forum   = is_array($forum ?? null) ? $forum : [];
$isEdit  = (bool)($isEdit ?? false);
$parents = is_array($parents ?? null) ? $parents : [];

$val = static function (string $key, string $default = '') use ($forum): string {
    return htmlspecialchars((string)($forum[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

$fid         = (int)($forum['id'] ?? 0);
$parentId    = (int)($forum['parent_id'] ?? 0);
$moderators  = htmlspecialchars((string)($forum['moderators_display'] ?? ''), ENT_QUOTES, 'UTF-8');
$action      = $isEdit ? '/admin/forums/update' : '/admin/forums/create';
?>
<form class="layui-form layuimini-form" lay-filter="forumForm" action="">

  <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= $fid ?>">
  <?php endif; ?>

  <div class="layui-form-item">
    <label class="layui-form-label required">板块名称</label>
    <div class="layui-input-block">
      <input type="text" name="name" class="layui-input" maxlength="50"
             lay-verify="required" placeholder="例如：技术讨论" value="<?= $val('name') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">描述</label>
    <div class="layui-input-block">
      <input type="text" name="description" class="layui-input" maxlength="200"
             placeholder="一句话说明这个板块是干什么的" value="<?= $val('description') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-inline">
      <label class="layui-form-label">上级板块</label>
      <div class="layui-input-inline">
        <select name="parent_id">
          <option value="0">顶级板块</option>
          <?php foreach ($parents as $p): ?>
            <?php $pid = (int)($p['id'] ?? 0); ?>
            <?php if ($pid === $fid) { continue; } // 不能把自己设成自己的上级 ?>
            <option value="<?= $pid ?>" <?= $pid === $parentId ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($p['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="layui-inline">
      <label class="layui-form-label">排序</label>
      <div class="layui-input-inline" style="width:100px">
        <input type="number" name="rank" class="layui-input" value="<?= (int)($forum['rank'] ?? 0) ?>">
      </div>
      <div class="layui-form-mid layui-word-aux">越大越靠前</div>
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">版主</label>
    <div class="layui-input-block">
      <input type="text" name="moderators" class="layui-input" maxlength="200"
             placeholder="用户名，多个用逗号分隔" value="<?= $moderators ?>">
    </div>
  </div>

  <div class="layui-form-item layui-form-text">
    <label class="layui-form-label">板块公告</label>
    <div class="layui-input-block">
      <textarea name="announcement" class="layui-textarea"
                placeholder="可选，显示在板块顶部"><?= $val('announcement') ?></textarea>
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">SEO 标题</label>
    <div class="layui-input-block">
      <input type="text" name="seo_title" class="layui-input" maxlength="100"
             placeholder="留空则使用板块名称" value="<?= $val('seo_title') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">SEO 关键词</label>
    <div class="layui-input-block">
      <input type="text" name="seo_keywords" class="layui-input" maxlength="200"
             placeholder="关键词1,关键词2" value="<?= $val('seo_keywords') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block">
      <button class="layui-btn" lay-submit lay-filter="forumFormSubmit">保存</button>
      <button type="button" class="layui-btn layui-btn-primary" id="forumFormCancel">取消</button>
    </div>
  </div>
</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  form.on('submit(forumFormSubmit)', function (data) {
    AdminUi.post('<?= $action ?>', data.field, function () {
      // 先刷新外层的板块列表，再关掉这个弹层
      AdminUi.closeLayerAndReload('forumTable');
    });
    return false;   // 阻止 layui 默认的表单提交
  });

  $('#forumFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
