#!/usr/bin/env bash
#
# AMuBBS 一键验证（Linux / macOS / CI 版）
#
# 与 scripts/verify.ps1 等价，顺序相同：
#   1. 全量 php -l
#   2. 6 个静态检查器（imports / routes / schema / layers / events / env）
#   3. 3 个自检（不需要数据库与 Redis）
#   4. 冒烟测试（真实 HTTP，需要站点已安装、后台验证码关闭）
#
# 用法：
#   bash scripts/verify.sh                     # 默认 http://127.0.0.1:8000
#   SMOKE_BASE_URL=https://bbs.example.com bash scripts/verify.sh
#   SKIP_SMOKE=1 bash scripts/verify.sh        # 只跑静态检查 + 自检
#   PHP_BIN=/usr/bin/php8.2 bash scripts/verify.sh
#
# 退出码：0 = 全部通过，1 = 有失败项。
#
# 注意：这是 CI/服务器上用的脚本，刻意不加任何外部依赖（只用 php + bash）。
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
BASE_URL="${SMOKE_BASE_URL:-http://127.0.0.1:8000}"
SKIP_SMOKE="${SKIP_SMOKE:-0}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "找不到 PHP 可执行文件：$PHP_BIN（可用 PHP_BIN=/path/to/php 指定）" >&2
    exit 1
fi

PASSED=0
FAILED=0
FAILED_LIST=()

run_step() {
    local name="$1"; shift
    echo
    echo "=== ${name} ==="
    if "$@"; then
        echo "  -> PASS"
        PASSED=$((PASSED + 1))
    else
        echo "  -> FAIL"
        FAILED=$((FAILED + 1))
        FAILED_LIST+=("$name")
    fi
}

# ---------------------------------------------------------------- 1. 语法
step_lint() {
    local files total=0 bad=0
    files=$(find app core config resources public install plugins scripts -name '*.php' 2>/dev/null | sort)
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        total=$((total + 1))
        if ! "$PHP_BIN" -l "$f" >/dev/null 2>&1; then
            echo "  LINT FAIL: $f"
            bad=$((bad + 1))
        fi
    done <<< "$files"
    echo "  扫描 ${total} 个文件，语法错误 ${bad}"
    [ "$bad" -eq 0 ]
}

run_step "1/4 语法检查（php -l 全量）" step_lint

# ---------------------------------------------------------------- 2. 静态检查
for c in check_imports check_routes check_schema check_layers check_events check_env check_docs; do
    run_step "2/4 静态检查（${c}）" "$PHP_BIN" "scripts/${c}.php"
done

# ---------------------------------------------------------------- 3. 自检
for s in selftest_cache selftest_hooks selftest_install; do
    run_step "3/4 自检（${s}）" "$PHP_BIN" "scripts/${s}.php"
done

# ---------------------------------------------------------------- 4. 冒烟
if [ "$SKIP_SMOKE" = "1" ]; then
    echo
    echo "=== 4/4 冒烟测试（SKIP_SMOKE=1，已跳过）==="
else
    smoke() {
        SMOKE_BASE_URL="$BASE_URL" "$PHP_BIN" scripts/smoke.php
    }
    run_step "4/4 冒烟测试（${BASE_URL}）" smoke
fi

# ---------------------------------------------------------------- 汇总
echo
echo "========================================================"
echo "通过：${PASSED}    失败：${FAILED}"
if [ "$FAILED" -eq 0 ]; then
    echo "验证通过 ✅"
    exit 0
fi

echo "失败项："
for f in "${FAILED_LIST[@]}"; do
    echo "  - $f"
done
echo "验证失败 ❌"
exit 1
