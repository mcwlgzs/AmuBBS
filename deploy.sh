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
        --exclude='storage/logs/*' \
        --exclude='storage/cache/*' \
        --exclude='public/uploads/*' \
        --exclude='install/install.lock' \
        ./ root@$NODE_IP:$REMOTE_DIR/

    # 2. 远程设置权限 + 重启
    info "设置权限并重启服务..."
    ssh root@$NODE_IP "
        chown -R www-data:www-data $REMOTE_DIR
        chmod -R 755 $REMOTE_DIR/storage
        chmod -R 755 $REMOTE_DIR/public/uploads
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
BACKUP_DIR="backups/$(date +%Y%m%d_%H%M%S)"
info "备份到 $BACKUP_DIR ..."
mkdir -p "$BACKUP_DIR"
cp .env "$BACKUP_DIR/.env" 2>/dev/null || true

# 2. 拉取最新代码
if [ -d ".git" ]; then
    info "拉取最新代码..."
    git pull origin main
fi

# 3. 设置权限
info "设置目录权限..."
mkdir -p storage/logs storage/cache public/uploads
chmod -R 755 storage public/uploads 2>/dev/null || true

# 3.5 清理旧的插件软链接（已改为 PluginManager 自动发布资源）
if [ -L "public/plugin-assets" ]; then
    rm -f "public/plugin-assets"
fi
mkdir -p public/plugin-assets

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
    warn "健康检查返回 HTTP $HTTP_CODE，请手动检查服务状态"
fi

info "部署完成"
