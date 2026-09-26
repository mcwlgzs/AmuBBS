<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMuBBS 安装引导</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚀</text></svg>">
    <link rel="stylesheet" href="/assets/css/install.css">
    <?= \App\Middlewares\Csrf::tokenMeta() ?>
    <script defer src="/assets/vendor/htmx/htmx.min.js"></script>
</head>
<body>

<?php /*
  安装向导：页头是固定的，只有一个 htmx 替换目标 #installWizard。
  每一步的「上一步 / 下一步 / 提交」都是 hx-post 按钮，由服务端重渲染整块向导，
  所以这里（以及 _wizard.php）不需要 Alpine，也不需要一行手写 JS。

  - hx-target / hx-swap 放在容器上，子元素继承；换的是容器自己的内容，属性不会丢
  - hx-headers 把 CSRF Token 带在请求头里，对应 Install::verifyCsrf()
  - 按钮一律 type="button"：它们只负责发动 htmx 请求，避免浏览器再原生提交一次表单
*/ ?>
<div class="install-container">

    <div class="install-header">
        <h1>AMuBBS 安装引导</h1>
        <p>按照步骤完成论坛系统的安装配置</p>
    </div>

    <div id="installWizard"
         hx-target="#installWizard"
         hx-swap="innerHTML"
         hx-headers='{"X-CSRF-TOKEN":"<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"}'>
        <?php include __DIR__ . '/install/_wizard.php'; ?>
    </div>
</div>

</body>
</html>
