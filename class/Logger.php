<?php

namespace Sv\Network\VmsRtbw;

/**
 * Class Logger provides methods to log data to the file(s).
 */
class Logger
{
    private array $config;
    private string $logFullPath;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function checkSettings(): void
    {
        if ($this->config['settings']['logData'] == 'Y') {
            $this->logFullPath = realpath(__DIR__ . '/../' . $this->config['settings']['logPath']);
            if (is_dir($this->logFullPath)) {
                if ($this->config['settings']['demo']) {
                    echo "Logging enabled.\n";
                } else {
                    echo "Logging to: [" . $this->logFullPath . "]\n";
                }
            } else {
                echo "Logging directory [" . $this->logFullPath . "] not found! Logging is disabled.\n";
                $this->config['settings']['logData'] = 'N';
            }
        } else {
            echo "Logging is disabled in the settings.\n";
        }
    }

    public function logData(array $providers, array $hardwareStats): void
    {
        $data['date'] = date('Y.m.d');
        $data['time'] = date('H:i.s');

        foreach ($providers as $providerData) {
            $data[$providerData['providerName'] . '_currentRX'] = (int)end($providerData['speedRX']);
            $data[$providerData['providerName'] . '_currentTX'] = (int)end($providerData['speedTX']);
        }

        $readyData = array_merge($data, $hardwareStats);
        $this->logToFile($readyData);
    }

    private function logToFile(array $data): void
    {
        $fileName = $this->logFullPath . DIRECTORY_SEPARATOR . 'log_' . date('Y_m_d') . '.csv';
        if (!is_file($fileName)) {
            $file = fopen($fileName, 'w');
            fputcsv($file, array_keys($data));
            fclose($file);
        }

        $file = fopen($fileName, 'a');
        fputcsv($file, array_values($data));
        fclose($file);
    }
}