#!/bin/bash
# deploy.sh - AMuBBS 自动部署脚本
# 用法:
#   ./deploy.sh                  本机部署（更新代码+重启服务）
#   ./deploy.sh 192.168.1.102   远程节点部署

set -e

REMOTE_DIR="/var/www/amubbs"
NODE_IP=$1

# 颜色输出
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# ===== 远程部署 =====
if [ -n "$NODE_IP" ]; then
    info "开始部署到远程节点: $NODE_IP"

    # 1. 同步代码
    info "同步代码..."
    rsync -avz --delete \
        --exclude='.git' \
        --exclude='.env' \
        --exclude='backups/' \
        --exclude='storage/logs/*' \
        --exclude='storage/cache/*' \
        --exclude='storage/sessions/*' \
        --exclude='public/uploads/*' \
        --exclude='install/install.lock' \
        ./ root@$NODE_IP:$REMOTE_DIR/

    # 2. 远程设置权限 + 重启
    #
    # 注意不要用 chmod -R 755 storage：那会把 storage/sessions/* 里的会话文件
    # （PHP 默认 0600，文件名就是 session id）变成全机可读，任何本地用户都能
    # 直接拿别人的 PHPSESSID。目录 750、文件 640 足够 www-data 读写自己的数据。
    info "设置权限并重启服务..."
    ssh root@$NODE_IP "
        chown -R www-data:www-data $REMOTE_DIR
        mkdir -p $REMOTE_DIR/storage/{logs,cache,sessions,uploads,tmp,plugin_config} $REMOTE_DIR/public/uploads
        find $REMOTE_DIR/storage -type d -exec chmod 750 {} +
        find $REMOTE_DIR/storage -type f -exec chmod 640 {} +
        chmod 700 $REMOTE_DIR/storage
        chmod 600 $REMOTE_DIR/.env 2>/dev/null || true
        chmod 600 $REMOTE_DIR/storage/app_key 2>/dev/null || true
        find $REMOTE_DIR/public/uploads -type d -exec chmod 755 {} +
        find $REMOTE_DIR/public/uploads -type f -exec chmod 644 {} +
        systemctl reload php8.2-fpm
    "

    # 3. 健康检查
    info "健康检查..."
    sleep 2
    HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' http://$NODE_IP/health 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        info "节点 $NODE_IP 部署成功 (HTTP $HTTP_CODE)"
    else
        error "节点 $NODE_IP 健康检查失败 (HTTP $HTTP_CODE)"
    fi
    exit 0
fi

# ===== 本机部署 =====
info "开始本机部署..."

# 1. 备份
# .env 里有数据库口令和 APP_KEY，备份目录也必须是「只有属主可读」，
# 否则任何能读到项目目录的人都能拿到整套凭据。
umask 077
BACKUP_DIR="backups/$(date +%Y%m%d_%H%M%S)"
info "备份到 $BACKUP_DIR ..."
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
if [ -f .env ]; then
    cp .env "$BACKUP_DIR/.env" && chmod 600 "$BACKUP_DIR/.env"
fi
if [ -f storage/app_key ]; then
    cp storage/app_key "$BACKUP_DIR/app_key" && chmod 600 "$BACKUP_DIR/app_key"
fi
umask 022

# 2. 拉取最新代码
if [ -d ".git" ]; then
    info "拉取最新代码..."
    git pull origin main
fi

# 3. 设置权限
# 同远程分支：storage 里的会话文件不能世界可读，用 750/640 而不是 -R 755。
info "设置目录权限..."
mkdir -p storage/logs storage/cache storage/sessions storage/uploads storage/tmp storage/plugin_config public/uploads
find storage -type d -exec chmod 750 {} + 2>/dev/null || true
find storage -type f -exec chmod 640 {} + 2>/dev/null || true
chmod 600 storage/app_key 2>/dev/null || true
chmod 600 .env 2>/dev/null || true
find public/uploads -type d -exec chmod 755 {} + 2>/dev/null || true
find public/uploads -type f -exec chmod 644 {} + 2>/dev/null || true

# 插件静态资源走 /plugin-assets/{plugin}/{file} 路由（core/Bootstrap.php 里注册），
# 不需要再把插件目录软链到 public/ 下，也就不需要创建 public/plugin-assets/。

# 4. 清除 OPcache（如果有 PHP-FPM）
if command -v systemctl &> /dev/null; then
    if systemctl is-active --quiet php8.2-fpm 2>/dev/null; then
        info "重载 PHP-FPM..."
        sudo systemctl reload php8.2-fpm
    fi
fi

# 5. 健康检查
APP_URL=$(grep '^APP_URL=' .env 2>/dev/null | cut -d'=' -f2 || echo "http://localhost:8000")
info "健康检查: $APP_URL/health"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$APP_URL/health" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    info "部署成功!"
else
    error "健康检查失败：$APP_URL/health 返回 HTTP $HTTP_CODE"
fi

info "部署完成"
