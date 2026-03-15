<?php include __DIR__ . '/layout_child.php'; ?>

<style>
.layui-card .layui-table{margin:0 !important;}
/* 用 CSS Grid 替代 layui 栅格，彻底解决等高 */
.dash-row{display:grid;gap:15px;margin-bottom:15px;}
.dash-row .layui-card{margin-bottom:0;height:100%;}
.dash-4{grid-template-columns:repeat(4,1fr);}
.dash-8-4{grid-template-columns:2fr 1fr;}
.dash-6-6{grid-template-columns:1fr 1fr;}
@media(max-width:768px){
  .dash-4{grid-template-columns:1fr 1fr;}
  .dash-8-4,.dash-6-6{grid-template-columns:1fr;}
}
/* 统计卡片 */
.stat-card{padding:20px 30px;box-sizing:border-box;}
.stat-card h3{font-size:14px;color:#666;font-weight:400;margin:0 0 10px;}
.stat-card .num{font-size:30px;font-weight:300;margin:0 0 8px;line-height:1.2;}
.stat-card .sub{font-size:12px;color:#999;margin:0;}
.stat-card .sub i{font-size:14px;margin-right:4px;vertical-align:middle;}
/* 快捷方式 */
.shortcut-grid{display:grid !important;grid-template-columns:repeat(4,1fr);padding:15px 10px;align-content:center;height:100%;box-sizing:border-box;}
.shortcut-grid a{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 0;color:#666;text-decoration:none;font-size:13px;transition:all .15s;}
.shortcut-grid a:hover{color:#009688;transform:translateY(-2px);}
.shortcut-grid a i{font-size:28px;margin-bottom:8px;}
/* 表格卡片统一 */
.tbl-card .layui-card-body{padding:0 15px 5px;}
</style>

<!-- 第一行：统计卡片 -->
<div class="dash-row dash-4">
  <div class="layui-card">
    <div class="layui-card-body stat-card">
      <h3>今日新增用户</h3>
      <p class="num" style="color:#009688;"><?= (int)($todayStats['users'] ?? 0) ?></p>
      <p class="sub"><i class="layui-icon layui-icon-user"></i>总计 <?= number_format($stats['users']) ?></p>
    </div>
  </div>
  <div class="layui-card">
    <div class="layui-card-body stat-card">
      <h3>今日发帖</h3>
      <p class="num" style="color:#1E9FFF;"><?= (int)($todayStats['threads'] ?? 0) ?></p>
      <p class="sub"><i class="layui-icon layui-icon-read"></i>总计 <?= number_format($stats['threads']) ?></p>
    </div>
  </div>
  <div class="layui-card">
    <div class="layui-card-body stat-card">
      <h3>今日回复</h3>
      <p class="num" style="color:#FFB800;"><?= (int)($todayStats['posts'] ?? 0) ?></p>
      <p class="sub"><i class="layui-icon layui-icon-dialogue"></i>总计 <?= number_format($stats['posts']) ?></p>
    </div>
  </div>
  <div class="layui-card">
    <div class="layui-card-body stat-card">
      <h3>板块数</h3>
      <p class="num" style="color:#FF5722;"><?= number_format($stats['forums']) ?></p>
      <p class="sub"><i class="layui-icon layui-icon-app"></i>活跃板块</p>
    </div>
  </div>
</div>

<!-- 第二行：快捷方式 -->
<div class="layui-card" style="margin-bottom:15px;">
  <div class="layui-card-header">快捷方式</div>
  <div class="layui-card-body shortcut-grid">
    <a lay-href="/admin/forums"><i class="layui-icon layui-icon-app" style="color:#009688;"></i>板块管理</a>
    <a lay-href="/admin/users"><i class="layui-icon layui-icon-user" style="color:#1E9FFF;"></i>用户管理</a>
    <a lay-href="/admin/threads"><i class="layui-icon layui-icon-read" style="color:#FFB800;"></i>帖子管理</a>
    <a lay-href="/admin/settings"><i class="layui-icon layui-icon-set" style="color:#FF5722;"></i>系统设置</a>
    <a lay-href="/admin/cache"><i class="layui-icon layui-icon-refresh-1" style="color:#2F4056;"></i>缓存管理</a>
    <a lay-href="/admin/announcements"><i class="layui-icon layui-icon-notice" style="color:#009688;"></i>公告管理</a>
    <a lay-href="/admin/logs"><i class="layui-icon layui-icon-log" style="color:#1E9FFF;"></i>操作日志</a>
    <a lay-href="/admin/navigation"><i class="layui-icon layui-icon-spread-left" style="color:#FFB800;"></i>导航管理</a>
  </div>
</div>

<!-- 第三行：最近注册 + 最近帖子 -->
<div class="dash-row dash-6-6">
  <div class="layui-card tbl-card">
    <div class="layui-card-header">最近注册</div>
    <div class="layui-card-body">
      <table class="layui-table" lay-size="sm">
        <thead><tr><th>用户名</th><th>邮箱</th><th>注册时间</th></tr></thead>
        <tbody>
        <?php foreach ($recentUsers as $u): ?>
          <tr>
            <td><?= htmlspecialchars(($u['nickname'] ?? '') ?: $u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= date('Y-m-d H:i', $u['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recentUsers)): ?>
          <tr><td colspan="3" style="text-align:center;color:#999;">暂无数据</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="layui-card tbl-card">
    <div class="layui-card-header">最近帖子</div>
    <div class="layui-card-body">
      <table class="layui-table" lay-size="sm">
        <thead><tr><th>标题</th><th>作者</th><th>时间</th></tr></thead>
        <tbody>
        <?php foreach ($recentThreads as $t): ?>
          <tr>
            <td><a href="/thread/<?= (int)$t['id'] ?>" target="_blank" style="color:#3b82f6;"><?= htmlspecialchars($t['title']) ?></a></td>
            <td><?= htmlspecialchars(($t['nickname'] ?? '') ?: $t['username']) ?></td>
            <td><?= date('Y-m-d H:i', $t['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recentThreads)): ?>
          <tr><td colspan="3" style="text-align:center;color:#999;">暂无数据</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- 第四行：操作日志 + 系统信息 -->
<div class="dash-row dash-8-4">
  <div class="layui-card tbl-card">
    <div class="layui-card-header">最近操作日志</div>
    <div class="layui-card-body">
      <table class="layui-table" lay-size="sm">
        <thead><tr><th width="150">时间</th><th width="90">管理员</th><th width="100">操作</th><th>详情</th></tr></thead>
        <tbody>
        <?php foreach ($recentLogs as $log): ?>
          <tr>
            <td style="font-size:12px;color:#999;"><?= date('Y-m-d H:i:s', $log['created_at'] ?? $log['timestamp'] ?? 0) ?></td>
            <td><?= htmlspecialchars($log['admin_username'] ?? $log['username'] ?? '-') ?></td>
            <td><span class="layui-badge layui-bg-blue"><?= htmlspecialchars($log['action'] ?? '-') ?></span></td>
            <td style="font-size:12px;"><?= htmlspecialchars($log['detail'] ?? $log['description'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recentLogs)): ?>
          <tr><td colspan="4" style="text-align:center;color:#999;">暂无日志</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="layui-card tbl-card">
    <div class="layui-card-header">系统信息</div>
    <div class="layui-card-body">
      <table class="layui-table" lay-size="sm">
        <tbody>
          <tr><td>PHP 版本</td><td><?= PHP_VERSION ?></td></tr>
          <tr><td>MySQL 版本</td><td><?= htmlspecialchars($mysqlVersion) ?></td></tr>
          <tr><td>服务器软件</td><td style="word-break:break-all;"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? '-') ?></td></tr>
          <tr><td>OPcache</td><td><?= extension_loaded('Zend OPcache') ? '<span style="color:#009688;">已启用</span>' : '<span style="color:#999;">未启用</span>' ?></td></tr>
          <tr><td>Redis</td><td><?= extension_loaded('redis') ? '<span style="color:#009688;">已安装</span>' : '<span style="color:#999;">未安装</span>' ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>


<?php include __DIR__ . '/layout_child_footer.php'; ?>
