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

> Inspired by [Xiuno BBS](https://bbs.xiuno.com/), reimagined on modern PHP (8.0+ compatible, 8.2 recommended).

### At a Glance

| | Feature | Detail |
|:--|:--------|:-------|
| ⚡ | **Fast** | Sub-50ms response, anonymous page cache under 5ms |
| 🪶 | **Lightweight** | Custom micro-framework — `core/` is only 25 files, no `vendor/` directory |
| 🖱️ | **Installs in 30s** | Six-step `/install` wizard creates the schema, the admin account and the install lock |
| 🧩 | **Deploys anywhere** | 1-core/1GB VM, shared hosting or a multi-node cluster — only `.env` changes |
| 🗄️ | **Redis-free by default** | `CACHE_DRIVER=auto` falls back to the file cache, no feature loss |
| 🔌 | **Extensible** | WordPress-style hooks (`add_action` / `apply_filters`), enable/disable from the admin |
| 🛡️ | **Secure** | Global CSRF, XSS sanitising, prepared statements everywhere, login lockout, upload allowlist |
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

**Option A — web installer (recommended, ~30 seconds, no SSH and no manual SQL):**

```bash
# 1. Get the code
git clone https://github.com/mcwlgzs/AMuBBS.git && cd AMuBBS

# 2. Configure + permissions (the installer checks these are writable)
cp .env.example .env        # usually only DB_* is needed; keep CACHE_DRIVER=auto
chmod -R 755 storage/ config/ install/ public/uploads/
# if PHP runs as another user (e.g. www-data): chown -R www-data:www-data storage config install public/uploads

# 3. Run (dev). In production point your Nginx/Apache docroot at public/
php -S localhost:8000 -t public public/router.php
```

Open the site — you are taken to `/install`, a six-step wizard:

| Step | What you do | What it does for you |
|:-----|:------------|:---------------------|
| 1️⃣ Instructions | Continue | Shows requirements and the license ([LICENSE](./LICENSE) / [DISCLAIMER.md](./DISCLAIMER.md)) |
| 2️⃣ Environment check | Nothing | Verifies PHP ≥ 8.0, `pdo` / `pdo_mysql` / `mbstring` / `json` (`redis`, `OPcache` optional) and that `config/`, `storage/logs|cache|sessions/`, `install/` are writable |
| 3️⃣ Database | Host / port / database / user (**the wizard can create the database** if the account is allowed to) | Creates the schema (39 tables) |
| 4️⃣ Administrator | Admin username + password | Creates the admin account (bcrypt) |
| 5️⃣ Site settings | Site name, site URL, … | Writes site settings |
| 6️⃣ Done | — | Writes `install/install.lock`; `/install` then only shows an "already installed" notice — **delete that lock file to reinstall** |

Then sign in to the admin panel at `/admin`.

**Option B — manual (CLI, for CI / automation):**

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
chmod -R 755 storage/ config/ install/ public/uploads/

# 5. Run
php -S localhost:8000 -t public public/router.php
```

> Either way the first browser visit is guided to `/install` to finish admin + site setup; see
> [`install/INSTALL.md`](./install/INSTALL.md) for the fully manual path.

### Requirements

| | Minimum | Recommended | Notes |
|:--|:--------|:------------|:------|
| PHP | **8.0** | 8.2+ | Only 8.0 features (`str_starts_with`, `match`, …) are used |
| Database | MySQL 5.6 / MariaDB 10.0 | 5.7+ / 8.0 | No MySQL 8-only syntax; InnoDB |
| Redis | not needed | 7.0+ | **Optional** — file cache is used automatically when absent |
| Web server | any | Nginx 1.20+ | Apache, shared hosting and `php -S` all work |

Required PHP extensions: **`pdo_mysql`, `mbstring`, `json`**. Optional: `redis`, `opcache`,
`gd`, `zip`. **Not required:** Composer, npm/Node, root access, shell access, any daemon, cron.

Writable directories: **`storage/`, `config/`, `install/` (install lock), `public/uploads/`** —
they must be owned by the PHP user (`www-data` for Nginx + PHP-FPM). The installer's step 2 checks
each one and tells you which is not writable.

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

### Deploy on a VM / VPS / Cloud Server

**Yes — and it is the most comfortable option.** A **1-core / 1 GB** Ubuntu or Debian VM (VMware,
VirtualBox, Hyper-V locally; or any cloud VPS) is enough for a small community. No Docker, no
Composer/Node, no daemon: PHP 8.0+ (8.2 recommended), MySQL 5.6+ / MariaDB 10+, and any web
server. Root access helps (PHP-FPM, systemd) but is **not** required — a hosting panel works too.

```bash
# Ubuntu 22.04 / 24.04
sudo apt update
sudo apt install -y nginx mariadb-server git unzip \
    php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-gd php8.2-zip

# Docroot must be public/ — storage/ stays OUTSIDE the web root
sudo mkdir -p /var/www/amubbs && cd /var/www/amubbs
sudo git clone https://github.com/mcwlgzs/AMuBBS.git .

sudo cp .env.example .env
sudo chown -R www-data:www-data /var/www/amubbs
sudo chmod 640 /var/www/amubbs/.env

sudo cp install/nginx.conf.example /etc/nginx/sites-available/amubbs   # edit server_name + root
sudo ln -s /etc/nginx/sites-available/amubbs /etc/nginx/sites-enabled/amubbs
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now php8.2-fpm mariadb

sudo mariadb -e "CREATE DATABASE amubbs DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# then open http://<vm-ip>/ and finish the six-step wizard; keep APP_DEBUG=false
```

| Workload | CPU | RAM | Disk |
|:---------|:----|:----|:-----|
| Personal / test | 1 core | 1 GB | 10 GB |
| Small community (recommended start) | 1–2 cores | 2 GB | 20 GB |
| Medium community | 2–4 cores | 4 GB+ | 40 GB+ |

Four things that bite people on VMs: (1) pointing the docroot at the project root instead of
`public/`; (2) unwritable `storage/`, `config/`, `install/` (the wizard's step 2 tells you
exactly which); (3) cloud firewall/security group not allowing 80/443 (or a NAT-mode NIC on a
local VM); (4) no HTTPS — use `certbot --nginx` and set `APP_URL` accordingly.

Prefer a GUI? With a hosting panel (aaPanel / BT Panel / 1Panel), create a site whose **run
directory is `public/`**, upload the code, create the database and open `/install`.

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
