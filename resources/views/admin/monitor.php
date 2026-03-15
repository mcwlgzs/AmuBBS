<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-row layui-col-space15">
    <div class="layui-col-xs4">
        <div class="layui-card">
            <div class="layui-card-body" style="text-align:center;padding:20px 0;">
                <p style="color:#999;font-size:13px;">今日新用户</p>
                <p style="font-size:28px;font-weight:600;"><?= number_format($todayStats['new_users']) ?></p>
            </div>
        </div>
    </div>
    <div class="layui-col-xs4">
        <div class="layui-card">
            <div class="layui-card-body" style="text-align:center;padding:20px 0;">
                <p style="color:#999;font-size:13px;">今日新帖</p>
                <p style="font-size:28px;font-weight:600;"><?= number_format($todayStats['new_threads']) ?></p>
            </div>
        </div>
    </div>
    <div class="layui-col-xs4">
        <div class="layui-card">
            <div class="layui-card-body" style="text-align:center;padding:20px 0;">
                <p style="color:#999;font-size:13px;">今日回复</p>
                <p style="font-size:28px;font-weight:600;"><?= number_format($todayStats['new_posts']) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- 7天趋势图 -->
<div class="layui-card" style="margin-top:15px;">
    <div class="layui-card-header">7天趋势</div>
    <div class="layui-card-body">
        <table class="layui-table">
            <thead><tr><th>日期</th><th>新帖</th><th>回复</th><th>新用户</th><th style="width:40%;">活跃度</th></tr></thead>
            <tbody>
            <?php
            $maxActivity = 1;
            foreach ($trend as $d) { $a = $d['threads'] * 3 + $d['posts'] + $d['users'] * 2; if ($a > $maxActivity) $maxActivity = $a; }
            ?>
            <?php foreach ($trend as $d): ?>
            <?php $activity = $d['threads'] * 3 + $d['posts'] + $d['users'] * 2; $pct = round($activity / $maxActivity * 100); ?>
            <tr>
                <td><?= htmlspecialchars($d['date']) ?></td>
                <td><?= (int)$d['threads'] ?></td>
                <td><?= (int)$d['posts'] ?></td>
                <td><?= (int)$d['users'] ?></td>
                <td>
                    <div style="background:#eee;border-radius:4px;height:20px;overflow:hidden;">
                        <div style="background:#1E9FFF;height:100%;width:<?= $pct ?>%;border-radius:4px;transition:width .3s;"></div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="layui-row layui-col-space15" style="margin-top:15px;">
    <!-- 本周热帖 -->
    <div class="layui-col-xs6">
        <div class="layui-card">
            <div class="layui-card-header">本周热帖 TOP 10</div>
            <div class="layui-card-body">
                <table class="layui-table">
                    <thead><tr><th width="40">#</th><th>标题</th><th width="70">浏览</th><th width="70">回复</th></tr></thead>
                    <tbody>
                    <?php foreach ($hotThreads as $i => $ht): ?>
                    <tr>
                        <td style="font-weight:600;<?= $i < 3 ? 'color:#1E9FFF;' : '' ?>"><?= $i + 1 ?></td>
                        <td><a href="/thread/<?= (int)$ht['id'] ?>"><?= htmlspecialchars(mb_substr($ht['title'], 0, 25)) ?></a></td>
                        <td><?= number_format($ht['views']) ?></td>
                        <td><?= number_format($ht['reply_count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($hotThreads)): ?>
                    <tr><td colspan="4" style="text-align:center;color:#999;">暂无数据</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 本周活跃用户 -->
    <div class="layui-col-xs6">
        <div class="layui-card">
            <div class="layui-card-header">本周活跃用户 TOP 10</div>
            <div class="layui-card-body">
                <table class="layui-table">
                    <thead><tr><th width="40">#</th><th>用户</th><th width="80">回复数</th></tr></thead>
                    <tbody>
                    <?php foreach ($activeUsers as $i => $au): ?>
                    <tr>
                        <td style="font-weight:600;<?= $i < 3 ? 'color:#1E9FFF;' : '' ?>"><?= $i + 1 ?></td>
                        <td><a href="/user/<?= (int)$au['id'] ?>"><?= htmlspecialchars(($au['nickname'] ?? '') ?: ($au['username'] ?? '')) ?></a></td>
                        <td><?= number_format($au['post_count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($activeUsers)): ?>
                    <tr><td colspan="3" style="text-align:center;color:#999;">暂无数据</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
