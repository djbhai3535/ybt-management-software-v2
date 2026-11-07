@echo off
setlocal
title DJ CRM Software — Windows Desktop Launcher

set "SCRIPT_DIR=%~dp0"
set "PHP_BIN=%SCRIPT_DIR%php\php.exe"
set "PHP_INI=%SCRIPT_DIR%php\php.ini"
set "ROOT_DIR=%SCRIPT_DIR%.."

echo ============================================================
echo  DJ CRM Software -- Desktop Launcher
echo  Script dir : %SCRIPT_DIR%
echo  PHP binary : %PHP_BIN%
echo ============================================================
echo.

if not exist "%PHP_BIN%" (
    echo [ERROR] Bundled portable PHP runtime not found.
    echo         Expected: %PHP_BIN%
    echo.
    echo  Possible causes:
    echo    - desktop\php\php.exe is missing from the project.
    echo    - The project folder was moved and the php subfolder is absent.
    echo.
    pause
    exit /b 1
)

echo [OK] PHP binary found.
echo Starting portable micro-engine via PowerShell...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%launch-desktop.ps1"
set "PS_EXIT=%ERRORLEVEL%"

echo.
echo ============================================================
if "%PS_EXIT%"=="0" (
    echo  Application exited normally.  (PowerShell exit code: 0)
) else (
    echo  [ERROR] PowerShell supervisor exited with code: %PS_EXIT%
    echo.
    echo  Troubleshooting steps:
    echo    1. Screenshot this entire window.
    echo    2. Check:  storage\logs\desktop_php_error.log
    echo    3. Check:  storage\logs\php-errors.log
    echo    4. Report the exit code and any red lines above.
)
echo ============================================================
echo.
echo  Press any key to close this window...
pause >nul
endlocal
