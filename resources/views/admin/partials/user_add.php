<?php
/**
 * 添加用户（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 形状照 resources/views/admin/partials/forum_form.php：
 *   <form class="layui-form layuimini-form"> → 字段 → 提交按钮 + 取消按钮
 *
 * 提交到 POST /admin/users/update（隐藏域 action=add_user），
 * 成功后 AdminUi.closeLayerAndReload('userTable')：先刷新外层的用户列表，再关掉自己。
 *
 * 字段名与 UserController::addUser() 一一对应：
 *   username / nickname / email / password / group_id
 *
 * 变量：$groups, $pageTitle
 */

$groups = is_array($groups ?? null) ? $groups : [];
?>
<form class="layui-form layuimini-form" lay-filter="userAddForm" action="">
  <input type="hidden" name="action" value="add_user">

  <div class="layui-form-item">
    <label class="layui-form-label required">用户名</label>
    <div class="layui-input-block">
      <input type="text" name="username" class="layui-input" maxlength="20"
             lay-verify="required" placeholder="3-20 个字符" autocomplete="off">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">昵称</label>
    <div class="layui-input-block">
      <input type="text" name="nickname" class="layui-input" maxlength="20"
             placeholder="可选，2-20 个字符" autocomplete="off">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label required">邮箱</label>
    <div class="layui-input-block">
      <input type="text" name="email" class="layui-input" maxlength="255"
             lay-verify="required|email" placeholder="user@example.com" autocomplete="off">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label required">密码</label>
    <div class="layui-input-block">
      <input type="password" name="password" class="layui-input"
             lay-verify="required" placeholder="至少 6 位" autocomplete="new-password">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">用户组</label>
    <div class="layui-input-block">
      <select name="group_id">
        <?php foreach ($groups as $g): ?>
          <option value="<?= (int)($g['id'] ?? 0) ?>">
            <?= htmlspecialchars((string)($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block">
      <div class="layui-word-aux">初始积分为 0，可在创建后通过「管理 → 调整积分」发放。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block">
      <button class="layui-btn" lay-submit lay-filter="userAddFormSubmit">创建</button>
      <button type="button" class="layui-btn layui-btn-primary" id="userAddFormCancel">取消</button>
    </div>
  </div>
</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  form.on('submit(userAddFormSubmit)', function (data) {
    AdminUi.post('/admin/users/update', data.field, function () {
      // 先刷新外层的用户列表，再关掉这个弹层
      AdminUi.closeLayerAndReload('userTable');
    });
    return false;   // 阻止 layui 默认的表单提交
  });

  $('#userAddFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
