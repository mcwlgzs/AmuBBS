<?php
/**
 * 路由类
 */

namespace Core;

class Router
{
    /** 动态路由（含参数占位符） */
    private array $routes = [];
    /** 静态路由快速查找表：$staticRoutes[METHOD][path] = route */
    private array $staticRoutes = [];
    private array $globalMiddlewares = [];

    /**
     * 注册 GET 路由
     */
    public function get(string $path, $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    /**
     * 注册 POST 路由
     */
    public function post(string $path, $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    /**
     * 添加全局中间件
     */
    public function use(\App\Middlewares\Middleware $middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }

    /**
     * 添加路由
     */
    private function addRoute(string $method, string $path, $handler, array $middlewares = []): void
    {
        // 静态路由（无参数占位符）用 hashmap 存储，O(1) 查找
        if (!str_contains($path, '{')) {
            $this->staticRoutes[$method][$path] = [
                'handler' => $handler,
                'middlewares' => $middlewares,
            ];
            return;
        }

        $this->routes[$method][] = [
            'path' => $path,
            'handler' => $handler,
            'pattern' => $this->convertToRegex($path),
            'middlewares' => $middlewares,
        ];
    }

    /**
     * 路由分发
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // 防止超长 URL 消耗正则匹配资源
        if (strlen($path) > 512) {
            http_response_code(414);
            echo '414 URI Too Long';
            return;
        }
        // 支持 .html 后缀访问：自动去除 .html 再匹配路由（需后台开启）
        if (str_ends_with($path, '.html') && $path !== '/.html'
            && \App\Services\SettingSvc::getBool('url_html_suffix', true)) {
            $path = substr($path, 0, -5);
            if ($path === '') {
                $path = '/';
            }
        }

        // 查找匹配的路由
        $route = $this->matchRoute($method, $path);

        if ($route === null) {
            $this->handleNotFound();
            return;
        }

        // 合并全局中间件和路由中间件
        $middlewares = array_merge($this->globalMiddlewares, $route['middlewares']);

        // 构建中间件链并执行
        $handler = $route['handler'];
        $params = $route['params'];
        $finalHandler = function () use ($handler, $params) {
            $this->runHandler($handler, $params);
        };

        $this->runMiddlewareChain($middlewares, $finalHandler);
    }

    /**
     * 路由匹配
     */
    private function matchRoute(string $method, string $path): ?array
    {
        // 优先查找静态路由（O(1) hashmap）
        if (isset($this->staticRoutes[$method][$path])) {
            $route = $this->staticRoutes[$method][$path];
            return [
                'handler' => $route['handler'],
                'params' => [],
                'middlewares' => $route['middlewares'],
            ];
        }

        // 回退到动态路由正则匹配
        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                array_shift($matches);
                return [
                    'handler' => $route['handler'],
                    'params' => $matches,
                    'middlewares' => $route['middlewares'],
                ];
            }
        }

        return null;
    }

    /**
     * 将路由路径转换为正则表达式
     */
    private function convertToRegex(string $path): string
    {
        $pattern = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function($m) {
            return isset($m[2]) ? '(' . $m[2] . ')' : '([^/]+)';
        }, $path);
        return '#^' . $pattern . '$#';
    }

    /**
     * 执行处理器
     */
    private function runHandler($handler, array $params): void
    {
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            call_user_func_array([$controller, $method], $params);
        }
    }

    /**
     * 执行中间件链
     */
    private function runMiddlewareChain(array $middlewares, callable $finalHandler): void
    {
        $chain = $finalHandler;

        // 从后往前包装中间件
        foreach (array_reverse($middlewares) as $middleware) {
            $next = $chain;
            $chain = function () use ($middleware, $next) {
                $middleware->handle($next);
            };
        }

        $chain();
    }

    /**
     * 处理 404
     */
    private function handleNotFound(): void
    {
        http_response_code(404);
        $viewFile = APP_PATH . 'resources/views/404.php';
        if (file_exists($viewFile)) {
            require $viewFile;
        } else {
            echo '404 Not Found';
        }
    }
}
