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
    private array $config;
    private string $logFullPath;
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
                fputcsv($file, array_keys($data));
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

}