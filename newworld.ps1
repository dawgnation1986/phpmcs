# Backup the old world, then start the server (which generates a new world).
# All text is ASCII on purpose: PowerShell 5.1 misreads non-ASCII .ps1 files
# unless they carry a BOM, and a BOM breaks .bat files in cmd.
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

Write-Host '=========================================='
Write-Host '  NEW WORLD  (old world is backed up)'
Write-Host '=========================================='
Write-Host ''

if (Test-Path 'world') {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
    $dest = Join-Path 'backup' ('world-' + $stamp)
    New-Item -ItemType Directory -Force -Path 'backup' | Out-Null

    try {
        Move-Item -LiteralPath 'world' -Destination $dest -ErrorAction Stop
        Write-Host ('Old world backed up to: ' + $dest) -ForegroundColor Green
    } catch {
        Write-Host 'Backup FAILED - is the server still running? Close it first.' -ForegroundColor Red
        Write-Host ('Detail: ' + $_.Exception.Message) -ForegroundColor DarkGray
        Write-Host ''
        Read-Host 'Press Enter to exit'
        exit 1
    }
} else {
    Write-Host 'No old world found, creating a fresh one.' -ForegroundColor Yellow
}

Write-Host ''
Write-Host 'Starting server...'
Write-Host ''

& (Join-Path $PSScriptRoot 'start.ps1')
