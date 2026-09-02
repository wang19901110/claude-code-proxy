@echo off
setlocal EnableExtensions

cd /d "%~dp0"
title Claude Code Desktop Free Model Gateway

if not exist "php8.3.2nts\php.exe" (
  echo [ERROR] Bundled PHP runtime was not found.
  pause
  exit /b 1
)

"%CD%\php8.3.2nts\php.exe" "%CD%\bin\migrate-keys.php"
if errorlevel 1 (
  pause
  exit /b 1
)

if not exist "vendor\autoload.php" (
  echo [ERROR] Dependencies are missing. Run: composer install
  pause
  exit /b 1
)

echo Starting gateway at http://127.0.0.1:8787
echo Press Ctrl+C to stop it.
echo.

"%CD%\php8.3.2nts\php.exe" "%CD%\proxy.php" start
set "EXIT_CODE=%ERRORLEVEL%"

echo.
echo Gateway stopped with exit code %EXIT_CODE%.
pause
exit /b %EXIT_CODE%
