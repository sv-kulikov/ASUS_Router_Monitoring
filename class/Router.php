<?php

namespace Sv\Network\VmsRtbw;

use phpseclib3\Net\SSH2;

class Router
{
    private SSH2 $sshClientRouter;
    private SSH2 $sshClientRepeater;
    private array $adaptersData = array();
    private array $providersData = array();
    private array $hardwareData = array();
    private array $config;
    private int $stepsToShow;
    private float $lastRefreshTime = 0;
    private float $currentRefreshTime = 0;

    public function init(Connection $connectionToRouter, Connection $connectionToRepeater, array $config, int $stepsToShow): void
    {
        $this->config = $config;
        $this->stepsToShow = $stepsToShow;
        $this->sshClientRouter = $connectionToRouter->getConnection($config['router']['ip'], $config['router']['login'], $config['router']['password'], $config['router']['port']);
        if ($config['repeater']['ip'] != '') {
            $this->sshClientRepeater = $connectionToRepeater->getConnection($config['repeater']['ip'], $config['repeater']['login'], $config['repeater']['password'], $config['repeater']['port']);
        }
        $this->lastRefreshTime = microtime(true);
    }

    public function refreshAdaptersData(): void
    {
        $sshResponse = $this->sshClientRouter->exec('ifconfig -a');
        $sshResponseDDNS = trim($this->sshClientRouter->exec('nvram get ddns_ipaddr'));

        $this->lastRefreshTime = $this->currentRefreshTime;
        $this->currentRefreshTime = microtime(true);
        $sshResponseLines = explode("\n", $sshResponse);

        $currentAdapter = '';
        $routerAdaptersList = array();

        foreach ($sshResponseLines as $line) {

            preg_match_all("/^([\w-]+)\s/", $line, $adapterRegexArray);
            if (isset($adapterRegexArray[1][0])) {
                $currentAdapter = $adapterRegexArray[1][0];
            }

            $lineTrimmed = trim(str_replace(array("\n", "\r"), '', $line));
            if (strlen($lineTrimmed) > 0) {

                preg_match_all("/inet addr:(\d+\.\d+\.\d+\.\d+) /", $lineTrimmed, $ipRegexArray);
                if (isset($ipRegexArray[1][0])) {
                    $routerAdaptersList[$currentAdapter]['ip'] = $ipRegexArray[1][0];
                    if ($routerAdaptersList[$currentAdapter]['ip'] == $sshResponseDDNS) {
                        $routerAdaptersList[$currentAdapter]['ddns'] = true;
                    } else {
                        $routerAdaptersList[$currentAdapter]['ddns'] = false;
                    }
                }

                preg_match_all("/RX bytes:(\d+) /", $lineTrimmed, $rxRegexArray);
                if (isset($rxRegexArray[1][0])) {
                    $routerAdaptersList[$currentAdapter]['rx'] = $rxRegexArray[1][0];
                }

                preg_match_all("/TX bytes:(\d+) /", $lineTrimmed, $txRegexArray);
                if (isset($txRegexArray[1][0])) {
                    $routerAdaptersList[$currentAdapter]['tx'] = $txRegexArray[1][0];
                }

                preg_match_all("/RX packets:(\d+).*dropped:(\d+) /", $lineTrimmed, $rxPacketsRegexArray);
                if (isset($rxPacketsRegexArray[1][0])) {
                    $routerAdaptersList[$currentAdapter]['rxPackets'] = $rxPacketsRegexArray[1][0];
                    $routerAdaptersList[$currentAdapter]['rxPacketsDropped'] = $rxPacketsRegexArray[2][0];
                }

                preg_match_all("/TX packets:(\d+).*dropped:(\d+) /", $lineTrimmed, $txPacketsRegexArray);
                if (isset($txPacketsRegexArray[1][0])) {
                    $routerAdaptersList[$currentAdapter]['txPackets'] = $txPacketsRegexArray[1][0];
                    $routerAdaptersList[$currentAdapter]['txPacketsDropped'] = $txPacketsRegexArray[2][0];
                }
            }
        }

        foreach ($routerAdaptersList as $adapterName => $adapterData) {
            if (!$this->isAdapterInConfig($adapterName, $this->config['providers'])) {
                unset($routerAdaptersList[$adapterName]);
            }
        }

        $this->adaptersData = $routerAdaptersList;
    }

    public function initProvidersData(): void
    {
        $this->currentRefreshTime = microtime(true);

        foreach ($this->adaptersData as $routerAdapterName => $routerAdapterData) {

            foreach ($this->config['providers']['provider'] as $providerKey => $providerData) {

                if ($providerData['vpnAdapterName'] == $routerAdapterName) {
                    $providerData['RXbytes'] = $routerAdapterData['rx'];
                    $providerData['TXbytes'] = $routerAdapterData['tx'];
                    $providerData['RXbytesLast'] = $providerData['RXbytes'] ?? 0;
                    $providerData['TXbytesLast'] = $providerData['TXbytes'] ?? 0;
                    $providerData['RXbytesOnStart'] = $providerData['RXbytes'] ?? 0;
                    $providerData['TXbytesOnStart'] = $providerData['TXbytes'] ?? 0;
                    $providerData['isOffline'] = false;
                    $providerData['ip'] = '';
                    $providerData['idleRXcount'] = 0;
                    $providerData['idleTXcount'] = 0;
                    $this->providersData[$providerKey] = $providerData;
                }
            }

        }

        $this->providersData['TOTAL']['providerName'] = 'TOTAL';
        $this->providersData['TOTAL']['vpnAdapterName'] = 'TOTAL';
        $this->providersData['TOTAL']['RXbytesLast'] = 0;
        $this->providersData['TOTAL']['TXbytesLast'] = 0;
        $this->providersData['TOTAL']['RXbytes'] = 0;
        $this->providersData['TOTAL']['TXbytes'] = 0;
        $this->providersData['TOTAL']['isOffline'] = false;
        $this->providersData['TOTAL']['ip'] = '';
        $this->providersData['TOTAL']['idleRXcount'] = 0;
        $this->providersData['TOTAL']['idleTXcount'] = 0;

        foreach ($this->providersData as $providerData) {
            if ($providerData['vpnAdapterName'] != 'TOTAL') {
                $this->providersData['TOTAL']['RXbytesLast'] += $providerData['RXbytesLast'];
                $this->providersData['TOTAL']['TXbytesLast'] += $providerData['TXbytesLast'];
                $this->providersData['TOTAL']['RXbytes'] += $providerData['RXbytes'];
                $this->providersData['TOTAL']['TXbytes'] += $providerData['TXbytes'];
            }
        }

        $this->providersData['TOTAL']['RXbytesOnStart'] = $this->providersData['TOTAL']['RXbytes'];
        $this->providersData['TOTAL']['TXbytesOnStart'] = $this->providersData['TOTAL']['TXbytes'];
    }


    public function refreshProvidersData(): void
    {
        foreach ($this->adaptersData as $routerAdapterName => $routerAdapterData) {

            foreach ($this->providersData as $providerKey => $providerData) {

                if ($providerData['vpnAdapterName'] == $routerAdapterName) {
                    $providerData['RXbytesLast'] = $providerData['RXbytes'] ?? 0;
                    $providerData['TXbytesLast'] = $providerData['TXbytes'] ?? 0;
                    $providerData['RXbytes'] = $routerAdapterData['rx'];
                    $providerData['TXbytes'] = $routerAdapterData['tx'];
                    $providerData['ip'] = $routerAdapterData['ip'];
                    $providerData['ddns'] = $routerAdapterData['ddns'];
                    $this->providersData[$providerKey] = $providerData;
                }
            }

        }

        $this->providersData['TOTAL']['RXbytesLast'] = 0;
        $this->providersData['TOTAL']['TXbytesLast'] = 0;
        $this->providersData['TOTAL']['RXbytes'] = 0;
        $this->providersData['TOTAL']['TXbytes'] = 0;

        foreach ($this->providersData as $providerData) {
            if ($providerData['vpnAdapterName'] != 'TOTAL') {
                $this->providersData['TOTAL']['RXbytes'] += $providerData['RXbytes'];
                $this->providersData['TOTAL']['TXbytes'] += $providerData['TXbytes'];
                $this->providersData['TOTAL']['RXbytesLast'] += $providerData['RXbytesLast'];
                $this->providersData['TOTAL']['TXbytesLast'] += $providerData['TXbytesLast'];
            }
        }
    }


    public function refreshStats(): void
    {
        $timeDelta = $this->currentRefreshTime - $this->lastRefreshTime;

        // 1st step of stats preparation (inits)
        foreach ($this->providersData as $providerName => $providerData) {
            $speedRX = ($providerData['RXbytes'] - $providerData['RXbytesLast']) / $timeDelta;
            $speedTX = ($providerData['TXbytes'] - $providerData['TXbytesLast']) / $timeDelta;
            $this->providersData[$providerName]['speedRX'][] = $speedRX;
            $this->providersData[$providerName]['speedTX'][] = $speedTX;

            if ($speedRX == 0) {
                $this->providersData[$providerName]['idleRXcount']++;
            }

            if ($speedTX == 0) {
                $this->providersData[$providerName]['idleTXcount']++;
            }

            $this->providersData[$providerName]['maxRXlast'] = $this->providersData[$providerName]['maxRX'] ?? 0;
            $this->providersData[$providerName]['minRXlast'] = $this->providersData[$providerName]['minRX'] ?? 0;
            $this->providersData[$providerName]['maxTXlast'] = $this->providersData[$providerName]['maxTX'] ?? 0;
            $this->providersData[$providerName]['minTXlast'] = $this->providersData[$providerName]['minTX'] ?? 0;
            $this->providersData[$providerName]['avgRXlast'] = $this->providersData[$providerName]['avgRX'] ?? 0;
            $this->providersData[$providerName]['avgTXlast'] = $this->providersData[$providerName]['avgTX'] ?? 0;

        }

        // 2nd step of stats preparation (primary calculations)
        foreach ($this->providersData as $providerName => $providerData) {
            for ($i = 0; $i < count($this->providersData[$providerName]['speedRX']) - $this->stepsToShow; $i++) {
                array_shift($this->providersData[$providerName]['speedRX']);
            }
            for ($i = 0; $i < count($this->providersData[$providerName]['speedTX']) - $this->stepsToShow; $i++) {
                array_shift($this->providersData[$providerName]['speedTX']);
            }

            $this->providersData[$providerName]['sumRX'] = 0;
            $this->providersData[$providerName]['sumTX'] = 0;
            $this->providersData[$providerName]['maxRX'] = 0;
            $this->providersData[$providerName]['maxTX'] = 0;

            for ($i = 0; $i < count($this->providersData[$providerName]['speedRX']); $i++) {
                $this->providersData[$providerName]['sumRX'] += $this->providersData[$providerName]['speedRX'][$i];
            }

            for ($i = 0; $i < count($this->providersData[$providerName]['speedTX']); $i++) {
                $this->providersData[$providerName]['sumTX'] += $this->providersData[$providerName]['speedTX'][$i];
            }

            $this->providersData[$providerName]['maxRX'] = max($this->providersData[$providerName]['speedRX']);
            $this->providersData[$providerName]['maxTX'] = max($this->providersData[$providerName]['speedTX']);

            $this->providersData[$providerName]['minRX'] = min($this->providersData[$providerName]['speedRX']);
            $this->providersData[$providerName]['minTX'] = min($this->providersData[$providerName]['speedTX']);

            $this->providersData[$providerName]['avgRX'] = $this->providersData[$providerName]['sumRX'] / count($this->providersData[$providerName]['speedRX']);
            $this->providersData[$providerName]['avgTX'] = $this->providersData[$providerName]['sumTX'] / count($this->providersData[$providerName]['speedTX']);
        }

        // 3rd step of stats preparation (globals calculations)
        foreach ($this->providersData as $providerName => $providerData) {
            $this->providersData[$providerName]['globalMaxRXLast'] = $providers[$providerName]['globalMaxRX'] ?? 0;
            $this->providersData[$providerName]['globalMaxTXLast'] = $providers[$providerName]['globalMaxTX'] ?? 0;

            $this->providersData[$providerName]['globalMinRXLast'] = $this->providersData[$providerName]['globalMinRX'] ?? 0;
            $this->providersData[$providerName]['globalMinTXLast'] = $this->providersData[$providerName]['globalMinTX'] ?? 0;

            $this->providersData[$providerName]['globalAvgRXLast'] = $this->providersData[$providerName]['globalAvgRX'] ?? 0;
            $this->providersData[$providerName]['globalAvgTXLast'] = $this->providersData[$providerName]['globalAvgTX'] ?? 0;

            $this->providersData[$providerName]['globalMaxRX'] = max($this->providersData[$providerName]['globalMaxRX'] ?? 0, $this->providersData[$providerName]['maxRX']);
            $this->providersData[$providerName]['globalMaxTX'] = max($this->providersData[$providerName]['globalMaxTX'] ?? 0, $this->providersData[$providerName]['maxTX']);

            $this->providersData[$providerName]['globalMinRX'] = min($this->providersData[$providerName]['globalMinRX'] ?? PHP_INT_MAX, $this->providersData[$providerName]['minRX']);
            $this->providersData[$providerName]['globalMinTX'] = min($this->providersData[$providerName]['globalMinTX'] ?? PHP_INT_MAX, $this->providersData[$providerName]['minTX']);

            $this->providersData[$providerName]['globalAvgRX'] = ($this->providersData[$providerName]['globalMinRX'] + $this->providersData[$providerName]['globalMaxRX']) / 2;
            $this->providersData[$providerName]['globalAvgTX'] = ($this->providersData[$providerName]['globalMinTX'] + $this->providersData[$providerName]['globalMaxTX']) / 2;
        }

        // Detecting offline ISP
        foreach ($this->providersData as $providerName => $providerData) {
            if ($providerData['maxRX'] == 0 && $providerData['maxTX'] == 0 && $providerData['minRX'] == 0 && $providerData['minTX'] == 0) {
                $this->providersData[$providerName]['isOffline'] = true;
            } else {
                $this->providersData[$providerName]['isOffline'] = false;
            }
        }

        $globalMaxSpeed = 0;

        foreach ($this->providersData as $providerData) {
            $globalMaxSpeed = max($globalMaxSpeed, $providerData['maxRX'], $providerData['maxTX']);
        }

        $this->providersData['TOTAL']['globalMaxSpeed'] = $globalMaxSpeed;
    }


    private function isAdapterInConfig(string $adapterName, array $configProvidersList): bool
    {
        foreach ($configProvidersList['provider'] as $provider) {
            if ($provider['vpnAdapterName'] == $adapterName) {
                return true;
            }
        }
        return false;
    }


    public function refreshHardwareData(): void
    {

        $hardwareDataArray['router']['ssh'] = $this->sshClientRouter;
        $hardwareDataArray['repeater']['ssh'] = $this->sshClientRepeater;

        foreach ($hardwareDataArray as $hardwareName => $hardwareData) {

            if ($hardwareData['ssh'] == null) {
                continue;
            }

            // Get temperatures
            $minTemp = PHP_INT_MAX;
            $maxTemp = 0;
            for ($i = 0; $i <= 11; $i++) {
                $sshResponse = (float)$hardwareData['ssh']->exec('cat /sys/class/thermal/thermal_zone' . $i . '/temp');
                if ($sshResponse > $maxTemp) {
                    $maxTemp = $sshResponse;
                }
                if (($sshResponse > 0) && ($sshResponse < $minTemp)) {
                    $minTemp = $sshResponse;
                }
            }

            $hardwareData['cpu_temp_max'] = (float)$maxTemp;
            $hardwareData['cpu_temp_min'] = (float)$minTemp;

            if ($hardwareData['cpu_temp_min'] == $hardwareData['cpu_temp_max']) {
                $hardwareData['cpu_temp'] = $hardwareData['cpu_temp_min'];
            } else {
                $hardwareData['cpu_temp'] = $hardwareData['cpu_temp_min'] . '-' . $hardwareData['cpu_temp_max'];
            }

            $sshResponse = $hardwareData['ssh']->exec('uptime');

            preg_match_all("/ up (.*),  /", $sshResponse, $upTimeArray);
            if (isset($upTimeArray[1][0])) {
                $hardwareData['uptime'] = $upTimeArray[1][0];
            } else {
                $hardwareData['uptime'] = 'n/a';
            }

            preg_match_all("/load average: (.*)/", $sshResponse, $loadAverageArray);
            if (isset($loadAverageArray[1][0])) {
                $hardwareData['loadAverage'] = $loadAverageArray[1][0];
            } else {
                $hardwareData['loadAverage'] = 'n/a';
            }

            $hardwareDataArray[$hardwareName] = $hardwareData;
        }
        $this->hardwareData = $hardwareDataArray;
    }

    public function getProvidersData(): array
    {
        return $this->providersData;
    }

    public function getHardwareData(): array
    {
        return $this->hardwareData;
    }

}