<?php
/**
 * Sitemap 控制器
 * 生成 XML sitemap 供搜索引擎抓取
 */

namespace App\Controllers;

use Core\Database;
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

        $siteUrl = rtrim(\App\Services\SettingSvc::get('site_url', ''), '/');
        $htmlSuffix = \App\Services\SettingSvc::getBool('url_html_suffix', true) ? '.html' : '';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // 首页
        $xml .= $this->urlEntry($siteUrl . '/', date('c'), 'daily', '1.0');

        // 板块页
        $forums = Database::fetchAll(
            "SELECT id, updated_at FROM forums WHERE deleted_at IS NULL ORDER BY sort_order ASC LIMIT 200"
        );
        foreach ($forums as $f) {
            $lastmod = !empty($f['updated_at']) ? date('c', (int)$f['updated_at']) : date('c');
            $xml .= $this->urlEntry($siteUrl . '/forum/' . $f['id'] . $htmlSuffix, $lastmod, 'daily', '0.8');
        }

        // 最近更新的帖子（最多 2000 条）
        $threads = Database::fetchAll(
            "SELECT id, updated_at, created_at FROM threads WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 2000"
        );
        foreach ($threads as $t) {
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
