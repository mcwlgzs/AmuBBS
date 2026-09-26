<?php
/**
 * 后台 - 用户设置（layuimini 子页面 / layui 标签页表单）
 *
 * 变量：$settings（键 => 值）, $groups
 *
 * 迁移自 Bootstrap + htmx，只用 layui 重写了外观与提交方式：
 *   1. 原来 7 个「卡片」合成 6 个 layui 标签页，每个字段一个不少；
 *   2. 开关改用 layui 的 lay-skin="switch"；同名的 hidden=0 伴生字段原样保留，
 *      未勾选时表单仍会提交 "0"，服务端据此把开关关掉（不再依赖前端 JS 补 false）；
 *   3. 提交走 form.on('submit(...)') + $(form).serialize() 后 POST 到 /admin/user-settings。
 *
 * ⚠️ 每一个 name= / value= / selected 条件都保持与旧版逐字一致。
 */

$settings = is_array($settings ?? null) ? $settings : [];
$groups   = is_array($groups ?? null) ? $groups : [];

/** 取设置值，保留旧版默认值 */
$sv = static function (string $key, string $default = '') use ($settings): string {
    return htmlspecialchars((string)($settings[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

/** 开关是否勾选（按旧版默认值） */
$on = static function (string $key, string $default) use ($settings): bool {
    return (($settings[$key] ?? $default) === '1');
};

/** 渲染一个 layui 开关（含隐藏的 =0 伴生字段，顺序：hidden 在前、checkbox 在后） */
$switch = static function (string $key, string $label, bool $checked, string $hint = ''): string {
    $id = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    $html  = '<div class="layui-form-item">' . "\n";
    $html .= '  <label class="layui-form-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>' . "\n";
    $html .= '  <div class="layui-input-block">' . "\n";
    $html .= '    <input type="hidden" name="' . $id . '" value="0">' . "\n";
    $html .= '    <input type="checkbox" name="' . $id . '" value="1" lay-skin="switch" lay-text="开启|关闭"'
           . ($checked ? ' checked' : '') . '>' . "\n";
    if ($hint !== '') {
        $html .= '    <div class="layui-form-mid layui-word-aux">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</div>' . "\n";
    }
    $html .= '  </div>' . "\n";
    $html .= '</div>';

    return $html;
};
?>

<form class="layui-form" id="userSettingsForm" lay-filter="userSettingsForm" action="">

  <div class="layui-tab layui-tab-brief" lay-filter="userSettingsTabs">
    <ul class="layui-tab-title">
      <li class="layui-this">注册设置</li>
      <li>用户名规则</li>
      <li>登录安全</li>
      <li>密码策略</li>
      <li>头像设置</li>
      <li>个人资料</li>
    </ul>

    <div class="layui-tab-content">

      <!-- ============ 注册设置 ============ -->
      <div class="layui-tab-item layui-show">

        <blockquote class="layui-elem-quote layui-quote-nm">控制注册开关、验证与新用户的默认归属。</blockquote>

        <?= $switch('user_register_enabled', '开放注册', $on('user_register_enabled', '1'), '关闭后新用户将无法注册') ?>
        <?= $switch('user_register_verify', '邮箱验证', $on('user_register_verify', '0'), '开启后注册需要验证邮箱（需先配置 SMTP）') ?>

        <div class="layui-form-item">
          <label class="layui-form-label">默认用户组</label>
          <div class="layui-input-block">
            <select id="userDefaultGroup" name="user_default_group">
              <?php foreach ($groups as $g): ?>
                <?php $gid = (int)($g['id'] ?? 0); ?>
                <option value="<?= $gid ?>" <?= ((string)($settings['user_default_group'] ?? '1') === (string)$gid) ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string)($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="layui-word-aux" style="padding-left:0">新注册用户默认分配的用户组。</div>
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">默认积分</label>
          <div class="layui-input-inline" style="width:160px">
            <input type="number" id="userDefaultCredits" name="user_default_credits" class="layui-input"
                   min="0" max="99999" value="<?= $sv('user_default_credits', '0') ?>">
          </div>
          <div class="layui-form-mid layui-word-aux">注册成功后赠送的初始积分</div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">禁止用户名</label>
          <div class="layui-input-block">
            <input type="text" id="userBannedUsernames" name="user_banned_usernames" class="layui-input"
                   maxlength="255" value="<?= $sv('user_banned_usernames', 'admin,test,root') ?>"
                   placeholder="admin,test,root">
            <div class="layui-word-aux" style="padding-left:0">用逗号分隔，这些用户名不允许注册。</div>
          </div>
        </div>

      </div>

      <!-- ============ 用户名规则 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">用户名长度与改名权限。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">最小长度</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" id="userUsernameMinLength" name="user_username_min_length" class="layui-input"
                   min="2" max="20" value="<?= $sv('user_username_min_length', '3') ?>">
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">最大长度</label>
            <div class="layui-input-inline" style="width:120px">
              <input type="number" id="userUsernameMaxLength" name="user_username_max_length" class="layui-input"
                     min="5" max="30" value="<?= $sv('user_username_max_length', '20') ?>">
            </div>
            <div class="layui-form-mid layui-word-aux">最小长度不能大于最大长度</div>
          </div>
        </div>

        <?= $switch('user_allow_rename', '允许改名', $on('user_allow_rename', '0'), '用户可自行修改用户名') ?>

      </div>

      <!-- ============ 登录安全 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">
          连续登录失败达到上限后，账号将被临时锁定。
        </blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">最大尝试次数</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" id="userLoginMaxAttempts" name="user_login_max_attempts" class="layui-input"
                   min="3" max="20" value="<?= $sv('user_login_max_attempts', '5') ?>">
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">锁定时间（分钟）</label>
            <div class="layui-input-inline" style="width:120px">
              <input type="number" id="userLoginLockMinutes" name="user_login_lock_minutes" class="layui-input"
                     min="1" max="1440" value="<?= $sv('user_login_lock_minutes', '30') ?>">
            </div>
          </div>
        </div>

      </div>

      <!-- ============ 密码策略 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">密码强度要求。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">最小长度</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" id="userPasswordMinLength" name="user_password_min_length" class="layui-input"
                   min="4" max="32" value="<?= $sv('user_password_min_length', '6') ?>">
          </div>
        </div>

        <?= $switch('user_password_require_mixed', '混合字符', $on('user_password_require_mixed', '0'), '密码必须同时包含字母和数字') ?>

      </div>

      <!-- ============ 头像设置 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">头像上传限制与默认头像。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">大小限制（KB）</label>
          <div class="layui-input-inline" style="width:140px">
            <input type="number" id="userAvatarMaxSize" name="user_avatar_max_size" class="layui-input"
                   min="64" max="10240" value="<?= $sv('user_avatar_max_size', '2048') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">图片格式</label>
          <div class="layui-input-inline" style="width:260px">
            <input type="text" id="userAvatarFormats" name="user_avatar_formats" class="layui-input"
                   maxlength="255" value="<?= $sv('user_avatar_formats', 'jpg,jpeg,png,gif,webp') ?>">
          </div>
          <div class="layui-form-mid layui-word-aux">用逗号分隔</div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">默认头像</label>
          <div class="layui-input-block">
            <input type="text" id="userDefaultAvatar" name="user_default_avatar" class="layui-input"
                   maxlength="500" value="<?= $sv('user_default_avatar', '') ?>"
                   placeholder="/assets/images/default-avatar.png">
          </div>
        </div>

      </div>

      <!-- ============ 个人资料 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">签名 / 简介长度，以及资料的展示与修改权限。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">签名长度</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" id="userSignatureMaxLength" name="user_signature_max_length" class="layui-input"
                   min="0" max="500" value="<?= $sv('user_signature_max_length', '100') ?>">
          </div>
          <div class="layui-form-mid layui-word-aux">0 表示禁止设置签名</div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">简介长度</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" id="userBioMaxLength" name="user_bio_max_length" class="layui-input"
                   min="0" max="500" value="<?= $sv('user_bio_max_length', '200') ?>">
          </div>
          <div class="layui-form-mid layui-word-aux">0 表示禁止填写简介</div>
        </div>

        <blockquote class="layui-elem-quote layui-quote-nm">展示与修改</blockquote>

        <?= $switch('user_allow_change_email', '修改邮箱', $on('user_allow_change_email', '1'), '允许用户修改绑定邮箱') ?>
        <?= $switch('user_show_email', '展示邮箱', $on('user_show_email', '0'), '在用户主页展示邮箱') ?>
        <?= $switch('user_show_login_ip', '展示登录 IP', $on('user_show_login_ip', '0'), '在用户主页展示最后登录 IP') ?>

      </div>

    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block" style="margin-left:0">
      <button class="layui-btn" lay-submit lay-filter="userSettingsSubmit">保存设置</button>
      <span class="layui-word-aux">修改后立即对全站生效</span>
    </div>
  </div>

</form>

<script>
layui.use(['form', 'element'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  // 保存：serialize() 保留「同名 hidden + checkbox」两条记录
  form.on('submit(userSettingsSubmit)', function () {
    AdminUi.post('/admin/user-settings', $('#userSettingsForm').serialize());
    return false;
  });
});
</script>
