<?php
/**
 * 用户组表单（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 形状照 resources/views/admin/partials/forum_form.php：
 *   <form class="layui-form layuimini-form"> → 字段 → 保存 + 取消
 *
 * 提交到 POST /admin/user-groups/save（新增 / 编辑同一个端点，靠隐藏域 id 区分），
 * 成功后 AdminUi.closeLayerAndReload('userGroupTable')。
 *
 * 12 个权限位**不需要** hidden 镜像：
 *   UserGroupController::userGroupSave() 是 `!empty($input[$field]) ? 1 : 0`，
 *   未勾选的 checkbox 浏览器本来就不提交，控制器把「缺失」当 0，语义正好一致。
 *   （forum_access.php 那种 hidden=0 + checkbox=1 的写法是给「缺失=放行」的接口用的。）
 *
 * is_admin 是单个布尔位，控制器读 `(int)($input['is_admin'] ?? 0)`，
 * 这里仍是 hidden=0 + checkbox=1 的标准写法：勾选时后者在 DOM 里靠后，
 * layui 的 form.getValue() 按 DOM 顺序覆盖，最终提交 "1"。
 *
 * 变量：$group（null 表示新增）, $isEdit, $permLabels, $pageTitle
 */

$isEdit     = (bool)($isEdit ?? false);
$g          = is_array($group ?? null) ? $group : [];
$permLabels = is_array($permLabels ?? null) ? $permLabels : [];

// 新增时的默认勾选：沿用旧版表单的 defaultChecked
$defaultOn = ['allow_read', 'allow_thread', 'allow_post', 'allow_attach', 'allow_down'];

$v = static function (string $key, string $default = '') use ($g): string {
    return htmlspecialchars((string)($g[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

/** 该权限位当前是否勾选 */
$checked = static function (string $field) use ($g, $isEdit, $defaultOn): bool {
    if (!$isEdit) {
        return in_array($field, $defaultOn, true);
    }

    return !empty($g[$field]);
};

$permJson = (string)($g['permissions'] ?? '');
if (trim($permJson) === '') {
    $permJson = '{}';
}
?>
<form class="layui-form layuimini-form" lay-filter="groupForm" action="">
  <input type="hidden" name="id" value="<?= (int)($g['id'] ?? 0) ?>">

  <div class="layui-form-item">
    <label class="layui-form-label required">组名</label>
    <div class="layui-input-block">
      <input type="text" name="name" class="layui-input" maxlength="32"
             lay-verify="required" placeholder="请输入组名" value="<?= $v('name') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">管理员权限</label>
    <div class="layui-input-block">
      <input type="hidden" name="is_admin" value="0">
      <input type="checkbox" name="is_admin" value="1" lay-skin="switch"
             lay-text="是|否" <?= !empty($g['is_admin']) ? 'checked' : '' ?>>
      <div class="layui-form-mid layui-word-aux">允许进入后台</div>
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">核心权限</label>
    <div class="layui-input-block">
      <?php foreach ($permLabels as $field => $label): ?>
        <input type="checkbox" name="<?= htmlspecialchars((string)$field, ENT_QUOTES, 'UTF-8') ?>"
               value="1" lay-skin="primary"
               title="<?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>"
               <?= $checked((string)$field) ? 'checked' : '' ?>>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="layui-form-item layui-form-text">
    <label class="layui-form-label">扩展权限</label>
    <div class="layui-input-block">
      <textarea name="permissions" class="layui-textarea"
                placeholder='{"thread.create":true}'><?= htmlspecialchars($permJson, ENT_QUOTES, 'UTF-8') ?></textarea>
      <div class="layui-word-aux">JSON 格式，留空表示无扩展权限，格式错误会拒绝保存。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block">
      <button class="layui-btn" lay-submit lay-filter="groupFormSubmit">保存</button>
      <button type="button" class="layui-btn layui-btn-primary" id="groupFormCancel">取消</button>
    </div>
  </div>
</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  // 权限勾选框是服务端渲染的，form 模块加载时会自动渲染一遍；
  // 这里再显式 render 一次，保证任何加载顺序下 checkbox / switch 都是 layui 样式。
  form.render();

  form.on('submit(groupFormSubmit)', function (data) {
    AdminUi.post('/admin/user-groups/save', data.field, function () {
      // 先刷新外层的用户组列表，再关掉这个弹层
      AdminUi.closeLayerAndReload('userGroupTable');
    });
    return false;   // 阻止 layui 默认的表单提交
  });

  $('#groupFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
