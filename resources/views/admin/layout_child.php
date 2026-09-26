<?php
/**
 * 后台子页面框架（iframe 内加载的完整 HTML 文档）
 * ==========================================================================
 * 由 AdminBase::renderAdmin() 渲染。每一个后台页面都是一个独立文档，
 * 在外壳的 iframe 里打开 —— 这正是 layuimini「iframe 多 tab」的约定，
 * 所以这里**没有** #admin-main、没有 htmx 片段替换。
 *
 * 需要的变量：$content（页面正文）、$pageTitle（可省）
 *
 * 加载哪些资源：完全照 layuimini 自己的子页面写法（见它的 page/table.html）——
 *   layui.css + public.css（layuimini 的页面级样式），
 *   再补 font-awesome（菜单/按钮图标）和 admin-layui.css（本项目少量覆写）。
 *   子页面不加载 layuimini.css / lay-config.js，那两样是外壳（tab 与菜单）才用的。
 *
 * 所有资源都走 Core\Helper::asset() 带版本号，避免改了样式仍拿旧缓存。
 */

use Core\Helper;

$__pageTitle = (string)($pageTitle ?? '管理后台');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($__pageTitle, ENT_QUOTES, 'UTF-8') ?> - AMuBBS 管理后台</title>
  <meta name="renderer" content="webkit">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <?= \App\Middlewares\Csrf::tokenMeta() ?>

  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/vendor/layui/css/layui.css'), ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/vendor/font-awesome/css/font-awesome.min.css'), ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/vendor/layuimini/css/public.css'), ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/css/admin-layui.css'), ENT_QUOTES) ?>">
</head>
<body>

<!-- layui 必须**先于**页面里的内联脚本加载。
     子页面的 JS 就写在片段内部（layui.use([...])），而片段在容器里、
     会先于 body 末尾的 <script> 执行 —— 放到末尾的话 layui 还没定义，
     页面的表格/表单全部初始化不了（表现为「layui 加载了但表格是空的」）。
     片段里的脚本又都写在自己要操作的元素之后，所以这里提前加载也不会取不到 DOM。 -->
<script src="<?= htmlspecialchars(Helper::asset('/assets/vendor/layui/layui.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(Helper::asset('/assets/js/admin-layui.js'), ENT_QUOTES) ?>"></script>

<div class="layuimini-container">
  <div class="layuimini-main">
<?= $content ?? '' ?>
  </div>
</div>

</body>
</html>
