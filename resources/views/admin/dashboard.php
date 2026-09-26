<?php
/**
 * 后台仪表盘（layuimini 子页面片段）
 *
 * 纯服务端渲染：数据由 DashboardController::dashboard() 一次给全，
 * 这里**没有** htmx、没有 /admin/api/* 请求、没有 Bootstrap 栅格 ——
 * 全部用 layui 的卡片 / 栅格 / 静态表格表达。
 *
 * 形态约定（layuimini iframe 多 tab）：
 *   - 只写正文片段，文档骨架（doctype / head / body）由 layout_child.php 提供
 *   - 跳转到**其它后台页面**用 layuimini 的 tab 属性
 *     [layuimini-content-href] + data-title（miniTab 监听这个属性），
 *     同时保留真实 href + target="_self" 作为兜底
 *   - 跳转到**前台**页面（/、/thread/…）用 target="_blank"，
 *     免得前台页面被塞进后台的 iframe 里
 *
 * 变量：$stats, $todayStats, $recentUsers, $recentThreads, $recentLogs, $mysqlVersion
 *
 * 布局说明：
 *   - 顶部四张统计卡（今日 + 累计）回答「现在怎么样」
 *   - 中间是快捷入口，回答「我要去哪」
 *   - 下面按「人 / 内容 / 系统」分栏，回答「出了什么事」
 */

$stats      = is_array($stats ?? null) ? $stats : [];
$todayStats = is_array($todayStats ?? null) ? $todayStats : [];
$recentUsers   = $recentUsers ?? [];
$recentThreads = $recentThreads ?? [];
$recentLogs    = $recentLogs ?? [];
$mysqlVersion  = $mysqlVersion ?? '-';

/* 转义助手：本文件里到处要输出文本，写一次省得每行都挂一长串 htmlspecialchars */
$e = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

/**
 * 后台页面之间的跳转链接属性：layuimini 原生「在 iframe 子页面里开新 tab」。
 * miniTab 里绑定的是 [layuimini-content-href]（值即 tabId），配 data-title 作标题。
 * 仍然输出真实 href + target="_self"，万一外壳没接管点击，链接也照常能用。
 */
$tabLink = static function (string $href, string $title, string $icon = '') use ($e): string {
    $attr = 'href="' . $e($href) . '" target="_self"'
        . ' layuimini-content-href="' . $e($href) . '"'
        . ' data-title="' . $e($title) . '"';

    if ($icon !== '') {
        $attr .= ' data-icon="' . $e($icon) . '"';
    }

    return $attr;
};

/* 语义色 → layui 配色（快捷入口图标） */
$tones = [
    'is-info'    => '#1e9fff',
    'is-success' => '#5fb878',
    'is-warning' => '#ffb800',
    'is-danger'  => '#ff5722',
    'is-accent'  => '#a233c6',
];

/* 快捷入口：URL / 文案 / Font Awesome 图标 / 语义色 */
$shortcuts = [
    ['/admin/forums',        '板块管理', 'fa fa-th-large',   'is-info'],
    ['/admin/users',         '用户管理', 'fa fa-user',       'is-success'],
    ['/admin/threads',       '帖子管理', 'fa fa-file-text-o', 'is-warning'],
    ['/admin/settings',      '系统设置', 'fa fa-cogs',       'is-accent'],
    ['/admin/cache',         '缓存管理', 'fa fa-refresh',    'is-info'],
    ['/admin/announcements', '公告管理', 'fa fa-bullhorn',   'is-danger'],
    ['/admin/logs',          '操作日志', 'fa fa-history',    'is-success'],
    ['/admin/navigation',    '导航管理', 'fa fa-list-ul',    'is-warning'],
];

/* 统计卡：今日值 + 累计值 */
$cards = [
    ['今日新增用户', (int)($todayStats['users'] ?? 0),   '累计 ' . number_format((int)($stats['users'] ?? 0)) . ' 位用户'],
    ['今日发帖',     (int)($todayStats['threads'] ?? 0), '累计 ' . number_format((int)($stats['threads'] ?? 0)) . ' 篇主题'],
    ['今日回复',     (int)($todayStats['posts'] ?? 0),   '累计 ' . number_format((int)($stats['posts'] ?? 0)) . ' 条回复'],
    ['板块数',       (int)($stats['forums'] ?? 0),       '内容分区'],
];
?>

<!-- 页面标题 + 主要动作 -->
<div class="layui-card">
  <div class="layui-card-body" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px">
    <div style="flex:1 1 260px;min-width:200px">
      <span style="font-size:16px;font-weight:600">仪表盘</span>
      <span class="layui-word-aux" style="margin-left:8px">站点运行概览 · 数据截至 <?= date('Y-m-d H:i') ?></span>
    </div>
    <div>
      <a class="layui-btn layui-btn-sm layui-btn-primary" href="/" target="_blank" rel="noopener">
        <i class="fa fa-external-link"></i> 访问前台
      </a>
      <a class="layui-btn layui-btn-sm" <?= $tabLink('/admin/threads', '帖子管理', 'fa fa-file-text-o') ?>>
        <i class="fa fa-file-text-o"></i> 管理帖子
      </a>
    </div>
  </div>
</div>

<!-- 统计卡：今日 + 累计 -->
<div class="layui-row layui-col-space15" style="margin-top:15px">
  <?php foreach ($cards as [$label, $value, $sub]): ?>
    <div class="layui-col-md3 layui-col-xs6">
      <div class="layui-card">
        <div class="layui-card-body">
          <div class="layui-word-aux"><?= $e($label) ?></div>
          <div class="admin-num" style="font-size:24px;font-weight:600"><?= number_format($value) ?></div>
          <div class="layui-word-aux"><?= $e($sub) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- 快捷入口 -->
<div class="layui-card">
  <div class="layui-card-header"><i class="fa fa-bolt"></i>快捷入口</div>
  <div class="layui-card-body">
    <div class="layui-row layui-col-space10">
      <?php foreach ($shortcuts as [$url, $text, $ico, $tone]): ?>
        <div class="layui-col-md3 layui-col-xs6">
          <a style="display:block;padding:12px 8px;border:1px solid #e6e6e6;border-radius:2px;text-align:center;color:#333"
             <?= $tabLink($url, $text, $ico) ?>>
            <i class="<?= $e($ico) ?>" style="display:block;font-size:20px;margin-bottom:6px;color:<?= $e($tones[$tone] ?? '#1e9fff') ?>"></i>
            <?= $e($text) ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- 最近注册 / 最近帖子 -->
<div class="layui-row layui-col-space15" style="margin-top:15px">
  <div class="layui-col-md6">
    <div class="layui-card">
      <div class="layui-card-header">
        <i class="fa fa-user-plus"></i>最近注册
        <span style="float:right;font-weight:400">
          <a <?= $tabLink('/admin/users', '用户列表', 'fa fa-user') ?>>全部用户 <i class="fa fa-angle-right"></i></a>
        </span>
      </div>
      <?php if (empty($recentUsers)): ?>
        <div class="layui-card-body">
          <div class="layui-word-aux" style="text-align:center;padding:20px 0">
            还没有新用户 · 有用户注册后会显示在这里
          </div>
        </div>
      <?php else: ?>
        <div class="layui-card-body">
          <table class="layui-table" lay-size="sm">
            <thead>
              <tr>
                <th>用户名</th>
                <th>邮箱</th>
                <th style="width:120px;text-align:right">注册时间</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($recentUsers as $u): ?>
              <tr>
                <td>
                  <span style="display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;
                               border-radius:50%;background:#f2f2f2;color:#666;font-size:12px;margin-right:6px">
                    <?= $e(mb_substr((string)(($u['nickname'] ?? '') ?: $u['username']), 0, 1)) ?>
                  </span>
                  <?= $e(($u['nickname'] ?? '') ?: $u['username']) ?>
                </td>
                <td class="admin-muted"><?= $e((string)$u['email']) ?></td>
                <td class="admin-muted" style="text-align:right;white-space:nowrap"><?= date('Y-m-d H:i', (int)$u['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="layui-col-md6">
    <div class="layui-card">
      <div class="layui-card-header">
        <i class="fa fa-file-text-o"></i>最近帖子
        <span style="float:right;font-weight:400">
          <a <?= $tabLink('/admin/threads', '帖子管理', 'fa fa-file-text-o') ?>>全部帖子 <i class="fa fa-angle-right"></i></a>
        </span>
      </div>
      <?php if (empty($recentThreads)): ?>
        <div class="layui-card-body">
          <div class="layui-word-aux" style="text-align:center;padding:20px 0">
            还没有帖子 · 新发布的主题会显示在这里
          </div>
        </div>
      <?php else: ?>
        <div class="layui-card-body">
          <table class="layui-table" lay-size="sm">
            <thead>
              <tr>
                <th>标题</th>
                <th style="width:90px">作者</th>
                <th style="width:120px;text-align:right">时间</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($recentThreads as $t): ?>
              <tr>
                <td>
                  <a class="admin-ellipsis" href="/thread/<?= (int)$t['id'] ?>" target="_blank" rel="noopener"
                     title="<?= $e((string)$t['title']) ?>">
                    <?= $e((string)$t['title']) ?>
                  </a>
                </td>
                <td class="admin-muted"><?= $e(($t['nickname'] ?? '') ?: (string)$t['username']) ?></td>
                <td class="admin-muted" style="text-align:right;white-space:nowrap"><?= date('Y-m-d H:i', (int)$t['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- 最近操作日志 / 运行环境 -->
<div class="layui-row layui-col-space15" style="margin-top:15px">
  <div class="layui-col-md8">
    <div class="layui-card">
      <div class="layui-card-header">
        <i class="fa fa-history"></i>最近操作日志
        <span style="float:right;font-weight:400">
          <a <?= $tabLink('/admin/logs', '操作日志', 'fa fa-history') ?>>全部日志 <i class="fa fa-angle-right"></i></a>
        </span>
      </div>
      <?php if (empty($recentLogs)): ?>
        <div class="layui-card-body">
          <div class="layui-word-aux" style="text-align:center;padding:20px 0">
            暂无操作日志 · 后台的增删改操作都会记录在这里
          </div>
        </div>
      <?php else: ?>
        <div class="layui-card-body">
          <table class="layui-table" lay-size="sm">
            <thead>
              <tr>
                <th style="width:150px">时间</th>
                <th style="width:90px">管理员</th>
                <th style="width:100px">操作</th>
                <th>详情</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
              <tr>
                <td class="admin-muted" style="white-space:nowrap"><?= date('Y-m-d H:i:s', (int)($log['created_at'] ?? $log['timestamp'] ?? 0)) ?></td>
                <td><?= $e($log['admin_username'] ?? $log['username'] ?? '-') ?></td>
                <td><span class="layui-badge layui-bg-blue"><?= $e($log['action'] ?? '-') ?></span></td>
                <td class="admin-muted">
                  <span class="admin-ellipsis" title="<?= $e((string)($log['detail'] ?? $log['description'] ?? '-')) ?>">
                    <?= $e((string)($log['detail'] ?? $log['description'] ?? '-')) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="layui-col-md4">
    <div class="layui-card">
      <div class="layui-card-header">
        <i class="fa fa-server"></i>运行环境
        <span style="float:right;font-weight:400">
          <a <?= $tabLink('/admin/system-info', '系统信息', 'fa fa-info-circle') ?>>详情 <i class="fa fa-angle-right"></i></a>
        </span>
      </div>
      <div class="layui-card-body">
        <table class="layui-table" lay-size="sm">
          <tbody>
            <tr>
              <td class="admin-muted" style="width:110px">PHP 版本</td>
              <td><?= PHP_VERSION ?></td>
            </tr>
            <tr>
              <td class="admin-muted">MySQL 版本</td>
              <td><?= $e((string)$mysqlVersion) ?></td>
            </tr>
            <tr>
              <td class="admin-muted">服务器软件</td>
              <td><?= $e($_SERVER['SERVER_SOFTWARE'] ?? '-') ?></td>
            </tr>
            <tr>
              <td class="admin-muted">OPcache</td>
              <td>
                <?php if (extension_loaded('Zend OPcache')): ?>
                  <span class="admin-tag admin-tag-highlight">已启用</span>
                <?php else: ?>
                  <span class="admin-tag admin-tag-locked">未启用</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="admin-muted">Redis</td>
              <td>
                <?php if (extension_loaded('redis')): ?>
                  <span class="admin-tag admin-tag-highlight">已安装</span>
                <?php else: ?>
                  <span class="admin-tag admin-tag-locked">未安装</span>
                <?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
