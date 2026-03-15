<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMuBBS 安装引导</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚀</text></svg>">
    <link rel="stylesheet" href="/assets/css/install.css">
    <?= \App\Middlewares\Csrf::tokenMeta() ?>
    <script defer src="/assets/js/alpine-collapse.min.js"></script>
    <script defer src="/assets/js/alpine.min.js"></script>
    <script>
        // 从服务器传递配置到前端
        window.installConfig = {
            programInfoUrl: '<?= $config['program_info_url'] ?? '' ?>',
            fallbackInfo: <?= json_encode($config['fallback_info'] ?? '') ?>,
            enableProgramInfo: <?= json_encode($config['enable_program_info'] ?? true) ?>,
            fetchTimeout: <?= $config['fetch_timeout'] ?? 10 ?>
        };
    </script>
</head>
<body>

<div class="install-container" x-data="installer()">

    <!-- 头部 -->
    <div class="install-header">
        <h1>AMuBBS 安装引导</h1>
        <p>按照步骤完成论坛系统的安装配置</p>
    </div>


    <!-- 步骤指示器 -->
    <div class="steps-bar">
        <template x-for="(label, i) in stepLabels" :key="i">
            <div class="step-item" :class="{ active: step === i + 1, done: step > i + 1 }">
                <div class="step-dot">
                    <template x-if="step > i + 1">
                        <span>&#10003;</span>
                    </template>
                    <template x-if="step <= i + 1">
                        <span x-text="i + 1"></span>
                    </template>
                </div>
                <div class="step-label" x-text="label"></div>
            </div>
        </template>
    </div>

    <!-- 错误/成功消息 -->
    <div class="install-body">
        <div x-show="errorMsg" class="msg msg-error" x-text="errorMsg" x-transition></div>

        <!-- 步骤1: 安装说明 -->
        <div x-show="step === 1" x-transition>
            <h3 class="step-title">欢迎使用 AMuBBS</h3>
            <div class="program-info-content" x-html="programInfo"></div>
        </div>

        <!-- 步骤2: 环境检测 -->
        <div x-show="step === 2" x-transition>
            <h3 class="step-title">环境检测</h3>
            <template x-if="!checkResult">
                <p class="step-description">点击下方按钮开始检测服务器环境。</p>
            </template>
            <template x-if="checkResult">
                <ul class="check-list">
                    <template x-for="item in checkResult.items" :key="item.name">
                        <li>
                            <span class="check-name" x-text="item.name"></span>
                            <span class="check-result" :class="item.pass ? 'pass' : 'fail'">
                                <span x-text="item.value"></span>
                                <span x-text="item.pass ? '✓' : '✗'"></span>
                            </span>
                        </li>
                    </template>
                </ul>
            </template>
        </div>

        <!-- 步骤3: 数据库配置 -->
        <div x-show="step === 3" x-transition>
            <h3 class="step-title">数据库配置</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>主机地址</label>
                    <input type="text" x-model="db.host" placeholder="127.0.0.1">
                </div>
                <div class="form-group">
                    <label>端口</label>
                    <input type="number" x-model="db.port" placeholder="3306">
                </div>
            </div>
            <div class="form-group">
                <label>数据库名</label>
                <input type="text" x-model="db.database" placeholder="amubbs">
                <div class="form-hint">如果数据库不存在将自动创建</div>
            </div>
            <div class="form-group">
                <label>用户名</label>
                <input type="text" x-model="db.username" placeholder="root">
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" x-model="db.password" placeholder="数据库密码">
            </div>
        </div>

        <!-- 步骤4: 管理员账号 -->
        <div x-show="step === 4" x-transition>
            <h3 class="step-title">创建管理员账号</h3>
            <div class="form-group">
                <label>用户名</label>
                <input type="text" x-model="admin.username" placeholder="admin">
            </div>
            <div class="form-group">
                <label>邮箱</label>
                <input type="email" x-model="admin.email" placeholder="admin@example.com">
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" x-model="admin.password" placeholder="至少 6 个字符">
            </div>
            <div class="form-group">
                <label>确认密码</label>
                <input type="password" x-model="admin.password_confirm" placeholder="再次输入密码">
            </div>
        </div>

        <!-- 步骤5: 站点设置 -->
        <div x-show="step === 5" x-transition>
            <h3 class="step-title">站点设置</h3>
            <div class="form-group">
                <label>站点名称</label>
                <input type="text" x-model="site.name" placeholder="AMuBBS">
            </div>
            <div class="form-group">
                <label>站点描述</label>
                <input type="text" x-model="site.description" placeholder="基于 PHP 的轻量化论坛系统">
            </div>
            <div class="form-group">
                <label>站点 URL</label>
                <input type="text" x-model="site.url" placeholder="http://localhost:8000">
                <div class="form-hint">不要以 / 结尾</div>
            </div>
        </div>

        <!-- 步骤6: 安装完成 -->
        <div x-show="step === 6" x-transition>
            <div class="complete-icon">&#10003;</div>
            <div class="complete-text">
                <h2>安装完成</h2>
                <p>AMuBBS 已成功安装，现在可以开始使用了。</p>

                <div class="site-info-card">
                    <div class="site-info-row">
                        <span class="site-info-label">站点名称</span>
                        <span class="site-info-value" x-text="site.name"></span>
                    </div>
                    <div class="site-info-row">
                        <span class="site-info-label">站点地址</span>
                        <a :href="site.url || '/'" class="site-info-link" x-text="site.url || window.location.origin" target="_blank"></a>
                    </div>
                    <div class="site-info-row">
                        <span class="site-info-label">管理员账号</span>
                        <span class="site-info-value" x-text="admin.username"></span>
                    </div>
                </div>

                <a :href="site.url || '/'" class="btn btn-success">进入首页</a>
            </div>
        </div>
    </div>

    <!-- 底部按钮 -->
    <div class="install-footer" x-show="step < 6">
        <button class="btn btn-secondary" x-show="step > 1" @click="prevStep()" :disabled="loading">
            上一步
        </button>
        <div x-show="step <= 1"></div>

        <!-- 步骤1: 开始安装 -->
        <template x-if="step === 1">
            <button class="btn btn-primary" @click="step = 2">
                <span>开始安装</span>
            </button>
        </template>

        <!-- 步骤2: 检测按钮 -->
        <template x-if="step === 2">
            <button class="btn btn-primary" @click="runCheck()" :disabled="loading">
                <span x-show="loading" class="spinner"></span>
                <span x-text="checkResult ? (checkResult.pass ? '下一步' : '重新检测') : '开始检测'"></span>
            </button>
        </template>

        <!-- 步骤3: 数据库 -->
        <template x-if="step === 3">
            <button class="btn btn-primary" @click="submitDatabase()" :disabled="loading">
                <span x-show="loading" class="spinner"></span>
                <span>测试连接并初始化</span>
            </button>
        </template>

        <!-- 步骤4: 管理员 -->
        <template x-if="step === 4">
            <button class="btn btn-primary" @click="submitAdmin()" :disabled="loading">
                <span x-show="loading" class="spinner"></span>
                <span>创建管理员</span>
            </button>
        </template>

        <!-- 步骤5: 站点设置 -->
        <template x-if="step === 5">
            <button class="btn btn-success" @click="submitComplete()" :disabled="loading">
                <span x-show="loading" class="spinner"></span>
                <span>完成安装</span>
            </button>
        </template>
    </div>
</div>

<script>
function installer() {
    return {
        step: 1,
        loading: false,
        errorMsg: '',
        checkResult: null,
        stepLabels: ['安装说明', '环境检测', '数据库', '管理员', '站点设置', '完成'],
        programInfo: `
            <h1>AMuBBS 论坛系统</h1>
            <p>欢迎使用 AMuBBS！基于 PHP 8.1+ 的轻量化论坛系统。</p>

            <h2>系统特点</h2>
            <ul>
                <li>轻量高效，快速响应</li>
                <li>现代化设计，简洁美观</li>
                <li>一键安装，快速部署</li>
            </ul>

            <h2>环境要求</h2>
            <ul>
                <li>PHP 8.1+</li>
                <li>MySQL 5.7+ / MariaDB 10.3+</li>
                <li>PDO、mbstring、JSON 扩展</li>
            </ul>

            <p><strong>准备好了吗？</strong> 点击下方"开始安装"按钮继续。</p>
        `,

        db: {
            host: '127.0.0.1',
            port: 3306,
            database: 'amubbs',
            username: 'root',
            password: ''
        },

        admin: {
            username: '',
            email: '',
            password: '',
            password_confirm: ''
        },

        site: {
            name: 'AMuBBS',
            description: '基于 PHP 的轻量化论坛系统',
            url: window.location.origin
        },

        async init() {
            // 初始化完成，程序说明已内置
        },

        prevStep() {
            if (this.step > 1) {
                this.step--;
                this.errorMsg = '';
            }
        },

        async post(url, data) {
            this.loading = true;
            this.errorMsg = '';
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(data)
                });
                return await res.json();
            } catch (e) {
                return { success: false, message: '网络请求失败，请重试' };
            } finally {
                this.loading = false;
            }
        },

        async runCheck() {
            if (this.checkResult && this.checkResult.pass) {
                this.step = 3;
                return;
            }
            const result = await this.post('/install/check', {});
            if (result.success) {
                this.checkResult = result.data;
                if (!result.data.pass) {
                    this.errorMsg = '环境检测未通过，请解决上述问题后重新检测。';
                }
            } else {
                this.errorMsg = result.message;
            }
        },

        async submitDatabase() {
            const result = await this.post('/install/database', this.db);
            if (result.success) {
                this.step = 4;
            } else {
                this.errorMsg = result.message;
            }
        },

        async submitAdmin() {
            if (this.admin.password !== this.admin.password_confirm) {
                this.errorMsg = '两次输入的密码不一致';
                return;
            }
            const result = await this.post('/install/admin', this.admin);
            if (result.success) {
                this.step = 5;
            } else {
                this.errorMsg = result.message;
            }
        },

        async submitComplete() {
            const result = await this.post('/install/complete', {
                site_name: this.site.name,
                site_description: this.site.description,
                site_url: this.site.url
            });
            if (result.success) {
                this.step = 6;
            } else {
                this.errorMsg = result.message;
            }
        }
    };
}
</script>

</body>
</html>
