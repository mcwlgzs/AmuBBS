<?php
/**
 * Sitemap 控制器
 * 生成 XML sitemap 供搜索引擎抓取
 */

namespace App\Controllers;

use App\Models\Forum;
use App\Models\Thread;
use App\Services\SettingSvc;
use Core\Cache;

class Sitemap extends Base
{
    public function index(): void
    {
        // 缓存 sitemap 30 分钟
        $xml = Cache::get('sitemap:xml');
        if ($xml !== null && is_string($xml)) {
            header('Content-Type: application/xml; charset=utf-8');
            echo $xml;
            return;
        }

        $siteUrl = rtrim(SettingSvc::get('site_url', ''), '/');
        $htmlSuffix = SettingSvc::getBool('url_html_suffix', true) ? '.html' : '';

        // sitemap 是「给所有人看」的，必须过滤掉当前访客无权浏览的板块 / 帖子，
        // 否则会把受限板块的链接直接交给搜索引擎
        $viewerId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // 首页
        $xml .= $this->urlEntry($siteUrl . '/', date('c'), 'daily', '1.0');

        // 板块页
        foreach (Forum::allForSitemap(200) as $f) {
            if (!\App\Services\ForumSvc::canRead((int)$f['id'], $viewerId)) {
                continue;
            }
            $lastmod = !empty($f['updated_at']) ? date('c', (int)$f['updated_at']) : date('c');
            $xml .= $this->urlEntry($siteUrl . '/forum/' . $f['id'] . $htmlSuffix, $lastmod, 'daily', '0.8');
        }

        // 最近更新的帖子（最多 2000 条）
        foreach (Thread::allForSitemap(2000) as $t) {
            if (!\App\Services\ForumSvc::canRead((int)($t['forum_id'] ?? 0), $viewerId)) {
                continue;
            }
            $ts = $t['updated_at'] ?: $t['created_at'];
            $lastmod = date('c', (int)$ts);
            $xml .= $this->urlEntry($siteUrl . '/thread/' . $t['id'] . $htmlSuffix, $lastmod, 'weekly', '0.6');
        }

        $xml .= '</urlset>';

        Cache::set('sitemap:xml', $xml, 1800);

        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
    }

    private function urlEntry(string $loc, string $lastmod, string $changefreq, string $priority): string
    {
        return "  <url>\n"
            . "    <loc>" . htmlspecialchars($loc) . "</loc>\n"
            . "    <lastmod>{$lastmod}</lastmod>\n"
            . "    <changefreq>{$changefreq}</changefreq>\n"
            . "    <priority>{$priority}</priority>\n"
            . "  </url>\n";
    }
}
