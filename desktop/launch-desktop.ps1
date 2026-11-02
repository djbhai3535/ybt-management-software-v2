# YBT Management Software — Windows Desktop Process Supervisor
$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = (Get-Item "$scriptDir\..").FullName
$phpBin = "$scriptDir\php\php.exe"
$phpIni = "$scriptDir\php\php.ini"

# 1. Discover random free loopback port
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

$appUrl = "http://127.0.0.1:$port/dashboard.php"
Write-Host "Server running at $appUrl (PID: $($proc.Id))"

# 3. Wait for readiness
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

# 4. Launch Native Edge WebView / Browser window in App Mode
$edgePath = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edgePath)) {
    $edgePath = "C:\Program Files\Microsoft\Edge\Application\msedge.exe"
}

if (Test-Path $edgePath) {
    # Launch in standalone application window mode (borderless/frameless app appearance)
    $browserProc = Start-Process -FilePath $edgePath -ArgumentList "--app=`"$appUrl`" --disable-extensions --window-size=1366,820" -PassThru
    Write-Host "Desktop app window launched. Running supervisor..."
    $browserProc.WaitForExit()
} else {
    Start-Process $appUrl
    Write-Host "Press Ctrl+C to terminate application and shutdown local server."
    Wait-Process -Id $proc.Id
}

# 5. Clean shutdown — kill portable PHP child process, preventing orphans
Write-Host "Shutting down portable micro-engine..."
Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
Write-Host "Application terminated safely."
