@echo off
rem JPPHAF - one-click backup. Double-click this file.
rem Saves the database and the uploaded files into the "backups" folder.
rem Keeps only the latest 10 backups of each type.
setlocal EnableDelayedExpansion
title JPPHAF - Backup
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "MYSQLBIN=%ROOT%\database\mysql\bin"
set "BK=%ROOT%\backups"
set "UPLOADS=%ROOT%\backend\storage\app"

if not exist "%BK%" mkdir "%BK%"

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HHmm"') do set "STAMP=%%i"
set "SQLFILE=%BK%\jpphaf-%STAMP%.sql"
set "ZIPFILE=%BK%\uploads-%STAMP%.zip"

echo.
echo  JPPHAF backup
echo  -------------
echo.

"%MYSQLBIN%\mysql.exe" -h 127.0.0.1 -P 3307 -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
  echo  FAILED: The database is not running.
  echo  Please start the site with start-server.bat first, then try again.
  goto :end
)

echo  Step 1 of 2: saving the database...
"%MYSQLBIN%\mysqldump.exe" -h 127.0.0.1 -P 3307 -u root --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file="%SQLFILE%" jpphaf
if errorlevel 1 (
  if exist "%SQLFILE%" del "%SQLFILE%"
  echo  FAILED: The database could not be saved.
  goto :end
)

echo  Step 2 of 2: saving the uploaded files...
powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Compress-Archive -Path '%UPLOADS%\*' -DestinationPath '%ZIPFILE%' -Force -ErrorAction Stop } catch { exit 1 }"
if errorlevel 1 (
  if exist "%ZIPFILE%" del "%ZIPFILE%"
  echo  FAILED: The uploaded files could not be saved.
  echo  The database backup was saved: !SQLFILE!
  goto :end
)

echo  Removing old backups (keeping the latest 10 of each)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "foreach ($p in 'jpphaf-????-??-??_????.sql','uploads-????-??-??_????.zip') { Get-ChildItem -LiteralPath '%BK%' -Filter $p | Sort-Object Name -Descending | Select-Object -Skip 10 | Remove-Item -Force }"

echo.
echo  SUCCESS - Your backup is ready.
echo    Database : !SQLFILE!
echo    Uploads  : !ZIPFILE!
echo.
echo  Tip: also copy the "backups" folder to a USB drive or cloud storage.

:end
echo.
pause
endlocal
