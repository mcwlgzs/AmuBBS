<?php
/**
 * 后台主框架页（Shell）- layuiAdmin iframe 版
 * 包含：顶部栏 + 侧边菜单 + 标签栏 + iframe 容器
 */

$_adminMenus = [
    'dashboard' => ['url' => '/admin/dashboard', 'text' => '首页', 'icon' => 'layui-icon-home'],
    'monitor' => ['url' => '/admin/monitor', 'text' => '监控统计', 'icon' => 'layui-icon-chart'],
    'content' => [
        'text' => '内容管理',
        'icon' => 'layui-icon-read',
        'children' => [
            'forums' => ['url' => '/admin/forums', 'text' => '板块管理', 'icon' => 'layui-icon-app'],
            'threads' => ['url' => '/admin/threads', 'text' => '帖子管理', 'icon' => 'layui-icon-read'],
            'posts' => ['url' => '/admin/posts', 'text' => '回帖管理', 'icon' => 'layui-icon-dialogue'],
            'attachments' => ['url' => '/admin/attachments', 'text' => '附件管理', 'icon' => 'layui-icon-file'],
        ],
    ],
    'operation' => [
        'text' => '运营管理',
        'icon' => 'layui-icon-flag',
        'children' => [
            'announcements' => ['url' => '/admin/announcements', 'text' => '公告管理', 'icon' => 'layui-icon-notice'],
            'tag-categories' => ['url' => '/admin/tag-categories', 'text' => '标签分类', 'icon' => 'layui-icon-note'],
            'sensitive-words' => ['url' => '/admin/sensitive-words', 'text' => '敏感词', 'icon' => 'layui-icon-auz'],
            'friend-links' => ['url' => '/admin/friend-links', 'text' => '友情链接', 'icon' => 'layui-icon-link'],
            'navigation' => ['url' => '/admin/navigation', 'text' => '导航管理', 'icon' => 'layui-icon-spread-left'],
        ],
    ],
    'user' => [
        'text' => '用户管理',
        'icon' => 'layui-icon-user',
        'children' => [
            'users' => ['url' => '/admin/users', 'text' => '用户列表', 'icon' => 'layui-icon-user'],
            'user-groups' => ['url' => '/admin/user-groups', 'text' => '用户组', 'icon' => 'layui-icon-group'],
            'levels' => ['url' => '/admin/levels', 'text' => '等级设置', 'icon' => 'layui-icon-chart'],
            'user-settings' => ['url' => '/admin/user-settings', 'text' => '用户设置', 'icon' => 'layui-icon-set'],
            'vip-settings' => ['url' => '/admin/vip-settings', 'text' => '会员设置', 'icon' => 'layui-icon-diamond'],
            'online-users' => ['url' => '/admin/online-users', 'text' => '在线用户', 'icon' => 'layui-icon-friends'],
        ],
    ],
    'interact' => [
        'text' => '互动管理',
        'icon' => 'layui-icon-chat',
        'children' => [
            'notifications' => ['url' => '/admin/notifications', 'text' => '通知管理', 'icon' => 'layui-icon-notice'],
            'messages' => ['url' => '/admin/messages', 'text' => '私信监控', 'icon' => 'layui-icon-email'],
            'credit-logs' => ['url' => '/admin/credit-logs', 'text' => '积分记录', 'icon' => 'layui-icon-rmb'],
        ],
    ],
    'system' => [
        'text' => '系统管理',
        'icon' => 'layui-icon-set',
        'children' => [
            'settings' => ['url' => '/admin/settings', 'text' => '系统设置', 'icon' => 'layui-icon-set'],
            'system-info' => ['url' => '/admin/system-info', 'text' => '系统信息', 'icon' => 'layui-icon-about'],
            'cache' => ['url' => '/admin/cache', 'text' => '缓存管理', 'icon' => 'layui-icon-refresh-1'],
            'cluster' => ['url' => '/admin/cluster', 'text' => '集群管理', 'icon' => 'layui-icon-engine'],
        ],
    ],
    'security' => [
        'text' => '安全管理',
        'icon' => 'layui-icon-auz',
        'children' => [
            'ip-blacklist' => ['url' => '/admin/ip-blacklist', 'text' => 'IP 黑名单', 'icon' => 'layui-icon-close-fill'],
            'logs' => ['url' => '/admin/logs', 'text' => '操作日志', 'icon' => 'layui-icon-log'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMuBBS 管理后台</title>
  <?= \App\Middlewares\Csrf::tokenMeta() ?>
  <link rel="stylesheet" href="/layui/css/layui.css">
  <link rel="stylesheet" href="/layuiadmin/style/admin.css">
  <link rel="stylesheet" href="/assets/css/admin-responsive.css">
</head>
<body class="layui-layout-body">

<div id="LAY_app">
<div class="layui-layout layui-layout-admin">

  <!-- 顶部栏 -->
  <div class="layui-header">
    <ul class="layui-nav layui-layout-left">
      <li class="layui-nav-item layadmin-flexible" layadmin-event="flexible">
        <a href="javascript:;" title="侧栏伸缩">
          <i class="layui-icon layui-icon-shrink-right" id="LAY_app_flexible"></i>
        </a>
      </li>
      <li class="layui-nav-item layui-hide-xs">
        <a href="/" target="_blank" title="前台首页"><i class="layui-icon layui-icon-website"></i></a>
      </li>
      <li class="layui-nav-item layui-hide-xs" layadmin-event="refresh">
        <a href="javascript:;" title="刷新"><i class="layui-icon layui-icon-refresh-3"></i></a>
      </li>
    </ul>
    <ul class="layui-nav layui-layout-right">
      <li class="layui-nav-item layui-hide-xs" layadmin-event="theme">
        <a href="javascript:;" title="主题"><i class="layui-icon layui-icon-theme"></i></a>
      </li>
      <li class="layui-nav-item layui-hide-xs" id="LAY_notice">
        <a href="javascript:;" layadmin-event="message" title="消息通知">
          <i class="layui-icon layui-icon-notice"></i>
          <span class="layui-badge-dot" id="LAY_notice_badge" style="display:none;"></span>
        </a>
      </li>
      <li class="layui-nav-item layui-hide-xs" layadmin-event="fullscreen">
        <a href="javascript:;" title="全屏"><i class="layui-icon layui-icon-screen-full"></i></a>
      </li>
      <li class="layui-nav-item">
        <a href="javascript:;">
          <?php if (!empty($_SESSION['avatar'])): ?>
          <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>" style="width:26px;height:26px;border-radius:50%;margin-right:6px;vertical-align:middle;">
          <?php else: ?>
          <i class="layui-icon layui-icon-username" style="margin-right:4px;"></i>
          <?php endif; ?>
          <?= htmlspecialchars(($_SESSION['nickname'] ?? '') ?: ($_SESSION['username'] ?? '管理员')) ?>
        </a>
        <dl class="layui-nav-child">
          <dd><a href="/" target="_blank">访问前台</a></dd>
          <dd><a href="javascript:;" layadmin-event="logout">退出登录</a></dd>
        </dl>
      </li>
    </ul>
  </div>

  <!-- 侧边菜单 -->
  <div class="layui-side layui-side-menu">
    <div class="layui-side-scroll">
      <div class="layui-logo" lay-href="/admin/dashboard">
        <span>AMuBBS</span>
      </div>
      <ul class="layui-nav layui-nav-tree" lay-shrink="all" id="LAY-system-side-menu" lay-filter="layadmin-system-side-menu">
        <?php foreach ($_adminMenus as $key => $menu): ?>
          <?php if (isset($menu['children'])): ?>
          <li class="layui-nav-item" data-name="<?= $key ?>">
            <a href="javascript:;" lay-tips="<?= $menu['text'] ?>" lay-direction="2">
              <i class="layui-icon <?= $menu['icon'] ?? '' ?>"></i>
              <cite><?= $menu['text'] ?></cite>
            </a>
            <dl class="layui-nav-child">
              <?php foreach ($menu['children'] as $cKey => $child): ?>
              <dd data-name="<?= $cKey ?>">
                <a lay-href="<?= $child['url'] ?>"><?= $child['text'] ?></a>
              </dd>
              <?php endforeach; ?>
            </dl>
          </li>
          <?php else: ?>
          <li class="layui-nav-item" data-name="<?= $key ?>">
            <a lay-href="<?= $menu['url'] ?>" lay-tips="<?= $menu['text'] ?>" lay-direction="2">
              <i class="layui-icon <?= $menu['icon'] ?? '' ?>"></i>
              <cite><?= $menu['text'] ?></cite>
            </a>
          </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <!-- 页面标签栏 -->
  <div class="layadmin-pagetabs" id="LAY_app_tabs">
    <div class="layui-icon layadmin-tabs-control layui-icon-prev" layadmin-event="leftPage"></div>
    <div class="layui-icon layadmin-tabs-control layui-icon-next" layadmin-event="rightPage"></div>
    <div class="layui-icon layadmin-tabs-control layui-icon-down">
      <ul class="layui-nav layadmin-tabs-select" lay-filter="layadmin-pagetabs-nav">
        <li class="layui-nav-item" lay-unselect>
          <a href="javascript:;"></a>
          <dl class="layui-nav-child layui-anim-fadein">
            <dd layadmin-event="closeThisTabs"><a href="javascript:;">关闭当前标签页</a></dd>
            <dd layadmin-event="closeOtherTabs"><a href="javascript:;">关闭其它标签页</a></dd>
            <dd layadmin-event="closeAllTabs"><a href="javascript:;">关闭全部标签页</a></dd>
          </dl>
        </li>
      </ul>
    </div>
    <div class="layui-tab" lay-unauto lay-allowClose="true" lay-filter="layadmin-layout-tabs">
      <ul class="layui-tab-title" id="LAY_app_tabsheader">
        <li lay-id="/admin/dashboard" lay-attr="/admin/dashboard" class="layui-this"><i class="layui-icon layui-icon-home"></i></li>
      </ul>
    </div>
  </div>

  <!-- iframe 主体容器 -->
  <div class="layui-body" id="LAY_app_body">
    <div class="layadmin-tabsbody-item layui-show">
      <iframe src="/admin/dashboard" frameborder="0" class="layadmin-iframe"></iframe>
    </div>
  </div>

</div>
</div>

<script src="/layui/layui.js"></script>
<script>
layui.config({
  base: '/layuiadmin/'
}).extend({
  index: 'lib/index'
}).use(['index'], function(){
  var $ = layui.$;

  // 消息通知 badge 轮询
  var noticeTimer = null;
  function checkNotice(){
    $.ajax({
      url: '/admin/api/notifications', type: 'GET', data: {page:1, limit:1},
      success: function(res){
        if(res.code === 0 && res.count > 0){
          $('#LAY_notice_badge').show();
        } else {
          $('#LAY_notice_badge').hide();
        }
      },
      error: function(xhr){
        if(xhr.status === 403 || xhr.status === 401){
          clearInterval(noticeTimer);
        }
      }
    });
  }
  checkNotice();
  noticeTimer = setInterval(checkNotice, 60000);

  // 点击消息通知 → 通过 layuiAdmin tabsPage 打开通知管理页
  layui.admin.events.message = function(){
    layui.index.openTabsPage('/admin/notifications', '通知管理');
    $('#LAY_notice_badge').hide();
  };
});
</script>
</body>
</html>
