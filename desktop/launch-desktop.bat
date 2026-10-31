@echo off
setlocal
title YBT Management Software — Windows Desktop Launcher

set "SCRIPT_DIR=%~dp0"
set "PHP_BIN=%SCRIPT_DIR%php\php.exe"
set "PHP_INI=%SCRIPT_DIR%php\php.ini"
set "ROOT_DIR=%SCRIPT_DIR%..\"

if not exist "%PHP_BIN%" (
    echo [ERROR] Bundled portable PHP runtime not found at %PHP_BIN%
    pause
    exit /b 1
)

echo Starting YBT Management Software portable micro-engine...
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%launch-desktop.ps1"

endlocal
