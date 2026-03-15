# AMuBBS 分布式部署指南 — 第二台机器接入

## 前置条件

| 项目 | 机器 A（主节点） | 机器 B（新节点） |
|------|-----------------|-----------------|
| 角色 | Web + MySQL + Redis | Web |
| IP | 192.168.1.101 | 192.168.1.102 |
| 系统 | Ubuntu 22.04+ / CentOS 8+ | 同左 |

> 以下命令中的 IP 请替换为你的实际地址。

---

## 一、机器 A：开放服务端口

### 1.1 MySQL 允许远程连接

```bash
# 编辑 MySQL 配置
sudo vim /etc/mysql/mysql.conf.d/mysqld.cnf
# 修改 bind-address
bind-address = 0.0.0.0

# 重启 MySQL
sudo systemctl restart mysql

# 创建远程用户
mysql -u root -p
```

```sql
CREATE USER 'amubbs'@'192.168.1.%' IDENTIFIED BY '你的密码';
GRANT ALL PRIVILEGES ON bbs.* TO 'amubbs'@'192.168.1.%';
FLUSH PRIVILEGES;
```

### 1.2 Redis 允许远程连接

```bash
sudo vim /etc/redis/redis.conf
```

```conf
# 绑定地址改为允许内网
bind 127.0.0.1 192.168.1.101

# 设置密码（强烈建议）
requirepass your_redis_password

# 关闭保护模式
protected-mode no
```

```bash
sudo systemctl restart redis
```

### 1.3 防火墙放行

```bash
# MySQL 3306 + Redis 6379，仅允许内网段
sudo ufw allow from 192.168.1.0/24 to any port 3306
sudo ufw allow from 192.168.1.0/24 to any port 6379
```

---

## 二、机器 B：安装环境

### 2.1 安装 PHP 8.2 + Nginx

```bash
sudo apt update
sudo apt install -y nginx php8.2-fpm php8.2-mysql php8.2-redis php8.2-mbstring php8.2-xml php8.2-gd php8.2-curl
```

### 2.2 同步代码

**方式一：从机器 A rsync**

```bash
sudo mkdir -p /var/www/amubbs
rsync -avz --exclude='.env' --exclude='storage/logs/*' --exclude='public/uploads/*' \
    root@192.168.1.101:/var/www/amubbs/ /var/www/amubbs/
```

**方式二：从 Git 拉取**

```bash
cd /var/www
git clone https://your-repo-url/amubbs.git
```

### 2.3 创建 .env 配置

```bash
cd /var/www/amubbs
cp .env.example .env
vim .env
```

写入以下内容（关键差异已标注）：

```env
# 应用
APP_MODE=distributed
APP_DEBUG=false
APP_URL=http://192.168.1.102

# 数据库 → 指向机器 A
DB_HOST=192.168.1.101
DB_PORT=3306
DB_DATABASE=bbs
DB_USERNAME=amubbs
DB_PASSWORD=你的密码

# Redis → 指向机器 A
REDIS_HOST=192.168.1.101
REDIS_PORT=6379
REDIS_PASSWORD=your_redis_password

# Session 使用 Redis（多节点共享的关键）
SESSION_DRIVER=redis
SESSION_LIFETIME=7200
SESSION_REDIS_DB=1

# 缓存
CACHE_DRIVER=redis

# 文件上传（见第三节）
UPLOAD_DRIVER=local
```

### 2.4 设置目录权限

```bash
sudo mkdir -p /var/www/amubbs/storage/{logs,cache}
sudo mkdir -p /var/www/amubbs/public/uploads
sudo chown -R www-data:www-data /var/www/amubbs
sudo chmod -R 755 /var/www/amubbs/storage /var/www/amubbs/public/uploads
```

### 2.5 配置 Nginx

```bash
sudo vim /etc/nginx/sites-available/amubbs
```

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/amubbs/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(js|css|png|jpg|gif|ico|svg|woff2?)$ {
        expires 30d;
        access_log off;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/amubbs /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

---

## 三、文件上传共享

两台机器都能处理请求，上传的文件必须共享，否则另一台看不到。

### 方案 A：NFS 共享（推荐小规模）

```bash
# 机器 A：安装 NFS 服务端
sudo apt install -y nfs-kernel-server
sudo vim /etc/exports
# 添加：
/var/www/amubbs/public/uploads 192.168.1.0/24(rw,sync,no_subtree_check,no_root_squash)

sudo exportfs -ra
sudo systemctl restart nfs-server

# 机器 B：挂载
sudo apt install -y nfs-common
sudo mount -t nfs 192.168.1.101:/var/www/amubbs/public/uploads /var/www/amubbs/public/uploads

# 开机自动挂载
echo '192.168.1.101:/var/www/amubbs/public/uploads /var/www/amubbs/public/uploads nfs defaults 0 0' | sudo tee -a /etc/fstab
```

### 方案 B：OSS 对象存储（推荐生产环境）

修改机器 A 和 B 的 `.env`：

```env
UPLOAD_DRIVER=oss
OSS_ENDPOINT=oss-cn-hangzhou.aliyuncs.com
OSS_BUCKET=amubbs-uploads
OSS_ACCESS_KEY=your_key
OSS_SECRET_KEY=your_secret
```

---

## 四、验证连通性

在机器 B 上逐项测试：

```bash
# 1. 测试 MySQL 连接
php -r "
new PDO('mysql:host=192.168.1.101;dbname=bbs', 'amubbs', '你的密码');
echo \"MySQL 连接成功\n\";
"

# 2. 测试 Redis 连接
php -r "
\$r = new Redis();
\$r->connect('192.168.1.101', 6379);
\$r->auth('your_redis_password');
echo \$r->ping() ? \"Redis 连接成功\n\" : \"Redis 失败\n\";
"

# 3. 测试健康检查接口
curl -s http://localhost/health | python3 -m json.tool
```

预期输出：

```json
{
    "status": "ok",
    "timestamp": 1740000000,
    "hostname": "machine-b",
    "checks": {
        "database": "ok",
        "redis": "ok",
        "disk": "45.2%"
    }
}
```

---

## 五、配置负载均衡

在机器 A（或独立的 LB 机器）上配置 Nginx 反向代理：

```bash
sudo vim /etc/nginx/conf.d/upstream.conf
```

```nginx
upstream amubbs_backend {
    least_conn;
    server 192.168.1.101:80 weight=2 max_fails=3 fail_timeout=30s;
    server 192.168.1.102:80 weight=2 max_fails=3 fail_timeout=30s;
    keepalive 16;
}

server {
    listen 80;
    server_name your-domain.com;

    location / {
        proxy_pass http://amubbs_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_next_upstream error timeout http_502 http_503;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 六、后续更新部署

使用项目自带的部署脚本：

```bash
# 在机器 A 项目目录下执行
# 更新本机
./deploy.sh

# 同步到机器 B
./deploy.sh 192.168.1.102
```

---

## 七、排查清单

| 问题 | 排查方法 |
|------|---------|
| 登录后刷新掉线 | 检查两台 `.env` 的 `SESSION_DRIVER=redis` 和 Redis 地址是否一致 |
| 上传图片另一台看不到 | 检查 NFS 挂载或 OSS 配置 |
| 数据库连接超时 | `telnet 192.168.1.101 3306`，检查防火墙和 MySQL bind-address |
| Redis 连接拒绝 | `telnet 192.168.1.101 6379`，检查 protected-mode 和密码 |
| 健康检查返回 500 | `curl http://localhost/health` 查看具体哪个 check 失败 |
| 两台数据不同步 | 确认都连同一个 MySQL，不是各自的本地库 |
