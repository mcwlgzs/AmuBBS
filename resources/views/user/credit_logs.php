<?php $pageTitle = '积分记录'; $pageCss = ['auth']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="container">
    <div class="breadcrumb">
        <a href="/">首页</a>
        <span class="breadcrumb-sep">›</span>
        <a href="/profile">个人中心</a>
        <span class="breadcrumb-sep">›</span>
        <span>积分记录</span>
    </div>

    <div class="card">
        <div class="section-title">
            我的积分记录
            <a href="/user/credit-ranking" style="font-size:13px;">积分排行榜 →</a>
        </div>

        <?php if (empty($logs)): ?>
            <?php $emptyIcon = 'list'; $emptyText = '暂无积分记录'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border-light);text-align:left;">
                            <th style="padding:12px 8px;font-size:13px;color:var(--text-secondary);font-weight:600;">时间</th>
                            <th style="padding:12px 8px;font-size:13px;color:var(--text-secondary);font-weight:600;">类型</th>
                            <th style="padding:12px 8px;font-size:13px;color:var(--text-secondary);font-weight:600;">描述</th>
                            <th style="padding:12px 8px;font-size:13px;color:var(--text-secondary);font-weight:600;text-align:right;">变动</th>
                            <th style="padding:12px 8px;font-size:13px;color:var(--text-secondary);font-weight:600;text-align:right;">余额</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:12px 8px;font-size:13px;color:var(--text-muted);">
                                <?= date('Y-m-d H:i', $log['created_at']) ?>
                            </td>
                            <td style="padding:12px 8px;">
                                <?php
                                $typeMap = [
                                    'checkin' => ['签到', 'var(--success)'],
                                    'thread' => ['发帖', 'var(--primary)'],
                                    'post' => ['评论', 'var(--primary)'],
                                    'like' => ['点赞', 'var(--danger)'],
                                    'reward' => ['打赏', 'var(--warning)'],
                                    'consume' => ['消费', 'var(--text-muted)'],
                                    'transfer' => ['转账', 'var(--text-muted)'],
                                    'refund' => ['退款', 'var(--success)'],
                                ];
                                $typeInfo = $typeMap[$log['type']] ?? ['其他', 'var(--text-muted)'];
                                ?>
                                <span style="display:inline-block;padding:2px 8px;font-size:11px;border-radius:var(--radius);background:<?= $typeInfo[1] ?>15;color:<?= $typeInfo[1] ?>;">
                                    <?= $typeInfo[0] ?>
                                </span>
                            </td>
                            <td style="padding:12px 8px;font-size:13px;color:var(--text);">
                                <?= htmlspecialchars($log['description']) ?>
                                <?php if ($log['related_type'] && $log['related_id']): ?>
                                    <?php if ($log['related_type'] === 'thread'): ?>
                                        <a href="/thread/<?= (int)$log['related_id'] ?>" style="margin-left:4px;font-size:12px;">查看</a>
                                    <?php elseif ($log['related_type'] === 'post' && isset($log['_thread_id'])): ?>
                                        <a href="/thread/<?= (int)$log['_thread_id'] ?>" style="margin-left:4px;font-size:12px;">查看</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 8px;font-size:14px;font-weight:600;text-align:right;color:<?= $log['amount'] > 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= $log['amount'] > 0 ? '+' : '' ?><?= number_format($log['amount']) ?>
                            </td>
                            <td style="padding:12px 8px;font-size:13px;color:var(--text-secondary);text-align:right;">
                                <?= number_format($log['balance']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="btn btn-sm btn-ghost">上一页</a>
                <?php endif; ?>
                <span class="page-info">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="btn btn-sm btn-ghost">下一页</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
