<?php
/**
 * 后台 - 公告管理
 *
 * 迁移说明（现在是 layuimini / layui）：
 *   最早是「空页面 + layui table 远程取数 + JSON 提交」，
 *   中途短暂用过 Bootstrap 5 + htmx，现已回到 layuimini 这套：
 *   列表用 layui table 吃 /admin/api/*，表单是独立的 iframe 子页面，
 *   由 layer.open({type: 2}) 打开。
 *
 * 顺带修掉的隐患：原来 start_at / end_at 直接 strtotime()，
 * 解析失败会返回 false，绑进 int 列在严格模式下会抛 1366 变成 500。
 * 现在非法时间会得到一句明确的中文提示。
 */

namespace App\Controllers\Admin;

use App\Models\Announcement;

class AnnounceController extends AdminBase
{
    private const TYPE_LABELS = [0 => '普通', 1 => '重要', 2 => '紧急'];

    public function announcements(): void
    {
        $this->requireAdmin();
        $this->renderAnnouncementsPage();
    }

    /**
     * 公告列表
     */
    private function fetchAnnouncements(): array
    {
        return Announcement::adminList();
    }

    /**
     * 渲染公告页面片段（GET 与增删改后的刷新共用同一渲染路径）
     */
    private function renderAnnouncementsPage(): void
    {
        $this->renderAdmin('admin/announcements', [
            'pageTitle'    => '公告管理',
            'announcements' => $this->fetchAnnouncements(),
            'typeLabels'   => self::TYPE_LABELS,
        ], 'announcements');
    }

    /**
     * 公告表单片段（layer iframe 弹层用）
     *
     * ?id=0 或省略 → 新增；?id=N → 编辑
     */
    public function announcementForm(): void
    {
        $this->requireAdmin();

        $id = max(0, (int)($_GET['id'] ?? 0));
        $announcement = null;

        if ($id > 0) {
            $announcement = Announcement::findFresh($id);
            if (!$announcement) {
                http_response_code(404);
                $this->renderAdmin('admin/partials/error', ['pageTitle' => '公告不存在', 'message' => '公告不存在']);
                return;
            }
        }

        $this->renderAdmin('admin/partials/announcement_form', [
            'pageTitle'    => $announcement !== null ? '编辑公告' : '新增公告',
            'announcement' => $announcement,
            'isEdit'       => $announcement !== null,
            'typeLabels'   => self::TYPE_LABELS,
        ], 'announcements');
    }

    /**
     * 公告列表 API（保留，供外部 AJAX 调用）
     */
    public function announcementsApi(): void
    {
        $this->requireAdmin();

        $announcements = $this->fetchAnnouncements();
        $this->jsonTable($announcements, count($announcements));
    }

    /**
     * 时间字符串 → 时间戳
     *
     * @return int|null|false  null = 未填（立即/永久）；false = 格式非法
     */
    private function parseDateTime(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $ts = strtotime($raw);

        return $ts === false ? false : $ts;
    }

    /**
     * 校验并收集表单字段
     *
     * @return array{0: bool, 1: string, 2: array}  [是否通过, 错误信息, 字段]
     */
    private function collectInput(array $input): array
    {
        // 公告已经不再有「标题」这个概念：表单只收集内容，标题由模型按内容首行派生。
        // 这里仍然接受可选的 title 字段，方便老客户端 / 脚本继续按旧参数调用。
        $content = trim((string)($input['content'] ?? ''));
        if ($content === '') {
            return [false, '公告内容不能为空', []];
        }

        $url = trim((string)($input['url'] ?? ''));
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
            return [false, '链接格式不正确，请使用 http:// 或 https:// 开头的地址', []];
        }
        if (mb_strlen($url) > 500) {
            return [false, '链接不能超过 500 个字符', []];
        }

        $startAt = $this->parseDateTime((string)($input['start_at'] ?? ''));
        if ($startAt === false) {
            return [false, '生效时间格式不正确', []];
        }

        $endAt = $this->parseDateTime((string)($input['end_at'] ?? ''));
        if ($endAt === false) {
            return [false, '过期时间格式不正确', []];
        }

        if ($startAt !== null && $endAt !== null && $endAt <= $startAt) {
            return [false, '过期时间必须晚于生效时间', []];
        }

        $type = (int)($input['type'] ?? 0);
        if (!isset(self::TYPE_LABELS[$type])) {
            $type = 0;
        }

        return [true, '', [
            // 标题不再是表单字段（界面已去掉）：留空时由 Announcement 模型取内容首行派生，
            // 因为 announcements.title 是 NOT NULL，后台列表 / JSON 接口还在用它。
            'title'    => trim((string)($input['title'] ?? '')),
            'content'  => $content,
            'url'      => $url,
            'type'     => $type,
            // 排序权重只允许非负：表单只给了 min=0，服务端不能只做 (int) 转换
            'rank'     => max(0, (int)($input['rank'] ?? 0)),
            'start_at' => $startAt,
            'end_at'   => $endAt,
        ]];
    }

    /**
     * 公告是否存在（写操作前校验）
     *
     * 不靠 UPDATE/DELETE 的 affected rows 判断：
     * MySQL 在「值没变化」时 affected 也是 0，会把「没改动」误报成「不存在」。
     */
    private function announcementMissing(int $id): bool
    {
        return Announcement::findFresh($id) === null;
    }

    public function announcementCreate(): void
    {
        $this->requireAdmin();

        [$ok, $msg, $f] = $this->collectInput($this->input());
        if (!$ok) {
            $this->respondMutation(false, $msg, fn() => $this->renderAnnouncementsPage());
            return;
        }

        Announcement::create($f, (int)($_SESSION['user_id'] ?? 0));

        $this->respondMutation(true, '公告已创建', fn() => $this->renderAnnouncementsPage());
    }

    public function announcementUpdate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderAnnouncementsPage());
            return;
        }

        [$ok, $msg, $f] = $this->collectInput($input);
        if (!$ok) {
            $this->respondMutation(false, $msg, fn() => $this->renderAnnouncementsPage());
            return;
        }

        if ($this->announcementMissing($id)) {
            $this->respondMutation(false, '公告不存在', fn() => $this->renderAnnouncementsPage());
            return;
        }

        Announcement::update($id, $f);

        $this->respondMutation(true, '公告已更新', fn() => $this->renderAnnouncementsPage());
    }

    public function announcementToggle(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderAnnouncementsPage());
            return;
        }

        $current = Announcement::isEnabled($id);
        if ($current === null) {
            $this->respondMutation(false, '公告不存在', fn() => $this->renderAnnouncementsPage());
            return;
        }

        // 兼容两种传法：显式给 is_enabled，或只给 id 由服务端取反
        if (array_key_exists('is_enabled', $input) && $input['is_enabled'] !== '') {
            $enabled = !empty($input['is_enabled']) ? 1 : 0;
        } else {
            $enabled = $current ? 0 : 1;
        }

        Announcement::setEnabled($id, $enabled);

        $this->respondMutation(true, $enabled ? '已启用' : '已禁用', fn() => $this->renderAnnouncementsPage());
    }

    public function announcementDelete(): void
    {
        $this->requireAdmin();

        $id = (int)($this->input()['id'] ?? 0);
        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderAnnouncementsPage());
            return;
        }

        if ($this->announcementMissing($id)) {
            $this->respondMutation(false, '公告不存在', fn() => $this->renderAnnouncementsPage());
            return;
        }

        Announcement::delete($id);

        $this->respondMutation(true, '公告已删除', fn() => $this->renderAnnouncementsPage());
    }
}
