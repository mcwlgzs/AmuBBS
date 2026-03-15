# AMuBBS 安装指南

## 快速安装

### 1. 导入数据库

```bash
# 创建数据库
mysql -u root -p -e "CREATE DATABASE amubbs DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 导入数据表
mysql -u root -p amubbs < install/database.sql
```

### 2. 配置数据库连接

编辑 `config/database.php`，修改数据库连接信息：

```php
'connections' => [
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'amubbs',
        'username' => 'root',
        'password' => 'your_password',  // 修改为你的密码
    ],
],
```

### 3. 配置 Redis

编辑 `config/cache.php`，确认 Redis 连接信息：

```php
'redis' => [
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => '',  // 如果有密码，填写这里
    'database' => 0,
],
```

### 4. 启动服务

```bash
# 进入项目目录
cd C:\Users\mc\Downloads\amubbs

# 启动 PHP 内置服务器
php -S localhost:8000 -t public
```

### 5. 访问网站

打开浏览器访问：http://localhost:8000

你应该看到 AMuBBS 首页，包含：
- 板块列表（5个初始板块）
- 统计信息
- 性能指标

## 已初始化的数据

### 用户组
- 普通用户（ID: 1）
- 版主（ID: 2）
- 管理员（ID: 3）

### 板块
- 站务管理
- 技术讨论
  - PHP
  - JavaScript
- 灌水乐园

### 系统配置
- 站点名称：AMuBBS
- 站点状态：5（所有人可读写）
- 站点描述：基于 PHP 8.2 的轻量化论坛系统

## 站点状态说明

参考 Xiuno BBS 的设计，站点状态配置：

- **0**: 站点关闭
- **1**: 管理员可读写
- **2**: 会员可读
- **3**: 会员可读写
- **4**: 所有人只读
- **5**: 所有人可读写（默认）

可以在数据库 `settings` 表中修改 `site_status` 的值。

## 故障排查

### 数据库连接失败
```bash
# 检查 MySQL 是否运行
mysql -u root -p -e "SELECT 1"

# 检查数据库是否存在
mysql -u root -p -e "SHOW DATABASES LIKE 'amubbs'"
```

### Redis 连接失败
```bash
# 检查 Redis 是否运行
redis-cli ping

# 应该返回 PONG
```

### 页面显示空白
```bash
# 检查 PHP 错误日志
tail -f /var/log/php_errors.log

# 或者在浏览器中查看源代码，看是否有 PHP 错误
```

## 下一步

安装完成后，你可以：

1. 创建管理员账号（待开发）
2. 发布第一个帖子（待开发）
3. 配置站点信息（待开发）
4. 安装插件（待开发）

---

**当前版本**: v0.1.0
**安装日期**: 2026-02-22
