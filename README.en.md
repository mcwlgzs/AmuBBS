<div align="center">

# AMuBBS

**A lightweight, high-performance forum system built with PHP 8.2 from scratch.**

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-8892BF?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL 8.0+](https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis 7.0+](https://img.shields.io/badge/Redis-7.0+-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![License MIT](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](./LICENSE)

[中文](./README.md) | English

---

**2000+ QPS** on a 4C8G server · **< 5ms** cached response · **Zero** third-party dependencies

</div>

<br>

## Why AMuBBS?

Most forum systems are bloated with dependencies and slow by default. AMuBBS takes a different approach — a custom micro-framework built entirely from scratch, no Composer, no npm, no build step. Just pure PHP performance.

> Inspired by [Xiuno BBS](https://bbs.xiuno.com/), reimagined for PHP 8.2 with modern architecture patterns.

### At a Glance

| | Feature | Detail |
|:--|:--------|:-------|
| ⚡ | **Fast** | Sub-50ms response, anonymous page cache under 5ms |
| 🪶 | **Lightweight** | Under 4MB per request, 28-class micro-framework |
| 🔌 | **Extensible** | Event-driven plugin system with dependency injection |
| 🛡️ | **Secure** | CSRF, XSS filtering, SQL injection prevention, rate limiting |
| 📦 | **Zero Deps** | No Composer, no npm, no build tools required |

---

### Tech Stack

```
Backend                          Frontend
├── PHP 8.2+ (OPcache + JIT)    ├── Alpine.js (15KB, zero build)
├── MySQL 8.0+ (InnoDB, FTS)    └── Layui (Admin UI)
├── Redis 7.0+ (multi-level)
└── Nginx + PHP-FPM              Architecture
                                  └── Controller → Service → Repository
```

### Features

<table>
<tr>
<td width="50%" valign="top">

**Community**
- Multi-forum management with permissions
- Threads & replies with sticky/highlight/lock
- Markdown + TinyMCE dual editors
- Image upload & fulltext search
- Paid content & tag system

</td>
<td width="50%" valign="top">

**Users**
- Registration & OAuth login
- GitHub / Google / WeChat / QQ
- Credit & level system, daily check-in
- Follow/fans, private messages
- Real-time notifications

</td>
</tr>
<tr>
<td width="50%" valign="top">

**Admin**
- Dashboard with analytics
- User & group management
- Content moderation & announcements
- Sensitive word filter & IP blacklist
- Plugin manager & operation logs

</td>
<td width="50%" valign="top">

**Security**
- CSRF token protection
- HTML sanitizer (XSS prevention)
- PDO prepared statements
- Three-tier rate limiting
- Six-level site run modes
- Distributed session support

</td>
</tr>
</table>

---

### Quick Start

```bash
# 1. Clone
git clone https://github.com/mcwlgzs/AMuBBS.git && cd AMuBBS

# 2. Configure
cp .env.example .env
# Edit .env with your database and Redis credentials

# 3. Database
mysql -u root -p -e "CREATE DATABASE amubbs DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p amubbs < install/database.sql

# 4. Permissions
chmod -R 755 storage/ public/uploads/

# 5. Run
php -S localhost:8000 -t public public/router.php
```

> Visit `http://localhost:8000` — the web installer will guide you through setup.

### Configuration

<details>
<summary><b>.env reference</b></summary>

```ini
APP_MODE=single              # single | distributed
APP_DEBUG=false
APP_URL=http://localhost:8000

DB_HOST=127.0.0.1
DB_DATABASE=amubbs
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

SESSION_DRIVER=file          # file | redis
CACHE_DRIVER=redis           # redis | file
UPLOAD_DRIVER=local          # local | oss | nfs
```

</details>

### Project Structure

```
AMuBBS/
├── app/                         # Application layer
│   ├── Controllers/             #   18 frontend + 17 admin controllers
│   ├── Services/                #   27 business logic services
│   ├── Repositories/            #   Data access layer
│   ├── Middlewares/              #   Auth, CSRF, RateLimit, RunLevel
│   ├── Events/                  #   Event definitions
│   └── Listeners/               #   Event listeners
├── core/                        # Custom micro-framework (28 classes)
├── config/                      # Configuration files
├── plugins/                     # Plugin directory
├── resources/views/             # View templates
├── public/                      # Web root (single entry point)
├── storage/                     # Runtime (logs, cache, sessions)
├── install/                     # Installer & database schema (30 tables)
└── docs/                        # Documentation (12 articles)
```

### Plugins

AMuBBS ships with 4 built-in plugins:

| Plugin | Description |
|:-------|:------------|
| `AutoAvatar` | Assigns random avatars on registration (57 built-in) |
| `Emoji` | Emoji shortcode parsing (`:name:` syntax) |
| `SocialLogin` | OAuth login — GitHub / Google / WeChat / QQ |
| `TinymceEditor` | Rich text editor as Markdown alternative |

<details>
<summary><b>Creating your own plugin</b></summary>

```
plugins/MyPlugin/
├── MyPluginPlugin.php       # Main class (required)
├── plugin.json              # Metadata (required)
├── config.php               # Default config (optional)
└── assets/                  # Static files (optional)
```

See [`docs/10-插件开发.md`](./docs/10-插件开发.md) for the full plugin development guide.

</details>

### Production Deployment

<details>
<summary><b>Nginx configuration</b></summary>

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/AMuBBS/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(css|js|png|jpg|gif|ico|svg|woff2?|ttf|eot)$ {
        expires 30d;
        access_log off;
    }

    location ~ /\.(env|git|htaccess) { deny all; }
    location ~ ^/(storage|config|core|app)/ { deny all; }
}
```

</details>

<details>
<summary><b>PHP optimization (php.ini)</b></summary>

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
opcache.preload=/var/www/AMuBBS/preload.php
opcache.preload_user=www-data
opcache.jit=1255
opcache.jit_buffer_size=64M
```

</details>

### Benchmarks

> Tested on 4C8G CentOS 8, PHP 8.2 (OPcache+JIT), MySQL 8.0, Redis 7.0

```
Scenario              Response Time    Throughput
─────────────────────────────────────────────────
Homepage (cached)         < 5ms        5000+ QPS
Homepage (no cache)      < 50ms        2000  QPS
Thread list              < 80ms        1500  QPS
Thread detail           < 100ms        1200  QPS
Fulltext search         < 200ms         500  QPS
```

### Documentation

Full developer documentation is available in the [`docs/`](./docs/) directory, covering architecture, database design, performance tuning, API reference, plugin development, and distributed deployment.

### Contributing

```bash
git clone https://github.com/mcwlgzs/AMuBBS.git
git checkout -b feature/your-feature
git commit -m 'feat: describe your change'
git push origin feature/your-feature
```

## 🗺️ Roadmap

All features have been completed!

### License

[MIT](./LICENSE) — use it however you like.

---

<div align="center">

Made with care by [AMuBBS](https://github.com/mcwlgzs/AMuBBS)

</div>
