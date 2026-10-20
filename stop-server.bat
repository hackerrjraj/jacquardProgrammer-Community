<# : batch portion - this file is both a batch file and a PowerShell script
@echo off
rem JPPHAF - one-click stop. Double-click this file.
rem Stops only JPPHAF's own frontend (3000), API (8000), chat server (8080) and MySQL (3307).
setlocal
set "PATH=C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64;C:\laragon\bin\nodejs\node-v22;%PATH%"
set "JPP_SELF=%~f0"
title JPPHAF - Stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "iex ([IO.File]::ReadAllText($env:JPP_SELF))"
set "JPP_RC=%errorlevel%"
echo.
pause
exit /b %JPP_RC%
#>

# ---------------- PowerShell portion ----------------
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Root = Split-Path -Parent $env:JPP_SELF

# True if the process runs from (or its command line points into) this project folder.
function Test-OurProcess($proc) {
    if (-not $proc) { return $false }
    $text = ("$($proc.ExecutablePath) $($proc.CommandLine)") -replace '/', '\'
    return $text.IndexOf($Root, [StringComparison]::OrdinalIgnoreCase) -ge 0
}

function Get-PortPids([int]$Port) {
    @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue).OwningProcess |
        Where-Object { $_ } | Sort-Object -Unique
}

function Stop-Tree([int]$ProcId) {
    & taskkill.exe /PID $ProcId /T /F *> $null
}

Write-Host ''
Write-Host '=== JPPHAF - stopping ===' -ForegroundColor Cyan

# 1. Close the "JPPHAF API" / "JPPHAF Reverb" / "JPPHAF Scheduler" / "JPPHAF Queue" / "JPPHAF Frontend" windows (and everything running inside them).
$windows = Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" |
    Where-Object { $_.CommandLine -match 'title JPPHAF (API|Reverb|Frontend|Scheduler|Queue) ' -and (Test-OurProcess $_) }
foreach ($w in $windows) { Stop-Tree $w.ProcessId }
if ($windows) { Write-Host 'Closed the JPPHAF API / Reverb / Scheduler / Queue / Frontend windows.' }

# 2. Anything still listening on 8000 / 8080 / 3000 - kill it only if it belongs to this project.
foreach ($port in 8000, 8080, 3000) {
    foreach ($procId in (Get-PortPids $port)) {
        $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$procId" -ErrorAction SilentlyContinue
        if (Test-OurProcess $proc) {
            Stop-Tree $procId
            Write-Host "Stopped JPPHAF process on port $port."
        } else {
            Write-Host "Port $port is used by another program - not touched." -ForegroundColor Yellow
        }
    }
}

# 3. MySQL - clean shutdown.
$dbStop = Start-Process cmd.exe -ArgumentList "/c `"`"$Root\database\db-stop.bat`"`"" -NoNewWindow -PassThru
$dbStop.WaitForExit()

# 4. Confirm the ports are free.
Start-Sleep -Seconds 2
Write-Host ''
$allFree = $true
foreach ($port in 3307, 8000, 8080, 3000) {
    if (Get-PortPids $port) {
        Write-Host "Port $port : still in use" -ForegroundColor Yellow
        $allFree = $false
    } else {
        Write-Host "Port $port : free" -ForegroundColor Green
    }
}
if ($allFree) {
    Write-Host 'JPPHAF is stopped.' -ForegroundColor Cyan
    exit 0
}
Write-Host 'Some ports are still in use (by JPPHAF still shutting down, or by another program).' -ForegroundColor Yellow
exit 1
