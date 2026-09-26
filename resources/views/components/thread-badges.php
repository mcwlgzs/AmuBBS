<?php
/**
 * 「置顶 / 精华」文字角标（列表页与详情页共用）。
 *
 * 传入：$badgeThread（主题行数组；不传则退回当前作用域的 $thread）
 * 可选：$badgeVerboseLevel = true 时把精华等级写全（详情页用「精华 II」；列表默认只显示「精华」）
 *
 * 为什么用文字而不是图标：
 *   Discourse 只给置顶帖加一个小图钉，社区长期反馈「置顶和普通帖看不出区别」，官方回复是自己写 CSS
 *   （https://meta.discourse.org/t/highlight-pinned-topics-better/102355）；
 *   GitHub 的 Pinned / 标签也是文字胶囊（https://github.com/orgs/community/discussions/191739）；
 *   中文论坛（Discuz / Xiuno）习惯同样是「置顶 / 精华」文字印章。
 *   文字 + 配色一眼可辨，也不依赖任何图标字体或 SVG。
 */
$_badgeThread = $badgeThread ?? $thread ?? [];
$_badgeTop = (int)($_badgeThread['is_top'] ?? 0);
$_badgeHl = (int)($_badgeThread['is_highlight'] ?? 0);
$_badgeVerbose = !empty($badgeVerboseLevel);
$_badgeHlText = $_badgeHl >= 3 ? '精华 III' : ($_badgeHl === 2 ? '精华 II' : '精华');
?>
<?php if ($_badgeTop > 0): ?><span class="tag tag-top" title="<?= $_badgeTop >= 2 ? '全局置顶' : '板块置顶' ?>">置顶</span><?php endif; ?><?php if ($_badgeHl > 0): ?><span class="tag tag-highlight" title="<?= htmlspecialchars($_badgeHlText) ?>"><?= $_badgeVerbose ? htmlspecialchars($_badgeHlText) : '精华' ?></span><?php endif; ?><?php
unset($_badgeThread, $_badgeTop, $_badgeHl, $_badgeVerbose, $_badgeHlText);
