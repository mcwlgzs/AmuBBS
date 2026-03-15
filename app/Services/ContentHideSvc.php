<?php
/**
 * 内容隐藏服务
 * 支持：登录可见、等级限制、积分购买
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class ContentHideSvc
{
    /**
     * 解析内容中的隐藏标签
     * 支持格式：
     *   [hide]内容[/hide]              - 登录可见
     *   [hide level=3]内容[/hide]      - 等级限制（需要 lv3 以上）
     *   [hide credit=50]内容[/hide]    - 积分购买（需支付50积分）
     *   [hide reply]内容[/hide]        - 回复可见
     */
    public static function parse(string $content, int $threadId, ?int $userId = null): string
    {
        // 查询帖子作者（只查一次，传递给所有回调）
        $thread = Database::fetchOneCached("SELECT user_id FROM threads WHERE id = ? AND deleted_at IS NULL", [$threadId], 120);
        $authorId = $thread ? (int)$thread['user_id'] : 0;

        // 预加载当前用户积分（避免 renderLevelHide 重复查询）
        $userCredits = null;
        if ($userId) {
            $user = Database::fetchOneCached("SELECT credits FROM users WHERE id = ?", [$userId], 60);
            $userCredits = (int)($user['credits'] ?? 0);
        }

        // 登录可见（使用非贪婪匹配，排除嵌套 hide 标签）
        $content = preg_replace_callback('/\[hide\]((?:(?!\[hide[\s\]])(?!\[\/hide\]).)*)\[\/hide\]/s', function($m) use ($userId) {
            return self::renderLoginHide($m[1], $userId);
        }, $content);

        // 等级限制
        $content = preg_replace_callback('/\[hide\s+level=(\d+)\]((?:(?!\[hide[\s\]])(?!\[\/hide\]).)*)\[\/hide\]/s', function($m) use ($userId, $authorId, $userCredits) {
            return self::renderLevelHide($m[2], (int)$m[1], $userId, $authorId, $userCredits);
        }, $content);

        // 积分购买
        $content = preg_replace_callback('/\[hide\s+credit=(\d+)\]((?:(?!\[hide[\s\]])(?!\[\/hide\]).)*)\[\/hide\]/s', function($m) use ($userId, $threadId, $authorId) {
            $price = min((int)$m[1], self::MAX_CREDIT_PRICE);
            return self::renderCreditHide($m[2], $price, $threadId, $userId, $authorId);
        }, $content);

        // 评论可见（直接传 authorId，不再重复查询）
        $content = preg_replace_callback('/\[hide\s+reply\]((?:(?!\[hide[\s\]])(?!\[\/hide\]).)*)\[\/hide\]/s', function($m) use ($userId, $threadId, $authorId) {
            return self::renderReplyHide($m[1], $threadId, $userId, $authorId);
        }, $content);

        return $content;
    }

    /**
     * 登录可见
     */
    private static function renderLoginHide(string $content, ?int $userId): string
    {
        if ($userId) {
            return '<div class="hidden-content unlocked">'
                . '<div class="hidden-content-badge">登录可见</div>'
                . $content . '</div>';
        }
        return '<div class="hidden-content locked">'
            . '<div class="hidden-content-icon">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'
            . '</div>'
            . '<div class="hidden-content-text">此处内容已隐藏，请<a href="/login">登录</a>后查看</div>'
            . '</div>';
    }

    /**
     * 等级限制
     */
    private static function renderLevelHide(string $content, int $requiredLevel, ?int $userId, int $authorId = 0, ?int $userCredits = null): string
    {
        $levelNames = ['学前班','小学','初中','高中','大学','研究生','博士','博导'];
        $levelName = $levelNames[$requiredLevel] ?? "Lv{$requiredLevel}";

        if ($userId) {
            // 作者豁免等级限制
            if ($userId === $authorId) {
                return '<div class="hidden-content unlocked">'
                    . '<div class="hidden-content-badge">等级限制（' . htmlspecialchars($levelName) . '）</div>'
                    . $content . '</div>';
            }

            $credits = $userCredits ?? 0;
            $userLevel = LevelSvc::getLevelByCredits($credits);
            $userLv = (int)($userLevel['level'] ?? 0);

            if ($userLv >= $requiredLevel) {
                return '<div class="hidden-content unlocked">'
                    . '<div class="hidden-content-badge">等级限制（' . htmlspecialchars($levelName) . '）</div>'
                    . $content . '</div>';
            }
        }

        return '<div class="hidden-content locked">'
            . '<div class="hidden-content-icon">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'
            . '</div>'
            . '<div class="hidden-content-text">此处内容需要 <strong>' . htmlspecialchars($levelName) . '</strong>（Lv' . $requiredLevel . '）及以上等级才能查看</div>'
            . '</div>';
    }

    /**
     * 积分购买
     */
    private static function renderCreditHide(string $content, int $price, int $threadId, ?int $userId, int $authorId = 0): string
    {
        if ($userId) {
            // 作者豁免积分购买
            if ($userId === $authorId) {
                return '<div class="hidden-content unlocked">'
                    . '<div class="hidden-content-badge">积分购买（作者）</div>'
                    . $content . '</div>';
            }
            // 检查是否已购买
            if (self::hasPurchased($userId, $threadId)) {
                return '<div class="hidden-content unlocked">'
                    . '<div class="hidden-content-badge">积分购买（已解锁）</div>'
                    . $content . '</div>';
            }
        }

        return '<div class="hidden-content locked">'
            . '<div class="hidden-content-icon">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            . '</div>'
            . '<div class="hidden-content-text">此处内容需要支付 <strong>' . (int)$price . '</strong> 积分查看</div>'
            . ($userId
                ? '<button class="btn btn-primary btn-sm hidden-content-buy" onclick="buyHiddenContent(' . (int)$threadId . ',' . (int)$price . ')">支付 ' . (int)$price . ' 积分解锁</button>'
                : '<div style="font-size:12px;color:var(--text-muted);margin-top:4px;">请先<a href="/login">登录</a></div>')
            . '</div>';
    }

    /**
     * 回复可见
     */
    private static function renderReplyHide(string $content, int $threadId, ?int $userId, int $authorId = 0): string
    {
        if ($userId) {
            // 帖子作者豁免评论可见限制
            if ($userId === $authorId) {
                return '<div class="hidden-content unlocked">'
                    . '<div class="hidden-content-badge">评论可见</div>'
                    . $content . '</div>';
            }
            $replied = Database::fetchOneCached(
                "SELECT id FROM posts WHERE thread_id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1",
                [$threadId, $userId],
                60
            );
            if ($replied) {
                return '<div class="hidden-content unlocked">'
                    . '<div class="hidden-content-badge">评论可见</div>'
                    . $content . '</div>';
            }
        }

        return '<div class="hidden-content locked">'
            . '<div class="hidden-content-icon">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'
            . '</div>'
            . '<div class="hidden-content-text">此处内容已隐藏，评论帖子后可查看</div>'
            . '</div>';
    }

    /**
     * 检查是否已购买
     */
    public static function hasPurchased(int $userId, int $threadId): bool
    {
        $row = Database::fetchOneCached(
            "SELECT id FROM credit_logs WHERE user_id = ? AND type = 'content_purchase' AND related_type = 'thread' AND related_id = ?",
            [$userId, $threadId],
            120
        );
        return (bool)$row;
    }

    /** 隐藏内容最高积分价格 */
    private const MAX_CREDIT_PRICE = 10000;

    /**
     * 从帖子内容中提取隐藏内容的积分价格
     */
    public static function extractPrice(int $threadId): int
    {
        $thread = Database::fetchOne("SELECT content FROM threads WHERE id = ? AND deleted_at IS NULL", [$threadId]);
        if (!$thread) return 0;
        if (preg_match('/\[hide\s+credit=(\d+)\]/', $thread['content'], $m)) {
            return min((int)$m[1], self::MAX_CREDIT_PRICE);
        }
        return 0;
    }

    /**
     * 购买隐藏内容（事务内防并发）
     */
    public static function purchase(int $userId, int $threadId): bool
    {
        // 从帖子内容中提取真实价格
        $realPrice = self::extractPrice($threadId);
        if ($realPrice <= 0) {
            throw new \RuntimeException('该帖子没有付费隐藏内容');
        }

        $thread = Database::fetchOne("SELECT user_id FROM threads WHERE id = ? AND deleted_at IS NULL", [$threadId]);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        if ((int)$thread['user_id'] === $userId) {
            throw new \RuntimeException('不能购买自己的内容');
        }

        // 快速路径：已购买直接返回
        if (self::hasPurchased($userId, $threadId)) {
            throw new \RuntimeException('已购买过此内容');
        }

        Database::beginTransaction();
        try {
            // 事务内再次检查（FOR UPDATE 防并发重复购买）
            $existing = Database::fetchOne(
                "SELECT id FROM credit_logs WHERE user_id = ? AND type = 'content_purchase' AND related_type = 'thread' AND related_id = ? FOR UPDATE",
                [$userId, $threadId]
            );
            if ($existing) {
                throw new \RuntimeException('已购买过此内容');
            }

            // 原子扣减积分
            $affected = Database::execute(
                "UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?",
                [$realPrice, $userId, $realPrice]
            );
            if ($affected === 0) {
                throw new \RuntimeException('积分不足');
            }

            // 记录购买日志（作为购买凭证）
            $balance = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$userId])['credits'] ?? 0;
            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$userId, -$realPrice, $balance, 'content_purchase', '购买隐藏内容', 'thread', $threadId, time()]
            );

            // 给作者积分（扣除10%手续费）
            $authorAmount = (int)floor($realPrice * 0.9);
            if ($authorAmount > 0) {
                Database::execute("UPDATE users SET credits = credits + ? WHERE id = ?", [$authorAmount, (int)$thread['user_id']]);
                $authorBalance = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [(int)$thread['user_id']])['credits'] ?? 0;
                Database::execute(
                    "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [(int)$thread['user_id'], $authorAmount, $authorBalance, 'content_sale', '隐藏内容被购买', 'thread', $threadId, time()]
                );
            }

            Database::commit();
        } catch (\RuntimeException $e) {
            Database::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[ContentHideSvc] purchase failed: ' . $e->getMessage());
            throw new \RuntimeException('购买失败，请重试');
        }
        // 清除用户积分缓存
        Cache::delete("user:profile:{$userId}");
        Cache::delete("user:profile:{$thread['user_id']}");
        // 清除 hasPurchased 缓存，使购买后立即可见
        $sql = "SELECT id FROM credit_logs WHERE user_id = ? AND type = 'content_purchase' AND related_type = 'thread' AND related_id = ?";
        Cache::delete('dbq1:' . md5($sql . serialize([$userId, $threadId])));
        return true;
    }
}
