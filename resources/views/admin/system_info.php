<?php
/**
 * 后台 - 系统信息（layuimini 子页面）
 *
 * 需要变量：$info
 *
 * 迁移自 Bootstrap，改成 layui 风格：三块 layui 卡片，每块里是一张「键 → 值」的
 * layui-table（定义式两列）。$info 里的每一个值照旧全部输出：
 * PHP 版本 / PHP SAPI / MySQL / Redis / 服务器软件 / 操作系统 /
 * OPcache / Redis 扩展 / 内存限制 / 最大执行时间 / 上传限制 / POST 限制 / 磁盘空间。
 */

/** 把「已启用/未启用」这类文本渲染成彩色徽章 */
$badge = static function (string $value): string {
    $v = mb_strtolower(trim($value));
    if ($v === '' || $v === '-') {
        return '<span class="admin-muted">-</span>';
    }
    $good = ['已启用', '已安装', 'enabled', 'on', 'yes', '已开启'];
    $bad  = ['未启用', '未安装', 'disabled', 'off', 'no', '已关闭'];

    if (in_array($v, array_map('mb_strtolower', $good), true)) {
        return '<span class="layui-badge layui-bg-green">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if (in_array($v, array_map('mb_strtolower', $bad), true)) {
        return '<span class="layui-badge layui-bg-gray">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$info = is_array($info ?? null) ? $info : [];
?>

<blockquote class="layui-elem-quote layui-quote-nm">
  服务器与 PHP 运行环境快照 · <?= date('Y-m-d H:i') ?>
</blockquote>

<div class="layui-card">
  <div class="layui-card-header">运行环境</div>
  <div class="layui-card-body">
    <table class="layui-table" lay-size="sm">
      <colgroup>
        <col width="200">
        <col>
      </colgroup>
      <tbody>
        <tr>
          <td class="admin-muted">PHP 版本</td>
          <td><?= htmlspecialchars((string)($info['php_version'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
          <td class="admin-muted">PHP SAPI</td>
          <td><?= htmlspecialchars((string)($info['php_sapi'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
          <td class="admin-muted">MySQL 版本</td>
          <td><?= htmlspecialchars((string)($info['mysql_version'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
          <td class="admin-muted">Redis 版本</td>
          <td><?= $badge((string)($info['redis_version'] ?? '-')) ?></td>
        </tr>
        <tr>
          <td class="admin-muted">服务器软件</td>
          <td><?= htmlspecialchars((string)($info['server_software'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
          <td class="admin-muted">操作系统</td>
          <td><?= htmlspecialchars((string)($info['os'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="layui-card">
  <div class="layui-card-header">PHP 配置</div>
  <div class="layui-card-body">
    <table class="layui-table" lay-size="sm">
      <colgroup>
        <col width="200">
        <col>
      </colgroup>
      <tbody>
        <tr>
          <td class="admin-muted">OPcache</td>
          <td><?= $badge((string)($info['opcache'] ?? '-')) ?></td>
        </tr>
        <tr>
          <td class="admin-muted">Redis 扩展</td>
          <td><?= $badge((string)($info['redis_ext'] ?? '-')) ?></td>
        </tr>
        <tr>
          <td class="admin-muted">内存限制</td>
          <td><?= htmlspecialchars((string)($info['memory_limit'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
          <td class="admin-muted">最大执行时间</td>
          <td><?= htmlspecialchars((string)($info['max_execution_time'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
          <td class="admin-muted">上传文件大小限制</td>
          <td><?= htmlspecialchars((string)($info['upload_max_filesize'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
          <td class="admin-muted">POST 大小限制</td>
          <td><?= htmlspecialchars((string)($info['post_max_size'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="layui-card">
  <div class="layui-card-header">磁盘空间</div>
  <div class="layui-card-body">
    <table class="layui-table" lay-size="sm">
      <colgroup>
        <col width="200">
        <col>
      </colgroup>
      <tbody>
        <tr>
          <td class="admin-muted">可用空间</td>
          <td><span class="admin-num"><?= htmlspecialchars((string)($info['disk_free'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span></td>
        </tr>
        <tr>
          <td class="admin-muted">总空间</td>
          <td><span class="admin-num"><?= htmlspecialchars((string)($info['disk_total'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
