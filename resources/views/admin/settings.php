<?php
/**
 * 后台 - 系统设置（layuimini 子页面 / layui 标签页表单）
 *
 * 变量：$settings, $currentIp, $captchaScenes
 *
 * 与旧版（Bootstrap + htmx）的区别只在「外观 + 提交方式」：
 *   1. 标签页改用 layui 的 element 标签（layui-tab-brief），六个页签一个不少；
 *   2. 开关改用 layui 的 lay-skin="switch"，隐藏的 =0 伴生字段原样保留；
 *   3. 提交走 form.on('submit(...)') + $(form).serialize() 后 POST 到 /admin/settings，
 *      由 AdminUi.post 统一带 CSRF 并处理 {code,msg}。
 *
 * ⚠️ 字段契约：每一个 name= / value= / checked 条件都必须与旧版逐字一致 ——
 *    这些字段直接写进站点配置，改名就等于静默丢数据。
 *    本页面共 52 个 name= 属性（其中 4 个是社交登录循环模板里的 2 个，
 *    4 个 captcha_scene_* 由循环展开），迁移前后数量必须相同。
 *
 * 开关字段（12 个）：hidden=0 + checkbox=1 成对出现，未勾选也会提交 "0"，
 * 服务端据此把开关关掉，不再依赖前端 JS 补 false。
 */

$settings = is_array($settings ?? null) ? $settings : [];
$currentIp = (string)($currentIp ?? '');
$captchaScenes = is_array($captchaScenes ?? null) ? $captchaScenes : ['register', 'login', 'thread', 'reply'];

/** 取设置值（保留旧版默认值） */
$sv = static function (string $key, string $default = '') use ($settings): string {
    return htmlspecialchars((string)($settings[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

/** 开关是否勾选（按旧版默认值） */
$on = static function (string $key, string $default) use ($settings): bool {
    return (($settings[$key] ?? $default) === '1');
};

/** 当前选中的值 */
$sel = static function (string $key, string $default) use ($settings): string {
    return (string)($settings[$key] ?? $default);
};

$sceneLabels = ['register' => '注册', 'login' => '登录', 'thread' => '发帖', 'reply' => '回复'];
$activeScenes = array_map('trim', explode(',', (string)($settings['captcha_scenes'] ?? '')));
$captchaOn = $on('captcha_enabled', '0');
$watermarkOn = $on('watermark_enabled', '0');

/**
 * 渲染一个 layui 开关（含隐藏的 =0 伴生字段）
 *
 * 顺序固定为 hidden 在前、checkbox 在后：后端用 !empty($input[$key]) 取「最后一个」，
 * 勾选时 checkbox 的 1 覆盖 hidden 的 0，未勾选时只剩 hidden 的 0。
 */
$switch = static function (string $key, string $label, bool $checked, string $hint = '', string $onText = '开启|关闭') use ($sv): string {
    $id = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    $html  = '<div class="layui-form-item">' . "\n";
    $html .= '  <label class="layui-form-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>' . "\n";
    $html .= '  <div class="layui-input-block">' . "\n";
    $html .= '    <input type="hidden" name="' . $id . '" value="0">' . "\n";
    $html .= '    <input type="checkbox" name="' . $id . '" value="1" lay-skin="switch" lay-text="'
           . htmlspecialchars($onText, ENT_QUOTES, 'UTF-8') . '"'
           . ($checked ? ' checked' : '') . '>' . "\n";
    if ($hint !== '') {
        $html .= '    <div class="layui-form-mid layui-word-aux">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</div>' . "\n";
    }
    $html .= '  </div>' . "\n";
    $html .= '</div>';

    return $html;
};

/** 运行级别下拉的选项（值 → 文案），顺序与旧版一致 */
$runlevelOptions = [
    '5' => '完全开放（所有人可读写）',
    '4' => '所有人只读（游客可浏览，禁止发帖回复）',
    '3' => '仅注册用户可读写（游客不可访问）',
    '2' => '注册用户只读（禁止发帖回复）',
    '1' => '仅管理员（维护模式）',
    '0' => '关站维护（所有人不可访问）',
];

$wmPositions = [
    'bottom-right' => '右下角',
    'bottom-left'  => '左下角',
    'top-right'    => '右上角',
    'top-left'     => '左上角',
    'center'       => '居中',
];

$socialProviders = [
    'github' => ['label' => 'GitHub 登录', 'id' => 'social_login_github_client_id', 'secret' => 'social_login_github_client_secret', 'idLabel' => 'Client ID', 'secretLabel' => 'Client Secret', 'default' => '0'],
    'google' => ['label' => 'Google 登录', 'id' => 'social_login_google_client_id', 'secret' => 'social_login_google_client_secret', 'idLabel' => 'Client ID', 'secretLabel' => 'Client Secret', 'default' => '0'],
    'wechat' => ['label' => '微信登录', 'id' => 'social_login_wechat_app_id', 'secret' => 'social_login_wechat_app_secret', 'idLabel' => 'App ID', 'secretLabel' => 'App Secret', 'default' => '0'],
    'qq'     => ['label' => 'QQ 登录', 'id' => 'social_login_qq_app_id', 'secret' => 'social_login_qq_app_key', 'idLabel' => 'App ID', 'secretLabel' => 'App Key', 'default' => '0'],
];
?>

<form class="layui-form" id="settingsForm" lay-filter="settingsForm" action="">

  <div class="layui-tab layui-tab-brief" lay-filter="settingsTabs">
    <ul class="layui-tab-title">
      <li class="layui-this">基本设置</li>
      <li>安全设置</li>
      <li>上传设置</li>
      <li>邮件设置</li>
      <li>防灌水</li>
      <li>功能设置</li>
    </ul>

    <div class="layui-tab-content">

      <!-- ============ 基本设置 ============ -->
      <div class="layui-tab-item layui-show">

        <blockquote class="layui-elem-quote layui-quote-nm">站点名称、地址与运行状态。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">站点名称</label>
          <div class="layui-input-block">
            <input type="text" name="site_name" class="layui-input" maxlength="100" value="<?= $sv('site_name') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">站点 URL</label>
          <div class="layui-input-block">
            <input type="text" name="site_url" class="layui-input" maxlength="255"
                   placeholder="http://localhost:8000" value="<?= $sv('site_url') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">站点描述</label>
          <div class="layui-input-block">
            <input type="text" name="site_description" class="layui-input" maxlength="255" value="<?= $sv('site_description') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">站点关键词</label>
          <div class="layui-input-block">
            <input type="text" name="site_keywords" class="layui-input" maxlength="255"
                   placeholder="用逗号分隔" value="<?= $sv('site_keywords') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">ICP 备案号</label>
          <div class="layui-input-block">
            <input type="text" name="icp_number" class="layui-input" maxlength="100"
                   placeholder="可留空" value="<?= $sv('icp_number') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">CDN 地址</label>
          <div class="layui-input-block">
            <input type="text" name="cdn_url" class="layui-input" maxlength="255"
                   placeholder="如 https://cdn.example.com（留空不启用）" value="<?= $sv('cdn_url') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">运行级别</label>
          <div class="layui-input-block">
            <?php $runlevel = $sel('site_runlevel', '5'); ?>
            <select name="site_runlevel">
              <?php foreach ($runlevelOptions as $val => $label): ?>
                <option value="<?= $val ?>" <?= $runlevel === (string)$val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">维护提示</label>
          <div class="layui-input-block">
            <input type="text" name="site_maintenance_msg" class="layui-input" maxlength="255"
                   placeholder="站点维护中，请稍后再访问..." value="<?= $sv('site_maintenance_msg') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">每页主题数</label>
            <div class="layui-input-inline">
              <input type="number" name="threads_per_page" class="layui-input"
                     min="5" max="100" value="<?= $sv('threads_per_page', '20') ?>">
            </div>
            <div class="layui-form-mid layui-word-aux">5 - 100</div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">公告隐藏时长</label>
            <div class="layui-input-inline" style="width:110px">
              <input type="number" name="announcement_hide_duration" class="layui-input"
                     min="0" value="<?= $sv('announcement_hide_duration', '60') ?>">
            </div>
            <div class="layui-form-mid layui-word-aux">分钟，0 表示刷新后立即恢复</div>
          </div>
        </div>

        <?= $switch('url_html_suffix', 'URL .html 后缀', $on('url_html_suffix', '1'), '支持 .html 后缀访问（如 /thread/1.html）') ?>

      </div>

      <!-- ============ 安全设置 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">访问控制与验证码。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">IP 白名单</label>
          <div class="layui-input-block">
            <input type="text" name="admin_bind_ip" class="layui-input" maxlength="255"
                   placeholder="留空不限制，多个 IP 用逗号分隔" value="<?= $sv('admin_bind_ip') ?>">
            <div class="layui-word-aux" style="padding-left:0">当前 IP：<?= htmlspecialchars($currentIp, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">定时任务密钥</label>
          <div class="layui-input-block">
            <input type="text" name="cron_key" class="layui-input" maxlength="100"
                   placeholder="留空不验证" value="<?= $sv('cron_key') ?>">
            <div class="layui-word-aux" style="padding-left:0">
              访问 <span class="layui-badge-rim">/cron/run?key=密钥</span> 触发定时任务。
            </div>
          </div>
        </div>

        <?= $switch('captcha_enabled', '启用验证码', $captchaOn) ?>

        <div id="captchaFields"<?= $captchaOn ? '' : ' style="display:none"' ?>>

          <div class="layui-form-item">
            <label class="layui-form-label">验证码类型</label>
            <div class="layui-input-block">
              <?php $captchaType = $sel('captcha_type', 'numeric'); ?>
              <select name="captcha_type">
                <option value="numeric" <?= $captchaType === 'numeric' ? 'selected' : '' ?>>数字验证码</option>
                <option value="alpha" <?= $captchaType === 'alpha' ? 'selected' : '' ?>>字母数字验证码</option>
                <option value="slider" <?= $captchaType === 'slider' ? 'selected' : '' ?>>滑动拼图验证</option>
              </select>
            </div>
          </div>

          <div class="layui-form-item">
            <label class="layui-form-label">启用场景</label>
            <div class="layui-input-block">
              <?php foreach ($sceneLabels as $scene => $label): ?>
                <input type="checkbox" name="captcha_scene_<?= $scene ?>" value="1" lay-skin="primary"
                       title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                       <?= in_array($scene, $activeScenes, true) ? 'checked' : '' ?>>
              <?php endforeach; ?>
              <div class="layui-word-aux" style="padding-left:0">未勾选的场景不校验验证码。</div>
            </div>
          </div>

        </div>

        <blockquote class="layui-elem-quote layui-quote-nm">
          频率限制：次数填 0 = 不限制；严格档用于登录/注册等敏感操作。
        </blockquote>

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">全局</label>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="rate_limit_global_max" class="layui-input"
                     min="0" value="<?= $sv('rate_limit_global_max', '60') ?>">
            </div>
            <div class="layui-form-mid">次 /</div>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="rate_limit_global_window" class="layui-input"
                     min="1" value="<?= $sv('rate_limit_global_window', '60') ?>">
            </div>
            <div class="layui-form-mid layui-word-aux">秒</div>
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">严格</label>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="rate_limit_strict_max" class="layui-input"
                     min="0" value="<?= $sv('rate_limit_strict_max', '5') ?>">
            </div>
            <div class="layui-form-mid">次 /</div>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="rate_limit_strict_window" class="layui-input"
                     min="1" value="<?= $sv('rate_limit_strict_window', '300') ?>">
            </div>
            <div class="layui-form-mid layui-word-aux">秒（登录/注册等敏感操作）</div>
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">搜索</label>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="rate_limit_search_max" class="layui-input"
                     min="0" value="<?= $sv('rate_limit_search_max', '10') ?>">
            </div>
            <div class="layui-form-mid">次 /</div>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="rate_limit_search_window" class="layui-input"
                     min="1" value="<?= $sv('rate_limit_search_window', '60') ?>">
            </div>
            <div class="layui-form-mid layui-word-aux">秒</div>
          </div>
        </div>

      </div>

      <!-- ============ 上传设置 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">图片处理与图片水印。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">最大宽度（px）</label>
          <div class="layui-input-inline" style="width:140px">
            <input type="number" name="image_max_width" class="layui-input"
                   min="0" value="<?= $sv('image_max_width', '1920') ?>">
          </div>
          <div class="layui-form-mid layui-word-aux">0 表示不限制</div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">缩略图宽度（px）</label>
          <div class="layui-input-inline" style="width:140px">
            <input type="number" name="image_thumb_width" class="layui-input"
                   min="0" value="<?= $sv('image_thumb_width', '400') ?>">
          </div>
          <div class="layui-form-mid layui-word-aux">0 表示不生成</div>
        </div>

        <?= $switch('watermark_enabled', '启用水印', $watermarkOn) ?>

        <div id="watermarkFields"<?= $watermarkOn ? '' : ' style="display:none"' ?>>

          <div class="layui-form-item">
            <label class="layui-form-label">水印文字</label>
            <div class="layui-input-inline" style="width:200px">
              <input type="text" name="watermark_text" class="layui-input"
                     maxlength="50" value="<?= $sv('watermark_text', 'AMuBBS') ?>">
            </div>

            <div class="layui-inline">
              <label class="layui-form-label">水印位置</label>
              <div class="layui-input-inline">
                <?php $wmPos = $sel('watermark_position', 'bottom-right'); ?>
                <select name="watermark_position">
                  <?php foreach ($wmPositions as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $wmPos === $val ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="layui-inline">
              <label class="layui-form-label">透明度</label>
              <div class="layui-input-inline" style="width:100px">
                <input type="number" name="watermark_opacity" class="layui-input"
                       min="0" max="100" value="<?= $sv('watermark_opacity', '30') ?>">
              </div>
              <div class="layui-form-mid layui-word-aux">0 - 100</div>
            </div>
          </div>

        </div>

      </div>

      <!-- ============ 邮件设置 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">SMTP 发信配置。</blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">SMTP 服务器</label>
          <div class="layui-input-block">
            <input type="text" name="smtp_host" class="layui-input" maxlength="255"
                   placeholder="如 smtp.qq.com" value="<?= $sv('smtp_host') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">端口</label>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="smtp_port" class="layui-input"
                     min="1" max="65535" value="<?= $sv('smtp_port', '465') ?>">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">加密方式</label>
            <div class="layui-input-inline" style="width:230px">
              <?php $smtpEnc = $sel('smtp_encryption', 'ssl'); ?>
              <select name="smtp_encryption">
                <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL（推荐，端口 465）</option>
                <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS（端口 587）</option>
                <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>无加密（端口 25）</option>
              </select>
            </div>
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">SMTP 用户名</label>
          <div class="layui-input-block">
            <input type="text" name="smtp_user" class="layui-input" maxlength="255"
                   placeholder="通常是邮箱地址" value="<?= $sv('smtp_user') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">SMTP 密码</label>
          <div class="layui-input-block">
            <input type="password" name="smtp_pass" class="layui-input" lay-ignore
                   autocomplete="new-password" placeholder="QQ 邮箱请使用授权码"
                   value="<?= $sv('smtp_pass') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">发件人地址</label>
          <div class="layui-input-block">
            <input type="text" name="smtp_from" class="layui-input" maxlength="255"
                   placeholder="留空则使用 SMTP 用户名" value="<?= $sv('smtp_from') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">发件人名称</label>
          <div class="layui-input-block">
            <input type="text" name="smtp_from_name" class="layui-input" maxlength="100"
                   value="<?= $sv('smtp_from_name', 'AMuBBS') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <div class="layui-input-block">
            <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="settingsTestEmail">
              发送测试邮件
            </button>
            <div class="layui-word-aux" style="padding-left:0">会按上方填写的配置直接发送，请先保存再测试。</div>
          </div>
        </div>

      </div>

      <!-- ============ 防灌水 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">
          限制同一 IP 每日操作次数，填 0 表示不限制。
        </blockquote>

        <div class="layui-form-item">
          <label class="layui-form-label">发帖上限</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" name="ip_limit_thread" class="layui-input"
                   min="0" value="<?= $sv('ip_limit_thread', '20') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">回帖上限</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" name="ip_limit_post" class="layui-input"
                   min="0" value="<?= $sv('ip_limit_post', '50') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">注册上限</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" name="ip_limit_register" class="layui-input"
                   min="0" value="<?= $sv('ip_limit_register', '5') ?>">
          </div>
        </div>

        <div class="layui-form-item">
          <label class="layui-form-label">上传上限</label>
          <div class="layui-input-inline" style="width:120px">
            <input type="number" name="ip_limit_upload" class="layui-input"
                   min="0" value="<?= $sv('ip_limit_upload', '30') ?>">
          </div>
        </div>

      </div>

      <!-- ============ 功能设置 ============ -->
      <div class="layui-tab-item">

        <blockquote class="layui-elem-quote layui-quote-nm">自动头像与 Emoji 表情。</blockquote>

        <?= $switch('auto_avatar_enabled', '启用自动头像', $on('auto_avatar_enabled', '1'), '用户注册时自动分配随机头像') ?>
        <?= $switch('auto_avatar_overwrite', '覆盖已有头像', $on('auto_avatar_overwrite', '0'), '是否覆盖用户已有的自定义头像') ?>
        <?= $switch('emoji_enabled', '启用 Emoji', $on('emoji_enabled', '1'), '将 :smile: 等短代码转换为 Emoji 表情') ?>

        <blockquote class="layui-elem-quote layui-quote-nm">社交登录：启用后需要填写对应的应用凭据。</blockquote>

        <?php foreach ($socialProviders as $key => $p): ?>
          <?php $enabled = $on('social_login_' . $key . '_enabled', $p['default']); ?>

          <?= $switch('social_login_' . $key . '_enabled', $p['label'], $enabled) ?>

          <div id="<?= $key ?>Fields" class="layui-form-item"<?= $enabled ? '' : ' style="display:none"' ?>>
            <div class="layui-inline">
              <label class="layui-form-label"><?= htmlspecialchars($p['idLabel'], ENT_QUOTES, 'UTF-8') ?></label>
              <div class="layui-input-inline" style="width:260px">
                <input type="text" name="<?= $p['id'] ?>" class="layui-input"
                       maxlength="255" value="<?= $sv($p['id']) ?>">
              </div>
            </div>

            <div class="layui-inline">
              <label class="layui-form-label"><?= htmlspecialchars($p['secretLabel'], ENT_QUOTES, 'UTF-8') ?></label>
              <div class="layui-input-inline" style="width:260px">
                <input type="password" name="<?= $p['secret'] ?>" class="layui-input" lay-ignore
                       maxlength="255" autocomplete="new-password" value="<?= $sv($p['secret']) ?>">
              </div>
            </div>
          </div>
        <?php endforeach; ?>

      </div>

    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block" style="margin-left:0">
      <button class="layui-btn" lay-submit lay-filter="settingsSubmit">保存设置</button>
      <span class="layui-word-aux">保存后立即生效，缓存会自动清理</span>
    </div>
  </div>

</form>

<script>
layui.use(['form', 'element'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  // 开关联动：勾选 / 取消勾选时显示或隐藏它控制的字段区块。
  // 用通配的 'switch' 事件再按 name 分派 —— 这些开关没有 lay-filter，
  // 绑 'switch(具体名字)' 永远不会触发。
  var toggles = {
    captcha_enabled: '#captchaFields',
    watermark_enabled: '#watermarkFields',
    <?php foreach (array_keys($socialProviders) as $key): ?>
    'social_login_<?= $key ?>_enabled': '#<?= $key ?>Fields',
    <?php endforeach; ?>
  };

  form.on('switch', function (data) {
    var name = data.elem && data.elem.name;
    if (!name || !toggles[name]) { return; }

    $(toggles[name]).toggle(data.elem.checked);
  });

  // 保存：serialize() 而不是 data.field —— 表单里有大量「同名 hidden + checkbox」，
  // serialize() 会把两条都发出去，交给 PHP 解析成最后一个值，行为与旧版一致。
  form.on('submit(settingsSubmit)', function () {
    AdminUi.post('/admin/settings', $('#settingsForm').serialize(), function () {
      // 整页配置表单，保存后不关弹层、不刷新表格，只给提示
    });
    return false;
  });

  // 发送测试邮件：只探测、不改数据，因此只弹提示
  $('#settingsTestEmail').on('click', function () {
    $.post('/admin/settings/test-email', $('#settingsForm').serialize(), function (res) {
      if (res && res.code === 0) {
        AdminUi.ok(res.msg || '测试邮件已发送');
      } else {
        AdminUi.fail((res && (res.msg || res.message)) || '邮件发送失败');
      }
    }, 'json').fail(function (xhr) {
      var msg = '邮件发送失败';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
      AdminUi.fail(msg);
    });
  });
});
</script>
