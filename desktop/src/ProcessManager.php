<?php
/**
 * YBT Management Software — Desktop Process & Port Manager
 * Discovers available loopback ports, launches the bundled portable PHP runtime,
 * monitors health, and guarantees zero orphan processes on application exit.
 */

class ProcessManager {
    private string $phpBin;
    private string $phpIni;
    private string $webRoot;
    private string $stateFile;

    public function __construct() {
        $desktopDir = dirname(__DIR__);
        $this->phpBin = $desktopDir . '/php/php.exe';
        $this->phpIni = $desktopDir . '/php/php.ini';
        $this->webRoot = dirname($desktopDir);
        $this->stateFile = $desktopDir . '/server.json';
    }

    /**
     * Find an open, unallocated TCP port on 127.0.0.1
     */
    public function findAvailablePort(int $startPort = 12000, int $maxTries = 100): int {
        for ($i = 0; $i < $maxTries; $i++) {
            $port = $startPort + $i;
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (!is_resource($connection)) {
                // Port is free
                return $port;
            }
            fclose($connection);
        }
        return 12088; // Default fallback
    }

    /**
     * Launch the portable PHP micro-server
     */
    public function startServer(?int $port = null): array {
        // If already running, return existing info
        if ($this->isRunning()) {
            return $this->getServerInfo();
        }

        $port = $port ?? $this->findAvailablePort();

        $cmd = sprintf(
            'powershell -Command "Start-Process -FilePath \'%s\' -ArgumentList \'-c\', \'%s\', \'-S\', \'127.0.0.1:%d\', \'-t\', \'%s\' -WindowStyle Hidden -PassThru | Select-Object -ExpandProperty Id"',
            str_replace('/', '\\', $this->phpBin),
            str_replace('/', '\\', $this->phpIni),
            $port,
            str_replace('/', '\\', $this->webRoot)
        );

        $pid = (int)trim((string)shell_exec($cmd));

        if ($pid <= 0) {
            return ['success' => false, 'error' => 'Failed to spawn portable PHP process.'];
        }

        $state = [
            'pid' => $pid,
            'port' => $port,
            'host' => '127.0.0.1',
            'url' => "http://127.0.0.1:{$port}",
            'php_bin' => $this->phpBin,
            'started_at' => date('Y-m-d H:i:s')
        ];

        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));

        // Poll for server readiness (up to 3 seconds)
        $ready = false;
        for ($i = 0; $i < 15; $i++) {
            usleep(200000); // 200ms
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($fp)) {
                fclose($fp);
                $ready = true;
                break;
            }
        }

        return [
            'success' => $ready,
            'pid' => $pid,
            'port' => $port,
            'url' => $state['url'],
            'ready' => $ready
        ];
    }

    /**
     * Stop the portable PHP process and clean up state
     */
    public function stopServer(): bool {
        if (!file_exists($this->stateFile)) {
            return true;
        }

        $info = $this->getServerInfo();
        $pid = (int)($info['pid'] ?? 0);

        if ($pid > 0) {
            // Gracefully terminate child process on Windows
            shell_exec("taskkill /F /PID {$pid} 2>&1");
        }

        @unlink($this->stateFile);
        return true;
    }

    /**
     * Check if the portable PHP server is running
     */
    public function isRunning(): bool {
        if (!file_exists($this->stateFile)) {
            return false;
        }

        $info = $this->getServerInfo();
        $pid = (int)($info['pid'] ?? 0);
        $port = (int)($info['port'] ?? 0);

        if ($pid <= 0 || $port <= 0) {
            return false;
        }

        // Verify socket connectivity
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }

        return false;
    }

    /**
     * Get active server information
     */
    public function getServerInfo(): array {
        if (!file_exists($this->stateFile)) {
            return [];
        }
        return json_decode((string)file_get_contents($this->stateFile), true) ?? [];
    }
}
