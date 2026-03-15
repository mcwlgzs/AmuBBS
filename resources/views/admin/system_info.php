<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header">运行环境</div>
    <div class="layui-card-body">
        <table class="layui-table">
            <colgroup><col width="200"><col></colgroup>
            <tr><td>PHP 版本</td><td><?= htmlspecialchars($info['php_version']) ?></td></tr>
            <tr><td>PHP SAPI</td><td><?= htmlspecialchars($info['php_sapi']) ?></td></tr>
            <tr><td>MySQL 版本</td><td><?= htmlspecialchars($info['mysql_version']) ?></td></tr>
            <tr><td>Redis 版本</td><td><?= htmlspecialchars($info['redis_version']) ?></td></tr>
            <tr><td>服务器软件</td><td><?= htmlspecialchars($info['server_software']) ?></td></tr>
            <tr><td>操作系统</td><td><?= htmlspecialchars($info['os']) ?></td></tr>
        </table>
    </div>
</div>

<div class="layui-card" style="margin-top:15px;">
    <div class="layui-card-header">PHP 配置</div>
    <div class="layui-card-body">
        <table class="layui-table">
            <colgroup><col width="200"><col></colgroup>
            <tr><td>OPcache</td><td><?= htmlspecialchars($info['opcache']) ?></td></tr>
            <tr><td>Redis 扩展</td><td><?= htmlspecialchars($info['redis_ext']) ?></td></tr>
            <tr><td>内存限制</td><td><?= htmlspecialchars($info['memory_limit']) ?></td></tr>
            <tr><td>最大执行时间</td><td><?= htmlspecialchars($info['max_execution_time']) ?></td></tr>
            <tr><td>上传文件大小限制</td><td><?= htmlspecialchars($info['upload_max_filesize']) ?></td></tr>
            <tr><td>POST 大小限制</td><td><?= htmlspecialchars($info['post_max_size']) ?></td></tr>
        </table>
    </div>
</div>

<div class="layui-card" style="margin-top:15px;">
    <div class="layui-card-header">磁盘空间</div>
    <div class="layui-card-body">
        <table class="layui-table">
            <colgroup><col width="200"><col></colgroup>
            <tr><td>可用空间</td><td><?= htmlspecialchars($info['disk_free']) ?></td></tr>
            <tr><td>总空间</td><td><?= htmlspecialchars($info['disk_total']) ?></td></tr>
        </table>
    </div>
</div>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
