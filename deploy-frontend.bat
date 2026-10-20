@echo off
rem JPPHAF - build the frontend for production and publish it to the GitHub branch "deploy-frontend" (Phase 14b).
rem The cPanel server cannot run "next build", so the build is made here and the server only downloads it (git pull).
rem   deploy-frontend.bat           build + assemble deploy\frontend-branch + publish
rem   deploy-frontend.bat nopush    build + assemble only (to test it with: node app.js)
rem Needs frontend\.env.production (see frontend\.env.production.example). Your running local site is NOT touched.
setlocal EnableDelayedExpansion
title JPPHAF - Frontend build branch
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "NODEDIR=C:\laragon\bin\nodejs\node-v22"
set "PATH=%NODEDIR%;%PATH%"
set "OUT=%ROOT%\deploy"
set "STAGE=%OUT%\stage-fe"
set "PKG=%OUT%\frontend-branch"
set "REMOTE=https://github.com/hackerrjraj/jacquardProgrammer-Community.git"
set "BRANCH=deploy-frontend"

echo.
echo  JPPHAF - frontend build branch
echo  ------------------------------
echo.

if not exist "%ROOT%\frontend\.env.production" (
  echo  STOPPED: frontend\.env.production is missing.
  goto :end
)

call :clean "%STAGE%"
call :clean "%PKG%"
mkdir "%STAGE%" "%PKG%" 2>nul

echo  [1/3] Building (a few minutes)...
rem .env.local (local development values) is NOT copied, so it can never leak into the online build.
robocopy "%ROOT%\frontend" "%STAGE%" /E /XD node_modules .next /XF .env.local .env.development* /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 ( echo  FAILED: could not copy the frontend. & goto :end )
pushd "%STAGE%"
call "%NODEDIR%\npm.cmd" ci
if errorlevel 1 ( popd & echo  FAILED: npm ci. & goto :end )
call "%NODEDIR%\npm.cmd" run build
if errorlevel 1 ( popd & echo  FAILED: npm run build. & goto :end )
popd
if not exist "%STAGE%\.next\standalone\server.js" ( echo  FAILED: no standalone build. & goto :end )

echo.
echo  [2/3] Assembling the ready-to-run folder...
robocopy "%STAGE%\.next\standalone" "%PKG%" /E /NFL /NDL /NJH /NJS /NP >nul
robocopy "%STAGE%\public" "%PKG%\public" /E /NFL /NDL /NJH /NJS /NP >nul
robocopy "%STAGE%\.next\static" "%PKG%\.next\static" /E /NFL /NDL /NJH /NJS /NP >nul
copy /y "%ROOT%\frontend\app.js" "%PKG%\app.js" >nul
rem No .env files in the published folder: the public values are already baked into the build.
del /q "%PKG%\.env*" 2>nul
call :clean "%STAGE%"
echo  Ready: %PKG%   (test with: node app.js)

if /i "%~1"=="nopush" goto :end

echo.
echo  [3/3] Publishing to branch "%BRANCH%" (only this branch is force-pushed; main is not touched)...
pushd "%PKG%"
git init -q -b %BRANCH%
git config core.longpaths true
git config core.autocrlf false
del /s /q "*.map" >nul 2>&1
git add -A
git commit -q -m "Frontend production build %DATE% %TIME%"
if errorlevel 1 ( popd & echo  FAILED: commit. & goto :end )
git remote add origin %REMOTE%
git push --force origin %BRANCH%
if errorlevel 1 ( popd & echo  FAILED: push. If a login window opens, sign in to GitHub there. & goto :end )
popd
echo.
echo  DONE. Branch "%BRANCH%" is published. On the server: see "Updating the live frontend" in docs\DEPLOYMENT_CPANEL.md

:end
echo.
pause
exit /b

rem Deletes a folder (robocopy mirror from an empty folder copes with the very long node_modules paths).
:clean
if not exist "%~1" exit /b 0
mkdir "%OUT%\empty" 2>nul
robocopy "%OUT%\empty" "%~1" /MIR /NFL /NDL /NJH /NJS /NP >nul
rmdir "%OUT%\empty" 2>nul
rmdir "%~1" 2>nul
exit /b 0
