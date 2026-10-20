@echo off
rem Cleanly shut down the project's portable MySQL (127.0.0.1:3307).
setlocal
cd /d "%~dp0"

"mysql\bin\mysqladmin.exe" --defaults-file="my.ini" -u root --protocol=TCP ping >nul 2>&1
if not %errorlevel%==0 (
    echo MySQL is not running on port 3307.
    exit /b 0
)

"mysql\bin\mysqladmin.exe" --defaults-file="my.ini" -u root --protocol=TCP shutdown
if not %errorlevel%==0 (
    echo Shutdown command failed.
    exit /b 1
)
echo MySQL stopped.
exit /b 0
