<# : batch portion - this file is both a batch file and a PowerShell script
@echo off
rem JPPHAF - one-click start. Double-click this file.
rem Starts MySQL (3307), Laravel API (8000), chat server Reverb (8080), the scheduler + queue worker (daily payment
rem reminder) and the Next.js frontend (3000),
rem then opens http://localhost:3000 in the default browser.
setlocal
set "PATH=C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64;C:\laragon\bin\nodejs\node-v22;%PATH%"
set "JPP_SELF=%~f0"
title JPPHAF - Start
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "iex ([IO.File]::ReadAllText($env:JPP_SELF))"
set "JPP_RC=%errorlevel%"
echo.
pause
exit /b %JPP_RC%
#>

# ---------------- PowerShell portion ----------------
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Root     = Split-Path -Parent $env:JPP_SELF
$Php      = 'C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64\php.exe'
$Composer = 'C:\laragon\bin\composer\composer.phar'
$Npm      = 'C:\laragon\bin\nodejs\node-v22\npm.cmd'

$Ports = [ordered]@{ 3307 = 'MySQL'; 8000 = 'API (Laravel)'; 8080 = 'Chat (Reverb)'; 3000 = 'Frontend (Next.js)' }

# True if the process runs from (or its command line points into) this project folder.
function Test-OurProcess($proc) {
    if (-not $proc) { return $false }
    $text = ("$($proc.ExecutablePath) $($proc.CommandLine)") -replace '/', '\'
    return $text.IndexOf($Root, [StringComparison]::OrdinalIgnoreCase) -ge 0
}

# Returns 'free', 'ours' or 'other' for a listening port.
function Get-PortState([int]$Port) {
    $conns = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    if ($conns.Count -eq 0) { return 'free' }
    foreach ($procId in ($conns.OwningProcess | Sort-Object -Unique)) {
        $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$procId" -ErrorAction SilentlyContinue
        if (-not (Test-OurProcess $proc)) { return 'other' }
    }
    return 'ours'
}

function Wait-Url([string]$Url, [int]$Seconds) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        try { return Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10 } catch { Start-Sleep -Seconds 2 }
    }
    return $null
}

Write-Host ''
Write-Host '=== JPPHAF - starting ===' -ForegroundColor Cyan
Write-Host "Project folder: $Root"

# 0. Tools must exist (a wrong PHP path made the API window fail silently).
foreach ($tool in @($Php, $Npm)) {
    if (-not (Test-Path $tool)) {
        Write-Host "Not found: $tool" -ForegroundColor Red
        Write-Host 'Fix the path at the top of start-server.bat (see CLAUDE.md section 2). JPPHAF was NOT started.' -ForegroundColor Red
        exit 1
    }
}

# 1. Rebuild database\my.ini for the current folder location.
$dbDir = ($Root + '\database') -replace '\\', '/'
$ini = [IO.File]::ReadAllText("$Root\database\my.ini.template").Replace('__DB_DIR__', $dbDir)
[IO.File]::WriteAllText("$Root\database\my.ini", $ini, (New-Object Text.UTF8Encoding $false))
Write-Host 'database\my.ini updated for this folder.'

# 2. Check ports.
$state = @{}
$busy = $false
foreach ($port in $Ports.Keys) {
    $state[$port] = Get-PortState $port
    if ($state[$port] -eq 'other') {
        Write-Host "Port $port is used by another program - please close it." -ForegroundColor Red
        $busy = $true
    }
}
if ($busy) {
    Write-Host 'JPPHAF was NOT started. Nothing was closed or changed.' -ForegroundColor Red
    exit 1
}

# 3. Install packages if missing.
if (-not (Test-Path "$Root\backend\vendor")) {
    Write-Host 'Installing backend packages (composer install) - this can take a few minutes...'
    Push-Location "$Root\backend"
    & $Php -d extension=zip $Composer install --no-interaction
    $rc = $LASTEXITCODE
    Pop-Location
    if ($rc -ne 0) { Write-Host 'composer install failed.' -ForegroundColor Red; exit 1 }
}
if (-not (Test-Path "$Root\frontend\node_modules")) {
    Write-Host 'Installing frontend packages (npm install) - this can take a few minutes...'
    Push-Location "$Root\frontend"
    & $Npm install
    $rc = $LASTEXITCODE
    Pop-Location
    if ($rc -ne 0) { Write-Host 'npm install failed.' -ForegroundColor Red; exit 1 }
}

# 3b. Picture link backend\public\storage (Phase 12d). It points to an absolute path, so it breaks when the
#     project folder is moved - then every uploaded picture fails. Re-made here when it is missing or broken.
$StorageLink = "$Root\backend\public\storage"
$StorageDir  = "$Root\backend\storage\app\public"
$linkItem = Get-Item -LiteralPath $StorageLink -Force -ErrorAction SilentlyContinue
$linkTarget = if ($linkItem -and $linkItem.LinkType) { @($linkItem.Target)[0] } else { $null }
if ($linkTarget -and (($linkTarget.TrimEnd('\') -ne $StorageDir) -or -not (Test-Path -LiteralPath $linkTarget))) {
    # Link to an old folder location: remove only the link itself (never the pictures).
    cmd.exe /c "rmdir `"$StorageLink`"" | Out-Null
    $linkItem = $null
}
if (-not $linkItem) {
    Write-Host 'Making the picture link (php artisan storage:link)...'
    Push-Location "$Root\backend"
    & $Php artisan storage:link | Out-Null
    Pop-Location
    if (Test-Path -LiteralPath "$StorageLink\.gitignore") {
        Write-Host 'Picture link made.' -ForegroundColor Green
    } else {
        Write-Host 'Could not make the picture link - uploaded pictures may not show. Run start-server.bat as administrator once.' -ForegroundColor Yellow
    }
}

# 4. MySQL (hidden window, so it cannot be closed by accident; stop it with stop-server.bat).
if ($state[3307] -eq 'ours') {
    Write-Host 'MySQL is already running (port 3307).' -ForegroundColor Green
} else {
    Write-Host 'Starting MySQL...'
    $db = Start-Process cmd.exe -ArgumentList "/c `"`"$Root\database\db-start.bat`"`"" -WindowStyle Hidden -PassThru
    $db.WaitForExit()
    if ($db.ExitCode -ne 0) {
        Write-Host 'MySQL did not start. See database\mysql-error.log' -ForegroundColor Red
        exit 1
    }
    Write-Host 'MySQL started (port 3307).' -ForegroundColor Green
}

# 5. Laravel API.
if ($state[8000] -eq 'ours') {
    Write-Host 'API is already running (port 8000).' -ForegroundColor Green
} else {
    Write-Host 'Starting API (port 8000)...'
    Start-Process cmd.exe -WindowStyle Minimized -ArgumentList `
        "/k title JPPHAF API & cd /d `"$Root\backend`" & `"$Php`" artisan serve --port=8000"
}

# 5b. Reverb (chat WebSocket, Phase 9a). Full artisan path, so the stop script can tell it is ours.
if ($state[8080] -eq 'ours') {
    Write-Host 'Chat server is already running (port 8080).' -ForegroundColor Green
} else {
    Write-Host 'Starting chat server (port 8080)...'
    Start-Process cmd.exe -WindowStyle Minimized -ArgumentList `
        "/k title JPPHAF Reverb & cd /d `"$Root\backend`" & `"$Php`" `"$Root\backend\artisan`" reverb:start --host=127.0.0.1 --port=8080"
}

# 5c. Scheduler (daily 2:00 PM payment reminder) + queue worker (web push / reminder jobs). No port, so we look for
#     our own window by title and start it only when it is not already running.
foreach ($worker in @(
        @{ Title = 'JPPHAF Scheduler'; Artisan = 'schedule:work' },
        @{ Title = 'JPPHAF Queue';     Artisan = 'queue:work --tries=1 --sleep=3' })) {
    $running = Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match ('title ' + $worker.Title + ' ') -and (Test-OurProcess $_) }
    if ($running) {
        Write-Host "$($worker.Title) is already running." -ForegroundColor Green
    } else {
        Write-Host "Starting $($worker.Title)..."
        Start-Process cmd.exe -WindowStyle Minimized -ArgumentList `
            "/k title $($worker.Title) & cd /d `"$Root\backend`" & `"$Php`" `"$Root\backend\artisan`" $($worker.Artisan)"
    }
}

# 6. Next.js frontend.
if ($state[3000] -eq 'ours') {
    Write-Host 'Frontend is already running (port 3000).' -ForegroundColor Green
} else {
    Write-Host 'Starting frontend (port 3000)...'
    Start-Process cmd.exe -WindowStyle Minimized -ArgumentList `
        "/k title JPPHAF Frontend & cd /d `"$Root\frontend`" & `"$Npm`" run dev"
}

# 7. Wait until both respond.
Write-Host 'Waiting for the API...'
$health = Wait-Url 'http://127.0.0.1:8000/api/health' 60
if (-not $health) {
    Write-Host 'The API did not respond. Look at the "JPPHAF API" window for errors.' -ForegroundColor Red
    exit 1
}
$dbStatus = ($health.Content | ConvertFrom-Json).database
if ($dbStatus -eq 'connected') {
    Write-Host 'API is up - database connected.' -ForegroundColor Green
} else {
    Write-Host "API is up, but the database is NOT connected ($dbStatus)." -ForegroundColor Yellow
}

Write-Host 'Waiting for the chat server...'
$deadline = (Get-Date).AddSeconds(30)
while ((Get-Date) -lt $deadline -and -not (Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue)) {
    Start-Sleep -Seconds 1
}
if (Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue) {
    Write-Host 'Chat server is up (port 8080).' -ForegroundColor Green
} else {
    Write-Host 'The chat server did not start. Look at the "JPPHAF Reverb" window for errors.' -ForegroundColor Red
    exit 1
}

Write-Host 'Waiting for the frontend (first start can take up to a minute)...'
if (-not (Wait-Url 'http://localhost:3000' 180)) {
    Write-Host 'The frontend did not respond. Look at the "JPPHAF Frontend" window for errors.' -ForegroundColor Red
    exit 1
}
Write-Host 'Frontend is up.' -ForegroundColor Green

# 8. Open the site.
Start-Process 'http://localhost:3000'
Write-Host ''
Write-Host 'JPPHAF is running: http://localhost:3000' -ForegroundColor Cyan
Write-Host 'To stop everything, double-click stop-server.bat.'
exit 0
