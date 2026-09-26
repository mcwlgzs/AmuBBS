<?php
/**
 * 后台主框架外壳（layuimini v2 / iframe 多 tab）
 * ==========================================================================
 * 结构照搬 layuimini 官方的 index.html：
 *   body.layui-layout-body.layuimini-all
 *     └ .layui-layout.layui-layout-admin
 *         ├ .layui-header        顶栏：logo + 折叠按钮 + 右侧操作
 *         │                       （单模块模式没有顶部菜单，分组都在左侧）
 *         ├ .layui-side          左侧无限级菜单（由 miniMenu 渲染）
 *         ├ .layuimini-loader    初始化加载层
 *         └ .layui-body
 *             └ .layuimini-tab   多 tab 容器，每个 tab 是一个 iframe
 *
 * 工作方式：
 *   miniAdmin.render({iniUrl: '/admin/menu.json'}) 启动 —— 菜单、首页 tab、
 *   配色方案、hash 定位全部由 layuimini 自己接管，这个模板只提供容器和配置。
 *   菜单数据来自 config/admin_nav.php，见 DashboardController::menu()。
 *
 * 和上一版（htmx 局部替换）的区别，写在 docs/06-前端选型.md 里：
 *   这里每个后台页面都是独立文档、跑在自己的 iframe 里，
 *   所以 AdminBase::renderAdmin() 渲染的是 layout_child.php 而不是本文件；
 *   本文件只由 /admin 这一条路由渲染。
 *
 * 需要的变量：$pageTitle（可省）
 */

use Core\Helper;

$pageTitle = (string)($pageTitle ?? '管理后台');
$adminName = ($_SESSION['nickname'] ?? '') ?: ($_SESSION['username'] ?? '管理员');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - AMuBBS 管理后台</title>
  <meta name="renderer" content="webkit">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" href="/assets/images/admin-logo.svg">
  <?= \App\Middlewares\Csrf::tokenMeta() ?>

  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/vendor/layui/css/layui.css'), ENT_QUOTES) ?>" media="all">
  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/vendor/layuimini/css/layuimini.css'), ENT_QUOTES) ?>" media="all">
  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/vendor/layuimini/css/themes/default.css'), ENT_QUOTES) ?>" media="all">
  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/vendor/font-awesome/css/font-awesome.min.css'), ENT_QUOTES) ?>" media="all">
  <link rel="stylesheet" href="<?= htmlspecialchars(Helper::asset('/assets/css/admin-layui.css'), ENT_QUOTES) ?>" media="all">

  <!-- layuimini 的配色方案会往这里写 CSS 变量 -->
  <style id="layuimini-bg-color"></style>
</head>
<body class="layui-layout-body layuimini-all">

<div class="layui-layout layui-layout-admin">

  <!-- ==================== 顶栏 ==================== -->
  <div class="layui-header header">
    <!-- logo 与标题由 miniAdmin 依据 /admin/menu.json 的 logoInfo 渲染 -->
    <div class="layui-logo layuimini-logo"></div>

    <div class="layuimini-header-content">
      <a>
        <div class="layuimini-tool"><i title="展开" class="fa fa-outdent" data-side-fold="1"></i></div>
      </a>

      <!-- 电脑端头部菜单（多模块时显示一级分组） -->
      <ul class="layui-nav layui-layout-left layuimini-header-menu layuimini-menu-header-pc layuimini-pc-show"></ul>

      <!-- 手机端头部菜单 -->
      <ul class="layui-nav layui-layout-left layuimini-header-menu layuimini-mobile-show">
        <li class="layui-nav-item">
          <a href="javascript:;"><i class="fa fa-list-ul"></i> 选择模块</a>
          <dl class="layui-nav-child layuimini-menu-header-mobile"></dl>
        </li>
      </ul>

      <ul class="layui-nav layui-layout-right">

        <li class="layui-nav-item" lay-unselect>
          <a href="javascript:;" data-refresh="刷新"><i class="fa fa-refresh"></i></a>
        </li>

        <li class="layui-nav-item mobile layui-hide-xs" lay-unselect>
          <a href="javascript:;" data-check-screen="full"><i class="fa fa-arrows-alt"></i></a>
        </li>

        <li class="layui-nav-item layuimini-setting">
          <a href="javascript:;"><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></a>
          <dl class="layui-nav-child">
            <dd>
              <a href="javascript:;" layuimini-content-href="/admin/settings"
                 data-title="系统设置" data-icon="fa fa-cogs">系统设置</a>
            </dd>
            <dd>
              <a href="javascript:;" layuimini-content-href="/admin/system-info"
                 data-title="系统信息" data-icon="fa fa-info-circle">系统信息</a>
            </dd>
            <dd><hr></dd>
            <dd>
              <a href="javascript:;" class="login-out">退出登录</a>
            </dd>
          </dl>
        </li>

        <li class="layui-nav-item layuimini-select-bgcolor" lay-unselect>
          <a href="javascript:;" data-bgcolor="配色方案"><i class="fa fa-ellipsis-v"></i></a>
        </li>

      </ul>
    </div>
  </div>

  <!-- ==================== 左侧菜单 ==================== -->
  <!-- 内容由 miniMenu 依据 /admin/menu.json 渲染，无限级 -->
  <div class="layui-side layui-bg-black layuimini-menu-left"></div>

  <!-- 初始化加载层 -->
  <div class="layuimini-loader">
    <div class="layuimini-loader-inner"></div>
  </div>

  <!-- 手机端遮罩层 -->
  <div class="layuimini-make"></div>

  <!-- 手机端导航按钮（点击行为与顶栏 [data-side-fold] 一致，miniMenu 绑定） -->
  <div class="layuimini-site-mobile"><i class="fa fa-bars"></i></div>

  <!-- ==================== 内容区（多 tab） ==================== -->
  <div class="layui-body">

    <div class="layuimini-tab layui-tab-rollTool layui-tab" lay-filter="layuiminiTab" lay-allowclose="true">
      <ul class="layui-tab-title">
        <li class="layui-this" id="layuiminiHomeTabId" lay-id=""></li>
      </ul>

      <div class="layui-tab-control">
        <li class="layuimini-tab-roll-left layui-icon layui-icon-left"></li>
        <li class="layuimini-tab-roll-right layui-icon layui-icon-right"></li>
        <li class="layui-tab-tool layui-icon layui-icon-down">
          <ul class="layui-nav close-box">
            <li class="layui-nav-item">
              <a href="javascript:;"><span class="layui-nav-more"></span></a>
              <dl class="layui-nav-child">
                <dd><a href="javascript:;" layuimini-tab-close="current">关 闭 当 前</a></dd>
                <dd><a href="javascript:;" layuimini-tab-close="other">关 闭 其 他</a></dd>
                <dd><a href="javascript:;" layuimini-tab-close="all">关 闭 全 部</a></dd>
              </dl>
            </li>
          </ul>
        </li>
      </div>

      <div class="layui-tab-content">
        <!-- 首页 tab：miniAdmin.renderHome() 会往这里塞 iframe -->
        <div id="layuiminiHomeTabIframe" class="layui-tab-item layui-show"></div>
      </div>
    </div>

  </div>
</div>

<script src="<?= htmlspecialchars(Helper::asset('/assets/vendor/layui/layui.js'), ENT_QUOTES) ?>"></script>
<!-- lay-config.js 必须在 layui.js 之后：它用 document.scripts 的最后一项算 rootPath，
     并 layui.config({base}) 把 lay-module/ 注册给 layui.extend() -->
<script src="<?= htmlspecialchars(Helper::asset('/assets/vendor/layuimini/js/lay-config.js'), ENT_QUOTES) ?>"></script>
<script>
layui.use(['jquery', 'layer', 'miniAdmin'], function () {
  var $ = layui.jquery;
  var miniAdmin = layui.miniAdmin;

  miniAdmin.render({
    iniUrl: '/admin/menu.json',   // 菜单/首页/logo 全部由这个接口提供
    urlHashLocation: true,        // 打开 hash 定位，刷新后能回到原 tab
    bgColorDefault: false,        // 主题配色：沿用用户上次选择
    multiModule: false,           // 单模块：7 个一级分组全部收进左侧菜单树，
                                  // 不再占用顶栏（multiModule:true 会把分组横在顶部）
    menuChildOpen: false,         // 分组默认收起，只有「当前页面所在分组」自动展开
    loadingTime: 0,               // 初始化加载层不额外停留
    pageAnim: true,               // iframe 淡入动画
    maxTabNum: 20                 // 最多同时开 20 个 tab
  });

  $('.login-out').on('click', function () {
    window.location = '/logout';
  });
});
</script>
</body>
</html>
