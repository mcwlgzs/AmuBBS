<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header">用户设置</div>
    <div class="layui-card-body">

        <form class="layui-form" lay-filter="userSettingsForm" style="max-width:600px;">

            <!-- 注册设置 -->
            <fieldset class="layui-elem-field">
                <legend>注册设置</legend>
                <div class="layui-field-box">
                    <div class="layui-form-item">
                        <label class="layui-form-label">开放注册</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="user_register_enabled" lay-skin="switch" lay-text="开|关" <?= ($settings['user_register_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">关闭后新用户将无法注册</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">邮箱验证</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="user_register_verify" lay-skin="switch" lay-text="开|关" <?= ($settings['user_register_verify'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">开启后注册需要验证邮箱（需先配置 SMTP）</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">默认用户组</label>
                        <div class="layui-input-block">
                            <select name="user_default_group">
                                <?php foreach ($groups as $g): ?>
                                <option value="<?= (int)$g['id'] ?>" <?= ($settings['user_default_group'] ?? '1') == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="layui-form-mid layui-word-aux">新注册用户默认分配的用户组</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">默认积分</label>
                        <div class="layui-input-block">
                            <input type="number" name="user_default_credits" class="layui-input" value="<?= htmlspecialchars($settings['user_default_credits'] ?? '0') ?>" min="0" max="99999" placeholder="0">
                            <div class="layui-form-mid layui-word-aux">注册成功后赠送的初始积分</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">禁止用户名</label>
                        <div class="layui-input-block">
                            <input type="text" name="user_banned_usernames" class="layui-input" value="<?= htmlspecialchars($settings['user_banned_usernames'] ?? 'admin,test,root') ?>" placeholder="admin,test,root">
                            <div class="layui-form-mid layui-word-aux">用逗号分隔，这些用户名不允许注册</div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- 用户名规则 -->
            <fieldset class="layui-elem-field">
                <legend>用户名规则</legend>
                <div class="layui-field-box">
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">最小长度</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="user_username_min_length" class="layui-input" value="<?= htmlspecialchars($settings['user_username_min_length'] ?? '3') ?>" min="2" max="20">
                            </div>
                        </div>
                        <div class="layui-inline">
                            <label class="layui-form-label">最大长度</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="user_username_max_length" class="layui-input" value="<?= htmlspecialchars($settings['user_username_max_length'] ?? '20') ?>" min="5" max="30">
                            </div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">允许改名</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="user_allow_rename" lay-skin="switch" lay-text="开|关" <?= ($settings['user_allow_rename'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">关闭后用户注册后不可更改用户名</div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- 登录安全 -->
            <fieldset class="layui-elem-field">
                <legend>登录安全</legend>
                <div class="layui-field-box">
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">最大尝试</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="user_login_max_attempts" class="layui-input" value="<?= htmlspecialchars($settings['user_login_max_attempts'] ?? '5') ?>" min="3" max="20" placeholder="5">
                            </div>
                        </div>
                        <div class="layui-inline">
                            <label class="layui-form-label">锁定(分钟)</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="user_login_lock_minutes" class="layui-input" value="<?= htmlspecialchars($settings['user_login_lock_minutes'] ?? '30') ?>" min="1" max="1440" placeholder="30">
                            </div>
                        </div>
                    </div>
                    <div class="layui-form-mid layui-word-aux" style="padding:0 0 10px 110px;">连续登录失败达到上限后，账号将被临时锁定</div>
                </div>
            </fieldset>

            <!-- 密码策略 -->
            <fieldset class="layui-elem-field">
                <legend>密码策略</legend>
                <div class="layui-field-box">
                    <div class="layui-form-item">
                        <label class="layui-form-label">最小长度</label>
                        <div class="layui-input-block">
                            <input type="number" name="user_password_min_length" class="layui-input" value="<?= htmlspecialchars($settings['user_password_min_length'] ?? '6') ?>" min="4" max="32" placeholder="6">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">混合字符</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="user_password_require_mixed" lay-skin="switch" lay-text="开|关" <?= ($settings['user_password_require_mixed'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">开启后密码必须包含字母和数字</div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- 头像设置 -->
            <fieldset class="layui-elem-field">
                <legend>头像设置</legend>
                <div class="layui-field-box">
                    <div class="layui-form-item">
                        <label class="layui-form-label">大小限制(KB)</label>
                        <div class="layui-input-block">
                            <input type="number" name="user_avatar_max_size" class="layui-input" value="<?= htmlspecialchars($settings['user_avatar_max_size'] ?? '2048') ?>" min="64" max="10240" placeholder="2048">
                            <div class="layui-form-mid layui-word-aux">用户上传头像的最大文件大小</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">图片格式</label>
                        <div class="layui-input-block">
                            <input type="text" name="user_avatar_formats" class="layui-input" value="<?= htmlspecialchars($settings['user_avatar_formats'] ?? 'jpg,jpeg,png,gif,webp') ?>" placeholder="jpg,jpeg,png,gif,webp">
                            <div class="layui-form-mid layui-word-aux">用逗号分隔，如 jpg,png,gif</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">默认头像</label>
                        <div class="layui-input-block">
                            <input type="text" name="user_default_avatar" class="layui-input" value="<?= htmlspecialchars($settings['user_default_avatar'] ?? '') ?>" placeholder="/assets/img/default-avatar.png">
                            <div class="layui-form-mid layui-word-aux">未上传头像时显示的默认图片路径</div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- 个人资料 -->
            <fieldset class="layui-elem-field">
                <legend>个人资料</legend>
                <div class="layui-field-box">
                    <div class="layui-form-item">
                        <label class="layui-form-label">签名长度</label>
                        <div class="layui-input-block">
                            <input type="number" name="user_signature_max_length" class="layui-input" value="<?= htmlspecialchars($settings['user_signature_max_length'] ?? '100') ?>" min="0" max="500" placeholder="100">
                            <div class="layui-form-mid layui-word-aux">设为 0 则禁止用户设置签名</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">简介长度</label>
                        <div class="layui-input-block">
                            <input type="number" name="user_bio_max_length" class="layui-input" value="<?= htmlspecialchars($settings['user_bio_max_length'] ?? '200') ?>" min="0" max="500" placeholder="200">
                            <div class="layui-form-mid layui-word-aux">设为 0 则禁止用户填写个人简介</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">修改邮箱</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="user_allow_change_email" lay-skin="switch" lay-text="开|关" <?= ($settings['user_allow_change_email'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">关闭后用户注册后不可更改绑定邮箱</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">公开邮箱</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="user_show_email" lay-skin="switch" lay-text="开|关" <?= ($settings['user_show_email'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">在用户主页是否展示邮箱地址</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">显示IP</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="user_show_login_ip" lay-skin="switch" lay-text="开|关" <?= ($settings['user_show_login_ip'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">在用户主页是否展示最后登录 IP</div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <div class="layui-form-item">
                <div class="layui-input-block">
                    <button type="button" class="layui-btn" lay-submit lay-filter="saveUserSettings">保存设置</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
layui.use(['form', 'layer'], function(){
    var $ = layui.$, layer = layui.layer, form = layui.form;

    // checkbox 开关字段列表
    var switchFields = [
        'user_register_enabled', 'user_register_verify', 'user_allow_rename',
        'user_password_require_mixed', 'user_allow_change_email',
        'user_show_email', 'user_show_login_ip'
    ];

    form.on('submit(saveUserSettings)', function(data){
        var formData = data.field;
        // switch 类型：勾选时 field 中值为 "on"，未勾选时不存在
        switchFields.forEach(function(f){
            formData[f] = formData[f] ? true : false;
        });

        var loadIdx = layer.load(2);
        $.ajax({
            url: '/admin/user-settings',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            dataType: 'json',
            success: function(res){
                layer.close(loadIdx);
                if (res.success) {
                    layer.msg(res.message || '保存成功', {icon: 1});
                } else {
                    layer.msg(res.message || '保存失败', {icon: 2});
                }
            },
            error: function(){
                layer.close(loadIdx);
                layer.msg('请求失败', {icon: 2});
            }
        });
        return false;
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
