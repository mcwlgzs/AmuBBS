<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header">站点设置</div>
    <div class="layui-card-body">

        <form class="layui-form" lay-filter="settingsForm" style="max-width:700px;">

        <div class="layui-tab layui-tab-brief" lay-filter="settingsTab">
            <ul class="layui-tab-title">
                <li class="layui-this">基本设置</li>
                <li>安全设置</li>
                <li>上传设置</li>
                <li>邮件设置</li>
                <li>防灌水</li>
                <li>功能设置</li>
            </ul>
            <div class="layui-tab-content">

                <!-- Tab 1: 基本设置 -->
                <div class="layui-tab-item layui-show">
                    <div class="layui-form-item">
                        <label class="layui-form-label">站点名称</label>
                        <div class="layui-input-block">
                            <input type="text" name="site_name" class="layui-input" value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">站点描述</label>
                        <div class="layui-input-block">
                            <input type="text" name="site_description" class="layui-input" value="<?= htmlspecialchars($settings['site_description'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">站点 URL</label>
                        <div class="layui-input-block">
                            <input type="text" name="site_url" class="layui-input" value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>" placeholder="http://localhost:8000">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">站点关键词</label>
                        <div class="layui-input-block">
                            <input type="text" name="site_keywords" class="layui-input" value="<?= htmlspecialchars($settings['site_keywords'] ?? '') ?>" placeholder="用逗号分隔">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">ICP 备案号</label>
                        <div class="layui-input-block">
                            <input type="text" name="icp_number" class="layui-input" value="<?= htmlspecialchars($settings['icp_number'] ?? '') ?>" placeholder="可留空">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">CDN 地址</label>
                        <div class="layui-input-block">
                            <input type="text" name="cdn_url" class="layui-input" value="<?= htmlspecialchars($settings['cdn_url'] ?? '') ?>" placeholder="如 https://cdn.example.com（留空不启用）">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">运行级别</label>
                        <div class="layui-input-block">
                            <?php $runlevel = $settings['site_runlevel'] ?? '5'; ?>
                            <select name="site_runlevel">
                                <option value="5" <?= $runlevel == '5' ? 'selected' : '' ?>>完全开放（所有人可读写）</option>
                                <option value="4" <?= $runlevel == '4' ? 'selected' : '' ?>>所有人只读（游客可浏览，禁止发帖回复）</option>
                                <option value="3" <?= $runlevel == '3' ? 'selected' : '' ?>>仅注册用户可读写（游客不可访问）</option>
                                <option value="2" <?= $runlevel == '2' ? 'selected' : '' ?>>注册用户只读（禁止发帖回复）</option>
                                <option value="1" <?= $runlevel == '1' ? 'selected' : '' ?>>仅管理员（维护模式）</option>
                                <option value="0" <?= $runlevel == '0' ? 'selected' : '' ?>>关站维护（所有人不可访问）</option>
                            </select>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">维护提示</label>
                        <div class="layui-input-block">
                            <input type="text" name="site_maintenance_msg" class="layui-input" value="<?= htmlspecialchars($settings['site_maintenance_msg'] ?? '') ?>" placeholder="站点维护中，请稍后再访问...">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">每页主题数</label>
                        <div class="layui-input-block">
                            <input type="number" name="threads_per_page" class="layui-input" value="<?= htmlspecialchars($settings['threads_per_page'] ?? '20') ?>" min="5" max="100">
                            <div class="layui-form-mid layui-word-aux">首页和帖子列表每页显示的主题数量（5-100）</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">公告隐藏时长</label>
                        <div class="layui-input-block">
                            <input type="number" name="announcement_hide_duration" class="layui-input" value="<?= htmlspecialchars($settings['announcement_hide_duration'] ?? '60') ?>" min="0">
                            <div class="layui-form-mid layui-word-aux">关闭后隐藏时长（分钟），0 表示刷新后立即恢复</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">URL .html 后缀</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="url_html_suffix" lay-skin="switch" lay-text="开|关" <?= ($settings['url_html_suffix'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <div class="layui-form-mid layui-word-aux">开启后支持 .html 后缀访问（如 /thread/1.html）</div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: 安全设置 -->
                <div class="layui-tab-item">
                    <div class="layui-form-item">
                        <label class="layui-form-label">IP 白名单</label>
                        <div class="layui-input-block">
                            <input type="text" name="admin_bind_ip" class="layui-input" value="<?= htmlspecialchars($settings['admin_bind_ip'] ?? '') ?>" placeholder="留空不限制，多个IP用逗号分隔">
                            <div class="layui-form-mid layui-word-aux">当前 IP：<?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '') ?></div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">定时任务密钥</label>
                        <div class="layui-input-block">
                            <input type="text" name="cron_key" class="layui-input" value="<?= htmlspecialchars($settings['cron_key'] ?? '') ?>" placeholder="留空不验证">
                            <div class="layui-form-mid layui-word-aux">访问 <code>/cron/run?key=密钥</code> 触发定时任务</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">启用验证码</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="captcha_enabled" lay-skin="switch" lay-text="开|关" lay-filter="captchaEnabled" <?= ($settings['captcha_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div id="captchaFields" style="<?= ($settings['captcha_enabled'] ?? '0') !== '1' ? 'display:none;' : '' ?>">
                        <div class="layui-form-item">
                            <label class="layui-form-label">验证码类型</label>
                            <div class="layui-input-block">
                                <?php $captchaType = $settings['captcha_type'] ?? 'numeric'; ?>
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
                                <?php $scenes = explode(',', $settings['captcha_scenes'] ?? ''); ?>
                                <input type="checkbox" name="captcha_scene_register" title="注册" <?= in_array('register', $scenes) ? 'checked' : '' ?>>
                                <input type="checkbox" name="captcha_scene_login" title="登录" <?= in_array('login', $scenes) ? 'checked' : '' ?>>
                                <input type="checkbox" name="captcha_scene_thread" title="发帖" <?= in_array('thread', $scenes) ? 'checked' : '' ?>>
                                <input type="checkbox" name="captcha_scene_reply" title="回复" <?= in_array('reply', $scenes) ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                    <div class="layui-form-item" style="margin-top:20px;">
                        <div class="layui-inline">
                            <label class="layui-form-label">全局频率</label>
                            <div class="layui-input-inline" style="width:100px;">
                                <input type="number" name="rate_limit_global_max" class="layui-input" value="<?= htmlspecialchars($settings['rate_limit_global_max'] ?? '60') ?>" min="0">
                            </div>
                            <div class="layui-form-mid">次 /</div>
                            <div class="layui-input-inline" style="width:100px;">
                                <input type="number" name="rate_limit_global_window" class="layui-input" value="<?= htmlspecialchars($settings['rate_limit_global_window'] ?? '60') ?>" min="1">
                            </div>
                            <div class="layui-form-mid">秒</div>
                            <div class="layui-form-mid layui-word-aux">（次数填 0 = 不限制）</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">严格频率</label>
                            <div class="layui-input-inline" style="width:100px;">
                                <input type="number" name="rate_limit_strict_max" class="layui-input" value="<?= htmlspecialchars($settings['rate_limit_strict_max'] ?? '5') ?>" min="0">
                            </div>
                            <div class="layui-form-mid">次 /</div>
                            <div class="layui-input-inline" style="width:100px;">
                                <input type="number" name="rate_limit_strict_window" class="layui-input" value="<?= htmlspecialchars($settings['rate_limit_strict_window'] ?? '300') ?>" min="1">
                            </div>
                            <div class="layui-form-mid">秒</div>
                            <div class="layui-form-mid layui-word-aux">（登录/注册等敏感操作）</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">搜索频率</label>
                            <div class="layui-input-inline" style="width:100px;">
                                <input type="number" name="rate_limit_search_max" class="layui-input" value="<?= htmlspecialchars($settings['rate_limit_search_max'] ?? '10') ?>" min="0">
                            </div>
                            <div class="layui-form-mid">次 /</div>
                            <div class="layui-input-inline" style="width:100px;">
                                <input type="number" name="rate_limit_search_window" class="layui-input" value="<?= htmlspecialchars($settings['rate_limit_search_window'] ?? '60') ?>" min="1">
                            </div>
                            <div class="layui-form-mid">秒</div>
                        </div>
                    </div>
                </div>
                <div class="layui-tab-item">
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">最大宽度(px)</label>
                            <div class="layui-input-inline" style="width:150px;">
                                <input type="number" name="image_max_width" class="layui-input" value="<?= htmlspecialchars($settings['image_max_width'] ?? '1920') ?>" placeholder="1920（0不限制）">
                            </div>
                        </div>
                        <div class="layui-inline">
                            <label class="layui-form-label">缩略图(px)</label>
                            <div class="layui-input-inline" style="width:150px;">
                                <input type="number" name="image_thumb_width" class="layui-input" value="<?= htmlspecialchars($settings['image_thumb_width'] ?? '400') ?>" placeholder="400（0不生成）">
                            </div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">图片水印</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="watermark_enabled" lay-skin="switch" lay-text="开|关" lay-filter="watermarkEnabled" <?= ($settings['watermark_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div id="watermarkFields" style="<?= ($settings['watermark_enabled'] ?? '0') !== '1' ? 'display:none;' : '' ?>">
                        <div class="layui-form-item">
                            <label class="layui-form-label">水印文字</label>
                            <div class="layui-input-block">
                                <input type="text" name="watermark_text" class="layui-input" value="<?= htmlspecialchars($settings['watermark_text'] ?? 'AMuBBS') ?>">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <div class="layui-inline">
                                <label class="layui-form-label">水印位置</label>
                                <div class="layui-input-inline" style="width:150px;">
                                    <?php $wmPos = $settings['watermark_position'] ?? 'bottom-right'; ?>
                                    <select name="watermark_position">
                                        <option value="bottom-right" <?= $wmPos === 'bottom-right' ? 'selected' : '' ?>>右下角</option>
                                        <option value="bottom-left" <?= $wmPos === 'bottom-left' ? 'selected' : '' ?>>左下角</option>
                                        <option value="top-right" <?= $wmPos === 'top-right' ? 'selected' : '' ?>>右上角</option>
                                        <option value="top-left" <?= $wmPos === 'top-left' ? 'selected' : '' ?>>左上角</option>
                                        <option value="center" <?= $wmPos === 'center' ? 'selected' : '' ?>>居中</option>
                                    </select>
                                </div>
                            </div>
                            <div class="layui-inline">
                                <label class="layui-form-label">透明度</label>
                                <div class="layui-input-inline" style="width:100px;">
                                    <input type="number" name="watermark_opacity" class="layui-input" value="<?= htmlspecialchars($settings['watermark_opacity'] ?? '30') ?>" min="0" max="100">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 4: 邮件设置 -->
                <div class="layui-tab-item">
                    <div class="layui-form-item">
                        <label class="layui-form-label">SMTP 服务器</label>
                        <div class="layui-input-block">
                            <input type="text" name="smtp_host" class="layui-input" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="如 smtp.qq.com">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">端口</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="smtp_port" class="layui-input" value="<?= htmlspecialchars($settings['smtp_port'] ?? '465') ?>">
                            </div>
                        </div>
                        <div class="layui-inline">
                            <label class="layui-form-label">加密方式</label>
                            <div class="layui-input-inline" style="width:180px;">
                                <?php $smtpEnc = $settings['smtp_encryption'] ?? 'ssl'; ?>
                                <select name="smtp_encryption">
                                    <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL（推荐，端口465）</option>
                                    <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS（端口587）</option>
                                    <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>无加密（端口25）</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">SMTP 用户名</label>
                        <div class="layui-input-block">
                            <input type="text" name="smtp_user" class="layui-input" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>" placeholder="通常是邮箱地址">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">SMTP 密码</label>
                        <div class="layui-input-block">
                            <input type="password" name="smtp_pass" class="layui-input" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>" placeholder="QQ邮箱请使用授权码">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">发件人地址</label>
                        <div class="layui-input-block">
                            <input type="text" name="smtp_from" class="layui-input" value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>" placeholder="留空则使用 SMTP 用户名">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">发件人名称</label>
                        <div class="layui-input-block">
                            <input type="text" name="smtp_from_name" class="layui-input" value="<?= htmlspecialchars($settings['smtp_from_name'] ?? 'AMuBBS') ?>">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <div class="layui-input-block">
                            <button type="button" class="layui-btn layui-btn-normal" id="btnTestEmail">发送测试邮件</button>
                        </div>
                    </div>
                </div>

                <!-- Tab 5: 防灌水 -->
                <div class="layui-tab-item">
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">发帖上限</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="ip_limit_thread" class="layui-input" value="<?= htmlspecialchars($settings['ip_limit_thread'] ?? '20') ?>">
                            </div>
                        </div>
                        <div class="layui-inline">
                            <label class="layui-form-label">回帖上限</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="ip_limit_post" class="layui-input" value="<?= htmlspecialchars($settings['ip_limit_post'] ?? '50') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <div class="layui-inline">
                            <label class="layui-form-label">注册上限</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="ip_limit_register" class="layui-input" value="<?= htmlspecialchars($settings['ip_limit_register'] ?? '5') ?>">
                            </div>
                        </div>
                        <div class="layui-inline">
                            <label class="layui-form-label">上传上限</label>
                            <div class="layui-input-inline" style="width:120px;">
                                <input type="number" name="ip_limit_upload" class="layui-input" value="<?= htmlspecialchars($settings['ip_limit_upload'] ?? '30') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="layui-form-mid layui-word-aux" style="padding:0 0 10px 110px;">限制同一 IP 每日操作次数，0 表示不限制</div>
                </div>

                <!-- Tab 6: 功能设置 -->
                <div class="layui-tab-item">
                    <fieldset class="layui-elem-field" style="margin-bottom:20px;">
                        <legend>自动头像</legend>
                        <div class="layui-field-box" style="padding:15px;">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用自动头像</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="auto_avatar_enabled" lay-skin="switch" lay-text="开|关" <?= ($settings['auto_avatar_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <div class="layui-form-mid layui-word-aux">用户注册时自动分配随机头像</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">覆盖已有头像</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="auto_avatar_overwrite" lay-skin="switch" lay-text="开|关" <?= ($settings['auto_avatar_overwrite'] ?? '0') === '1' ? 'checked' : '' ?>>
                                    <div class="layui-form-mid layui-word-aux">是否覆盖用户已有的自定义头像</div>
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="layui-elem-field" style="margin-bottom:20px;">
                        <legend>Emoji 表情</legend>
                        <div class="layui-field-box" style="padding:15px;">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用 Emoji</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="emoji_enabled" lay-skin="switch" lay-text="开|关" <?= ($settings['emoji_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <div class="layui-form-mid layui-word-aux">将 :smile: 等短代码转换为 Emoji 表情</div>
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="layui-elem-field" style="margin-bottom:20px;">
                        <legend>社交登录</legend>
                        <div class="layui-field-box" style="padding:15px;">
                            <div class="layui-form-item">
                                <label class="layui-form-label">GitHub 登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="social_login_github_enabled" lay-skin="switch" lay-text="开|关" lay-filter="githubEnabled" <?= ($settings['social_login_github_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                </div>
                            </div>
                            <div id="githubFields" style="<?= ($settings['social_login_github_enabled'] ?? '0') !== '1' ? 'display:none;' : '' ?>">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">Client ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="social_login_github_client_id" class="layui-input" value="<?= htmlspecialchars($settings['social_login_github_client_id'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">Client Secret</label>
                                    <div class="layui-input-block">
                                        <input type="password" name="social_login_github_client_secret" class="layui-input" value="<?= htmlspecialchars($settings['social_login_github_client_secret'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="layui-form-item" style="margin-top:15px;">
                                <label class="layui-form-label">Google 登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="social_login_google_enabled" lay-skin="switch" lay-text="开|关" lay-filter="googleEnabled" <?= ($settings['social_login_google_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                </div>
                            </div>
                            <div id="googleFields" style="<?= ($settings['social_login_google_enabled'] ?? '0') !== '1' ? 'display:none;' : '' ?>">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">Client ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="social_login_google_client_id" class="layui-input" value="<?= htmlspecialchars($settings['social_login_google_client_id'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">Client Secret</label>
                                    <div class="layui-input-block">
                                        <input type="password" name="social_login_google_client_secret" class="layui-input" value="<?= htmlspecialchars($settings['social_login_google_client_secret'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="layui-form-item" style="margin-top:15px;">
                                <label class="layui-form-label">微信登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="social_login_wechat_enabled" lay-skin="switch" lay-text="开|关" lay-filter="wechatEnabled" <?= ($settings['social_login_wechat_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                </div>
                            </div>
                            <div id="wechatFields" style="<?= ($settings['social_login_wechat_enabled'] ?? '0') !== '1' ? 'display:none;' : '' ?>">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">App ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="social_login_wechat_app_id" class="layui-input" value="<?= htmlspecialchars($settings['social_login_wechat_app_id'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">App Secret</label>
                                    <div class="layui-input-block">
                                        <input type="password" name="social_login_wechat_app_secret" class="layui-input" value="<?= htmlspecialchars($settings['social_login_wechat_app_secret'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="layui-form-item" style="margin-top:15px;">
                                <label class="layui-form-label">QQ 登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="social_login_qq_enabled" lay-skin="switch" lay-text="开|关" lay-filter="qqEnabled" <?= ($settings['social_login_qq_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                </div>
                            </div>
                            <div id="qqFields" style="<?= ($settings['social_login_qq_enabled'] ?? '0') !== '1' ? 'display:none;' : '' ?>">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">App ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="social_login_qq_app_id" class="layui-input" value="<?= htmlspecialchars($settings['social_login_qq_app_id'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">App Key</label>
                                    <div class="layui-input-block">
                                        <input type="password" name="social_login_qq_app_key" class="layui-input" value="<?= htmlspecialchars($settings['social_login_qq_app_key'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="layui-elem-field" style="margin-bottom:20px;">
                        <legend>编辑器设置</legend>
                        <div class="layui-field-box" style="padding:15px;">
                            <div class="layui-form-item">
                                <label class="layui-form-label">编辑器类型</label>
                                <div class="layui-input-block">
                                    <?php $editorType = $settings['editor_type'] ?? 'markdown'; ?>
                                    <select name="editor_type" lay-filter="editorType">
                                        <option value="markdown" <?= $editorType === 'markdown' ? 'selected' : '' ?>>Markdown 编辑器</option>
                                        <option value="tinymce" <?= $editorType === 'tinymce' ? 'selected' : '' ?>>TinyMCE 富文本编辑器</option>
                                    </select>
                                </div>
                            </div>
                            <div id="tinymceFields" style="<?= ($settings['editor_type'] ?? 'markdown') !== 'tinymce' ? 'display:none;' : '' ?>">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">发帖启用</label>
                                    <div class="layui-input-block">
                                        <input type="checkbox" name="tinymce_enable_post_editor" lay-skin="switch" lay-text="开|关" <?= ($settings['tinymce_enable_post_editor'] ?? '1') === '1' ? 'checked' : '' ?>>
                                        <div class="layui-form-mid layui-word-aux">在发帖页面启用 TinyMCE</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">回复启用</label>
                                    <div class="layui-input-block">
                                        <input type="checkbox" name="tinymce_enable_reply_editor" lay-skin="switch" lay-text="开|关" <?= ($settings['tinymce_enable_reply_editor'] ?? '1') === '1' ? 'checked' : '' ?>>
                                        <div class="layui-form-mid layui-word-aux">在回复页面启用 TinyMCE</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>


            </div>
        </div>

            <div class="layui-form-item">
                <div class="layui-input-block">
                    <button type="button" class="layui-btn" lay-submit lay-filter="saveSettings">保存设置</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
layui.use(['form', 'layer', 'element'], function(){
    var $ = layui.$, layer = layui.layer, form = layui.form;

    form.on('switch(captchaEnabled)', function(data){ $('#captchaFields').toggle(data.elem.checked); });
    form.on('switch(watermarkEnabled)', function(data){ $('#watermarkFields').toggle(data.elem.checked); });
    form.on('switch(githubEnabled)', function(data){ $('#githubFields').toggle(data.elem.checked); });
    form.on('switch(googleEnabled)', function(data){ $('#googleFields').toggle(data.elem.checked); });
    form.on('switch(wechatEnabled)', function(data){ $('#wechatFields').toggle(data.elem.checked); });
    form.on('switch(qqEnabled)', function(data){ $('#qqFields').toggle(data.elem.checked); });
    form.on('select(editorType)', function(data){ $('#tinymceFields').toggle(data.value === 'tinymce'); });


    function collectFormData(fields) {
        var data = {};
        for (var k in fields) data[k] = fields[k];
        data.captcha_enabled = fields.captcha_enabled ? '1' : '0';
        data.watermark_enabled = fields.watermark_enabled ? '1' : '0';
        data.url_html_suffix = fields.url_html_suffix ? '1' : '0';
        data.auto_avatar_enabled = fields.auto_avatar_enabled ? '1' : '0';
        data.auto_avatar_overwrite = fields.auto_avatar_overwrite ? '1' : '0';
        data.emoji_enabled = fields.emoji_enabled ? '1' : '0';
        data.social_login_github_enabled = fields.social_login_github_enabled ? '1' : '0';
        data.social_login_google_enabled = fields.social_login_google_enabled ? '1' : '0';
        data.social_login_wechat_enabled = fields.social_login_wechat_enabled ? '1' : '0';
        data.social_login_qq_enabled = fields.social_login_qq_enabled ? '1' : '0';
        data.tinymce_enable_post_editor = fields.tinymce_enable_post_editor ? '1' : '0';
        data.tinymce_enable_reply_editor = fields.tinymce_enable_reply_editor ? '1' : '0';
        var scenes = [];
        if (fields.captcha_scene_register) scenes.push('register');
        if (fields.captcha_scene_login) scenes.push('login');
        if (fields.captcha_scene_thread) scenes.push('thread');
        if (fields.captcha_scene_reply) scenes.push('reply');
        data.captcha_scenes = scenes.join(',');
        delete data.captcha_scene_register; delete data.captcha_scene_login;
        delete data.captcha_scene_thread; delete data.captcha_scene_reply;
        return data;
    }

    form.on('submit(saveSettings)', function(data){
        var formData = collectFormData(data.field);
        var l = layer.load(2);
        $.ajax({ url:'/admin/settings', type:'POST', contentType:'application/json', data:JSON.stringify(formData),
            success:function(res){ layer.close(l);
                if(res.code===0||res.success) layer.msg(res.msg||res.message||'保存成功',{icon:1});
                else layer.msg(res.msg||res.message||'保存失败',{icon:2});
            }, error:function(){ layer.close(l); layer.msg('请求失败',{icon:2}); }
        });
        return false;
    });

    $('#btnTestEmail').on('click', function(){
        var fields = form.val('settingsForm');
        var formData = collectFormData(fields);
        var l = layer.load(2);
        $.ajax({ url:'/admin/settings/test-email', type:'POST', contentType:'application/json', data:JSON.stringify(formData),
            success:function(res){ layer.close(l);
                if(res.code===0||res.success) layer.msg(res.msg||res.message||'发送成功',{icon:1});
                else layer.msg(res.msg||res.message||'发送失败',{icon:2});
            }, error:function(){ layer.close(l); layer.msg('请求失败',{icon:2}); }
        });
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
