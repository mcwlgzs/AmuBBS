<?php
/**
 * 后台 - 插件管理（layuimini 子页面）
 *
 * 变量：
 *   $plugins  name => [title, version, description, author, enabled, installed_at, ...]
 *   $loaded   本次请求真正加载并执行了 register() 的插件名
 *
 * 这一页的数据是服务端直接渲染的（没有对应的 JSON API），所以用静态
 * layui-table 渲染，而不是 table.render()。操作按钮走 AdminUi.post()，
 * 成功后重载当前 iframe —— 插件列表很短，重载一次比手动同步 DOM 更简单可靠。
 */
?>

<div class="layui-card">
  <div class="layui-card-header">
    插件列表
    <span class="layui-badge layui-bg-gray">共 <?= count($plugins) ?> 个</span>
  </div>
  <div class="layui-card-body">

    <blockquote class="layui-elem-quote layui-quote-nm" style="margin-bottom:12px">
      插件放在 <code>plugins/</code> 目录下（每个插件一个子目录，内含 <code>plugin.json</code> 与 <code>Plugin.php</code>）。
      启用状态存在 <code>storage/plugin_config/plugins.json</code>，不依赖数据库、不需要 Composer、不需要任何常驻进程。
      <br>
      启用 / 停用会立即写入状态文件，<strong>从下一个请求开始生效</strong>；
      首次启用会调用插件的 <code>install()</code>，卸载会调用 <code>uninstall()</code>。
    </blockquote>

    <?php if (empty($plugins)): ?>
      <div style="padding:32px 0;text-align:center;color:#999">
        <i class="fa fa-puzzle-piece" style="font-size:28px"></i>
        <p style="margin:8px 0 0">还没有任何插件</p>
        <p style="font-size:12px">把插件目录上传到 plugins/ 后刷新本页即可看到（例如自带的 plugins/Example）</p>
      </div>
    <?php else: ?>

      <table class="layui-table" lay-size="sm">
        <thead>
          <tr>
            <th style="width:220px">插件</th>
            <th style="width:100px">版本</th>
            <th>说明</th>
            <th style="width:190px">状态</th>
            <th style="width:170px;text-align:center">操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($plugins as $name => $p): ?>
          <?php
            $enabled  = !empty($p['enabled']);
            $isLoaded = in_array((string)$name, $loaded, true);
            $jsonName = htmlspecialchars(json_encode((string)$name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
          ?>
          <tr>
            <td>
              <div style="font-weight:600"><?= htmlspecialchars((string)($p['title'] ?? $name), ENT_QUOTES, 'UTF-8') ?></div>
              <div class="admin-muted"><code><?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?></code></div>
            </td>
            <td class="admin-muted"><?= htmlspecialchars((string)($p['version'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <div><?= htmlspecialchars((string)($p['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              <?php if (!empty($p['author'])): ?>
                <div class="admin-muted">作者：<?= htmlspecialchars((string)$p['author'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($enabled): ?>
                <span class="layui-badge layui-bg-green">已启用</span>
              <?php else: ?>
                <span class="layui-badge layui-bg-gray">已停用</span>
              <?php endif; ?>
              <?php if ($isLoaded): ?>
                <span class="layui-badge layui-bg-blue">本次请求已加载</span>
              <?php endif; ?>
              <?php if (!empty($p['installed_at'])): ?>
                <div class="admin-muted">
                  安装于 <?= htmlspecialchars(date('Y-m-d H:i', (int)$p['installed_at']), ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($enabled): ?>
                <button class="layui-btn layui-btn-xs layui-btn-primary admin-plugin-toggle"
                        data-name="<?= $jsonName ?>" data-enabled="0">停用</button>
              <?php else: ?>
                <button class="layui-btn layui-btn-xs admin-plugin-toggle"
                        data-name="<?= $jsonName ?>" data-enabled="1">启用</button>
              <?php endif; ?>
              <button class="layui-btn layui-btn-xs layui-btn-danger admin-plugin-uninstall"
                      data-name="<?= $jsonName ?>">卸载</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>

  </div>
</div>

<script>
layui.use(['layer'], function () {
  var $ = layui.jquery;

  // 启用 / 停用
  $(document).on('click', '.admin-plugin-toggle', function () {
    var name = $(this).data('name');
    var enabled = $(this).data('enabled');

    AdminUi.post('/admin/plugins/toggle', { name: name, enabled: enabled }, function () {
      // 插件列表很短，直接重载本页最省事也最不容易出错
      setTimeout(function () { window.location.reload(); }, 600);
    });
  });

  // 卸载（会调用插件的 uninstall()，所以要二次确认）
  $(document).on('click', '.admin-plugin-uninstall', function () {
    var name = $(this).data('name');

    AdminUi.confirmPost('卸载会调用插件的 uninstall() 并清除启用状态（插件文件保留）。确定卸载吗？',
      '/admin/plugins/uninstall', { name: name }, function () {
        setTimeout(function () { window.location.reload(); }, 600);
      });
  });
});
</script>
