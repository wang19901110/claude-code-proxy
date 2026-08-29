@echo off
setlocal EnableExtensions

cd /d "%~dp0"
title Claude Code Desktop Local Proxy

if not exist "php8.3.2nts\php.exe" (
  echo [ERROR] Bundled PHP runtime was not found.
  echo Expected: %CD%\php8.3.2nts\php.exe
  pause
  exit /b 1
)

if not exist "vendor\autoload.php" (
  where composer >nul 2>nul
  if errorlevel 1 (
    echo [ERROR] Composer was not found and vendor\autoload.php is missing.
    pause
    exit /b 1
  )
  echo Installing PHP dependencies...
  composer install --no-interaction --prefer-dist
  if errorlevel 1 (
    echo [ERROR] Composer install failed.
    pause
    exit /b 1
  )
)

echo.
echo Starting Claude Code Desktop local proxy at http://127.0.0.1:8787
echo This window shows safe request and response summaries in real time.
echo Request logs are written to %CD%\workerman.log
echo Keep this window open while Claude Code Desktop uses the proxy.
echo Press Ctrl+C to stop it.
echo.

"%CD%\php8.3.2nts\php.exe" "%CD%\proxy.php" start
set "EXIT_CODE=%ERRORLEVEL%"

echo.
echo Proxy stopped with exit code %EXIT_CODE%.
pause
exit /b %EXIT_CODE%
