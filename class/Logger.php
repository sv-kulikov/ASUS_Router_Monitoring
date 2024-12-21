<?php

namespace Sv\Network\VmsRtbw;

/**
 * Class Logger provides methods to log data to files.
 */
class Logger
{
    private array $config;
    private string $logFullPath;

    /**
     * Logger constructor.
     * @param Config $config Configuration object for accessing settings.
     */
    public function __construct(Config $config)
    {
        $this->config = $config->getConfigData();
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
                    : "Logging to: [{$this->logFullPath}]\n";
            } else {
                echo "Logging directory [{$this->logFullPath}] is invalid or not writable! Logging is disabled.\n";
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
                    throw new \RuntimeException("Failed to create log file: {$fileName}");
                }
                fputcsv($file, array_keys($data));
                fclose($file);
            }

            // Append data to the file
            $file = fopen($fileName, 'a');
            if ($file === false) {
                throw new \RuntimeException("Failed to open log file for writing: {$fileName}");
            }
            fputcsv($file, array_values($data));
            fclose($file);
        } catch (\RuntimeException $e) {
            echo "Logging error: " . $e->getMessage() . "\n";
        }
    }
}