<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>操作日志</h3>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="/admin/logs" class="layui-btn layui-btn-sm <?= empty($filter) ? '' : 'layui-btn-primary' ?>">文件日志</a>
            <a href="/admin/logs?filter=mod" class="layui-btn layui-btn-sm <?= ($filter ?? '') === 'mod' ? '' : 'layui-btn-primary' ?>">版主操作</a>
            <?php if (empty($filter)): ?>
            <form method="GET" action="/admin/logs" style="display:flex;gap:8px;">
                <input type="date" name="date" value="<?= htmlspecialchars($date ?? date('Y-m-d')) ?>" class="layui-input" style="width:160px;height:38px;">
                <button type="submit" class="layui-btn layui-btn-sm layui-btn-primary">查看</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="layui-card-body">
        <?php if (($filter ?? '') === 'mod'): ?>

            <!-- 搜索表单 -->
            <form class="layui-form" lay-filter="logSearch" style="margin-bottom:10px;">
                <div class="layui-inline">
                    <select name="action">
                        <option value="">全部操作</option>
                        <?php foreach ($actionTypes ?? [] as $at): ?>
                        <option value="<?= htmlspecialchars($at) ?>"><?= htmlspecialchars($at) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="layui-inline">
                    <input type="text" name="target_type" placeholder="目标类型" class="layui-input" style="width:120px;">
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-sm" lay-submit lay-filter="doLogSearch">搜索</button>
                    <button type="reset" class="layui-btn layui-btn-sm layui-btn-primary" id="resetLogSearch">重置</button>
                </div>
            </form>

            <table id="modLogsTable" lay-filter="modLogsTable"></table>

            <script>
            layui.use(['table', 'form', 'util'], function(){
                var table = layui.table, form = layui.form, util = layui.util, $ = layui.$;

                function formatTime(ts){
                    if(!ts) return '-';
                    var d = new Date(ts * 1000), p = function(n){ return n<10?'0'+n:n; };
                    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
                }

                table.render({
                    elem: '#modLogsTable', id: 'modLogsTable',
                    url: '/admin/api/logs',
                    page: true, limit: 30, limits: [20, 30, 50],
                    cols: [[
                        {field:'created_at', title:'时间', width:170, templet:function(d){ return formatTime(d.created_at); }},
                        {field:'operator_name', title:'操作者', width:120, templet:function(d){ return util.escape(d.operator_name || ('ID:'+(d.user_id||''))); }},
                        {field:'action', title:'操作', width:130, templet:function(d){ return '<span class="layui-badge layui-bg-blue">'+util.escape(d.action||'-')+'</span>'; }},
                        {field:'target_type', title:'目标', width:140, templet:function(d){ return util.escape(d.target_type||'-')+' #'+(d.target_id||''); }},
                        {field:'detail', title:'详情', minWidth:200, templet:function(d){
                            var detail = d.detail || '';
                            if(typeof detail === 'string') {
                                try { var obj = JSON.parse(detail); detail = Object.keys(obj).map(function(k){ return k+': '+obj[k]; }).join(' '); } catch(e){}
                            }
                            return '<span style="font-size:12px;color:#999;">'+util.escape(String(detail))+'</span>';
                        }},
                        {field:'ip', title:'IP', width:130, templet:function(d){ return d.ip || '-'; }}
                    ]],
                    text: {none: '暂无操作日志'}
                });

                form.on('submit(doLogSearch)', function(data){
                    table.reload('modLogsTable', { where: data.field, page: {curr:1} });
                    return false;
                });
                $('#resetLogSearch').on('click', function(){
                    $('form[lay-filter="logSearch"]')[0].reset();
                    form.render('select');
                    table.reload('modLogsTable', { where: {}, page: {curr:1} });
                });
            });
            </script>

        <?php else: ?>
            <?php if (empty($logs)): ?>
                <div style="text-align:center;color:#94a3b8;padding:40px;">该日期暂无日志</div>
            <?php else: ?>
                <div style="font-family:monospace;font-size:13px;line-height:1.8;max-height:600px;overflow-y:auto;background:#f8fafc;padding:16px;border-radius:8px;">
                    <?php foreach ($logs as $line): ?>
                        <div style="border-bottom:1px solid #e2e8f0;padding:4px 0;"><?= htmlspecialchars($line) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
