@echo off
setlocal EnableExtensions

cd /d "%~dp0"
title B.AI Claude Desktop Local Proxy

where php >nul 2>nul
if errorlevel 1 (
  echo [ERROR] PHP was not found in PATH.
  echo Install PHP 8.1+ or add php.exe to PATH, then run this file again.
  pause
  exit /b 1
)

if not exist ".env" (
  copy /y ".env.example" ".env" >nul
  echo [ACTION REQUIRED] Created .env from .env.example.
  echo Open .env and set BAI_API_KEY to a newly generated B.AI Key.
  notepad ".env"
  pause
  exit /b 1
)

set "BAI_API_KEY="
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
  if /I "%%A"=="BAI_API_KEY" set "BAI_API_KEY=%%B"
)
if not defined BAI_API_KEY (
  echo [ACTION REQUIRED] BAI_API_KEY is empty in .env.
  echo Add a newly generated B.AI Key, save the file, and run this file again.
  notepad ".env"
  pause
  exit /b 1
)

if not exist "vendor\autoload.php" (
  where composer >nul 2>nul
  if errorlevel 1 (
    echo [ERROR] Composer was not found and vendor\autoload.php is missing.
    echo Install Composer or run composer install in this folder.
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
echo Starting B.AI Claude Desktop local proxy at http://127.0.0.1:8787
echo This window shows safe request and response summaries in real time.
echo Request logs are appended to %CD%\workerman.log
echo Keep this window open while Claude Desktop uses the proxy.
echo Press Ctrl+C to stop it.
echo.

php8.3.2nts\php.exe proxy.php start
set "EXIT_CODE=%ERRORLEVEL%"

echo.
echo Proxy stopped with exit code %EXIT_CODE%.
pause
exit /b %EXIT_CODE%
