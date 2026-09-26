<?php
/**
 * 后台导航定义（layuimini 菜单的唯一数据源）
 *
 * 两处消费，所以抽成配置文件，避免两边对不上：
 *   1. /admin/menu.json  → 转成 layuimini 的 {homeInfo, logoInfo, menuInfo} 交给前端渲染
 *   2. 其它需要「页面标题 / 面包屑 / 图标」的地方（例如子页面 <title>）
 *
 * icon 用 Font Awesome 4.7 的类名（layuimini 自带的图标体系，见
 * public/assets/vendor/font-awesome）。href 用绝对路径，因为子页面是在
 * iframe 里加载的，相对路径会以 iframe 的地址为基准，容易错。
 *
 * 注意：菜单是「一级分组 + 二级页面」，和 layuimini 的 menuInfo 结构一致。
 */

return [
    // 首页 tab：layuimini 启动时会用 homeInfo.href 打开这个 iframe
    'home' => [
        'title' => '仪表盘',
        'href'  => '/admin/dashboard',
    ],

    'logo' => [
        'title' => 'AMuBBS',
        'image' => '/assets/images/admin-logo.svg',
        'href'  => '/admin',
    ],

    'groups' => [
        '总览' => [
            'icon'  => 'fa fa-dashboard',
            'pages' => [
                'dashboard' => ['title' => '仪表盘',   'href' => '/admin/dashboard', 'icon' => 'fa fa-tachometer'],
                'monitor'   => ['title' => '监控统计', 'href' => '/admin/monitor',   'icon' => 'fa fa-line-chart'],
            ],
        ],
        '内容管理' => [
            'icon'  => 'fa fa-th-large',
            'pages' => [
                'forums'      => ['title' => '板块管理', 'href' => '/admin/forums',      'icon' => 'fa fa-th-large'],
                'threads'     => ['title' => '帖子管理', 'href' => '/admin/threads',     'icon' => 'fa fa-file-text-o'],
                'posts'       => ['title' => '回帖管理', 'href' => '/admin/posts',       'icon' => 'fa fa-comments-o'],
                'attachments' => ['title' => '附件管理', 'href' => '/admin/attachments', 'icon' => 'fa fa-paperclip'],
            ],
        ],
        '运营管理' => [
            'icon'  => 'fa fa-bullhorn',
            'pages' => [
                'announcements'   => ['title' => '公告管理', 'href' => '/admin/announcements',   'icon' => 'fa fa-bullhorn'],
                'tag-categories'  => ['title' => '标签分类', 'href' => '/admin/tag-categories',  'icon' => 'fa fa-tags'],
                'sensitive-words' => ['title' => '敏感词',   'href' => '/admin/sensitive-words', 'icon' => 'fa fa-shield'],
                'friend-links'    => ['title' => '友情链接', 'href' => '/admin/friend-links',    'icon' => 'fa fa-link'],
                'navigation'      => ['title' => '导航管理', 'href' => '/admin/navigation',      'icon' => 'fa fa-list-ul'],
            ],
        ],
        '用户管理' => [
            'icon'  => 'fa fa-users',
            'pages' => [
                'users'         => ['title' => '用户列表', 'href' => '/admin/users',         'icon' => 'fa fa-user'],
                'user-groups'   => ['title' => '用户组',   'href' => '/admin/user-groups',   'icon' => 'fa fa-users'],
                'levels'        => ['title' => '等级设置', 'href' => '/admin/levels',        'icon' => 'fa fa-signal'],
                'user-settings' => ['title' => '用户设置', 'href' => '/admin/user-settings', 'icon' => 'fa fa-sliders'],
                'vip-settings'  => ['title' => '会员设置', 'href' => '/admin/vip-settings',  'icon' => 'fa fa-diamond'],
            ],
        ],
        '互动管理' => [
            'icon'  => 'fa fa-comments',
            'pages' => [
                'notifications' => ['title' => '通知管理', 'href' => '/admin/notifications', 'icon' => 'fa fa-bell-o'],
                'messages'      => ['title' => '私信监控', 'href' => '/admin/messages',      'icon' => 'fa fa-envelope-o'],
                'credit-logs'   => ['title' => '积分记录', 'href' => '/admin/credit-logs',   'icon' => 'fa fa-database'],
            ],
        ],
        '系统管理' => [
            'icon'  => 'fa fa-cogs',
            'pages' => [
                'settings'    => ['title' => '系统设置', 'href' => '/admin/settings',    'icon' => 'fa fa-cogs'],
                'system-info' => ['title' => '系统信息', 'href' => '/admin/system-info', 'icon' => 'fa fa-info-circle'],
                'cache'       => ['title' => '缓存管理', 'href' => '/admin/cache',       'icon' => 'fa fa-refresh'],
                'cluster'     => ['title' => '集群管理', 'href' => '/admin/cluster',     'icon' => 'fa fa-server'],
                'plugins'     => ['title' => '插件管理', 'href' => '/admin/plugins',     'icon' => 'fa fa-puzzle-piece'],
            ],
        ],
        '安全管理' => [
            'icon'  => 'fa fa-shield',
            'pages' => [
                'ip-blacklist' => ['title' => 'IP 黑名单', 'href' => '/admin/ip-blacklist', 'icon' => 'fa fa-ban'],
                'logs'         => ['title' => '操作日志',  'href' => '/admin/logs',         'icon' => 'fa fa-history'],
            ],
        ],
    ],
];
