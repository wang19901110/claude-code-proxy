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
  echo [ERROR] vendor\autoload.php is missing.
  echo This distribution requires the bundled vendor folder.
  pause
  exit /b 1
)

echo.
echo Starting Claude Code Desktop local proxy at http://127.0.0.1:8787
echo This window must stay open while Claude Code Desktop uses the proxy.
echo Press Ctrl+C to stop it.
echo.

"%CD%\php8.3.2nts\php.exe" "%CD%\proxy.php" start
set "EXIT_CODE=%ERRORLEVEL%"

echo.
echo Proxy stopped with exit code %EXIT_CODE%.
pause
exit /b %EXIT_CODE%
