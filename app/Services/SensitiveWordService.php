<?php
/**
 * 敏感词过滤服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class SensitiveWordService
{
    /** @var array|null 编译后的正则和替换映射（进程内缓存） */
    private static ?array $compiled = null;

    /**
     * 过滤文本中的敏感词
     * @return array ['text' => 过滤后文本, 'blocked' => 是否包含禁止发布的词, 'words' => 匹配到的敏感词]
     */
    public static function filter(string $text): array
    {
        $compiled = self::getCompiled();
        if ($compiled === null) {
            return ['text' => $text, 'blocked' => false, 'words' => []];
        }

        $matched = [];
        $blocked = false;
        $filtered = $text;

        // 一次正则检测所有禁止词
        if ($compiled['blockPattern']) {
            $ret = preg_match_all($compiled['blockPattern'], $text, $m);
            if ($ret === false) {
                error_log('[SensitiveWordService] preg_match_all failed: ' . preg_last_error_msg());
            } elseif ($ret > 0) {
                $matched = array_merge($matched, $m[0]);
                $blocked = true;
            }
        }

        // 一次正则替换所有过滤词
        if ($compiled['replacePattern']) {
            $replaceMap = $compiled['replaceMap'];
            $result = preg_replace_callback($compiled['replacePattern'], function($m) use ($replaceMap, &$matched) {
                $matched[] = $m[0];
                return $replaceMap[mb_strtolower($m[0])] ?? '***';
            }, $filtered);
            if ($result === null) {
                error_log('[SensitiveWordService] preg_replace_callback failed: ' . preg_last_error_msg());
            } else {
                $filtered = $result;
            }
        }

        return [
            'text' => $filtered,
            'blocked' => $blocked,
            'words' => array_unique($matched),
        ];
    }

    /**
     * 获取编译后的正则和替换映射（进程内缓存 + Redis 缓存）
     */
    private static function getCompiled(): ?array
    {
        if (self::$compiled !== null) {
            return self::$compiled;
        }

        // 尝试从 Redis 读取预编译结果
        $cached = Cache::get('sensitive_words:compiled');
        if ($cached !== null && $cached !== false) {
            self::$compiled = $cached;
            return self::$compiled;
        }

        // 从 DB 加载并编译
        $words = self::getWords();
        if (empty($words)) {
            self::$compiled = null;
            return null;
        }

        $blockParts = [];
        $replaceParts = [];
        $replaceMap = [];
        foreach ($words as $word) {
            $escaped = preg_quote($word['word'], '/');
            if ((int)$word['level'] === 2) {
                $blockParts[] = $escaped;
            } else {
                $replaceParts[] = $escaped;
                $replaceMap[mb_strtolower($word['word'])] = $word['replacement'];
            }
        }

        self::$compiled = [
            'blockPattern' => $blockParts ? '/' . implode('|', $blockParts) . '/iu' : null,
            'replacePattern' => $replaceParts ? '/' . implode('|', $replaceParts) . '/iu' : null,
            'replaceMap' => $replaceMap,
        ];

        // 缓存到 Redis（与词表同 TTL）
        Cache::set('sensitive_words:compiled', self::$compiled, 600);

        return self::$compiled;
    }

    /**
     * 检查文本是否包含禁止发布的敏感词
     */
    public static function isBlocked(string $text): bool
    {
        $result = self::filter($text);
        return $result['blocked'];
    }

    /**
     * 获取敏感词列表（带缓存）
     */
    private static function getWords(): array
    {
        return Cache::get('sensitive_words:all', function () {
            return Database::fetchAll("SELECT word, replacement, level FROM sensitive_words ORDER BY level DESC");
        }, 600);
    }

    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        Cache::delete('sensitive_words:all');
        Cache::delete('sensitive_words:compiled');
        self::$compiled = null;
    }
}
