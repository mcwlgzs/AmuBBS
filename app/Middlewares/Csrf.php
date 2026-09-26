<?php
/**
 * CSRF 防护中间件
 *
 * Token 来源有两条，按访客状态自动选择：
 *  1) 已启动会话的访客（登录用户、带 PHPSESSID 的回访者、所有 POST）用会话里的 token；
 *  2) 完全匿名且没有会话 Cookie 的访客（首访、爬虫、curl）用「双提交 Cookie」：
 *     服务端随机生成 token 同时写进 Cookie 和页面，提交时比对二者。
 *
 * 为什么需要第 2 条：以前匿名 GET 也要走 Csrf::generateToken() → ensureSession()，
 * 于是每个没有 Cookie 的访客（包括每个爬虫页面）都会在 storage/sessions 落一个文件，
 * 而 session gc 只有 1/1000 概率触发、且只清 gc_maxlifetime(=1440s) 之前的文件
 * ——文件数单调增长，最终 inode 耗尽，站点写入全挂。
 * 双提交 Cookie 不需要服务端存储，SameSite=Lax 又保证跨站 POST 根本带不上这个 Cookie。
 */

namespace App\Middlewares;

class Csrf implements Middleware
{
    /** 双提交 Cookie 名 */
    private const COOKIE_NAME = 'amubbs_csrf';

    /** Cookie/Cookie 里 token 的形状（64 位十六进制） */
    private const TOKEN_RE = '/^[0-9a-f]{64}$/';

    /**
     * 本请求内已发放的 token
     *
     * 必须缓存：一次响应里模板可能多处调用 generateToken()，若每次都新生成，
     * 页面里的 token 与 PageCache 抽占位符时拿到的 token 就不一致，占位符会漏进缓存文件。
     */
    private static string $issued = '';

    public function handle(callable $next): void
    {
        // GET/HEAD/OPTIONS 请求不需要 CSRF 验证
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) {
            $next();
            return;
        }

        // API 路由：仅当携带 Bearer Token 时跳过 CSRF 验证
        // 防止攻击者构造表单 POST 到 /api/ 路径绕过 CSRF
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (str_starts_with($path, '/api/')) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (stripos($auth, 'Bearer ') === 0 && strlen($auth) > 7) {
                $next();
                return;
            }
            // 无 Bearer Token 的 /api/ 请求继续走 CSRF 验证
        }

        $token = (string)($_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if ($token === '' || !self::matchesAny(self::currentTokens(), $token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'CSRF 验证失败，请刷新页面重试',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 表单提交轮换 token 防止重放；AJAX 请求不轮换，避免后续请求 403
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
        if (!$isAjax) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                self::$issued = (string)$_SESSION['csrf_token'];
            } else {
                // POST 正常情况下一定会启动会话（Bootstrap::startSession 不跳过 POST），
                // 这里只是兜底：没有会话就轮换双提交 Cookie。
                self::$issued = self::issueCookieToken(true);
            }
        }

        $next();
    }

    /**
     * 生成（或取回）本请求的 CSRF Token
     */
    public static function generateToken(): string
    {
        if (self::$issued !== '') {
            return self::$issued;
        }

        // 1) 会话已启动：登录用户 / 带 PHPSESSID 的回访者 / 所有 POST
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return self::$issued = (string)$_SESSION['csrf_token'];
        }

        // 2) 未启动但访客带着 session cookie：必须恢复会话（身份依赖它）
        if (isset($_COOKIE[session_name()])) {
            \Core\Bootstrap::ensureSession();
            if (session_status() === PHP_SESSION_ACTIVE) {
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
                return self::$issued = (string)$_SESSION['csrf_token'];
            }
        }

        // 3) 完全匿名且无会话 Cookie：双提交 Cookie，不创建 session 文件
        return self::$issued = self::issueCookieToken(false);
    }

    /**
     * 本请求已经发放过的 token（不触发生成，供 PageCache 抽占位符用）
     */
    public static function currentToken(): string
    {
        return self::$issued;
    }

    /**
     * 本次提交可接受的 token 集合（会话 token + 本请求发放的 token + 双提交 Cookie）
     *
     * @return string[]
     */
    public static function currentTokens(): array
    {
        $tokens = [];
        if (!empty($_SESSION['csrf_token'])) {
            $tokens[] = (string)$_SESSION['csrf_token'];
        }
        if (self::$issued !== '') {
            $tokens[] = self::$issued;
        }
        $cookie = (string)($_COOKIE[self::COOKIE_NAME] ?? '');
        if (preg_match(self::TOKEN_RE, $cookie)) {
            $tokens[] = $cookie;
        }
        return array_values(array_unique($tokens));
    }

    /**
     * 校验提交的 token 是否命中任一合法值（全用 hash_equals，避免时序侧信道）
     *
     * @param string[] $expected
     */
    public static function matchesAny(array $expected, string $token): bool
    {
        foreach ($expected as $candidate) {
            if ($candidate !== '' && hash_equals($candidate, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 发放（或复用）双提交 Cookie token
     */
    private static function issueCookieToken(bool $rotate = false): string
    {
        $existing = (string)($_COOKIE[self::COOKIE_NAME] ?? '');
        if (!$rotate && preg_match(self::TOKEN_RE, $existing)) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie(self::COOKIE_NAME, $token, [
                'expires'  => time() + 7200,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        // setcookie() 不会更新 $_COOKIE，手工同步，保证同一请求内后续读取一致
        $_COOKIE[self::COOKIE_NAME] = $token;
        return $token;
    }

    /**
     * 获取 CSRF Token 的 HTML hidden input
     */
    public static function tokenField(): string
    {
        $token = self::generateToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * 获取 CSRF Token 的 meta 标签（供 JS 使用）
     */
    public static function tokenMeta(): string
    {
        $token = self::generateToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
