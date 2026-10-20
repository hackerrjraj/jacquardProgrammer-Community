@echo off
rem JPPHAF - cPanel deployment package (Phase 14a). Double-click this file.
rem Builds the site in a separate "deploy\stage" folder (your running local site is NOT touched) and creates:
rem   deploy\frontend.zip  - Next.js standalone build + public + static files + app.js (Passenger startup file)
rem   deploy\backend.zip   - Laravel without .env, tests, uploads, logs, node_modules
rem Before the first run: copy frontend\.env.production.example to frontend\.env.production and fill it in.
setlocal EnableDelayedExpansion
title JPPHAF - Deploy package
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "PHP=C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64\php.exe"
set "COMPOSER_PHAR=C:\laragon\bin\composer\composer.phar"
set "NODEDIR=C:\laragon\bin\nodejs\node-v22"
set "PATH=%NODEDIR%;%PATH%"
set "OUT=%ROOT%\deploy"
set "STAGE=%OUT%\stage"

echo.
echo  JPPHAF - cPanel deploy package
echo  ------------------------------
echo.

if not exist "%ROOT%\frontend\.env.production" (
  echo  STOPPED: frontend\.env.production is missing.
  echo  Copy frontend\.env.production.example to frontend\.env.production and fill in the values, then run this again.
  goto :end
)

call :cleanstage
if exist "%OUT%\frontend.zip" del /q "%OUT%\frontend.zip"
if exist "%OUT%\backend.zip" del /q "%OUT%\backend.zip"
mkdir "%STAGE%\frontend" "%STAGE%\backend" "%STAGE%\fe-pkg" 2>nul

rem ---------- 1. Frontend ----------
echo  [1/4] Building the frontend (this takes a few minutes)...
rem .env.local (local development values) is NOT copied, so it can never leak into the online build.
robocopy "%ROOT%\frontend" "%STAGE%\frontend" /E /XD node_modules .next /XF .env.local .env.development* /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 ( echo  FAILED: could not copy the frontend. & goto :end )
pushd "%STAGE%\frontend"
call "%NODEDIR%\npm.cmd" ci
if errorlevel 1 ( popd & echo  FAILED: npm ci. & goto :end )
call "%NODEDIR%\npm.cmd" run build
if errorlevel 1 ( popd & echo  FAILED: npm run build. & goto :end )
popd
if not exist "%STAGE%\frontend\.next\standalone\server.js" (
  echo  FAILED: the standalone build was not created.
  goto :end
)
robocopy "%STAGE%\frontend\.next\standalone" "%STAGE%\fe-pkg" /E /NFL /NDL /NJH /NJS /NP >nul
robocopy "%STAGE%\frontend\public" "%STAGE%\fe-pkg\public" /E /NFL /NDL /NJH /NJS /NP >nul
robocopy "%STAGE%\frontend\.next\static" "%STAGE%\fe-pkg\.next\static" /E /NFL /NDL /NJH /NJS /NP >nul
copy /y "%ROOT%\frontend\app.js" "%STAGE%\fe-pkg\app.js" >nul
rem The public values are already baked into the build; keep no .env files in the zip.
del /q "%STAGE%\fe-pkg\.env*" 2>nul

rem ---------- 2. Backend ----------
echo.
echo  [2/4] Preparing the backend...
robocopy "%ROOT%\backend" "%STAGE%\backend" /E /XD vendor node_modules tests .git storage\logs storage\app\public storage\app\private storage\framework\cache storage\framework\sessions storage\framework\views storage\framework\testing /XF .env .phpunit.result.cache phpunit.xml "*.sqlite" /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 ( echo  FAILED: could not copy the backend. & goto :end )
rem empty folders Laravel needs (the .gitignore placeholders are not needed on the server)
for %%D in (storage\logs storage\app\public storage\app\private storage\framework\cache\data storage\framework\sessions storage\framework\views storage\framework\testing) do mkdir "%STAGE%\backend\%%D" 2>nul
del /q "%STAGE%\backend\bootstrap\cache\*.php" 2>nul

echo  [3/4] Installing backend packages without dev tools...
pushd "%STAGE%\backend"
"%PHP%" -d "extension=zip" "%COMPOSER_PHAR%" install --no-dev --optimize-autoloader --no-interaction --no-scripts
if errorlevel 1 ( popd & echo  FAILED: composer install. & goto :end )
popd

rem ---------- 3. Zip ----------
echo.
echo  [4/4] Creating the zip files...
"%SystemRoot%\System32\tar.exe" -a -c -f "%OUT%\frontend.zip" -C "%STAGE%\fe-pkg" .
if errorlevel 1 ( echo  FAILED: frontend.zip. & goto :end )
"%SystemRoot%\System32\tar.exe" -a -c -f "%OUT%\backend.zip" -C "%STAGE%\backend" .
if errorlevel 1 ( echo  FAILED: backend.zip. & goto :end )
call :cleanstage

echo.
echo  DONE. Your files are in the "deploy" folder:
dir /b "%OUT%\*.zip"
echo.
echo  Next: follow docs\DEPLOYMENT_CPANEL.md
:end
echo.
pause
exit /b

rem Deletes the stage folder (robocopy mirror from an empty folder copes with the very long node_modules paths).
:cleanstage
if not exist "%STAGE%" exit /b 0
mkdir "%OUT%\empty" 2>nul
robocopy "%OUT%\empty" "%STAGE%" /MIR /NFL /NDL /NJH /NJS /NP >nul
rmdir "%OUT%\empty" 2>nul
rmdir "%STAGE%" 2>nul
exit /b 0
