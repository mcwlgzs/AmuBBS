param(
    # PHP 可执行文件；默认用 PATH 里的 php
    [string]$Php = 'php',
    # 冒烟测试的站点地址；默认读环境变量 SMOKE_BASE_URL，再默认本机 8000
    [string]$BaseUrl = '',
    # 跳过冒烟（例如本地没起服务时只跑静态检查与自检）
    [switch]$SkipSmoke
)

# ⚠️ 本文件必须保存为 UTF-8 with BOM：PowerShell 5.1 读「无 BOM 的 UTF-8」会按 ANSI 解码，
#    中文注释会变成乱码并直接语法报错（用文本编辑器改完记得确认编码）。
# AMuBBS 一键验证（Windows / PowerShell 版）
#
# 与 scripts/verify.sh 等价，二者都按同一顺序跑：
#   1. 全量 php -l
#   2. 6 个静态检查器（imports / routes / schema / layers / events / env）
#   3. 3 个自检（cache / hooks / install，不需要数据库与 Redis）
#   4. 冒烟测试（真实 HTTP，需要站点已安装、后台验证码关闭）
#
# 用法：
#   pwsh scripts/verify.ps1
#   pwsh scripts/verify.ps1 -SkipSmoke
#   pwsh scripts/verify.ps1 -BaseUrl http://127.0.0.1:8000
#
# 退出码：0 = 全部通过，1 = 有失败项。

$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Push-Location $root

if ($BaseUrl -eq '') {
    $BaseUrl = if ($env:SMOKE_BASE_URL) { $env:SMOKE_BASE_URL } else { 'http://127.0.0.1:8000' }
}

$results = [System.Collections.Generic.List[object]]::new()

function Invoke-Step {
    param([string]$Name, [scriptblock]$Body)

    Write-Host ''
    Write-Host "=== $Name ===" -ForegroundColor Cyan
    $ok = $true
    try {
        & $Body
        if ($LASTEXITCODE -ne $null -and $LASTEXITCODE -ne 0) { $ok = $false }
    } catch {
        Write-Host "  ERROR: $($_.Exception.Message)" -ForegroundColor Red
        $ok = $false
    }

    $script:results.Add([pscustomobject]@{ Step = $Name; Passed = $ok })
    if ($ok) { Write-Host "  -> PASS" -ForegroundColor Green } else { Write-Host "  -> FAIL" -ForegroundColor Red }
    return $ok
}

# ---------------------------------------------------------------- 1. 语法
Invoke-Step '1/4 语法检查（php -l 全量）' {
    $dirs = @('app', 'core', 'config', 'resources', 'public', 'install', 'plugins', 'scripts')
    $files = Get-ChildItem -Path $dirs -Recurse -Include *.php -ErrorAction SilentlyContinue
    $bad = @()
    foreach ($f in $files) {
        & $Php -l $f.FullName *> $null
        if ($LASTEXITCODE -ne 0) { $bad += $f.FullName }
    }
    Write-Host ("  扫描 {0} 个文件，语法错误 {1}" -f $files.Count, $bad.Count)
    foreach ($b in $bad) { Write-Host "  LINT FAIL: $b" -ForegroundColor Red }
    $global:LASTEXITCODE = if ($bad.Count -eq 0) { 0 } else { 1 }
}

# ---------------------------------------------------------------- 2. 静态检查
foreach ($c in @('check_imports', 'check_routes', 'check_schema', 'check_layers', 'check_events', 'check_env', 'check_docs')) {
    Invoke-Step "2/4 静态检查（$c）" {
        & $Php "scripts/$c.php"
    } | Out-Null
}

# ---------------------------------------------------------------- 3. 自检
foreach ($s in @('selftest_cache', 'selftest_hooks', 'selftest_install')) {
    Invoke-Step "3/4 自检（$s）" {
        & $Php "scripts/$s.php"
    } | Out-Null
}

# ---------------------------------------------------------------- 4. 冒烟
if ($SkipSmoke) {
    Write-Host ''
    Write-Host '=== 4/4 冒烟测试（已用 -SkipSmoke 跳过）===' -ForegroundColor Yellow
    $results.Add([pscustomobject]@{ Step = '4/4 冒烟测试'; Passed = $null })
} else {
    Invoke-Step "4/4 冒烟测试（$BaseUrl）" {
        $env:SMOKE_BASE_URL = $BaseUrl
        & $Php 'scripts/smoke.php'
    } | Out-Null
}

# ---------------------------------------------------------------- 汇总
Write-Host ''
Write-Host ('=' * 56)
$failed = @($results | Where-Object { $_.Passed -eq $false })
$skipped = @($results | Where-Object { $_.Passed -eq $null })
foreach ($r in $results) {
    $mark = if ($r.Passed -eq $true) { 'PASS' } elseif ($r.Passed -eq $false) { 'FAIL' } else { 'SKIP' }
    $color = if ($r.Passed -eq $true) { 'Green' } elseif ($r.Passed -eq $false) { 'Red' } else { 'Yellow' }
    Write-Host ("  [{0}] {1}" -f $mark, $r.Step) -ForegroundColor $color
}
Write-Host ''
if ($failed.Count -eq 0) {
    Write-Host ("验证通过：{0} 项通过，{1} 项跳过" -f ($results.Count - $skipped.Count), $skipped.Count) -ForegroundColor Green
    Pop-Location
    exit 0
}

Write-Host ("验证失败：{0} 项失败" -f $failed.Count) -ForegroundColor Red
Pop-Location
exit 1
