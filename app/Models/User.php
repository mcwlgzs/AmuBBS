<?php
/**
 * 用户模型
 *
 * 由原 App\Repositories\UserRepo 迁移而来，方法名与语义保持一致，
 * 以便上层调用点只做「换名字」而不是「改逻辑」。
 *
 * 缓存注意：FIND_BY_ID_SQL / GET_PROFILE_SQL 既是查询语句也是缓存键的一部分，
 * 失效时必须用同一份常量（forgetOne(self::XXX, $params)），不能另写一份 SQL。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class User extends Model
{
    protected static string $table = 'users';

    private const FIND_BY_ID_SQL =
        "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL";

    private const GET_PROFILE_SQL =
        "SELECT u.*, g.name as group_name FROM users u LEFT JOIN user_groups g ON u.group_id = g.id WHERE u.id = ? AND u.deleted_at IS NULL";

    /**
     * 清除用户相关的两条缓存查询
     */
    private static function clearCache(int $userId): void
    {
        self::forgetOne(self::FIND_BY_ID_SQL, [$userId]);
        self::forgetOne(self::GET_PROFILE_SQL, [$userId]);
    }

    /**
     * 按用户失效所有与它相关的缓存
     *
     * 除了模型自己那两条 cachedOne，还有别处按语义键写的 user:profile / user:group。
     * 改用户组、改资料、改未读数都会影响这些键，统一在这里清，调用方不必记。
     */
    public static function forgetUserCaches(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        self::clearCache($userId);
        Cache::delete("user:profile:{$userId}");
        Cache::delete("user:group:{$userId}");
    }

    public static function findById(int $id): ?array
    {
        return self::cachedOne(self::FIND_BY_ID_SQL, [$id], 300);
    }

    public static function findByUsername(string $username): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM users WHERE username = ? AND deleted_at IS NULL",
            [$username]
        );
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM users WHERE email = ? AND deleted_at IS NULL",
            [$email]
        );
    }

    public static function findByNickname(string $nickname): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM users WHERE nickname = ? AND deleted_at IS NULL",
            [$nickname]
        );
    }

    public static function findByUsernameOrEmail(string $identity): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL",
            [$identity, $identity]
        );
    }

    public static function findByUsernameOrNickname(string $name): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM users WHERE (username = ? OR nickname = ?) AND deleted_at IS NULL",
            [$name, $name]
        );
    }

    /**
     * 只要用户名（发帖/回复时冗余存进 posts.username 用）
     */
    public static function getUsername(int $id): string
    {
        $row = Database::fetchOne("SELECT username FROM users WHERE id = ?", [$id]);

        return (string)($row['username'] ?? '');
    }

    /**
     * 按 id 批量取「头像 + 显示名」，用于版主列表这类小列表
     *
     * @param int[] $ids
     * @return array<int, array{id: int, username: string, nickname: string, avatar: string, nickname_color: string}>
     */
    public static function getBasicsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAllCached(
            "SELECT id, username, nickname, avatar, nickname_color FROM users WHERE id IN ({$placeholders})",
            $ids,
            300
        );

        return array_map(static fn(array $r): array => [
            'id'             => (int)$r['id'],
            'username'       => (string)$r['username'],
            'nickname'       => (string)($r['nickname'] ?? ''),
            'avatar'         => (string)($r['avatar'] ?? ''),
            'nickname_color' => (string)($r['nickname_color'] ?? ''),
        ], $rows);
    }

    /**
     * 按 id 批量取「id => 用户名」映射（后台把 moderators 的 id 串显示成名字用）
     *
     * @param int[] $ids
     * @return array<int, string>
     */
    public static function getUsernameMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT id, username FROM users WHERE id IN ({$placeholders})",
            $ids
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = (string)$row['username'];
        }

        return $map;
    }

    /**
     * 单个用户的基础信息（私信会话这类要显示昵称颜色的场景）
     *
     * 与 getBasicsByIds 的区别：这里过滤已注销用户，且不做缓存（会话头部要求实时）。
     *
     * @return array{id: int, username: string, nickname: string, avatar: string, nickname_color: string}|null
     */
    public static function getBasicsById(int $id): ?array
    {
        $row = Database::fetchOne(
            "SELECT id, username, nickname, avatar, nickname_color
             FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // 存在性检查：收敛原先散落在多个 Controller / Service 里的重复查询
    // ------------------------------------------------------------------

    public static function usernameExists(string $username): bool
    {
        return self::existsWhere('username = ?', [$username]);
    }

    public static function emailExists(string $email): bool
    {
        return self::existsWhere('email = ?', [$email]);
    }

    public static function nicknameExists(string $nickname): bool
    {
        return self::existsWhere('nickname = ?', [$nickname]);
    }

    /** 改名/改邮箱时排除自己 */
    public static function usernameTakenByOther(string $username, int $exceptId): bool
    {
        return self::existsWhere('username = ? AND id != ?', [$username, $exceptId]);
    }

    public static function emailTakenByOther(string $email, int $exceptId): bool
    {
        return self::existsWhere('email = ? AND id != ?', [$email, $exceptId]);
    }

    public static function nicknameTakenByOther(string $nickname, int $exceptId): bool
    {
        return self::existsWhere('nickname = ? AND id != ?', [$nickname, $exceptId]);
    }

    public static function exists(int $id): bool
    {
        return self::existsWhere('id = ?', [$id]);
    }

    // ------------------------------------------------------------------
    // 写入
    // ------------------------------------------------------------------

    public static function create(
        string $username,
        string $email,
        string $hashedPassword,
        int $groupId = 1,
        int $credits = 0,
        ?string $nickname = null,
        string $avatar = '/assets/images/default-avatar.png'
    ): int {
        Database::execute(
            "INSERT INTO users (username, nickname, email, password, group_id, credits, avatar, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$username, $nickname, $email, $hashedPassword, $groupId, $credits, $avatar, time()]
        );

        return Database::lastInsertId();
    }

    public static function updateLoginInfo(int $userId, string $ip): int
    {
        $result = Database::execute(
            "UPDATE users SET login_ip = ?, login_at = ? WHERE id = ?",
            [$ip, time(), $userId]
        );
        self::clearCache($userId);

        return $result;
    }

    /**
     * 新鲜读一整行（不加缓存，也不过滤已注销）
     *
     * 登录/第三方登录这类「刚写完就要读回来」的路径不能用带缓存的 findById。
     */
    public static function findByIdFresh(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id]);

        return $row ?: null;
    }

    /**
     * 第三方登录首次注册建号
     *
     * 与 create() 的区别：这里显式写 updated_at（社交登录路径原先就这么写），
     * 且头像允许为空字符串（平台可能不返回头像）。
     */
    public static function createFromSocial(
        string $username,
        string $email,
        string $hashedPassword,
        int $groupId,
        int $credits,
        string $nickname,
        string $avatar
    ): int {
        $now = time();
        Database::execute(
            "INSERT INTO users (username, email, password, group_id, credits, nickname, avatar, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$username, $email, $hashedPassword, $groupId, $credits, $nickname, $avatar, $now, $now]
        );

        return Database::lastInsertId();
    }

    public static function getProfile(int $userId): ?array
    {
        return self::cachedOne(self::GET_PROFILE_SQL, [$userId], 300);
    }

    public static function incrementThreadCount(int $userId): int
    {
        $result = Database::execute(
            "UPDATE users SET thread_count = thread_count + 1 WHERE id = ?",
            [$userId]
        );
        self::clearCache($userId);

        return $result;
    }

    public static function incrementPostCount(int $userId): int
    {
        $result = Database::execute(
            "UPDATE users SET post_count = post_count + 1 WHERE id = ?",
            [$userId]
        );
        self::clearCache($userId);

        return $result;
    }

    public static function decrementThreadCount(int $userId): int
    {
        $result = Database::execute(
            "UPDATE users SET thread_count = CASE WHEN thread_count > 0 THEN thread_count - 1 ELSE 0 END WHERE id = ?",
            [$userId]
        );
        self::clearCache($userId);

        return $result;
    }

    public static function decrementPostCount(int $userId): int
    {
        $result = Database::execute(
            "UPDATE users SET post_count = CASE WHEN post_count > 0 THEN post_count - 1 ELSE 0 END WHERE id = ?",
            [$userId]
        );
        self::clearCache($userId);

        return $result;
    }

    /**
     * 按条数调整用户的回复数（负数表示减少，且不会减到 0 以下）
     *
     * 批量删回帖要按作者聚合后一次扣减，单条版 decrementPostCount 不够用。
     */
    public static function adjustPostCount(int $userId, int $delta): int
    {
        if ($delta === 0 || $userId <= 0) {
            return 0;
        }

        $result = $delta > 0
            ? Database::execute("UPDATE users SET post_count = post_count + ? WHERE id = ?", [$delta, $userId])
            : Database::execute(
                "UPDATE users SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?",
                [-$delta, -$delta, $userId]
            );
        self::clearCache($userId);

        return $result;
    }

    public static function updateAvatar(int $userId, string $avatar): int
    {
        $result = Database::execute(
            "UPDATE users SET avatar = ? WHERE id = ?",
            [$avatar, $userId]
        );
        self::clearCache($userId);

        return $result;
    }

    public static function updateProfile(int $userId, string $email, string $signature, ?string $nickname = null): int
    {
        $result = Database::execute(
            "UPDATE users SET email = ?, signature = ?, nickname = ?, updated_at = ? WHERE id = ?",
            [$email, $signature, $nickname, time(), $userId]
        );
        self::forgetUserCaches($userId);

        return $result;
    }

    public static function updatePassword(int $userId, string $hashedPassword): int
    {
        $result = Database::execute(
            "UPDATE users SET password = ?, updated_at = ? WHERE id = ?",
            [$hashedPassword, time(), $userId]
        );
        self::forgetUserCaches($userId);

        return $result;
    }

    public static function softDelete(int $userId): int
    {
        $result = Database::execute(
            "UPDATE users SET deleted_at = ?, updated_at = ? WHERE id = ?",
            [time(), time(), $userId]
        );
        self::clearCache($userId);

        return $result;
    }

    /**
     * 积分排行榜（带缓存）
     *
     * 原本在 CreditSvc 里 —— 但它查的是 users 表，属于用户实体的读查询，放这里更合适。
     */
    public static function getCreditRanking(int $limit = 10): array
    {
        return Cache::get("credits:ranking:{$limit}", function () use ($limit) {
            return Database::fetchAll(
                "SELECT id, username, nickname, avatar, credits, nickname_color, thread_count, post_count
                 FROM users WHERE deleted_at IS NULL ORDER BY credits DESC LIMIT ?",
                [$limit]
            );
        }, 300);
    }
    // ------------------------------------------------------------------
    // 后台用户管理
    // ------------------------------------------------------------------

    /** 后台列表允许的排序字段（白名单） */
    private const ADMIN_SORTS = [
        'id'         => 'u.id',
        'username'   => 'u.username',
        'credits'    => 'u.credits',
        'threads'    => 'u.thread_count',
        'posts'      => 'u.post_count',
        'created_at' => 'u.created_at',
    ];

    /** 后台列表与资料页共用的列 */
    private const ADMIN_COLUMNS =
        "u.id, u.username, u.nickname, u.email, u.avatar, u.group_id, u.credits,
         u.signature, u.nickname_color, u.thread_count, u.post_count,
         u.login_ip, u.login_at, u.created_at, g.name as group_name";

    /**
     * 后台筛选条件 → WHERE + 参数 + 排序
     *
     * @param array{search?:string, uid?:string, group_id?:string, ip?:string, sort?:string, dir?:string} $filters
     * @return array{where: string, params: array, sortCol: string, sortDir: string}
     */
    public static function adminQuery(array $filters): array
    {
        $where = 'WHERE u.deleted_at IS NULL';
        $params = [];

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $where .= ' AND (u.username LIKE ? OR u.email LIKE ? OR u.nickname LIKE ?)';
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }

        $uid = trim((string)($filters['uid'] ?? ''));
        if ($uid !== '') {
            $where .= ' AND u.id = ?';
            $params[] = (int)$uid;
        }

        $groupId = (string)($filters['group_id'] ?? '');
        if ($groupId !== '') {
            $where .= ' AND u.group_id = ?';
            $params[] = (int)$groupId;
        }

        $ip = trim((string)($filters['ip'] ?? ''));
        if ($ip !== '') {
            $escapedIp = addcslashes($ip, '%_\\');
            $where .= ' AND (u.login_ip LIKE ? OR u.register_ip LIKE ?)';
            $params[] = "%{$escapedIp}%";
            $params[] = "%{$escapedIp}%";
        }

        $sortCol = self::ADMIN_SORTS[(string)($filters['sort'] ?? 'id')] ?? 'u.id';
        $sortDir = strtolower((string)($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return ['where' => $where, 'params' => $params, 'sortCol' => $sortCol, 'sortDir' => $sortDir];
    }

    /**
     * 后台用户列表 + 总数
     *
     * @return array{rows: array, total: int, page: int, pages: int, sortDir: string}
     */
    public static function adminList(array $filters, int $page = 1, int $limit = 20): array
    {
        $q = self::adminQuery($filters);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM users u {$q['where']}",
            $q['params']
        )['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT " . self::ADMIN_COLUMNS . "
             FROM users u LEFT JOIN user_groups g ON u.group_id = g.id
             {$q['where']} ORDER BY {$q['sortCol']} {$q['sortDir']} LIMIT ? OFFSET ?",
            array_merge($q['params'], [$limit, $offset])
        );

        return [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'pages'   => max(1, (int)ceil($total / $limit)),
            'sortDir' => $q['sortDir'],
        ];
    }

    /**
     * 后台查看单个用户资料
     *
     * @param bool $includeDeleted 后端「取详情」接口历史上不带 deleted_at 条件，这里保留开关
     */
    public static function adminDetail(int $id, bool $includeDeleted = false): ?array
    {
        $sql = "SELECT " . self::ADMIN_COLUMNS . "
                FROM users u LEFT JOIN user_groups g ON u.group_id = g.id
                WHERE u.id = ?" . ($includeDeleted ? '' : ' AND u.deleted_at IS NULL');
        $row = Database::fetchOne($sql, [$id]);

        return $row ?: null;
    }

    /**
     * 改用户组（单个）
     */
    public static function setGroup(int $userId, int $groupId): int
    {
        $affected = Database::execute(
            "UPDATE users SET group_id = ?, updated_at = ? WHERE id = ?",
            [$groupId, time(), $userId]
        );
        self::forgetUserCaches($userId);
        self::forgetGroupIdCache($userId);

        return $affected;
    }

    /**
     * 改用户组（批量）
     *
     * @param int[] $userIds
     */
    public static function setGroupBulk(array $userIds, int $groupId): int
    {
        $ids = array_values(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0));
        if (empty($ids)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $affected = Database::execute(
            "UPDATE users SET group_id = ?, updated_at = ? WHERE id IN ({$ph})",
            array_merge([$groupId, time()], $ids)
        );

        foreach ($ids as $id) {
            self::forgetUserCaches($id);
            // 单用户路径 setGroup() 会清两个键，批量路径漏了 group_id 的话，
            // 批量封禁后 60 秒内权限判定仍按旧组放行
            self::forgetGroupIdCache($id);
        }

        return $affected;
    }

    /**
     * 后台编辑资料（昵称、邮箱、签名、昵称颜色）
     */
    public static function adminUpdate(int $userId, string $username, ?string $nickname, string $email, string $signature, string $nicknameColor): int
    {
        $affected = Database::execute(
            "UPDATE users SET username = ?, nickname = ?, email = ?, signature = ?, nickname_color = ?, updated_at = ? WHERE id = ?",
            [$username, $nickname, $email, $signature, $nicknameColor, time(), $userId]
        );
        self::forgetUserCaches($userId);

        return $affected;
    }

    /**
     * 写入或清除 API token（登录/登出时顺带记一次登录信息）
     */
    public static function setApiToken(int $userId, ?string $tokenHash, string $loginIp = '', int $loginAt = 0): int
    {
        if ($loginAt > 0) {
            $affected = Database::execute(
                "UPDATE users SET api_token = ?, login_ip = ?, login_at = ? WHERE id = ?",
                [$tokenHash, $loginIp, $loginAt, $userId]
            );
        } else {
            $affected = Database::execute("UPDATE users SET api_token = ? WHERE id = ?", [$tokenHash, $userId]);
        }
        self::forgetUserCaches($userId);

        return $affected;
    }

    /**
     * 调整未读通知数（正数加、负数减且不为负）
     */
    public static function adjustUnreadNotifications(int $userId, int $delta): int
    {
        if ($delta === 0 || $userId <= 0) {
            return 0;
        }

        $affected = $delta > 0
            ? Database::execute(
                "UPDATE users SET unread_notifications = unread_notifications + ? WHERE id = ?",
                [$delta, $userId]
            )
            : Database::execute(
                "UPDATE users SET unread_notifications = CASE WHEN unread_notifications >= ? THEN unread_notifications - ? ELSE 0 END WHERE id = ?",
                [-$delta, -$delta, $userId]
            );

        Cache::delete(Notification::unreadCountKey($userId));

        return $affected;
    }

    /**
     * 按 id 批次取用户（后台群发通知时按批遍历，避免一次拉全表）
     *
     * @return int[]
     */
    public static function idsBatch(int $lastId, int $limit): array
    {
        $rows = Database::fetchAll(
            "SELECT id FROM users WHERE deleted_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?",
            [$lastId, $limit]
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * 按用户组批次取用户 id
     *
     * @return int[]
     */
    public static function idsByGroupBatch(int $groupId, int $lastId, int $limit): array
    {
        $rows = Database::fetchAll(
            "SELECT id FROM users WHERE group_id = ? AND deleted_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?",
            [$groupId, $lastId, $limit]
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    // ------------------------------------------------------------------
    // 后台概览 / 监控
    // ------------------------------------------------------------------

    /**
     * 调整主题数（正数加、负数减且不为负）
     */
    public static function adjustThreadCount(int $userId, int $delta): int
    {
        if ($delta === 0 || $userId <= 0) {
            return 0;
        }

        return $delta > 0
            ? Database::execute("UPDATE users SET thread_count = thread_count + ? WHERE id = ?", [$delta, $userId])
            : Database::execute(
                "UPDATE users SET thread_count = CASE WHEN thread_count >= ? THEN thread_count - ? ELSE 0 END WHERE id = ?",
                [-$delta, -$delta, $userId]
            );
    }

    /** 用户积分（VIP 页这类只读一个数字的场景） */
    public static function getCredits(int $id): int
    {
        $row = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$id]);

        return (int)($row['credits'] ?? 0);
    }

    /**
     * 用户积分（60 秒缓存）
     *
     * 内容解析（等级限制）会在一页里反复读同一个用户的积分，所以要带缓存；
     * 记账场景请用新鲜读的 getCredits()。
     */
    public static function creditsCached(int $id): int
    {
        $row = self::cachedOne("SELECT credits FROM users WHERE id = ?", [$id], 60);

        return (int)($row['credits'] ?? 0);
    }

    /**
     * 加积分（纯 SQL，不碰缓存）
     *
     * 记账用例都在事务里，缓存必须等提交后再清 —— 所以这里刻意不做失效，
     * 由调用方（CreditSvc / RewardSvc）在 commit 之后清理。
     */
    public static function addCredits(int $userId, int $amount): int
    {
        return Database::execute(
            "UPDATE users SET credits = credits + ? WHERE id = ?",
            [$amount, $userId]
        );
    }

    /**
     * 扣积分，余额不足则不扣
     *
     * @return int 受影响行数：0 表示余额不足（条件写进 SQL，避免先查后扣的竞态）
     */
    public static function deductCreditsIfEnough(int $userId, int $amount): int
    {
        return Database::execute(
            "UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?",
            [$amount, $userId, $amount]
        );
    }

    /**
     * 批量取 id => [id, username, nickname]（打赏要在一条 SQL 里拿双方显示名）
     *
     * @param int[] $ids
     * @return array<int, array{id: int, username: string, nickname: string}>
     */
    public static function nameMapByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT id, username, nickname FROM users WHERE id IN ({$placeholders})",
            $ids
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }

        return $map;
    }

    /** 头像地址（自动生成头像要判断「还没有头像」） */
    public static function getAvatar(int $id): string
    {
        $row = Database::fetchOne("SELECT avatar FROM users WHERE id = ?", [$id]);

        return (string)($row['avatar'] ?? '');
    }

    public static function setAvatar(int $id, string $avatar): int
    {
        $affected = Database::execute("UPDATE users SET avatar = ? WHERE id = ?", [$avatar, $id]);
        self::forgetUserCaches($id);

        return $affected;
    }

    /**
     * 自动升级用户组要用的字段（刚发过积分，必须新鲜读）
     *
     * @return array{id: int, credits: int, group_id: int}|null
     */
    public static function getCreditsAndGroup(int $id): ?array
    {
        $row = Database::fetchOne(
            "SELECT id, credits, group_id FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $row ?: null;
    }

    /** 权限判断用的 group_id 查询（60 秒短缓存，管理员改了组也能较快生效） */
    private const GROUP_ID_SQL = "SELECT group_id FROM users WHERE id = ? AND deleted_at IS NULL";

    public static function getGroupIdCached(int $id): ?int
    {
        $row = self::cachedOne(self::GROUP_ID_SQL, [$id], 60);

        return $row === null ? null : (int)$row['group_id'];
    }

    /**
     * 让 group_id 缓存失效
     *
     * 只在这里推导缓存键（用同一份 SQL 常量），调用方不用手写 md5 拼 key。
     */
    public static function forgetGroupIdCache(int $id): void
    {
        self::forgetOne(self::GROUP_ID_SQL, [$id]);
    }

    /**
     * 按 API token 哈希取认证所需字段（开放 API 的 Bearer 认证）
     *
     * 返回的列与 ApiBase 原先的裸 SQL 完全一致，避免认证语义被悄悄改掉。
     *
     * @return array{id: int, username: string, email: string, group_id: int, credits: int, login_at: int}|null
     */
    public static function findByApiToken(string $tokenHash): ?array
    {
        $row = Database::fetchOne(
            "SELECT id, username, email, group_id, credits, login_at
             FROM users WHERE api_token = ? AND deleted_at IS NULL",
            [$tokenHash]
        );

        return $row ?: null;
    }

    /**
     * 按用户名批量取用户（@提及用）
     *
     * @param string[] $usernames
     * @return array<int, array{id: int, username: string}>
     */
    public static function idsByUsernames(array $usernames): array
    {
        $usernames = array_values(array_unique(array_filter(array_map('strval', $usernames), static fn(string $u): bool => $u !== '')));
        if (empty($usernames)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($usernames), '?'));

        return Database::fetchAll(
            "SELECT id, username FROM users WHERE username IN ({$placeholders}) AND deleted_at IS NULL",
            $usernames
        );
    }

    /**
     * 批量递减 post_count（一条 CASE WHEN 写完一批，负数不为负）
     *
     * @param array<int, int> $countsById user_id => 扣减量
     */
    public static function adjustPostCountBulk(array $countsById): int
    {
        return self::adjustCountBulk('post_count', $countsById);
    }

    /**
     * 批量递减 thread_count（负数不为负）
     *
     * @param array<int, int> $countsById user_id => 扣减量
     */
    public static function adjustThreadCountBulk(array $countsById): int
    {
        return self::adjustCountBulk('thread_count', $countsById);
    }

    /**
     * 批量递减某个计数列（两条 *Bulk 方法的共同实现）
     *
     * @param array<int, int> $countsById
     */
    private static function adjustCountBulk(string $column, array $countsById): int
    {
        $countsById = array_filter($countsById, static fn(int $n): bool => $n > 0);
        if (empty($countsById)) {
            return 0;
        }

        $cases = [];
        $params = [];
        foreach ($countsById as $uid => $dec) {
            $cases[] = "WHEN id = ? THEN CASE WHEN {$column} >= ? THEN {$column} - ? ELSE 0 END";
            array_push($params, $uid, $dec, $dec);
        }

        $ids = array_keys($countsById);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);

        return Database::execute(
            "UPDATE users SET {$column} = CASE " . implode(' ', $cases) . " ELSE {$column} END WHERE id IN ({$placeholders})",
            $params
        );
    }

    /**
     * 最新注册用户（后台仪表盘）
     */
    public static function recentlyRegistered(int $limit = 5): array
    {
        return Database::fetchAll(
            "SELECT id, username, email, group_id, created_at
             FROM users WHERE deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * 某段时间内发帖最多的用户（后台监控页 + 侧边栏「活跃用户」共用）
     *
     * 注意：沿用原行为，只过滤帖子是否被删，不额外过滤用户是否已注销。
     */
    public static function mostActiveSince(int $since, int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.nickname_color, COUNT(p.id) as post_count
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.created_at >= ? AND p.deleted_at IS NULL
             GROUP BY u.id
             ORDER BY post_count DESC
             LIMIT ?",
            [$since, $limit]
        );
    }
}