<?php
/**
 * 社交登录服务 - 提供 OAuth 第三方登录功能
 * 支持 GitHub、Google、微信、QQ
 */

namespace App\Services;

use Core\Database;

class SocialLoginService
{
    /**
     * 支持的 OAuth 提供者列表
     */
    private const PROVIDERS = ['github', 'google', 'wechat', 'qq'];

    /**
     * 获取已启用的提供者列表
     *
     * @return array 已启用的提供者配置数组
     */
    public static function getEnabledProviders(): array
    {
        $enabled = [];
        
        foreach (self::PROVIDERS as $provider) {
            $config = self::getProviderConfig($provider);
            if ($config['enabled'] && !empty($config['client_id'])) {
                $enabled[] = [
                    'name' => $provider,
                    'display_name' => self::getProviderDisplayName($provider),
                    'config' => $config,
                ];
            }
        }
        
        return $enabled;
    }

    /**
     * 获取提供者配置
     *
     * @param string $provider 提供者名称
     * @return array 配置数组
     */
    public static function getProviderConfig(string $provider): array
    {
        $provider = strtolower($provider);
        
        if (!in_array($provider, self::PROVIDERS, true)) {
            return ['enabled' => false];
        }

        $prefix = "social_login_{$provider}_";
        
        return [
            'enabled' => SettingSvc::getBool($prefix . 'enabled', false),
            'client_id' => SettingSvc::get($prefix . 'client_id', ''),
            'client_secret' => SettingSvc::get($prefix . 'client_secret', ''),
        ];
    }

    /**
     * 生成并存储 OAuth state 令牌
     *
     * @param string $provider 提供者名称
     * @return string state 令牌
     */
    public static function generateState(string $provider): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION["oauth_state_{$provider}"] = $state;
        return $state;
    }

    /**
     * 验证 OAuth state 令牌
     *
     * @param string $provider 提供者名称
     * @param string $state 待验证的 state
     * @return bool 验证是否通过
     */
    private static function verifyState(string $provider, string $state): bool
    {
        $sessionKey = "oauth_state_{$provider}";
        
        if (!isset($_SESSION[$sessionKey])) {
            return false;
        }
        
        $expectedState = $_SESSION[$sessionKey];
        unset($_SESSION[$sessionKey]); // 使用后立即失效
        
        return hash_equals($expectedState, $state);
    }

    /**
     * 处理 OAuth 回调
     *
     * @param string $provider 提供者名称
     * @param string $code 授权码
     * @param string $state 状态令牌
     * @return array 登录结果 ['success' => bool, 'user_id' => int, 'error' => string]
     */
    public static function handleCallback(string $provider, string $code, string $state): array
    {
        // 验证 state 防止 CSRF
        if (!self::verifyState($provider, $state)) {
            return ['success' => false, 'error' => '状态验证失败'];
        }

        // 获取提供者配置
        $config = self::getProviderConfig($provider);
        if (!$config['enabled']) {
            return ['success' => false, 'error' => '提供者未启用'];
        }

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            return ['success' => false, 'error' => '提供者配置不完整'];
        }

        try {
            // 交换授权码获取访问令牌
            $accessToken = self::exchangeCodeForToken($provider, $code, $config);
            
            // 获取用户信息
            $userInfo = self::fetchUserInfo($provider, $accessToken, $config);
            
            // 查找或创建本地用户
            $userId = self::findOrCreateUser($provider, $userInfo);
            
            // 获取用户完整信息
            $user = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
            
            if (!$user) {
                return ['success' => false, 'error' => '用户不存在'];
            }
            
            // 设置 session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['group_id'] = $user['group_id'];
            
            return ['success' => true, 'user_id' => $userId];
            
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 交换授权码获取访问令牌
     *
     * @param string $provider 提供者名称
     * @param string $code 授权码
     * @param array $config 提供者配置
     * @return string 访问令牌
     * @throws \Exception
     */
    private static function exchangeCodeForToken(string $provider, string $code, array $config): string
    {
        $endpoints = self::getOAuthEndpoints($provider);
        
        $params = [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
        ];
        
        // 不同提供者的参数略有不同
        if ($provider === 'github' || $provider === 'google') {
            $params['redirect_uri'] = self::getRedirectUri($provider);
        }
        
        if ($provider === 'github') {
            $params['grant_type'] = 'authorization_code';
        }
        
        $response = self::httpPost($endpoints['token'], $params, [
            'Accept: application/json',
        ]);
        
        if (isset($response['access_token'])) {
            return $response['access_token'];
        }
        
        throw new \Exception('获取访问令牌失败');
    }

    /**
     * 获取用户信息
     *
     * @param string $provider 提供者名称
     * @param string $accessToken 访问令牌
     * @param array $config 提供者配置
     * @return array 用户信息
     * @throws \Exception
     */
    private static function fetchUserInfo(string $provider, string $accessToken, array $config): array
    {
        $endpoints = self::getOAuthEndpoints($provider);
        
        $headers = ["Authorization: Bearer {$accessToken}"];
        
        if ($provider === 'github') {
            $headers[] = 'User-Agent: AMuBBS';
        }
        
        $response = self::httpGet($endpoints['user'], $headers);
        
        // 标准化用户信息格式
        return self::normalizeUserInfo($provider, $response);
    }

    /**
     * 查找或创建本地用户
     *
     * @param string $provider 提供者名称
     * @param array $userInfo 用户信息
     * @return int 用户 ID
     * @throws \Exception
     */
    public static function findOrCreateUser(string $provider, array $userInfo): int
    {
        // 查找已关联的用户
        $social = Database::fetchOne(
            "SELECT * FROM social_logins WHERE provider = ? AND open_id = ?",
            [$provider, $userInfo['open_id']]
        );
        
        if ($social) {
            // 更新社交账号信息
            Database::execute(
                "UPDATE social_logins SET nickname = ?, avatar = ? WHERE id = ?",
                [$userInfo['nickname'], $userInfo['avatar'], $social['id']]
            );
            return (int)$social['user_id'];
        }
        
        // 创建新用户
        Database::beginTransaction();
        
        try {
            // 生成唯一用户名
            $username = self::generateUniqueUsername($provider, $userInfo);
            
            // 创建用户记录
            $defaultGroup = SettingSvc::getInt('user_default_group', 1);
            $defaultCredits = SettingSvc::getInt('user_default_credits', 0);
            $hashedPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT); // 随机密码
            
            Database::execute(
                "INSERT INTO users (username, email, password, group_id, credits, nickname, avatar, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $username,
                    $userInfo['email'] ?? '',
                    $hashedPassword,
                    $defaultGroup,
                    $defaultCredits,
                    $userInfo['nickname'],
                    $userInfo['avatar'],
                    time(),
                    time(),
                ]
            );
            
            $userId = (int)Database::lastInsertId();
            
            // 创建社交登录关联
            Database::execute(
                "INSERT INTO social_logins (user_id, provider, open_id, nickname, avatar, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $provider,
                    $userInfo['open_id'],
                    $userInfo['nickname'],
                    $userInfo['avatar'],
                    time(),
                ]
            );
            
            Database::commit();
            
            // 更新运行时统计
            RuntimeSvc::increment('users');
            
            return $userId;
            
        } catch (\Exception $e) {
            Database::rollBack();
            throw new \Exception('创建用户失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成唯一用户名
     *
     * @param string $provider 提供者名称
     * @param array $userInfo 用户信息
     * @return string 唯一用户名
     */
    private static function generateUniqueUsername(string $provider, array $userInfo): string
    {
        $base = $provider . '_' . substr($userInfo['open_id'], 0, 10);
        $username = $base;
        $counter = 1;
        
        while (Database::fetchOne("SELECT id FROM users WHERE username = ?", [$username])) {
            $username = $base . '_' . $counter;
            $counter++;
        }
        
        return $username;
    }

    /**
     * 标准化不同提供者的用户信息格式
     *
     * @param string $provider 提供者名称
     * @param array $response API 响应
     * @return array 标准化的用户信息
     */
    private static function normalizeUserInfo(string $provider, array $response): array
    {
        switch ($provider) {
            case 'github':
                return [
                    'open_id' => (string)$response['id'],
                    'nickname' => $response['name'] ?? $response['login'],
                    'avatar' => $response['avatar_url'] ?? '',
                    'email' => $response['email'] ?? '',
                ];
                
            case 'google':
                return [
                    'open_id' => $response['sub'] ?? $response['id'],
                    'nickname' => $response['name'] ?? '',
                    'avatar' => $response['picture'] ?? '',
                    'email' => $response['email'] ?? '',
                ];
                
            case 'wechat':
                return [
                    'open_id' => $response['openid'] ?? '',
                    'nickname' => $response['nickname'] ?? '',
                    'avatar' => $response['headimgurl'] ?? '',
                    'email' => '',
                ];
                
            case 'qq':
                return [
                    'open_id' => $response['openid'] ?? '',
                    'nickname' => $response['nickname'] ?? '',
                    'avatar' => $response['figureurl_qq_2'] ?? $response['figureurl_qq_1'] ?? '',
                    'email' => '',
                ];
                
            default:
                return [
                    'open_id' => '',
                    'nickname' => '',
                    'avatar' => '',
                    'email' => '',
                ];
        }
    }

    /**
     * 获取 OAuth 端点 URL
     *
     * @param string $provider 提供者名称
     * @return array 端点 URL 数组
     */
    private static function getOAuthEndpoints(string $provider): array
    {
        $endpoints = [
            'github' => [
                'authorize' => 'https://github.com/login/oauth/authorize',
                'token' => 'https://github.com/login/oauth/access_token',
                'user' => 'https://api.github.com/user',
            ],
            'google' => [
                'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token' => 'https://oauth2.googleapis.com/token',
                'user' => 'https://www.googleapis.com/oauth2/v2/userinfo',
            ],
            'wechat' => [
                'authorize' => 'https://open.weixin.qq.com/connect/qrconnect',
                'token' => 'https://api.weixin.qq.com/sns/oauth2/access_token',
                'user' => 'https://api.weixin.qq.com/sns/userinfo',
            ],
            'qq' => [
                'authorize' => 'https://graph.qq.com/oauth2.0/authorize',
                'token' => 'https://graph.qq.com/oauth2.0/token',
                'user' => 'https://graph.qq.com/user/get_user_info',
            ],
        ];
        
        return $endpoints[$provider] ?? [];
    }

    /**
     * 获取回调 URL
     *
     * @param string $provider 提供者名称
     * @return string 回调 URL
     */
    private static function getRedirectUri(string $provider): string
    {
        $baseUrl = SettingSvc::get('site_url', '');
        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                     . '://' . $_SERVER['HTTP_HOST'];
        }
        return rtrim($baseUrl, '/') . "/auth/{$provider}/callback";
    }

    /**
     * 获取提供者显示名称
     *
     * @param string $provider 提供者名称
     * @return string 显示名称
     */
    private static function getProviderDisplayName(string $provider): string
    {
        $names = [
            'github' => 'GitHub',
            'google' => 'Google',
            'wechat' => '微信',
            'qq' => 'QQ',
        ];
        
        return $names[$provider] ?? ucfirst($provider);
    }

    /**
     * HTTP GET 请求
     *
     * @param string $url 请求 URL
     * @param array $headers 请求头
     * @return array 响应数据
     * @throws \Exception
     */
    private static function httpGet(string $url, array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || $httpCode !== 200) {
            throw new \Exception('HTTP 请求失败');
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON 解析失败');
        }
        
        return $data;
    }

    /**
     * HTTP POST 请求
     *
     * @param string $url 请求 URL
     * @param array $params 请求参数
     * @param array $headers 请求头
     * @return array 响应数据
     * @throws \Exception
     */
    private static function httpPost(string $url, array $params, array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || $httpCode !== 200) {
            throw new \Exception('HTTP 请求失败');
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON 解析失败');
        }
        
        return $data;
    }
}
