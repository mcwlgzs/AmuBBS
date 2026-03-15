<?php
/**
 * 统一输入验证器
 * 提供用户名、邮箱、密码、通用字段等常用验证方法
 * 参考 Xiuno BBS 的 param_check 系列函数，以 OOP 方式实现
 */

namespace Core;

class Validator
{
    /** 验证错误信息 */
    private array $errors = [];

    /**
     * 验证用户名
     */
    public function username(string $value, int $minLen = 3, int $maxLen = 20): self
    {
        $len = mb_strlen($value);
        if ($len === 0) {
            $this->errors[] = '用户名不能为空';
        } elseif ($len < $minLen || $len > $maxLen) {
            $this->errors[] = "用户名长度为 {$minLen}-{$maxLen} 个字符";
        } elseif (preg_match('/[\x00-\x1f\x7f<>"\'&\\\\\/]/', $value)) {
            $this->errors[] = '用户名包含非法字符';
        } elseif (preg_match('/^\s|\s$/', $value)) {
            $this->errors[] = '用户名首尾不能有空格';
        }
        return $this;
    }

    /**
     * 验证邮箱
     */
    public function email(string $value): self
    {
        if (empty($value)) {
            $this->errors[] = '邮箱不能为空';
        } elseif (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = '邮箱格式不正确';
        } elseif (mb_strlen($value) > 100) {
            $this->errors[] = '邮箱长度不能超过 100 个字符';
        }
        return $this;
    }

    /**
     * 验证密码
     */
    public function password(string $value, int $minLen = 6, bool $requireMixed = false): self
    {
        if (empty($value)) {
            $this->errors[] = '密码不能为空';
        } elseif (strlen($value) < $minLen) {
            $this->errors[] = "密码长度至少 {$minLen} 个字符";
        } elseif (strlen($value) > 128) {
            $this->errors[] = '密码长度不能超过 128 个字符';
        } elseif ($requireMixed && (!preg_match('/[a-zA-Z]/', $value) || !preg_match('/[0-9]/', $value))) {
            $this->errors[] = '密码必须同时包含字母和数字';
        }
        return $this;
    }

    /**
     * 验证两次密码一致
     */
    public function passwordConfirm(string $password, string $confirm): self
    {
        if ($password !== $confirm) {
            $this->errors[] = '两次密码不一致';
        }
        return $this;
    }

    /**
     * 验证必填字段
     */
    public function required(mixed $value, string $fieldName = '字段'): self
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->errors[] = "{$fieldName}不能为空";
        }
        return $this;
    }

    /**
     * 验证字符串长度
     */
    public function length(string $value, string $fieldName, int $min = 0, int $max = 255): self
    {
        $len = mb_strlen($value);
        if ($min > 0 && $len < $min) {
            $this->errors[] = "{$fieldName}长度至少 {$min} 个字符";
        }
        if ($len > $max) {
            $this->errors[] = "{$fieldName}长度不能超过 {$max} 个字符";
        }
        return $this;
    }

    /**
     * 验证整数范围
     */
    public function intRange(int $value, string $fieldName, int $min = 0, int $max = PHP_INT_MAX): self
    {
        if ($value < $min || $value > $max) {
            $this->errors[] = "{$fieldName}必须在 {$min}-{$max} 之间";
        }
        return $this;
    }

    /**
     * 验证 URL
     */
    public function url(string $value, string $fieldName = 'URL'): self
    {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[] = "{$fieldName}格式不正确";
        }
        return $this;
    }

    /**
     * 验证 IP 地址
     */
    public function ip(string $value, string $fieldName = 'IP'): self
    {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_IP)) {
            $this->errors[] = "{$fieldName}格式不正确";
        }
        return $this;
    }

    /**
     * 自定义验证规则
     */
    public function custom(bool $condition, string $message): self
    {
        if (!$condition) {
            $this->errors[] = $message;
        }
        return $this;
    }

    /**
     * 是否通过验证
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * 是否验证失败
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * 获取第一条错误信息
     */
    public function firstError(): string
    {
        return $this->errors[0] ?? '';
    }

    /**
     * 获取所有错误信息
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 验证失败时抛出异常（便捷方法）
     *
     * @throws \RuntimeException
     */
    public function throwOnFail(): self
    {
        if ($this->fails()) {
            throw new \RuntimeException($this->firstError());
        }
        return $this;
    }

    /**
     * 重置错误
     */
    public function reset(): self
    {
        $this->errors = [];
        return $this;
    }

    /**
     * 静态快捷创建
     */
    public static function make(): self
    {
        return new self();
    }
}
