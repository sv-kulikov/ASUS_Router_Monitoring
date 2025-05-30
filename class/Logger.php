<?php

namespace Sv\Network\VmsRtbw;

use RuntimeException;
use Throwable;

/**
 * Class Logger provides methods to log data to files.
 * It also handles exceptions and logs them to a file.
 */
class Logger
{
    /**
     * @var array Configuration data for logging settings.
     */
    private array $config = [];

    /**
     * @var string Full path to the log directory.
     * This is set during the constructor based on the configuration.
     */
    private string $logFullPath = '';

    /**
     * @var string Last exception date and time as a formatted string.
     * This is updated whenever an exception is logged.
     */
    private string $lastExceptionDateTimeAsString = '';

    /**
     * Logger constructor.
     * @param Config $config Configuration object for accessing settings.
     */
    public function __construct(Config $config)
    {
        $this->config = $config->getConfigData();
        if ($this->config['settings']['logData'] === 'Y') {
            $this->logFullPath = realpath(__DIR__ . '/../' . $this->config['settings']['logPath']);
            if (!($this->logFullPath && is_dir($this->logFullPath) && is_writable($this->logFullPath))) {
                $this->config['settings']['logData'] = 'N';
            }
        }
    }

    /**
     * Checks logging settings and initializes the logging directory.
     */
    public function checkSettings(): void
    {
        if ($this->config['settings']['logData'] === 'Y') {
            $this->logFullPath = realpath(__DIR__ . '/../' . $this->config['settings']['logPath']);
            if ($this->logFullPath && is_dir($this->logFullPath) && is_writable($this->logFullPath)) {
                echo $this->config['settings']['demo']
                    ? "Logging enabled. In demo mode the log file name is hidden.\n"
                    : "Logging to: [$this->logFullPath]\n";
            } else {
                echo "Logging directory [$this->logFullPath] is invalid or not writable! Logging is disabled.\n";
                $this->config['settings']['logData'] = 'N';
            }
        } else {
            echo "Logging is disabled in the settings.\n";
        }
    }

    /**
     * Logs data related to providers and hardware statistics.
     *
     * @param array $providers Provider statistics.
     * @param array $hardwareStats Hardware-related statistics.
     */
    public function logData(array $providers, array $hardwareStats): void
    {
        $data = [
            'date' => date('Y.m.d'),
            'time' => date('H:i:s')
        ];

        foreach ($providers as $providerData) {
            $data["{$providerData['providerName']}_IP"] = $providerData['ip'];
            $data["{$providerData['providerName']}_currentRX"] = (int)end($providerData['speedRX']);
            $data["{$providerData['providerName']}_currentTX"] = (int)end($providerData['speedTX']);
            $data["{$providerData['providerName']}_totalRX"] = (int)$providerData['RXbytes'];
            $data["{$providerData['providerName']}_totalTX"] = (int)$providerData['TXbytes'];
        }

        $readyData = array_merge($data, $hardwareStats);
        $this->logToFile($readyData);
    }

    /**
     * Logs the processed data to a CSV file.
     *
     * @param array $data Data to be logged.
     */
    private function logToFile(array $data): void
    {
        $fileName = $this->logFullPath . DIRECTORY_SEPARATOR . 'log_' . date('Y_m_d') . '.csv';

        try {
            // Create file and write header if it doesn't exist
            if (!is_file($fileName)) {
                $file = fopen($fileName, 'w');
                if ($file === false) {
                    throw new RuntimeException("Failed to create log file: $fileName");
                }
                fputcsv($file, array_keys($data), ',', '"', '\\', PHP_EOL);
                fclose($file);
            }

            // Append data to the file
            $file = fopen($fileName, 'a');
            if ($file === false) {
                throw new RuntimeException("Failed to open log file for writing: $fileName");
            }
            fputcsv($file, array_values($data), ',', '"', '\\', PHP_EOL);
            fclose($file);
        } catch (RuntimeException $e) {
            $this->logException($e);
        }
    }


    /**
     * Default exception handler that logs detailed exception data to a daily file.
     *
     * @param Throwable $exception The exception.
     *
     * @return void
     */
    public function logException(Throwable $exception): void
    {
        $this->lastExceptionDateTimeAsString = date('Y-m-d H:i:s');

        $fileName = $this->logFullPath . DIRECTORY_SEPARATOR . 'exception_' . date('Y_m_d') . '.txt';

        $logData = [
            'Timestamp' => date('Y-m-d H:i:s'),
            'Exception Class' => get_class($exception),
            'Message' => $exception->getMessage(),
            'Code' => $exception->getCode(),
            'File' => $exception->getFile(),
            'Line' => $exception->getLine(),
            'Stack Trace' => $exception->getTraceAsString(),
            'Previous' => $exception->getPrevious() ? (string)$exception->getPrevious() : 'None'
        ];

        $logContent = "=== Exception ===\n";
        foreach ($logData as $key => $value) {
            $logContent .= "$key:\n$value\n\n";
        }

        file_put_contents($fileName, $logContent, FILE_APPEND);
    }

    /**
     * Returns the last exception date and time as a formatted string.
     *
     * @return string The last exception date and time.
     */
    public function getLastExceptionDateTimeAsString(): string
    {
        return $this->lastExceptionDateTimeAsString;
    }


    /** Generates a formatted HTML string for logging network data to Telegram.
     *
     * @param array $providers Array of provider data.
     * @param array $hardware Array of hardware data.
     * @return string Formatted HTML string with network data.
     */
    public function getPrettyTelegramLogData(array $providers, array $hardware): string
    {

        $html = "<pre>Network: " . date("Y.m.d H:i:s") . "\n\n";

        // Providers table header
        $html .= "Provider   Download   Upload     IP\n";

        $providerInDemoModeNumber = 1;
        foreach ($providers as $providerData) {
            $name = $providerData['providerName'];

            if ($name === 'TOTAL') continue;

            $rx = $this->formatBytes((int)$providerData['RXbytes']);
            $tx = $this->formatBytes((int)$providerData['TXbytes']);
            $ip = $providerData['ip'] ?? '-';

            if ($this->config['settings']['demo'] || $this->config['settings']['demo'] === 'Y') {
                // In demo mode, hide provider names and IPs
                $name = "Provider{$providerInDemoModeNumber}";
                $providerInDemoModeNumber++;
                $ip = '***.***.***.***'; // Mask IP address
            }

            $line = str_pad($name, 11);
            $line .= str_pad($rx, 11);
            $line .= str_pad($tx, 11);
            $line .= str_pad($ip, 15);
            $html .= $line . "\n";
        }

        $rxTotal = $this->formatBytes((int)$providers['TOTAL']['RXbytes']);
        $txTotal = $this->formatBytes((int)$providers['TOTAL']['TXbytes']);
        $html .= str_pad('TOTAL', 11) . str_pad($rxTotal, 11) . str_pad($txTotal, 11) . "\n\n";

        $combinedClientsData = [];

        // Hardware table header
        $html .= "Device     Cl CPU/C RAM/MB RAM/% Uptime\n";
        foreach ($hardware as $device) {
            if (!is_array($device) || !isset($device['deviceName'])) continue;

            $name = $device['deviceName'];
            $clients = $device['clientsCount'] ?? '?';
            $cpuTemp = $device['cpuTempString'] ?? '?';
            $ramMB = $device['memoryUsed'] ?? '?';
            $ramPerc = number_format((float)($device['memoryUsedPerc'] ?? 0), 1);

            $d = str_pad($device['uptimeD'] ?? '00', 2, '0', STR_PAD_LEFT);
            $h = str_pad($device['uptimeH'] ?? '00', 2, '0', STR_PAD_LEFT);
            $i = str_pad($device['uptimeI'] ?? '00', 2, '0', STR_PAD_LEFT);
            $s = str_pad($device['uptimeS'] ?? '00', 2, '0', STR_PAD_LEFT);
            $uptime = "$d.$h:$i:$s";

            $line = str_pad($name, 11);
            $line .= str_pad($clients, 3);
            $line .= str_pad($cpuTemp, 6);
            $line .= str_pad($ramMB, 7);
            $line .= str_pad($ramPerc . '%', 6);
            $line .= $uptime;
            $html .= $line . "\n";

            foreach ($device['clientsList'] as $client) {
                if ($client['isOnline']) {
                    $combinedClientsData[$client['MAC']] = $client;
                }
            }

        }

        uasort($combinedClientsData, function ($a, $b) {
            return ip2long($a['IP']) <=> ip2long($b['IP']);
        });

        $html .= "\n";
        $html .= "IP              Connection Name\n";
        foreach ($combinedClientsData as $client) {
            $ip = str_pad($client['IP'], 16);
            $type = str_pad($client['Connection'], 11);
            $name = str_pad($client['Name'], 20);
            if ($client['NickName'] != '') {
                $name = str_pad($client['NickName'], 20);
            }

            if (strlen($name) > 20) {
                $words = explode(' ', $name);
                $shortened = '';
                foreach ($words as $word) {
                    // +1 accounts for space if not the first word
                    $nextLength = strlen($shortened) + strlen($word) + ($shortened === '' ? 0 : 1);
                    if ($nextLength > 20) {
                        break;
                    }
                    $shortened .= ($shortened === '' ? '' : ' ') . $word;
                }
                $name = $shortened;
            }

            if ($this->config['settings']['demo']) {
                $ip = str_pad("***.***.***.***", 16);
                $name = str_pad("HiddenInDemoMode", 16);
            }

            $html .= $ip . $type . $name . "\n";
        }

        $html .= "</pre>";
        return $html;

    }


    /**
     * Formats bytes into a human-readable string with appropriate units.
     *
     * @param int|float $bytes The number of bytes to format.
     * @param int $precision The number of decimal places to include in the formatted output.
     * @return string Formatted string with the size and unit.
     */
    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        $tmpResult = round($bytes, $precision);

        while (strrpos($tmpResult, '.') >= strlen($tmpResult) - $precision) {
            $tmpResult .= '0';
        }

        return $tmpResult . ' ' . $units[$pow];
    }

}