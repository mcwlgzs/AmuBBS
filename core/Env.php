<?php
/**
 * 环境变量加载器
 * 从 .env 文件读取配置，支持分布式部署的配置外部化
 */

namespace Core;

class Env
{
    private static bool $loaded = false;
    private static array $cache = [];

    /**
     * 加载 .env 文件
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        $file = rtrim($path, '/') . '/.env';
        if (!file_exists($file)) {
            self::$loaded = true;
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // 跳过注释
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // 去除引号
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // 类型转换
            $value = match (strtolower($value)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)' => null,
                'empty', '(empty)' => '',
                default => $value,
            };

            self::$cache[$key] = $value;

            // 同时设置到 $_ENV（putenv 可能被服务器禁用）
            if (is_string($value) || is_numeric($value)) {
                $_ENV[$key] = $value;
                if (\function_exists('putenv')) {
                    \putenv("{$key}={$value}");
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * 获取环境变量
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $value = $_ENV[$key] ?? (\function_exists('getenv') ? \getenv($key) : false);
        if ($value === false) {
            return $default;
        }

        return $value;
    }
}

/**
 * 全局 env() 辅助函数
 */
function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}
