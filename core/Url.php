<?php
/**
 * URL 生成辅助类
 * 提供命名路由的反向 URL 生成
 */

namespace Core;

class Url
{
    /** 路由名称 → 路径模板映射 */
    private static array $namedRoutes = [];

    /** 站点 URL 前缀 */
    private static string $siteUrl = '';

    /**
     * 初始化
     */
    public static function init(string $siteUrl = ''): void
    {
        self::$siteUrl = rtrim($siteUrl, '/');
        self::registerDefaultRoutes();
    }

    /**
     * 注册命名路由
     */
    public static function register(string $name, string $pattern): void
    {
        self::$namedRoutes[$name] = $pattern;
    }

    /**
     * 生成相对 URL
     *
     * @param string $name 路由名称，如 'thread.detail'
     * @param array $params 路径参数，如 ['id' => 123]
     * @param array $query 查询参数，如 ['page' => 2]
     */
    public static function to(string $name, array $params = [], array $query = []): string
    {
        $pattern = self::$namedRoutes[$name] ?? null;

        if ($pattern === null) {
            // 未注册的路由名，直接当路径用
            $url = '/' . ltrim($name, '/');
        } else {
            // 替换路径参数 {param}
            $url = $pattern;
            foreach ($params as $key => $value) {
                $url = str_replace('{' . $key . '}', rawurlencode((string)$value), $url);
            }
        }

        // 附加查询参数
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * 生成绝对 URL（带站点域名前缀）
     */
    public static function full(string $name, array $params = [], array $query = []): string
    {
        $siteUrl = self::$siteUrl;
        if (empty($siteUrl)) {
            try {
                $siteUrl = \App\Services\SettingSvc::get('site_url', '');
            } catch (\Throwable $e) {
                $siteUrl = '';
            }
        }

        return rtrim($siteUrl, '/') . self::to($name, $params, $query);
    }

    /**
     * 注册默认路由映射
     */
    private static function registerDefaultRoutes(): void
    {
        $routes = [
            'home'              => '/',
            'login'             => '/login',
            'register'          => '/register',
            'logout'            => '/logout',
            'search'            => '/search',
            'profile'           => '/profile',
            'notifications'     => '/notifications',
            'messages'          => '/messages',
            'favorites'         => '/favorites',
            'checkin'           => '/checkin',
            'moments'           => '/moments',
            'vip'               => '/vip',
            'navigation'        => '/navigation',
            'forum.all'         => '/forum/all',
            'forum.show'        => '/forum/{id}',
            'thread.create'     => '/thread/create',
            'thread.detail'     => '/thread/{id}',
            'thread.edit'       => '/thread/edit/{id}',
            'user.show'         => '/user/{id}',
            'user.followers'    => '/user/{id}/followers',
            'user.following'    => '/user/{id}/following',
            'user.credits'      => '/user/credit-logs',
            'user.ranking'      => '/user/credit-ranking',
            'user.levels'       => '/user/levels',
            'tag.show'          => '/tag/{name}',
            'message.show'      => '/messages/{id}',
            'admin'             => '/admin',
            'admin.forums'      => '/admin/forums',
            'admin.threads'     => '/admin/threads',
            'admin.users'       => '/admin/users',
            'admin.settings'    => '/admin/settings',
            'admin.plugins'     => '/admin/plugins',
        ];

        foreach ($routes as $name => $pattern) {
            self::$namedRoutes[$name] = $pattern;
        }
    }
}
