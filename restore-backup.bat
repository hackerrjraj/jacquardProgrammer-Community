@echo off
rem JPPHAF - restore a database backup. Double-click this file.
rem WARNING: this REPLACES the current database with the chosen backup.
setlocal EnableDelayedExpansion
title JPPHAF - Restore backup
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "MYSQLBIN=%ROOT%\database\mysql\bin"
set "BK=%ROOT%\backups"

echo.
echo  JPPHAF restore
echo  --------------
echo.

"%MYSQLBIN%\mysql.exe" -h 127.0.0.1 -P 3307 -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
  echo  STOPPED: The database is not running.
  echo  Please start the site with start-server.bat first, then try again.
  goto :end
)

set COUNT=0
for /f "delims=" %%f in ('dir /b /a-d /o-n "%BK%\jpphaf-*.sql" 2^>nul') do (
  set /a COUNT+=1
  set "FILE!COUNT!=%%f"
  echo    !COUNT!. %%f
)
if %COUNT%==0 (
  echo  No database backups found in the backups folder.
  goto :end
)

echo.
set "CHOICE="
set /p "CHOICE=Type the number of the backup to restore (or press Enter to cancel): "
if "%CHOICE%"=="" goto :cancel
set "PICK=!FILE%CHOICE%!"
if "%PICK%"=="" (
  echo  That number is not in the list.
  goto :end
)

echo.
echo  You chose: %PICK%
echo  WARNING: All current data in the database will be REPLACED by this backup.
set "CONFIRM="
set /p "CONFIRM=Type YES to confirm: "
if not "%CONFIRM%"=="YES" goto :cancel

echo.
echo  Restoring...
"%MYSQLBIN%\mysql.exe" -h 127.0.0.1 -P 3307 -u root --default-character-set=utf8mb4 -e "DROP DATABASE IF EXISTS jpphaf; CREATE DATABASE jpphaf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
  echo  FAILED: Could not prepare the database.
  goto :end
)
"%MYSQLBIN%\mysql.exe" -h 127.0.0.1 -P 3307 -u root --default-character-set=utf8mb4 jpphaf < "%BK%\%PICK%"
if errorlevel 1 (
  echo  FAILED: The restore did not finish. Do not use the site. Ask for help.
  goto :end
)
echo.
echo  SUCCESS - The database was restored from %PICK%.
echo  Note: uploaded files are not changed. If needed, unzip the matching
echo  uploads-*.zip into backend\storage\app yourself.
goto :end

:cancel
echo.
echo  Cancelled. Nothing was changed.

:end
echo.
pause
endlocal
