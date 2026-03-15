<?php
$pageTitle = '任务中心';
$pageCss = ['index'];
include APP_PATH . 'resources/views/layout/header.php';
$tasks = $taskData['tasks'];
$completedCount = $taskData['completedCount'];
$totalCount = $taskData['totalCount'];
$earnedCredits = $taskData['earnedCredits'];
$totalCredits = $taskData['totalCredits'];
$pct = $totalCount > 0 ? round($completedCount / $totalCount * 100) : 0;
$hasUnclaimed = false;
foreach ($tasks as $t) { if ($t['completed'] && !$t['claimed']) { $hasUnclaimed = true; break; } }
?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>任务中心</span>
</div>

<div x-data="taskCenter()">
<!-- 进度概览 -->
<div class="tc-header">
    <div class="tc-header-top">
        <div>
            <div class="tc-header-title">每日任务</div>
            <div class="tc-header-sub">每日 00:00 重置，完成后请及时领取奖励</div>
        </div>
        <?php if ($hasUnclaimed): ?>
        <button class="tc-claim-all-btn" :disabled="claimingAll" @click="claimAllTasks()">
            <span x-show="!claimingAll">一键领取全部</span>
            <span x-show="claimingAll">领取中...</span>
        </button>
        <?php endif; ?>
    </div>
    <div class="tc-header-bottom">
        <div class="tc-stat-card">
            <span class="tc-stat-value"><?= (int)$completedCount ?><small>/<?= (int)$totalCount ?></small></span>
            <span class="tc-stat-label">已完成</span>
            <div class="tc-progress-bar"><div class="tc-progress-fill" style="width:<?= (int)$pct ?>%"></div></div>
        </div>
        <div class="tc-stat-card">
            <span class="tc-stat-value"><?= (int)$earnedCredits ?></span>
            <span class="tc-stat-label">已获积分</span>
        </div>
        <div class="tc-stat-card">
            <span class="tc-stat-value"><?= (int)$totalCredits ?></span>
            <span class="tc-stat-label">总可获积分</span>
        </div>
    </div>
</div>

<!-- 任务列表 -->
<div class="tc-grid">
    <?php foreach ($tasks as $task): ?>
    <div class="tc-card<?= $task['claimed'] ? ' tc-card-done' : ($task['completed'] ? ' tc-card-ready' : '') ?>">
        <div class="tc-card-top">
            <div class="tc-card-icon"><?= $task['icon'] ?></div>
            <div class="tc-card-reward">+<?= (int)$task['credits'] ?></div>
        </div>
        <div class="tc-card-name"><?= htmlspecialchars($task['name']) ?></div>
        <div class="tc-card-desc"><?= htmlspecialchars($task['desc']) ?></div>
        <div class="tc-card-progress">
            <div class="tc-card-bar">
                <div class="tc-card-bar-fill" style="width:<?= $task['max'] > 0 ? round($task['current'] / $task['max'] * 100) : 0 ?>%;background:<?= $task['completed'] ? 'var(--success)' : 'var(--primary)' ?>"></div>
            </div>
            <span class="tc-card-count"><?= (int)$task['current'] ?>/<?= (int)$task['max'] ?></span>
        </div>
        <div class="tc-card-action">
            <?php if ($task['claimed']): ?>
                <span class="tc-done-tag">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    已领取
                </span>
            <?php elseif ($task['completed']): ?>
                <button class="btn btn-primary btn-sm tc-claim-btn" :disabled="claiming === '<?= htmlspecialchars($task['key'], ENT_QUOTES) ?>'" @click="claimTask('<?= htmlspecialchars($task['key'], ENT_QUOTES) ?>')">
                    <span x-show="claiming !== '<?= htmlspecialchars($task['key'], ENT_QUOTES) ?>'">领取奖励</span>
                    <span x-show="claiming === '<?= htmlspecialchars($task['key'], ENT_QUOTES) ?>'">...</span>
                </button>
            <?php else: ?>
                <span class="tc-pending-tag">未完成</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
</div>

<style>
/* 头部 */
.tc-header {
    background: linear-gradient(135deg, var(--primary) 0%, #1890ff 100%);
    border-radius: var(--radius-lg); padding: 22px 24px; color: #fff; margin-bottom: var(--gap);
}
.tc-header-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.tc-header-title { font-size: 18px; font-weight: 700; }
.tc-header-sub { font-size: 13px; opacity: .75; margin-top: 4px; }
.tc-claim-all-btn {
    padding: 8px 20px; border: 1.5px solid rgba(255,255,255,.5); border-radius: var(--radius-full);
    background: rgba(255,255,255,.15); color: #fff; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .2s; white-space: nowrap; flex-shrink: 0;
}
.tc-claim-all-btn:hover { background: rgba(255,255,255,.3); border-color: #fff; }
.tc-claim-all-btn:disabled { opacity: .5; cursor: not-allowed; }
.tc-header-bottom { display: flex; gap: 12px; margin-top: 18px; }
.tc-stat-card {
    flex: 1; background: rgba(255,255,255,.13); border-radius: var(--radius);
    padding: 12px 14px; text-align: center;
}
.tc-stat-value { font-size: 22px; font-weight: 800; display: block; }
.tc-stat-value small { font-size: 14px; font-weight: 500; opacity: .7; }
.tc-stat-label { font-size: 11px; opacity: .7; margin-top: 2px; display: block; }
.tc-progress-bar {
    height: 4px; background: rgba(255,255,255,.2); border-radius: 2px;
    margin-top: 8px; overflow: hidden;
}
.tc-progress-fill { height: 100%; background: #fff; border-radius: 2px; transition: width .3s; }

/* 任务网格 */
.tc-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}
.tc-card {
    background: var(--bg-card); border: 1px solid var(--border-light);
    border-radius: var(--radius-lg); padding: 18px; transition: all .2s;
    display: flex; flex-direction: column;
}
.tc-card:hover { border-color: var(--border); box-shadow: var(--shadow-sm); }
.tc-card-done { opacity: .55; }
.tc-card-ready { border-color: var(--primary); background: rgba(59,130,246,.02); }
.tc-card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.tc-card-icon {
    width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
    background: var(--primary-light); border-radius: var(--radius); color: var(--primary);
}
.tc-card-icon svg { width: 20px; height: 20px; }
.tc-card-reward {
    font-size: 14px; font-weight: 700; color: var(--warning);
    background: rgba(245,158,11,.08); padding: 2px 10px; border-radius: var(--radius-full);
}
.tc-card-name { font-weight: 600; font-size: 14px; color: var(--text); }
.tc-card-desc { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.tc-card-progress { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.tc-card-bar { flex: 1; height: 4px; background: var(--border-light); border-radius: 2px; overflow: hidden; }
.tc-card-bar-fill { height: 100%; border-radius: 2px; transition: width .3s; }
.tc-card-count { font-size: 11px; color: var(--text-muted); flex-shrink: 0; }
.tc-card-action { margin-top: 12px; text-align: center; }
.tc-claim-btn { width: 100%; }
.tc-done-tag {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 12px; color: var(--success); font-weight: 500;
}
.tc-done-tag svg { width: 14px; height: 14px; }
.tc-pending-tag { font-size: 12px; color: var(--text-muted); }

/* 暗色 */
html.dark .tc-card { background: var(--bg-card); }
html.dark .tc-card:hover { box-shadow: none; }
html.dark .tc-card-ready { background: rgba(59,130,246,.06); }
html.dark .tc-card-icon { background: rgba(59,130,246,.12); }

/* 手机 */
@media (max-width: 767px) {
    .tc-header { padding: 16px; }
    .tc-header-title { font-size: 16px; }
    .tc-header-bottom { gap: 8px; margin-top: 14px; }
    .tc-stat-card { padding: 10px 8px; }
    .tc-stat-value { font-size: 18px; }
    .tc-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .tc-card { padding: 14px; }
    .tc-card-icon { width: 34px; height: 34px; }
    .tc-card-icon svg { width: 18px; height: 18px; }
    .tc-card-name { font-size: 13px; }
    .tc-card-reward { font-size: 12px; padding: 2px 8px; }
}
@media (max-width: 374px) {
    .tc-header { padding: 12px; }
    .tc-header-bottom { flex-wrap: wrap; }
    .tc-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .tc-card { padding: 12px; }
}
</style>

<script>
function taskCenter() {
    return {
        claiming: '',
        claimingAll: false,
        claimTask(key) {
            this.claiming = key;
            App.post('/tasks/claim', {task_key: key}, {silent:true}).then(d => {
                if (d.success) { location.reload(); }
                else { toast(d.message || '领取失败', 'error'); }
            }).finally(() => this.claiming = '');
        },
        claimAllTasks() {
            this.claimingAll = true;
            App.post('/tasks/claim-all', {}, {silent:true}).then(d => {
                if (d.success) { location.reload(); }
                else { toast(d.message || '领取失败', 'error'); }
            }).finally(() => this.claimingAll = false);
        }
    };
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
