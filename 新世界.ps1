# 备份旧世界并启动服务器（生成新世界）
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

Write-Host '=========================================='
Write-Host '  开新世界（旧世界会先备份，不会删）'
Write-Host '=========================================='
Write-Host ''

if (Test-Path 'world') {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
    $dest = Join-Path 'backup' ('世界-' + $stamp)
    New-Item -ItemType Directory -Force -Path 'backup' | Out-Null

    try {
        Move-Item -LiteralPath 'world' -Destination $dest -ErrorAction Stop
        Write-Host ('旧世界已备份到: ' + $dest) -ForegroundColor Green
    } catch {
        Write-Host ('备份失败，可能是服务器还开着。先关掉服务器窗口再试。') -ForegroundColor Red
        Write-Host ('详情: ' + $_.Exception.Message) -ForegroundColor DarkGray
        Write-Host ''
        Read-Host '按回车退出'
        exit 1
    }
} else {
    Write-Host '没有旧世界，直接新建。' -ForegroundColor Yellow
}

Write-Host ''
Write-Host '正在启动服务器，会自动生成新世界...'
Write-Host ''

& (Join-Path $PSScriptRoot 'start.ps1')
