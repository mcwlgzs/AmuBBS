<?php
/**
 * 多语言管理类
 * 支持按需加载语言包、占位符替换
 */

namespace Core;

class Lang
{
    /** 当前语言 */
    private static string $locale = 'zh-cn';

    /** 已加载的语言数据 */
    private static array $loaded = [];

    /** 语言包目录 */
    private static string $langPath = '';

    /**
     * 初始化语言系统
     */
    public static function init(string $locale = '', string $langPath = ''): void
    {
        self::$langPath = $langPath ?: APP_PATH . 'resources/lang/';

        if ($locale !== '') {
            self::$locale = $locale;
        }
    }

    /**
     * 设置当前语言
     */
    public static function setLocale(string $locale): void
    {
        // 防止路径遍历：只允许字母、数字、中划线
        if (!preg_match('/^[a-zA-Z0-9\-]+$/', $locale)) {
            throw new \InvalidArgumentException('Invalid locale format');
        }
        self::$locale = $locale;
        self::$loaded = []; // 切换语言时清空已加载数据
    }

    /**
     * 获取当前语言
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * 获取翻译文本
     *
     * @param string $key 格式: "module.key" 或 "module.key.subkey"
     * @param array $params 占位符替换 ['name' => '张三']
     * @param string|null $default 默认值，null 时返回 key 本身
     */
    public static function get(string $key, array $params = [], ?string $default = null): string
    {
        $parts = explode('.', $key, 2);
        if (count($parts) < 2) {
            return $default ?? $key;
        }

        [$module, $itemKey] = $parts;

        // 按需加载语言文件
        self::loadModule($module);

        // 查找翻译
        $value = self::$loaded[self::$locale][$module][$itemKey] ?? null;

        if ($value === null) {
            return $default ?? $key;
        }

        // 占位符替换
        if (!empty($params)) {
            foreach ($params as $k => $v) {
                $value = str_replace(":{$k}", (string)$v, $value);
            }
        }

        return $value;
    }

    /**
     * 按需加载语言模块文件
     */
    private static function loadModule(string $module): void
    {
        if (isset(self::$loaded[self::$locale][$module])) {
            return;
        }

        // 防止路径遍历：module 只允许字母、数字、下划线
        if (!preg_match('/^\w+$/', $module)) {
            self::$loaded[self::$locale][$module] = [];
            return;
        }

        $file = self::$langPath . self::$locale . '/' . $module . '.php';
        if (file_exists($file)) {
            $data = require $file;
            if (is_array($data)) {
                self::$loaded[self::$locale][$module] = $data;
                return;
            }
        }

        // 文件不存在或格式错误，设为空数组避免重复加载
        self::$loaded[self::$locale][$module] = [];
    }

    /**
     * 获取所有可用语言列表
     */
    public static function getAvailableLocales(): array
    {
        $locales = [];
        $dir = self::$langPath;
        if (!is_dir($dir)) {
            return $locales;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir($dir . $item)) {
                $locales[] = $item;
            }
        }
        return $locales;
    }
}
