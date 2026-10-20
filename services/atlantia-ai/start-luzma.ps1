# Starts what Luzma needs, only if it is not already running: Ollama (11434) and the Luzma gateway (8100).
# It is idempotent, so it can be run by hand, by the Windows task installed with install-luzma-autostart.ps1,
# or by the Laravel watchdog (php artisan luzma:watchdog) whenever Luzma stops answering.
$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$logs = Join-Path $env:TEMP 'atlantia-luzma'
New-Item -ItemType Directory -Force $logs | Out-Null

function Test-Url([string] $url) {
    try { Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 $url | Out-Null; return $true } catch { return $false }
}

if (-not (Test-Url 'http://127.0.0.1:11434/api/tags')) {
    $ollama = (Get-Command ollama -ErrorAction SilentlyContinue).Source
    if (-not $ollama) { $ollama = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe' }
    if (Test-Path $ollama) {
        Start-Process -FilePath $ollama -ArgumentList 'serve' -WindowStyle Hidden
        Start-Sleep -Seconds 4
    }
}

if (-not (Test-Url 'http://127.0.0.1:8100/')) {
    $python = Join-Path $root '.venv\Scripts\python.exe'
    Start-Process -FilePath $python -ArgumentList '-m', 'uvicorn', 'app.main:app', '--host', '127.0.0.1', '--port', '8100' `
        -WorkingDirectory $root -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $logs 'gateway.log') -RedirectStandardError (Join-Path $logs 'gateway.err.log')
}
