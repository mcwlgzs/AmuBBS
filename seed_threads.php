<?php
/**
 * 数据填充脚本（优化版）- 批量 INSERT + 高效计数更新
 * 插入 1000 个用户 + 20000 个帖子 + 100000 个主题评论 + 签到/积分 + 20000 动态 + 50000 动态评论 + 60000 点赞
 * 用法: php seed_threads.php
 */

$rootDir = __DIR__ . '/';
if (!file_exists($rootDir . 'core/Bootstrap.php') && file_exists(dirname(__DIR__) . '/core/Bootstrap.php')) {
    $rootDir = dirname(__DIR__) . '/';
}
define('APP_PATH', $rootDir);

$isCli = (php_sapi_name() === 'cli');

function out(string $text): void {
    global $isCli;
    if ($isCli) {
        echo $text . "\n";
    } else {
        echo htmlspecialchars($text) . "<br>\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>AMuBBS 数据填充</title>';
    echo '<style>body{font-family:monospace;background:#1a1a2e;color:#e0e0e0;padding:20px;line-height:1.8}</style></head><body><pre>';
}

require APP_PATH . 'core/Env.php';
Core\Env::load(APP_PATH);
define('DEBUG', false);
require APP_PATH . 'config/app.php';
require APP_PATH . 'core/Bootstrap.php';
Core\Bootstrap::getInstance();

use Core\Database;

$pdo = Database::getConnection();

// 批量插入辅助函数：每 $batchSize 行执行一次多行 INSERT
function batchInsert(PDO $pdo, string $table, array $columns, array &$rows, int $batchSize = 500): int
{
    $total = 0;
    $colStr = implode(',', $columns);
    $colCount = count($columns);
    $placeholder = '(' . implode(',', array_fill(0, $colCount, '?')) . ')';

    foreach (array_chunk($rows, $batchSize) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), $placeholder));
        $params = [];
        foreach ($chunk as $row) {
            foreach ($row as $v) $params[] = $v;
        }
        $pdo->exec("SET SESSION foreign_key_checks = 0");
        $stmt = $pdo->prepare("INSERT INTO {$table} ({$colStr}) VALUES {$placeholders}");
        $stmt->execute($params);
        $total += count($chunk);
    }
    $rows = []; // 释放内存
    return $total;
}

// ========== 清理旧测试数据 ==========
out("========== 清理旧测试数据 ==========");
$t = microtime(true);
$pdo->exec("SET SESSION foreign_key_checks = 0");
$pdo->exec("DELETE FROM posts WHERE username LIKE 'testuser\\_%'");
$pdo->exec("DELETE FROM threads WHERE username LIKE 'testuser\\_%'");
$pdo->exec("DELETE FROM moment_comments WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'testuser\\_%')");
$pdo->exec("DELETE FROM moment_likes WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'testuser\\_%')");
$pdo->exec("DELETE FROM moments WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'testuser\\_%')");
$pdo->exec("DELETE FROM user_checkins WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'testuser\\_%')");
$pdo->exec("DELETE FROM users WHERE username LIKE 'testuser\\_%'");
$pdo->exec("SET SESSION foreign_key_checks = 1");
out("✅ 旧数据清理完成，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

$forums = Database::fetchAll("SELECT id FROM forums WHERE deleted_at IS NULL LIMIT 10");
if (empty($forums)) {
    out("错误: 没有可用的板块。");
    exit(1);
}
$forumIds = array_column($forums, 'id');

$now = time();
$password = password_hash('test123456', PASSWORD_BCRYPT);

$firstNames = ['张','李','王','刘','陈','杨','赵','黄','周','吴','徐','孙','胡','朱','高','林','何','郭','马','罗'];
$lastNames = ['伟','芳','娜','敏','静','丽','强','磊','军','洋','勇','艳','杰','涛','明','超','秀英','华','平','刚'];

// ========== 第一步：插入 100 个用户 ==========
$userCount = 1000;
out("========== 插入 {$userCount} 个测试用户 ==========");
$t = microtime(true);
$rows = [];
for ($i = 1; $i <= $userCount; $i++) {
    $username = 'testuser_' . $i . '_' . rand(100, 999);
    $nickname = $firstNames[array_rand($firstNames)] . $lastNames[array_rand($lastNames)] . $i . '_' . rand(10, 99);
    $email = "testuser{$i}_" . rand(1000, 9999) . '@test.com';
    $credits = rand(0, 5000);
    $createdAt = $now - rand(0, 86400 * 90);
    $rows[] = [$username, $nickname, $email, $password, 1, $credits, $createdAt, $createdAt];
}
$pdo->beginTransaction();
$n = batchInsert($pdo, 'users', ['username','nickname','email','password','group_id','credits','created_at','updated_at'], $rows);
$pdo->commit();
out("✅ 插入 {$n} 个用户，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// 获取所有用户
$userList = Database::fetchAll("SELECT id, username FROM users WHERE deleted_at IS NULL");

// 为用户分配随机头像（模拟 AutoAvatar 插件行为）
$avatarDir = APP_PATH . 'plugins/AutoAvatar/assets/';
if (is_dir($avatarDir)) {
    $avatarFiles = array_values(array_filter(scandir($avatarDir), fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'png'));
    if (!empty($avatarFiles)) {
        out("  分配随机头像（{$avatarDir} 共 " . count($avatarFiles) . " 个）...");
        $t = microtime(true);
        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ? AND (avatar IS NULL OR avatar = '')");
        foreach ($userList as $u) {
            $chosen = $avatarFiles[array_rand($avatarFiles)];
            $stmt->execute(['/plugin-assets/AutoAvatar/' . $chosen, $u['id']]);
        }
        out("✅ 头像分配完成，耗时 " . round((microtime(true) - $t) * 1000) . "ms");
    }
}

// ========== 第二步：插入 10000 个帖子 ==========
$threadCount = 20000;
$titles = [
    'PHP 8.3 新特性全面解析','如何优化 MySQL 慢查询','Redis 缓存穿透的解决方案',
    'Docker 容器化部署最佳实践','Vue 3 组合式 API 使用心得','Nginx 反向代理配置详解',
    'Git 分支管理策略探讨','RESTful API 设计规范','Linux 服务器安全加固指南',
    '前端性能优化的 10 个技巧','TypeScript 类型体操入门','WebSocket 实时通信实战',
    'CI/CD 流水线搭建教程','微服务架构设计思考','Go 语言并发编程模式',
    'JavaScript 异步编程详解','CSS Grid 布局完全指南','Elasticsearch 全文搜索实践',
    'Kubernetes 入门到实战','Python 数据分析工具链','数据库索引优化策略',
    'OAuth 2.0 认证流程解析','React Hooks 深入理解','Webpack 5 模块联邦',
    'GraphQL vs REST 对比分析','单元测试编写最佳实践','设计模式在实际项目中的应用',
    '高并发系统设计要点','CDN 加速原理与实践','代码审查的艺术',
];
$contents = [
    "## 概述\n\n这是一篇关于技术实践的分享文章。\n\n## 核心要点\n\n1. 理解基本原理\n2. 多动手尝试\n3. 关注社区动态\n\n## 总结\n\n欢迎评论区交流。",
    "最近项目中遇到了一个有趣的问题。\n\n问题描述：高并发场景下响应时间增加。\n\n解决方案：\n- 调整连接池\n- 优化 SQL\n- 增加缓存\n\n性能提升约 60%。",
    "分享学习心得。\n\n学习方法：\n1. 每天阅读技术文章\n2. 动手写 Demo\n3. 参与开源项目\n4. 写博客总结",
    "聊聊代码质量。\n\n好代码特点：\n- 可读性强\n- 易于维护\n- 性能良好\n- 安全可靠",
    "关于技术选型的思考。\n\n考虑因素：\n1. 团队熟悉度\n2. 社区生态\n3. 性能扩展性\n4. 维护成本",
];

out("========== 插入 {$threadCount} 个测试帖子 ==========");
$t = microtime(true);
$rows = [];
for ($i = 1; $i <= $threadCount; $i++) {
    $user = $userList[array_rand($userList)];
    $forumId = $forumIds[array_rand($forumIds)];
    $title = $titles[array_rand($titles)] . ' #' . $i;
    $content = $contents[array_rand($contents)];
    $contentFmt = '<p>' . nl2br(htmlspecialchars($content)) . '</p>';
    $createdAt = $now - rand(0, 86400 * 60);
    $views = rand(10, 10000);
    $likes = rand(0, 200);
    $isTop = ($i <= 3) ? 1 : 0;
    $isHighlight = (rand(1, 20) === 1) ? 1 : 0;
    $rows[] = [$forumId, (int)$user['id'], $user['username'], $title, $content, $contentFmt, $views, 0, $likes, $isTop, $isHighlight, $createdAt, $createdAt, $createdAt, (int)$user['id']];
    if (count($rows) >= 500) {
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        batchInsert($pdo, 'threads', ['forum_id','user_id','username','title','content','content_fmt','views','reply_count','likes','is_top','is_highlight','created_at','updated_at','last_post_time','last_post_user_id'], $rows);
        $pdo->commit();
        out("  帖子 {$i}/{$threadCount}...");
    }
}
if (!empty($rows)) {
    $pdo->beginTransaction();
    batchInsert($pdo, 'threads', ['forum_id','user_id','username','title','content','content_fmt','views','reply_count','likes','is_top','is_highlight','created_at','updated_at','last_post_time','last_post_user_id'], $rows);
    $pdo->commit();
}
out("✅ 插入 {$threadCount} 个帖子，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// ========== 第三步：插入 20000 个回复 ==========
$postCount = 100000;
$threadList = Database::fetchAll("SELECT id, created_at FROM threads WHERE deleted_at IS NULL");
$replyContents = [
    "写得很好，学到了不少东西，感谢分享！",
    "这个方案我在项目中试过，确实有效。",
    "有个小问题想请教一下楼主？",
    "mark 一下，回头仔细看看。",
    "和我之前遇到的问题一模一样。",
    "补充一点：生产环境还需要考虑安全性。",
    "不太同意楼主的观点，方案二更合适。",
    "请问楼主用的是什么版本？",
    "太详细了，收藏了！",
    "这个坑我也踩过，改一下配置就好了。",
    "前排支持，期待后续更新。",
    "能不能出一个视频教程？",
    "楼主说的对，代码质量真的很重要。",
    "测试了一下，Windows 下有个小 bug。",
    "感谢分享，正好项目中需要用到。",
];

out("========== 插入 {$postCount} 个测试回复 ==========");
$t = microtime(true);
$rows = [];
$threadFloors = [];
$batchNum = 0;
for ($i = 1; $i <= $postCount; $i++) {
    $thread = $threadList[array_rand($threadList)];
    $threadId = (int)$thread['id'];
    $user = $userList[array_rand($userList)];
    $content = $replyContents[array_rand($replyContents)];
    $contentFmt = '<p>' . htmlspecialchars($content) . '</p>';
    $createdAt = (int)$thread['created_at'] + rand(60, 86400 * 30);
    $likes = rand(0, 50);
    if (!isset($threadFloors[$threadId])) $threadFloors[$threadId] = 1;
    $floor = $threadFloors[$threadId]++;

    $rows[] = [$threadId, (int)$user['id'], $user['username'], $content, $contentFmt, 'markdown', 0, $floor, $likes, $createdAt, $createdAt];

    if (count($rows) >= 500) {
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        batchInsert($pdo, 'posts', ['thread_id','user_id','username','content','content_fmt','content_format','quote_post_id','floor','likes','created_at','updated_at'], $rows);
        $pdo->commit();
        $batchNum += 500;
        if ($batchNum % 2000 === 0) out("  回复 {$batchNum}/{$postCount}...");
    }
}
if (!empty($rows)) {
    $pdo->beginTransaction();
    batchInsert($pdo, 'posts', ['thread_id','user_id','username','content','content_fmt','content_format','quote_post_id','floor','likes','created_at','updated_at'], $rows);
    $pdo->commit();
}
out("✅ 插入 {$postCount} 个回复，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// 更新计数（拆成两步避免相关子查询）
out("  更新帖子回复计数...");
$t = microtime(true);
// 第一步：更新 reply_count 和 last_post_time
$pdo->exec("
    UPDATE threads t
    INNER JOIN (
        SELECT thread_id, COUNT(*) as cnt, MAX(created_at) as last_time
        FROM posts WHERE deleted_at IS NULL GROUP BY thread_id
    ) p ON t.id = p.thread_id
    SET t.reply_count = p.cnt, t.last_post_time = p.last_time
    WHERE t.deleted_at IS NULL
");
// 第二步：更新 last_post_user_id（用 MAX(id) 找最后一条回复）
$pdo->exec("
    UPDATE threads t
    INNER JOIN (
        SELECT p.thread_id, p.user_id
        FROM posts p
        INNER JOIN (SELECT thread_id, MAX(id) as max_id FROM posts WHERE deleted_at IS NULL GROUP BY thread_id) pm ON p.id = pm.max_id
    ) lp ON t.id = lp.thread_id
    SET t.last_post_user_id = lp.user_id
    WHERE t.deleted_at IS NULL
");
out("  帖子回复计数更新完成，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

out("  更新板块/用户计数...");
$t = microtime(true);
$pdo->exec("
    UPDATE forums f
    INNER JOIN (SELECT forum_id, COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL GROUP BY forum_id) tc ON f.id = tc.forum_id
    SET f.thread_count = tc.cnt WHERE f.deleted_at IS NULL
");
$pdo->exec("
    UPDATE forums f
    INNER JOIN (
        SELECT t.forum_id, COUNT(*) as cnt FROM posts p INNER JOIN threads t ON p.thread_id = t.id
        WHERE p.deleted_at IS NULL AND t.deleted_at IS NULL GROUP BY t.forum_id
    ) pc ON f.id = pc.forum_id
    SET f.post_count = pc.cnt WHERE f.deleted_at IS NULL
");
$pdo->exec("UPDATE users u INNER JOIN (SELECT user_id, COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL GROUP BY user_id) tc ON u.id = tc.user_id SET u.thread_count = tc.cnt");
$pdo->exec("UPDATE users u INNER JOIN (SELECT user_id, COUNT(*) as cnt FROM posts WHERE deleted_at IS NULL GROUP BY user_id) pc ON u.id = pc.user_id SET u.post_count = pc.cnt");
out("  板块/用户计数更新完成，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// ========== 第四步：签到 + 积分 ==========
out("========== 插入签到 + 积分记录 ==========");
$t = microtime(true);
$checkinRows = [];
$creditRows = [];
$checkinUsers = array_slice(array_filter($userList, fn($u) => str_starts_with($u['username'], 'testuser_')), 0, 200);
foreach ($checkinUsers as $user) {
    $uid = (int)$user['id'];
    $checkinDays = rand(7, 60);
    $consecutive = 0;
    for ($d = $checkinDays; $d >= 1; $d--) {
        if ($d > 1 && rand(1, 5) === 1) { $consecutive = 0; continue; }
        $consecutive++;
        $date = date('Y-m-d', strtotime("-{$d} days"));
        $ts = strtotime($date) + rand(0, 86400);
        $credits = $consecutive >= 7 ? 20 : ($consecutive >= 3 ? 15 : 10);
        $checkinRows[] = [$uid, $date, $consecutive, $credits, $ts];
        $creditRows[] = [$uid, $credits, 0, 'checkin', "每日签到（连续{$consecutive}天）", '', 0, $ts];
        if (count($checkinRows) >= 500) {
            $pdo->beginTransaction();
            batchInsert($pdo, 'user_checkins', ['user_id','checkin_date','consecutive_days','credits','created_at'], $checkinRows);
            batchInsert($pdo, 'credit_logs', ['user_id','amount','balance','type','description','related_type','related_id','created_at'], $creditRows);
            $pdo->commit();
        }
    }
}
if (!empty($checkinRows)) {
    $pdo->beginTransaction();
    batchInsert($pdo, 'user_checkins', ['user_id','checkin_date','consecutive_days','credits','created_at'], $checkinRows);
    $pdo->commit();
}
if (!empty($creditRows)) {
    $pdo->beginTransaction();
    batchInsert($pdo, 'credit_logs', ['user_id','amount','balance','type','description','related_type','related_id','created_at'], $creditRows);
    $pdo->commit();
}
// 补充发帖/回复积分采样
$sampleThreads = Database::fetchAll("SELECT id, user_id, created_at FROM threads WHERE deleted_at IS NULL ORDER BY RAND() LIMIT 500");
$rows = [];
foreach ($sampleThreads as $st) {
    $rows[] = [(int)$st['user_id'], 5, 0, 'thread', '发布帖子', 'thread', (int)$st['id'], (int)$st['created_at']];
}
if ($rows) { $pdo->beginTransaction(); batchInsert($pdo, 'credit_logs', ['user_id','amount','balance','type','description','related_type','related_id','created_at'], $rows); $pdo->commit(); }
$samplePosts = Database::fetchAll("SELECT id, user_id, created_at FROM posts WHERE deleted_at IS NULL ORDER BY RAND() LIMIT 1000");
$rows = [];
foreach ($samplePosts as $sp) {
    $rows[] = [(int)$sp['user_id'], 2, 0, 'post', '发表评论', 'post', (int)$sp['id'], (int)$sp['created_at']];
}
if ($rows) { $pdo->beginTransaction(); batchInsert($pdo, 'credit_logs', ['user_id','amount','balance','type','description','related_type','related_id','created_at'], $rows); $pdo->commit(); }
out("✅ 签到+积分完成，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// ========== 第五步：动态 ==========
$momentCount = 20000;
$momentContents = [
    '今天天气真好，适合出去走走','刚学了一个新的设计模式','终于把那个 bug 修好了',
    '周末在家研究 Rust','推荐《代码整洁之道》','今天的咖啡特别好喝',
    '加班到深夜，项目上线了','有没有人一起组队打 CTF','刚入手了一把新键盘',
    '分享一个 VS Code 插件','周末去爬山了，风景很美','最近在学 K8s',
    '写了一个小工具自动化日常工作','新项目用了 Go 语言','今天帮同事解决了疑难杂症',
    '准备考 AWS 认证了','刚发现一个很棒的开源项目','终于把 Vim 配置好了',
    '新买的显示器到了，4K 写代码太爽了','最近迷上了函数式编程',
];
$momentImages = [null, null, null, null, null, null,
    '["/uploads/images/2025/01/img_a1b2c3.jpg"]',
    '["/uploads/images/2025/02/img_b2c3d4.png"]',
    '["/uploads/images/2025/01/img_c3d4e5.jpg","/uploads/images/2025/01/img_d4e5f6.jpg"]',
];

out("========== 插入 {$momentCount} 条动态 ==========");
$t = microtime(true);
$rows = [];
for ($i = 1; $i <= $momentCount; $i++) {
    $user = $userList[array_rand($userList)];
    $rows[] = [(int)$user['id'], $momentContents[array_rand($momentContents)], $momentImages[array_rand($momentImages)], rand(0, 300), rand(0, 50), $now - rand(0, 86400 * 90)];
    if (count($rows) >= 500) {
        $pdo->beginTransaction();
        batchInsert($pdo, 'moments', ['user_id','content','images','likes','comment_count','created_at'], $rows);
        $pdo->commit();
        if ($i % 2000 === 0) out("  动态 {$i}/{$momentCount}...");
    }
}
if (!empty($rows)) { $pdo->beginTransaction(); batchInsert($pdo, 'moments', ['user_id','content','images','likes','comment_count','created_at'], $rows); $pdo->commit(); }
out("✅ 插入 {$momentCount} 条动态，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// ========== 第六步：动态评论 ==========
$momentCommentCount = 50000;
$momentList = Database::fetchAll("SELECT id, user_id, created_at FROM moments WHERE deleted_at IS NULL");
$mcContents = ['说得好！','哈哈哈','同感','赞一个','太真实了','我也是这么想的','学到了','感谢分享','厉害了',
    '求详细教程','这个不错','收藏了','期待后续','一起加油','请问用的什么工具？','大佬带带我','前排围观','确实，深有体会','mark'];

out("========== 插入 {$momentCommentCount} 条动态评论 ==========");
$t = microtime(true);
$rows = [];
$inserted = 0;
for ($i = 1; $i <= $momentCommentCount; $i++) {
    $moment = $momentList[array_rand($momentList)];
    $user = $userList[array_rand($userList)];
    $createdAt = min((int)$moment['created_at'] + rand(60, 86400 * 7), $now);
    $replyUserId = (rand(1, 5) === 1) ? (int)$userList[array_rand($userList)]['id'] : 0;
    $rows[] = [(int)$moment['id'], (int)$user['id'], $replyUserId, $mcContents[array_rand($mcContents)], $createdAt];
    if (count($rows) >= 500) {
        $pdo->beginTransaction();
        batchInsert($pdo, 'moment_comments', ['moment_id','user_id','reply_user_id','content','created_at'], $rows);
        $pdo->commit();
        $inserted += 500;
        if ($inserted % 5000 === 0) out("  动态评论 {$inserted}/{$momentCommentCount}...");
    }
}
if (!empty($rows)) { $pdo->beginTransaction(); batchInsert($pdo, 'moment_comments', ['moment_id','user_id','reply_user_id','content','created_at'], $rows); $pdo->commit(); }
// 更新动态评论数
$pdo->exec("UPDATE moments m INNER JOIN (SELECT moment_id, COUNT(*) as cnt FROM moment_comments WHERE deleted_at IS NULL GROUP BY moment_id) mc ON m.id = mc.moment_id SET m.comment_count = mc.cnt WHERE m.deleted_at IS NULL");
out("✅ 插入 {$momentCommentCount} 条动态评论，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// ========== 第七步：动态点赞 ==========
$momentLikeCount = 60000;
out("========== 插入 {$momentLikeCount} 条动态点赞 ==========");
$t = microtime(true);
$rows = [];
$likeSet = [];
$inserted = 0;
for ($i = 1; $i <= $momentLikeCount; $i++) {
    $moment = $momentList[array_rand($momentList)];
    $user = $userList[array_rand($userList)];
    $key = (int)$moment['id'] . '_' . (int)$user['id'];
    if (isset($likeSet[$key])) continue;
    $likeSet[$key] = true;
    $rows[] = [(int)$moment['id'], (int)$user['id'], min((int)$moment['created_at'] + rand(60, 86400 * 14), $now)];
    if (count($rows) >= 500) {
        $pdo->beginTransaction();
        batchInsert($pdo, 'moment_likes', ['moment_id','user_id','created_at'], $rows);
        $pdo->commit();
        $inserted += 500;
        if ($inserted % 10000 === 0) out("  动态点赞 {$inserted}...");
    }
}
if (!empty($rows)) { $pdo->beginTransaction(); batchInsert($pdo, 'moment_likes', ['moment_id','user_id','created_at'], $rows); $pdo->commit(); $inserted += count($rows); }
unset($likeSet);
// 更新动态点赞数
$pdo->exec("UPDATE moments m INNER JOIN (SELECT moment_id, COUNT(*) as cnt FROM moment_likes GROUP BY moment_id) ml ON m.id = ml.moment_id SET m.likes = ml.cnt WHERE m.deleted_at IS NULL");
out("✅ 插入动态点赞，耗时 " . round((microtime(true) - $t) * 1000) . "ms");

// ========== 性能测试 ==========
$totalThreads = Database::fetchOne("SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL")['cnt'];
$totalUsers = Database::fetchOne("SELECT COUNT(*) as cnt FROM users WHERE deleted_at IS NULL")['cnt'];
$totalPosts = Database::fetchOne("SELECT COUNT(*) as cnt FROM posts WHERE deleted_at IS NULL")['cnt'];
$totalMoments = Database::fetchOne("SELECT COUNT(*) as cnt FROM moments WHERE deleted_at IS NULL")['cnt'];
out("========== 性能测试 ({$totalThreads} 帖 / {$totalPosts} 回复 / {$totalUsers} 用户 / {$totalMoments} 动态) ==========");

$tests = [];

$t = microtime(true);
$r = Database::fetchAll("SELECT t.*, u.avatar FROM threads t LEFT JOIN users u ON t.user_id = u.id WHERE t.deleted_at IS NULL ORDER BY t.is_top DESC, t.last_post_time DESC LIMIT 20");
$tests[] = ['首页帖子列表 (20条)', microtime(true) - $t, count($r)];

$t = microtime(true);
$r = Database::fetchAll("SELECT t.*, u.avatar FROM threads t LEFT JOIN users u ON t.user_id = u.id WHERE t.forum_id = ? AND t.deleted_at IS NULL ORDER BY t.is_top DESC, t.last_post_time DESC LIMIT 20", [$forumIds[0]]);
$tests[] = ['板块帖子列表 (20条)', microtime(true) - $t, count($r)];

$t = microtime(true);
$r = Database::fetchAll("SELECT t.* FROM threads t WHERE t.deleted_at IS NULL AND MATCH(t.title, t.content) AGAINST(? IN BOOLEAN MODE) LIMIT 20", ['PHP']);
$tests[] = ['全文搜索 PHP', microtime(true) - $t, count($r)];

$t = microtime(true);
$r = Database::fetchAll("SELECT t.*, u.avatar FROM threads t LEFT JOIN users u ON t.user_id = u.id WHERE t.deleted_at IS NULL ORDER BY t.created_at DESC LIMIT 20 OFFSET 480");
$tests[] = ['深度分页 第25页', microtime(true) - $t, count($r)];

$t = microtime(true);
$r = Database::fetchOne("SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL");
$tests[] = ['帖子总数统计', microtime(true) - $t, $r['cnt']];

$t = microtime(true);
$r = Database::fetchAll("SELECT id, title, views, reply_count FROM threads WHERE deleted_at IS NULL ORDER BY views DESC LIMIT 10");
$tests[] = ['热门帖子 TOP10', microtime(true) - $t, count($r)];

$sampleThread = Database::fetchOne("SELECT id FROM threads WHERE deleted_at IS NULL AND reply_count > 0 LIMIT 1");
if ($sampleThread) {
    $t = microtime(true);
    $r = Database::fetchAll("SELECT p.*, u.avatar, u.nickname FROM posts p LEFT JOIN users u ON p.user_id = u.id WHERE p.thread_id = ? AND p.deleted_at IS NULL ORDER BY p.floor ASC LIMIT 20", [(int)$sampleThread['id']]);
    $tests[] = ['帖子回复列表 (20条)', microtime(true) - $t, count($r)];
}

$t = microtime(true);
$r = Database::fetchAll("SELECT m.*, u.username, u.nickname, u.avatar FROM moments m LEFT JOIN users u ON m.user_id = u.id WHERE m.deleted_at IS NULL ORDER BY m.created_at DESC LIMIT 20");
$tests[] = ['动态列表 (20条)', microtime(true) - $t, count($r)];

$t = microtime(true);
$r = Database::fetchAll("SELECT m.id, m.content, m.likes, m.comment_count, u.username FROM moments m LEFT JOIN users u ON m.user_id = u.id WHERE m.deleted_at IS NULL ORDER BY m.likes DESC LIMIT 10");
$tests[] = ['热门动态 TOP10', microtime(true) - $t, count($r)];

$totalTime = 0;
foreach ($tests as $idx => $test) {
    $ms = round($test[1] * 1000, 2);
    $totalTime += $ms;
    $num = $idx + 1;
    out("{$num}. {$test[0]}: {$ms}ms (返回 {$test[2]} 条)");
}
out("总查询耗时: {$totalTime}ms");
if ($totalTime < 50) { out("性能优秀"); } elseif ($totalTime < 200) { out("性能良好"); } else { out("性能需要优化"); }

if (!$isCli) {
    echo '</pre><hr style="border-color:#333"><p style="color:#888">填充完成 - ' . date('Y-m-d H:i:s') . '</p></body></html>';
}
