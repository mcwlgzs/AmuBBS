<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header">缓存操作</div>
    <div class="layui-card-body">
        <div class="layui-btn-group">
            <button class="layui-btn" id="clearAll">清除全部缓存</button>
            <button class="layui-btn layui-btn-primary" id="clearForums">清除板块缓存</button>
            <button class="layui-btn layui-btn-primary" id="clearThreads">清除帖子缓存</button>
            <button class="layui-btn layui-btn-primary" id="clearUsers">清除用户缓存</button>
        </div>
    </div>
</div>

<?php if ($redisInfo): ?>
<div class="layui-card" style="margin-top:15px;">
    <div class="layui-card-header">Redis 状态</div>
    <div class="layui-card-body">
        <div class="layui-row layui-col-space15" style="margin-bottom:15px;">
            <div class="layui-col-xs3">
                <div class="layui-card" style="text-align:center;padding:15px 0;">
                    <p style="color:#999;font-size:13px;">版本</p>
                    <p style="font-size:20px;font-weight:600;"><?= htmlspecialchars($redisInfo['version']) ?></p>
                </div>
            </div>
            <div class="layui-col-xs3">
                <div class="layui-card" style="text-align:center;padding:15px 0;">
                    <p style="color:#999;font-size:13px;">内存使用</p>
                    <p style="font-size:20px;font-weight:600;"><?= htmlspecialchars($redisInfo['used_memory_human']) ?></p>
                </div>
            </div>
            <div class="layui-col-xs3">
                <div class="layui-card" style="text-align:center;padding:15px 0;">
                    <p style="color:#999;font-size:13px;">Key 数量</p>
                    <p style="font-size:20px;font-weight:600;"><?= number_format($redisInfo['total_keys']) ?></p>
                </div>
            </div>
            <div class="layui-col-xs3">
                <div class="layui-card" style="text-align:center;padding:15px 0;">
                    <p style="color:#999;font-size:13px;">命中率</p>
                    <p style="font-size:20px;font-weight:600;"><?= htmlspecialchars($redisInfo['hit_rate']) ?>%</p>
                </div>
            </div>
        </div>
        <table class="layui-table">
            <colgroup><col width="200"><col></colgroup>
            <tr><td>连接客户端数</td><td><?= (int)$redisInfo['connected_clients'] ?></td></tr>
            <tr><td>运行天数</td><td><?= (int)$redisInfo['uptime_days'] ?> 天</td></tr>
        </table>
    </div>
</div>
<?php else: ?>
<div class="layui-card" style="margin-top:15px;">
    <div class="layui-card-body" style="text-align:center;padding:40px;color:#999;">
        Redis 未连接或未安装 Redis 扩展，当前使用进程内缓存
    </div>
</div>
<?php endif; ?>

<script>
layui.use(['layer'], function(){
    var $ = layui.$, layer = layui.layer;

    function clearCache(type) {
        var loadIdx = layer.load(2);
        $.ajax({
            url: '/admin/cache/clear',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ type: type }),
            dataType: 'json',
            success: function(res) {
                layer.close(loadIdx);
                if (res.success) {
                    layer.msg('缓存已清除', {icon: 1});
                } else {
                    layer.msg(res.message || '操作失败', {icon: 2});
                }
            },
            error: function() {
                layer.close(loadIdx);
                layer.msg('请求失败', {icon: 2});
            }
        });
    }

    $('#clearAll').on('click', function(){ clearCache('all'); });
    $('#clearForums').on('click', function(){ clearCache('forums'); });
    $('#clearThreads').on('click', function(){ clearCache('threads'); });
    $('#clearUsers').on('click', function(){ clearCache('users'); });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
