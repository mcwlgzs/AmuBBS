<?php
/**
 * 后台 - 缓存管理（layuimini 子页面）
 *
 * 变量：$redisInfo, $cacheDriver
 *
 * 迁移要点：
 *   1. 四个「清除缓存」按钮改为 AdminUi.confirmPost()，POST /admin/cache/clear，
 *      type 取值 all / forums / threads / users 一个不少；
 *   2. 驱动徽章用 layui-badge，Redis 状态用 layui-table 渲染「键 → 值」，
 *      数值全部来自 $redisInfo（版本 / 内存 / Key 数 / 命中率 / 连接数 / 运行天数）；
 *   3. 没有 Redis 时保留原有的兜底说明（扩展未装或连不上会自动改用文件缓存）。
 */

$redisInfo   = $redisInfo ?? null;
$cacheDriver = $cacheDriver ?? 'file';

$driverLabel = $cacheDriver === 'redis' ? 'Redis' : '文件缓存';
$driverHint  = $cacheDriver === 'redis'
    ? '当前使用 Redis 作为缓存后端。'
    : '当前使用文件缓存（storage/cache/），无需 Redis 扩展即可工作。';
?>

<blockquote class="layui-elem-quote layui-quote-nm">
  <?= htmlspecialchars($driverHint, ENT_QUOTES, 'UTF-8') ?>
  <span class="layui-badge <?= $cacheDriver === 'redis' ? 'layui-bg-green' : 'layui-bg-gray' ?>">
    当前驱动：<?= htmlspecialchars($driverLabel, ENT_QUOTES, 'UTF-8') ?>
  </span>
</blockquote>

<div class="layui-card">
  <div class="layui-card-header">缓存操作</div>
  <div class="layui-card-body">
    <div class="layui-btn-container">
      <button type="button" class="layui-btn layui-btn-sm layui-btn-danger" id="cacheClearAll">
        清除全部缓存
      </button>
      <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-cache-type="forums">
        清除板块缓存
      </button>
      <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-cache-type="threads">
        清除帖子缓存
      </button>
      <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-cache-type="users">
        清除用户缓存
      </button>
    </div>
    <div class="layui-word-aux" style="padding-left:0">
      清除后站点会短暂变慢，随后自动重建；板块 / 帖子 / 用户缓存可分别清理。
    </div>
  </div>
</div>

<div class="layui-card">
  <div class="layui-card-header">Redis 状态</div>
  <div class="layui-card-body">

    <?php if ($redisInfo): ?>

      <table class="layui-table" lay-size="sm">
        <colgroup>
          <col width="180">
          <col>
        </colgroup>
        <tbody>
          <tr>
            <td class="admin-muted">版本</td>
            <td><?= htmlspecialchars((string)$redisInfo['version'], ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <tr>
            <td class="admin-muted">内存使用</td>
            <td><?= htmlspecialchars((string)$redisInfo['used_memory_human'], ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <tr>
            <td class="admin-muted">Key 数量</td>
            <td><span class="admin-num"><?= number_format((int)$redisInfo['total_keys']) ?></span></td>
          </tr>
          <tr>
            <td class="admin-muted">命中率</td>
            <td>
              <span class="admin-num"><?= htmlspecialchars((string)$redisInfo['hit_rate'], ENT_QUOTES, 'UTF-8') ?>%</span>
              <span class="admin-muted">（keyspace hits）</span>
            </td>
          </tr>
          <tr>
            <td class="admin-muted">连接客户端数</td>
            <td><span class="admin-num"><?= (int)$redisInfo['connected_clients'] ?></span></td>
          </tr>
          <tr>
            <td class="admin-muted">运行天数</td>
            <td><span class="admin-num"><?= (int)$redisInfo['uptime_days'] ?></span> 天</td>
          </tr>
        </tbody>
      </table>

    <?php else: ?>

      <blockquote class="layui-elem-quote layui-quote-nm" style="margin-bottom:10px">
        <strong>未检测到可用的 Redis</strong><br>
        扩展未安装或连接失败；项目会自动改用文件缓存，功能不受影响。
      </blockquote>
      <p class="admin-muted" style="padding-left:0">
        缓存目录 <code>storage/cache/</code>，只需保证该目录可写；共享虚拟主机上通常就是这种模式。
      </p>

    <?php endif; ?>

  </div>
</div>

<script>
layui.use(['layer'], function () {
  var $ = layui.jquery;

  function clearCache(type, text) {
    AdminUi.confirmPost(text, '/admin/cache/clear', { type: type });
  }

  $('#cacheClearAll').on('click', function () {
    clearCache('all', '确定要清除全部缓存吗？站点会短暂变慢，随后自动重建。');
  });

  $('[data-cache-type]').on('click', function () {
    var type = $(this).data('cache-type');
    var labels = { forums: '板块', threads: '帖子', users: '用户' };
    clearCache(type, '确定要清除「' + (labels[type] || type) + '」缓存吗？');
  });
});
</script>
