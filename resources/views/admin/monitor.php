<?php
/**
 * 后台 - 监控统计（layuimini 子页面片段，纯服务端渲染，无 htmx、无 Bootstrap）
 *
 * 数据由 DashboardController::monitor() 一次给全，所以这里没有 /admin/api/* 请求：
 * 趋势表和两个 TOP 10 都是服务端渲染的静态 layui 表格（<table class="layui-table">），
 * 不要用 table.render() —— 那会再发一次请求去取已经在本页上的数据。
 *
 * 跳转前台的链接（/thread/…、/user/…）一律 target="_blank"：
 * 前台页面塞进后台 iframe 里会把整个后台外壳挤掉。
 *
 * 变量：$todayStats, $trend, $hotThreads, $activeUsers
 */

$todayStats  = $todayStats ?? [];
$trend       = $trend ?? [];
$hotThreads  = $hotThreads ?? [];
$activeUsers = $activeUsers ?? [];

/* 转义助手 */
$e = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

// 活跃度 = 新帖*3 + 回复 + 新用户*2，取 7 天内最大值做归一化
$maxActivity = 1;
foreach ($trend as $d) {
    $a = ((int)$d['threads'] * 3) + (int)$d['posts'] + ((int)$d['users'] * 2);
    if ($a > $maxActivity) {
        $maxActivity = $a;
    }
}

$cards = [
    ['今日新用户', (int)($todayStats['new_users'] ?? 0)],
    ['今日新帖',   (int)($todayStats['new_threads'] ?? 0)],
    ['今日回复',   (int)($todayStats['new_posts'] ?? 0)],
];
?>

<!-- 页面标题 -->
<div class="layui-card">
  <div class="layui-card-body">
    <span style="font-size:16px;font-weight:600">监控统计</span>
    <span class="layui-word-aux" style="margin-left:8px">数据截至 <?= date('Y-m-d H:i') ?></span>
  </div>
</div>

<!-- 今日概览 -->
<div class="layui-row layui-col-space15" style="margin-top:15px">
  <?php foreach ($cards as [$label, $value]): ?>
    <div class="layui-col-md4 layui-col-xs12">
      <div class="layui-card">
        <div class="layui-card-body">
          <div class="layui-word-aux"><?= $e($label) ?></div>
          <div class="admin-num" style="font-size:24px;font-weight:600"><?= number_format($value) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- 7 天趋势 -->
<div class="layui-card" style="margin-top:15px">
  <div class="layui-card-header">
    <i class="fa fa-line-chart"></i>7 天趋势
    <span style="float:right;font-weight:400" class="layui-word-aux">共 <?= count($trend) ?> 天</span>
  </div>
  <div class="layui-card-body">
    <table class="layui-table" lay-size="sm">
      <thead>
        <tr>
          <th style="width:100px">日期</th>
          <th style="width:80px;text-align:right">新帖</th>
          <th style="width:80px;text-align:right">回复</th>
          <th style="width:80px;text-align:right">新用户</th>
          <th>活跃度</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($trend as $d): ?>
        <?php
          $activity = ((int)$d['threads'] * 3) + (int)$d['posts'] + ((int)$d['users'] * 2);
          $pct = (int)round($activity / $maxActivity * 100);
        ?>
        <tr>
          <td class="admin-muted"><?= $e((string)$d['date']) ?></td>
          <td style="text-align:right" class="admin-num"><?= (int)$d['threads'] ?></td>
          <td style="text-align:right" class="admin-num"><?= (int)$d['posts'] ?></td>
          <td style="text-align:right" class="admin-num"><?= (int)$d['users'] ?></td>
          <td>
            <div style="background:#f2f2f2;border-radius:2px;height:8px;overflow:hidden" title="活跃度 <?= $pct ?>%">
              <div style="width:<?= $pct ?>%;height:100%;background:#1e9fff"></div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($trend)): ?>
        <tr>
          <td colspan="5">
            <div class="layui-word-aux" style="text-align:center;padding:15px 0">暂无趋势数据 · 近 7 天还没有产生内容</div>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="layui-row layui-col-space15" style="margin-top:15px">
  <!-- 本周热帖 -->
  <div class="layui-col-md6">
    <div class="layui-card">
      <div class="layui-card-header">
        <i class="fa fa-list-ul"></i>本周热帖 TOP 10
        <span style="float:right;font-weight:400" class="layui-word-aux">共 <?= count($hotThreads) ?> 条</span>
      </div>
      <div class="layui-card-body">
        <table class="layui-table" lay-size="sm">
          <thead>
            <tr>
              <th style="width:44px">#</th>
              <th>标题</th>
              <th style="width:80px;text-align:right">浏览</th>
              <th style="width:80px;text-align:right">回复</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($hotThreads as $i => $ht): ?>
            <tr>
              <td><span style="font-weight:600;<?= $i < 3 ? 'color:#1e9fff' : 'color:#999' ?>"><?= $i + 1 ?></span></td>
              <td>
                <a class="admin-ellipsis" href="/thread/<?= (int)$ht['id'] ?>" target="_blank" rel="noopener"
                   title="<?= $e((string)$ht['title']) ?>">
                  <?= $e(mb_substr((string)$ht['title'], 0, 25)) ?>
                </a>
              </td>
              <td class="admin-muted admin-num" style="text-align:right"><?= number_format((int)$ht['views']) ?></td>
              <td class="admin-muted admin-num" style="text-align:right"><?= number_format((int)$ht['reply_count']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($hotThreads)): ?>
            <tr>
              <td colspan="4">
                <div class="layui-word-aux" style="text-align:center;padding:15px 0">暂无热帖 · 近 7 天还没有主题被浏览</div>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 本周活跃用户 -->
  <div class="layui-col-md6">
    <div class="layui-card">
      <div class="layui-card-header">
        <i class="fa fa-comments-o"></i>本周活跃用户 TOP 10
        <span style="float:right;font-weight:400" class="layui-word-aux">共 <?= count($activeUsers) ?> 条</span>
      </div>
      <div class="layui-card-body">
        <table class="layui-table" lay-size="sm">
          <thead>
            <tr>
              <th style="width:44px">#</th>
              <th>用户</th>
              <th style="width:90px;text-align:right">回复数</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($activeUsers as $i => $au): ?>
            <tr>
              <td><span style="font-weight:600;<?= $i < 3 ? 'color:#1e9fff' : 'color:#999' ?>"><?= $i + 1 ?></span></td>
              <td>
                <a href="/user/<?= (int)$au['id'] ?>" target="_blank" rel="noopener">
                  <?= $e(($au['nickname'] ?? '') ?: ($au['username'] ?? '')) ?>
                </a>
              </td>
              <td class="admin-muted admin-num" style="text-align:right"><?= number_format((int)$au['post_count']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($activeUsers)): ?>
            <tr>
              <td colspan="3">
                <div class="layui-word-aux" style="text-align:center;padding:15px 0">暂无活跃用户 · 近 7 天还没有用户参与回复</div>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
