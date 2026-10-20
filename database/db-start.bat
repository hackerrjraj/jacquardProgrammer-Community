@echo off
rem Start the project's portable MySQL (127.0.0.1:3307) in the background.
setlocal
cd /d "%~dp0"

"mysql\bin\mysqladmin.exe" --defaults-file="my.ini" -u root --protocol=TCP ping >nul 2>&1
if %errorlevel%==0 (
    echo MySQL is already running on port 3307.
    exit /b 0
)

start "JPPHAF MySQL" /B "mysql\bin\mysqld.exe" --defaults-file="my.ini"

rem Wait up to 30 seconds for MySQL to accept connections.
for /l %%i in (1,1,30) do (
    ping -n 2 127.0.0.1 >nul
    "mysql\bin\mysqladmin.exe" --defaults-file="my.ini" -u root --protocol=TCP ping >nul 2>&1 && (
        echo MySQL started on 127.0.0.1:3307.
        exit /b 0
    )
)
echo MySQL did not start. See database\mysql-error.log
exit /b 1
