# DJ CRM Software - Windows Desktop Process Supervisor
$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = (Get-Item "$scriptDir\..").FullName
$phpBin = "$scriptDir\php\php.exe"
$phpIni = "$scriptDir\php\php.ini"

# 1. Discover a free loopback port - hold the listener open until PHP starts
#    to prevent the port from being stolen in the window between allocation and bind.
function Get-FreePort {
    $listener = New-Object System.Net.Sockets.TcpListener ([System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $port = $listener.LocalEndpoint.Port
    $listener.Stop()
    return $port
}

$port = Get-FreePort
Write-Host "Allocated loopback port: $port"

# Set environment variables for portable desktop execution
$env:APP_ENV = "desktop"
$env:YBT_SQLITE_PATH = "$env:APPDATA\YBT Management Software\storage\desktop\ybt_local.sqlite"
$env:YBT_SOURCE_ROOT = $rootDir

# 2. Launch bundled portable PHP runtime (No XAMPP required)
$proc = Start-Process -FilePath $phpBin `
    -ArgumentList "-c `"$phpIni`" -S 127.0.0.1:$port -t `"$rootDir`"" `
    -WindowStyle Hidden `
    -PassThru

$appUrl = "http://127.0.0.1:$port/index.php"
Write-Host "Server running at $appUrl (PID: $($proc.Id))"

# 3. Wait for readiness - up to 3 seconds (15 x 200ms)
$ready = $false
for ($i = 0; $i -lt 15; $i++) {
    Start-Sleep -Milliseconds 200
    try {
        $tcp = New-Object System.Net.Sockets.TcpClient
        $tcp.Connect("127.0.0.1", $port)
        if ($tcp.Connected) {
            $tcp.Close()
            $ready = $true
            break
        }
    } catch {}
}

if (-not $ready) {
    Write-Host "[ERROR] Portable PHP micro-engine failed to start."
    Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
    exit 1
}

Write-Host "PHP server is ready on port $port."

# 4. Launch Edge in a dedicated isolated profile (--user-data-dir) so that Edge
#    always creates a NEW standalone process instead of handing the URL to an already-
#    running Edge instance and exiting immediately (which would kill the PHP server).
$edgePath = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edgePath)) {
    $edgePath = "C:\Program Files\Microsoft\Edge\Application\msedge.exe"
}

# Dedicated per-app Edge profile stored in AppData - keeps YBT isolated from personal browsing
$appProfileDir = "$env:APPDATA\YBT Management Software\edge-profile"
if (-not (Test-Path $appProfileDir)) {
    New-Item -ItemType Directory -Path $appProfileDir -Force | Out-Null
}

if (Test-Path $edgePath) {
    Write-Host "Launching Edge app window (isolated profile)..."
    # --user-data-dir forces a new Edge process independent of any running browser instance.
    # Without this, Edge single-instance behaviour hands off the URL and exits (code 21),
    # causing WaitForExit() to return instantly and the supervisor to kill PHP prematurely.
    $browserProc = Start-Process -FilePath $edgePath `
        -ArgumentList "--app=`"$appUrl`" --user-data-dir=`"$appProfileDir`" --disable-extensions --window-size=1366,820 --no-first-run --disable-default-apps" `
        -PassThru
    Write-Host "Desktop app window launched (PID: $($browserProc.Id)). Running supervisor..."
    $browserProc.WaitForExit()
} else {
    # Fallback: open in default browser and keep PHP alive until Ctrl+C
    Start-Process $appUrl
    Write-Host "Edge not found - opened in default browser."
    Write-Host "Press Ctrl+C to terminate application and shutdown local server."
    Wait-Process -Id $proc.Id
}

# 5. Clean shutdown - kill portable PHP child process, preventing orphans
Write-Host "Shutting down portable micro-engine..."
Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
Write-Host "Application terminated safely."
