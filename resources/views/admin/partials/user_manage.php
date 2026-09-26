<?php
/**
 * 用户管理面板（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 形状照 resources/views/admin/partials/forum_form.php，只是这里把旧页面的四个
 * 标签页（基本资料 / 安全设置 / 用户组 / 危险操作）换成 layui 的 element tab：
 *
 *   <div class="layui-tab layui-tab-brief"> → <ul class="layui-tab-title"> + <div class="layui-tab-content">
 *
 * 每个子表单都是独立表单，各自 POST 到 /admin/users/update：
 *   基本资料  action=edit_profile      + 昵称颜色
 *   积分调整  action=adjust_credits    （独立表单，避免和资料表单的字段混在一起）
 *   安全设置  action=reset_password
 *   用户组    action=change_group
 *   危险操作  action=ban / unban / delete（按钮，走 AdminUi.confirmPost）
 * 字段名与 UserController::applyUserAction() 一一对应，一个都没改。
 *
 * 保存成功后统一 AdminUi.closeLayerAndReload('userTable')：先刷新外层列表，再关掉自己
 * （旧实现是靠后端 HX-Trigger 的 adminCloseModal 关弹窗，行为一致）。
 *
 * 变量：$user, $groups, $isSelf, $adminGroupId, $pageTitle
 */

$user         = is_array($user ?? null) ? $user : [];
$groups       = is_array($groups ?? null) ? $groups : [];
$isSelf       = (bool)($isSelf ?? false);
$adminGroupId = (int)($adminGroupId ?? 3);

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$uid         = (int)($user['id'] ?? 0);
$nickname    = (string)($user['nickname'] ?? '');
$displayName = $nickname !== '' ? $nickname : (string)($user['username'] ?? '');

$v = static function (string $key, string $default = '') use ($user): string {
    return htmlspecialchars((string)($user[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

$currentGroupId = (int)($user['group_id'] ?? 0);
$nicknameColor  = trim((string)($user['nickname_color'] ?? ''));

/** picker 只认 hex，空值/异常值不传给它 */
$pickerColor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $nicknameColor) ? $nicknameColor : '';

/** 预置色板只用 hex（layui 默认色板里有 rgb()/rgba()，会被 nickname_color 的格式校验丢掉） */
$predefineColors = [
    '#000000', '#333333', '#666666', '#999999', '#cccccc', '#ffffff',
    '#FF5722', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#1E9FFF',
    '#009688', '#16baaa', '#5FB878', '#8bc34a', '#FFB800', '#ff8c00',
];

/** 昵称颜色（纯文本），用于资料页顶部回显 */
$displayNameHtml = $nicknameColor !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $nicknameColor)
    ? '<span style="color:' . $e($nicknameColor) . '">' . $e($displayName) . '</span>'
    : $e($displayName);
?>
<blockquote class="layui-elem-quote">
  管理用户 - <?= $displayNameHtml ?> <span class="admin-muted">#<?= $uid ?></span>
</blockquote>

<div class="layui-tab layui-tab-brief" lay-filter="userManageTab">
  <ul class="layui-tab-title">
    <li class="layui-this">基本资料</li>
    <li>安全设置</li>
    <li>用户组</li>
    <?php if (!$isSelf): ?>
      <li>危险操作</li>
    <?php endif; ?>
  </ul>

  <div class="layui-tab-content">

    <!-- ==================== 基本资料 ==================== -->
    <div class="layui-tab-item layui-show">

      <form class="layui-form layuimini-form" lay-filter="userProfileForm" action="">
        <input type="hidden" name="action" value="edit_profile">
        <input type="hidden" name="user_id" value="<?= $uid ?>">

        <div class="layui-form-item">
          <label class="layui-form-label required">用户名</label>
          <div class="layui-input-block">
            <input type="text" name="username" class="layui-input" maxlength="20"
                   lay-verify="required" value="<?= $v('username') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">昵称</label>
          <div class="layui-input-block">
            <input type="text" name="nickname" class="layui-input" maxlength="20"
                   placeholder="可选" value="<?= $v('nickname') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label required">邮箱</label>
          <div class="layui-input-block">
            <input type="text" name="email" class="layui-input" maxlength="255"
                   lay-verify="required|email" value="<?= $v('email') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">个性签名</label>
          <div class="layui-input-block">
            <input type="text" name="signature" class="layui-input" maxlength="200"
                   value="<?= $v('signature') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">昵称颜色</label>
          <div class="layui-input-inline" style="width:200px">
            <input type="text" name="nickname_color" id="uNicknameColorText"
                   class="layui-input" maxlength="7"
                   placeholder="如 #FF0000，留空为默认" value="<?= $e($nicknameColor) ?>">
          </div>
          <div class="layui-input-inline" style="width:auto">
            <div id="uNicknameColorBox"></div>
          </div>
          <div class="layui-form-mid">
            <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="uColorClear">清除</button>
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">当前积分</label>
          <div class="layui-input-inline" style="width:160px">
            <input type="text" class="layui-input layui-disabled"
                   value="<?= number_format((int)($user['credits'] ?? 0)) ?>" disabled>
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-input-block">
            <button class="layui-btn" lay-submit lay-filter="userProfileSubmit">保存资料</button>
          </div>
        </div>
      </form>

      <!-- 积分调整（独立表单，避免和资料表单的字段混在一起） -->
      <blockquote class="layui-elem-quote layui-quote-nm" style="margin-top:16px">调整积分</blockquote>
      <form class="layui-form layuimini-form" lay-filter="userCreditForm" action="">
        <input type="hidden" name="action" value="adjust_credits">
        <input type="hidden" name="user_id" value="<?= $uid ?>">

        <div class="layui-form-item">
          <label class="layui-form-label">变动数量</label>
          <div class="layui-input-inline" style="width:140px">
            <input type="number" name="amount" class="layui-input" placeholder="正加负减">
          </div>
          <div class="layui-inline">
            <label class="layui-form-label">原因</label>
            <div class="layui-input-inline" style="width:200px">
              <input type="text" name="reason" class="layui-input" maxlength="100" value="管理员调整">
            </div>
          </div>
          <div class="layui-inline">
            <button class="layui-btn" lay-submit lay-filter="userCreditSubmit">确认调整</button>
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-input-block">
            <div class="layui-word-aux">填正数增加、负数扣减；扣减时若积分不足会失败。</div>
          </div>
        </div>
      </form>
    </div>

    <!-- ==================== 安全设置 ==================== -->
    <div class="layui-tab-item">
      <blockquote class="layui-elem-quote layui-quote-nm">
        重置后该用户的所有登录状态会失效，需要用新密码重新登录。
      </blockquote>

      <form class="layui-form layuimini-form" lay-filter="userPasswordForm" action="">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" value="<?= $uid ?>">

        <div class="layui-form-item">
          <label class="layui-form-label required">新密码</label>
          <div class="layui-input-inline" style="width:220px">
            <input type="password" name="new_password" class="layui-input"
                   lay-verify="required" placeholder="至少 6 位" autocomplete="new-password">
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-input-block">
            <button class="layui-btn layui-btn-warm" lay-submit lay-filter="userPasswordSubmit">重置密码</button>
          </div>
        </div>
      </form>

      <blockquote class="layui-elem-quote layui-quote-nm">登录信息</blockquote>
      <div class="layui-form-item">
        <div class="layui-input-block layui-word-aux" style="line-height:24px">
          <div>登录 IP：<?= $v('login_ip', '-') ?></div>
          <div>最后登录：<?= (int)($user['login_at'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$user['login_at']) : '从未登录' ?></div>
          <div>注册时间：<?= (int)($user['created_at'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$user['created_at']) : '-' ?></div>
          <div>帖子 / 回复：<?= (int)($user['thread_count'] ?? 0) ?> / <?= (int)($user['post_count'] ?? 0) ?></div>
        </div>
      </div>
    </div>

    <!-- ==================== 用户组 ==================== -->
    <div class="layui-tab-item">
      <form class="layui-form layuimini-form" lay-filter="userGroupForm" action="">
        <input type="hidden" name="action" value="change_group">
        <input type="hidden" name="user_id" value="<?= $uid ?>">

        <div class="layui-form-item">
          <label class="layui-form-label">用户组</label>
          <div class="layui-input-block">
            <?php foreach ($groups as $g): ?>
              <?php
                $gid   = (int)($g['id'] ?? 0);
                $gname = htmlspecialchars((string)($g['name'] ?? ''), ENT_QUOTES, 'UTF-8');
              ?>
              <input type="radio" name="group_id" value="<?= $gid ?>"
                     title="<?= $gname ?>" <?= $currentGroupId === $gid ? 'checked' : '' ?>>
              <?php if ($gid === $adminGroupId): ?>
                <span class="layui-badge">管理员</span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-input-block">
            <button class="layui-btn" lay-submit lay-filter="userGroupSubmit">保存用户组</button>
          </div>
        </div>
      </form>
    </div>

    <?php if (!$isSelf): ?>
      <!-- ==================== 危险操作 ==================== -->
      <div class="layui-tab-item">
        <blockquote class="layui-elem-quote layui-quote-nm">
          以下操作请谨慎执行。
        </blockquote>

        <div class="layui-form-item">
          <div class="layui-input-block">
            <button type="button" class="layui-btn layui-btn-warm" id="userBanBtn">封禁用户</button>
            <button type="button" class="layui-btn layui-btn-normal" id="userUnbanBtn">解封用户</button>
            <button type="button" class="layui-btn layui-btn-danger" id="userDeleteBtn">删除用户</button>
            <div class="layui-word-aux" style="margin-top:8px">
              「封禁」会把用户移到内置的「禁止用户组」，「解封」恢复为默认用户组。
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<div class="layui-form-item" style="margin-top:16px">
  <div class="layui-input-block">
    <button type="button" class="layui-btn layui-btn-primary" id="userManageClose">关闭</button>
  </div>
</div>

<script>
var USER_MANAGE_ID    = <?= (int)$uid ?>;
var NICKNAME_COLORS   = <?= json_encode($predefineColors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var USER_MANAGE_NAME  = <?= json_encode((string)$displayName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

layui.use(['form', 'element', 'colorpicker', 'util'], function () {
  var form        = layui.form;
  var colorpicker = layui.colorpicker;
  var util        = layui.util;
  var $           = layui.jquery;

  var API = '/admin/users/update';

  /** layer 的提示内容是当 HTML 渲染的，用户昵称要先转义 */
  var esc = function (s) { return util.escape(s == null ? '' : String(s)); };

  /** 保存成功后的收尾：先刷新外层列表，再关掉这个弹层 */
  function done() {
    AdminUi.closeLayerAndReload('userTable');
  }

  /** 提交一个子表单（data.field 里已经带了隐藏的 action / user_id） */
  function submit(field) {
    AdminUi.post(API, field, done);
  }

  // ---------------- 昵称颜色：picker ↔ 文本框 ----------------
  var $ncText = $('#uNicknameColorText');

  /** 只接受 #rrggbb：选择器里的「清空」按空处理，rgb()/rgba() 一律忽略 */
  function applyNickColor(color) {
    if (!color) { $ncText.val(''); return; }
    if (/^#[0-9a-fA-F]{3}$/.test(color)) {
      color = '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
    }
    if (/^#[0-9a-fA-F]{6}$/.test(color)) { $ncText.val(color); }
  }

  colorpicker.render({
    elem: '#uNicknameColorBox',
    color: <?= json_encode($pickerColor) ?>,
    predefine: true,
    colors: NICKNAME_COLORS,
    change: applyNickColor,
    done: applyNickColor
  });

  $('#uColorClear').on('click', function () { $ncText.val(''); });

  // ---------------- 子表单提交 ----------------
  form.on('submit(userProfileSubmit)', function (data) {
    submit(data.field);
    return false;
  });

  form.on('submit(userCreditSubmit)', function (data) {
    submit(data.field);
    return false;
  });

  form.on('submit(userGroupSubmit)', function (data) {
    submit(data.field);
    return false;
  });

  // 重置密码要二次确认（旧页面是 hx-confirm="确定要重置该用户的密码吗？"）
  form.on('submit(userPasswordSubmit)', function (data) {
    layui.layer.confirm('确定要重置该用户的密码吗？', function (idx) {
      layui.layer.close(idx);
      submit(data.field);
    });
    return false;
  });

  // ---------------- 危险操作 ----------------
  function danger(action, text) {
    AdminUi.confirmPost(text, API, { action: action, user_id: USER_MANAGE_ID }, done);
  }

  $('#userBanBtn').on('click', function () {
    danger('ban', '确定要封禁「' + esc(USER_MANAGE_NAME) + '」吗？');
  });

  $('#userUnbanBtn').on('click', function () {
    danger('unban', '确定要解封「' + esc(USER_MANAGE_NAME) + '」吗？');
  });

  $('#userDeleteBtn').on('click', function () {
    danger('delete', '确定要删除「' + esc(USER_MANAGE_NAME) + '」吗？此操作不可恢复！');
  });

  $('#userManageClose').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
