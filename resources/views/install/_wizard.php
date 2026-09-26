<?php
/**
 * 安装向导主体：步骤条 + 当前步骤 + 底部按钮
 *
 * 由 install.php（整页）和 Install::renderWizard()（htmx 片段）共用，
 * 所以这里只输出 #installWizard 的内容，不带 <html>/<head> 外壳。
 *
 * 变量：
 *   $step        当前步骤 1-6（服务端判定，见 Install::currentStep()）
 *   $stepLabels  步骤标题
 *   $installed   安装锁已存在（并发安装时另一个标签页先装完了）
 *   $done        安装完成信息，非空才显示第 6 步的完成卡片
 *   $error       错误提示文案
 *   $checkResult ['items'=>[], 'pass'=>bool] 或 null
 *   $form        表单回填值 ['db'=>[], 'admin'=>[], 'site'=>[]]
 */

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$step = (int)($step ?? 1);
$installed = !empty($installed);
$done = $done ?? null;
$error = (string)($error ?? '');
$checkResult = $checkResult ?? null;
$form = $form ?? ['db' => [], 'admin' => [], 'site' => []];
$db = $form['db'];
$admin = $form['admin'];
$site = $form['site'];

// 已经装好了（并发安装）：不再显示向导，只提示怎么重新安装
if ($installed && !$done):
?>
<div class="install-body">
    <div class="msg msg-error">系统已安装。如需重新安装，请删除 install/install.lock 文件。</div>
</div>
<?php
return;
endif;
?>

<?php /* 表单只用来收集当前这一步的字段值：按钮上的 hx-post 会把最近一层 <form> 的字段一起提交 */ ?>
<form id="installForm" novalidate>
    <div class="steps-bar">
        <?php foreach ($stepLabels as $i => $label): $n = $i + 1; ?>
        <div class="step-item <?= $step === $n ? 'active' : ($step > $n ? 'done' : '') ?>">
            <div class="step-dot">
                <span><?= $step > $n ? '&#10003;' : $n ?></span>
            </div>
            <div class="step-label"><?= $e($label) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="install-body">
        <?php if ($error !== ''): ?>
        <div class="msg msg-error"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if ($done): ?>
        <?php /* 第 6 步：完成 */ ?>
        <div class="complete-icon">&#10003;</div>
        <div class="complete-text">
            <h2>安装完成</h2>
            <p>AMuBBS 已成功安装，现在可以开始使用了。</p>

            <div class="site-info-card">
                <div class="site-info-row">
                    <span class="site-info-label">站点名称</span>
                    <span class="site-info-value"><?= $e($done['site_name']) ?></span>
                </div>
                <div class="site-info-row">
                    <span class="site-info-label">站点地址</span>
                    <a href="<?= $e($done['site_url']) ?>" class="site-info-link" target="_blank" rel="noopener"><?= $e($done['site_url']) ?></a>
                </div>
                <div class="site-info-row">
                    <span class="site-info-label">管理员账号</span>
                    <span class="site-info-value"><?= $e($done['admin']) ?></span>
                </div>
            </div>

            <a href="<?= $e($done['site_url']) ?>" class="btn btn-success">进入首页</a>
        </div>

        <?php elseif ($step === 1): ?>
        <h3 class="step-title">欢迎使用 AMuBBS</h3>
        <div class="program-info-content">
            <h1>AMuBBS 论坛系统</h1>
            <p>欢迎使用 AMuBBS！基于 PHP 8.0+ 的轻量化论坛系统。</p>

            <h2>系统特点</h2>
            <ul>
                <li>轻量高效，快速响应</li>
                <li>无需 Composer / Node，上传即用</li>
                <li>一键安装，快速部署</li>
            </ul>

            <h2>环境要求</h2>
            <ul>
                <li>PHP 8.0+</li>
                <li>MySQL 5.7+ / MariaDB 10.3+</li>
                <li>PDO、mbstring、JSON 扩展</li>
            </ul>

            <p><strong>准备好了吗？</strong> 点击下方「开始安装」按钮继续。</p>
        </div>

        <?php elseif ($step === 2): ?>
        <h3 class="step-title">环境检测</h3>
        <?php if (!$checkResult): ?>
        <p class="step-description">点击下方按钮开始检测服务器环境。</p>
        <?php else: ?>
        <ul class="check-list">
            <?php foreach ($checkResult['items'] as $item): ?>
            <li>
                <span class="check-name"><?= $e($item['name']) ?></span>
                <span class="check-result <?= !empty($item['pass']) ? 'pass' : 'fail' ?>">
                    <span><?= $e($item['value']) ?></span>
                    <span><?= !empty($item['pass']) ? '&#10003;' : '&#10007;' ?></span>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php elseif ($step === 3): ?>
        <h3 class="step-title">数据库配置</h3>
        <div class="form-row">
            <div class="form-group">
                <label for="db-host">主机地址</label>
                <input id="db-host" type="text" name="host" value="<?= $e($db['host']) ?>" placeholder="127.0.0.1">
            </div>
            <div class="form-group">
                <label for="db-port">端口</label>
                <input id="db-port" type="number" name="port" value="<?= $e($db['port']) ?>" placeholder="3306">
            </div>
        </div>
        <div class="form-group">
            <label for="db-database">数据库名</label>
            <input id="db-database" type="text" name="database" value="<?= $e($db['database']) ?>" placeholder="amubbs">
            <div class="form-hint">如果数据库不存在将自动创建</div>
        </div>
        <div class="form-group">
            <label for="db-username">用户名</label>
            <input id="db-username" type="text" name="username" value="<?= $e($db['username']) ?>" placeholder="root">
        </div>
        <div class="form-group">
            <label for="db-password">密码</label>
            <input id="db-password" type="password" name="password" value="<?= $e($db['password']) ?>" placeholder="数据库密码">
        </div>

        <?php elseif ($step === 4): ?>
        <h3 class="step-title">创建管理员账号</h3>
        <div class="form-group">
            <label for="admin-username">用户名</label>
            <input id="admin-username" type="text" name="username" value="<?= $e($admin['username']) ?>" placeholder="admin">
        </div>
        <div class="form-group">
            <label for="admin-email">邮箱</label>
            <input id="admin-email" type="email" name="email" value="<?= $e($admin['email']) ?>" placeholder="admin@example.com">
        </div>
        <div class="form-group">
            <label for="admin-password">密码</label>
            <input id="admin-password" type="password" name="password" placeholder="至少 6 个字符" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label for="admin-password-confirm">确认密码</label>
            <input id="admin-password-confirm" type="password" name="password_confirm" placeholder="再次输入密码" autocomplete="new-password">
        </div>

        <?php elseif ($step === 5): ?>
        <h3 class="step-title">站点设置</h3>
        <div class="form-group">
            <label for="site-name">站点名称</label>
            <input id="site-name" type="text" name="site_name" value="<?= $e($site['name']) ?>" placeholder="AMuBBS">
        </div>
        <div class="form-group">
            <label for="site-description">站点描述</label>
            <input id="site-description" type="text" name="site_description" value="<?= $e($site['description']) ?>" placeholder="基于 PHP 的轻量化论坛系统">
        </div>
        <div class="form-group">
            <label for="site-url">站点 URL</label>
            <input id="site-url" type="text" name="site_url" value="<?= $e($site['url']) ?>" placeholder="http://localhost:8000">
            <div class="form-hint">不要以 / 结尾</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$done): ?>
    <div class="install-footer">
        <?php if ($step > 1): ?>
        <button type="button" class="btn btn-secondary" hx-post="/install/back">
            <span class="hx-busy spinner"></span><span class="hx-idle">上一步</span>
        </button>
        <?php else: ?>
        <span></span>
        <?php endif; ?>

        <div class="footer-actions">
            <?php if ($step === 1): ?>
            <button type="button" class="btn btn-primary" hx-post="/install/next">
                <span class="hx-busy spinner"></span><span class="hx-idle">开始安装</span>
            </button>

            <?php elseif ($step === 2): ?>
            <button type="button" class="btn btn-secondary" hx-post="/install/check">
                <span class="hx-busy spinner"></span>
                <span class="hx-idle"><?= $checkResult ? '重新检测' : '开始检测' ?></span>
            </button>
            <?php if ($checkResult): ?>
            <button type="button" class="btn btn-primary" hx-post="/install/next" <?= empty($checkResult['pass']) ? 'disabled' : '' ?>>
                <span class="hx-busy spinner"></span><span class="hx-idle">下一步</span>
            </button>
            <?php endif; ?>

            <?php elseif ($step === 3): ?>
            <button type="button" class="btn btn-primary" hx-post="/install/database">
                <span class="hx-busy spinner"></span><span class="hx-idle">测试连接并初始化</span>
            </button>

            <?php elseif ($step === 4): ?>
            <button type="button" class="btn btn-primary" hx-post="/install/admin">
                <span class="hx-busy spinner"></span><span class="hx-idle">创建管理员</span>
            </button>

            <?php elseif ($step === 5): ?>
            <button type="button" class="btn btn-success" hx-post="/install/complete">
                <span class="hx-busy spinner"></span><span class="hx-idle">完成安装</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</form>
