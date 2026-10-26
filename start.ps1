chcp 65001 > $null
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$Port  = 25565
$Motd  = 'PHP 写的 1.8.9 - 新的世界'
$World = Join-Path $PSScriptRoot 'world'
$Php   = 'php'

$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if ($phpCmd) {
    $Php = $phpCmd.Source
} elseif (Test-Path 'D:\php\php.exe') {
    $Php = 'D:\php\php.exe'
} else {
    Write-Host '[x] 找不到 php.exe，请把 PHP 加入 PATH，或修改本脚本里的 $Php。'
    Read-Host
    exit 1
}

Write-Host "[start] 检查端口 $Port ..."
$listeners = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($listeners) {
    $pids = $listeners.OwningProcess | Sort-Object -Unique
    foreach ($procId in $pids) {
        Write-Host "[start] 端口 $Port 被 PID $procId 占用，强制结束..."
        try {
            Stop-Process -Id $procId -Force -ErrorAction Stop
        } catch {
            Write-Host "[warn] 结束 PID $procId 失败：$($_.Exception.Message)"
        }
    }

    for ($i = 0; $i -lt 30; $i++) {
        if (-not (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)) {
            break
        }
        Start-Sleep -Milliseconds 100
    }
}

Write-Host "[start] 启动服务端：端口 $Port，存档 $World"
& $Php 'bin\server.php' $Port $Motd $World

Write-Host ''
Write-Host '服务端已退出。'
Read-Host
