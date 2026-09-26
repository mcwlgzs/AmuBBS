<div align="center">

# AMuBBS

**A lightweight, high-performance forum system built with PHP 8 from scratch.**

[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-8892BF?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL 5.6+](https://img.shields.io/badge/MySQL-5.6%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis Optional](https://img.shields.io/badge/Redis-Optional-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![License MIT](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](./LICENSE)
[![Disclaimer](https://img.shields.io/badge/Illegal%20Use-Not%20Our%20Responsibility-orange?style=for-the-badge)](./LICENSE)

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
├── PHP 8.0+ (OPcache)           ├── htmx 2.0.11 + hand-written CSS (zero build)
├── MySQL 5.6+ / MariaDB 10+     └── layuimini v2 + Layui 2.6.3 (admin, iframe tabs)
├── Cache: file (default) or Redis (optional)
└── Nginx / Apache / shared host   Architecture
                                   └── Controller + Service + Model
```

### Features

<table>
<tr>
<td width="50%" valign="top">

**Community**
- Multi-forum management with permissions
- Threads & replies with sticky/highlight/lock
- Markdown editor (no third-party rich-text dependency)
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
# Edit .env with your database credentials
# No Redis? Keep CACHE_DRIVER=auto and the file cache is used automatically

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
APP_DEBUG=false              # debug mode
APP_URL=http://localhost:8000
APP_KEY=                     # signing key for remember-me / admin API tokens
                             # generate: php -r "echo bin2hex(random_bytes(32));"

DB_HOST=127.0.0.1
DB_DATABASE=amubbs
DB_USERNAME=root
DB_PASSWORD=
# Optional read replicas (reads fall back to the primary when unset)
# DB_READ_HOST=192.168.1.202,192.168.1.203

REDIS_HOST=127.0.0.1         # optional: file cache is used when Redis is absent
REDIS_PORT=6379

SESSION_DRIVER=file          # file (default) | redis (needed to share logins across nodes)
CACHE_DRIVER=auto            # auto (recommended) | file | redis

# Uploads always go to public/uploads/. For multiple web nodes, share that
# directory (NFS or a hosting-panel shared folder); there is no OSS driver.
```

> Every key above is actually read by the code — `php scripts/check_env.php` keeps
> `.env.example` and the code in sync so no "documented but ignored" config can creep back.

</details>

### Project Structure

```
AMuBBS/
├── app/                         # Application layer
│   ├── Controllers/             #   17 frontend + 16 admin controllers
│   ├── Services/                #   26 services (rules, transactions, audit logs)
│   ├── Models/                  #   40 models (SQL + row-cache invalidation)
│   ├── Middlewares/             #   Auth, CSRF, RateLimit, RunLevel
│   ├── Events/                  #   30 event constants
│   └── Listeners/               #   Event listeners
├── core/                        # Custom micro-framework (24 classes)
├── config/                      # Configuration files
├── plugins/                     # Plugins (enable/disable from the admin panel)
├── resources/views/             # View templates (103 files)
├── public/                      # Web root (single entry point)
├── storage/                     # Runtime (cache, logs, sessions, plugin_config)
├── install/                     # Installer & database schema (39 tables)
├── scripts/                     # Verification (smoke + self-tests + 5 static checkers)
└── docs/                        # Documentation (16 articles)
```

### Plugins

Plugins use **WordPress-style hooks**: they hook into events the core already fires with
`add_action()` / `add_filter()` — no Composer, no core patches.

- Enable/disable/uninstall from **System → Plugins** in the admin panel;
  the state lives in `storage/plugin_config/plugins.json`.
- `install()` runs once on first enable, `uninstall()` on uninstall (both optional).
- A broken plugin is logged and skipped — it can never white-screen the site.

The functionality usually packaged as plugins still ships as core services:

| Built-in service | Description |
|:-------|:------------|
| `AutoAvatarService` | Assigns random avatars on registration |
| `EmojiService` | Emoji shortcode parsing (`:name:` syntax) |
| `SocialLoginService` | OAuth login — GitHub / Google / WeChat / QQ |

<details>
<summary><b>Creating your own plugin</b></summary>

```
plugins/MyPlugin/
├── Plugin.php               # Main class (required, implements Core\PluginInterface)
├── plugin.json              # Metadata (name / title / version / entry / class)
└── assets/                  # Static files (optional, served via /plugin-assets/MyPlugin/...)
```

```php
<?php
namespace Plugins\MyPlugin;

use Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function register(): void
    {
        add_filter('thread.title', fn(string $t) => $t . ' [MyPlugin]');
        add_action('thread.created', function (array $data) {
            // ['thread_id' => ..., 'forum_id' => ..., 'user_id' => ..., 'username' => ...]
        });
    }
}
```

See [`docs/10-插件开发.md`](./docs/10-插件开发.md) for the author guide and
[`docs/08-插件系统.md`](./docs/08-插件系统.md) for the implementation.

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

**Run the verification suite before committing** (syntax + 7 static checkers + 3 self-tests + 125 smoke
checks). It must be green **without Redis**:

```bash
bash scripts/verify.sh          # Linux / macOS / CI
powershell scripts/verify.ps1   # Windows
```

## 🗺️ Roadmap

All features have been completed!

### License

**MIT License** — see [LICENSE](./LICENSE). Commercial use, modification, redistribution and
private deployment are allowed; just keep the copyright and permission notice.
Bundled third-party assets (layui / layuimini / Font Awesome / htmx) keep their own licenses —
see [`public/assets/vendor/VERSIONS.txt`](./public/assets/vendor/VERSIONS.txt).

> **Additional disclaimer** (see [DISCLAIMER.md](./DISCLAIMER.md); it does not narrow any right
> granted by the MIT License): the Software is a general-purpose forum program provided "as is".
> The authors and copyright holders do **not** operate or control any site built with it, and
> are **not responsible or liable for any illegal or criminal use** — including any website
> built or operated with this Software that violates applicable law. That responsibility rests
> solely with the operator.

---

<div align="center">

Made with care by [AMuBBS](https://github.com/mcwlgzs/AMuBBS)

</div>
