<?php

namespace Sv\Network\VmsRtbw;

use DateTime;
use Exception;
use phpseclib3\Net\SSH2;

/**
 * Class Router provides methods to manage the router and repeater.
 */
class Router
{
    private SSH2 $sshClientRouter;
    private SSH2 $sshClientRepeater;
    private Hooks $hooksRouter;
    private Hooks $hooksRepeater;
    private array $adaptersData = array();
    private array $providersData = array();
    private array $hardwareData = array();
    private array $config;
    private Config $configObject;
    private int $stepsToShow;
    private float $lastRefreshTime = 0;
    private float $currentRefreshTime = 0;
    private int $totalRouterTraffic;
    private int $totalRepeaterTraffic;
    private DateTime $routerStartDateTime;

    public function init(Connection $connectionToRouter, Connection $connectionToRepeater, Config $config, int $stepsToShow): void
    {
        $this->configObject = $config;
        $this->config = $config->getConfigData();
        $this->stepsToShow = $stepsToShow;

        $attemptsLeft = 20;
        while ($attemptsLeft > 0) {
            try {
                $this->sshClientRouter = $connectionToRouter->getConnection($this->config['router']['ip'], $this->config['router']['login'], $this->config['router']['password'], $this->config['router']['port']);
                if ($this->config['settings']['demo']) {
                    $this->config['router']['deviceName'] = 'Router';
                } else {
                    $this->config['router']['deviceName'] = str_replace(["\n", "\r"], "", $this->sshClientRouter->exec('nvram get wps_device_name'));
                }
                echo "Connected to router: " . $this->config['router']['deviceName'] . "\n";
                if ($this->config['settings']['showDetailedDevicesData'] == 'Y') {
                    $this->hooksRouter = new Hooks($this->config['router']['ip'], $this->config['router']['login'], $this->config['router']['password']);
                }

                // We need router uptime here to calculate "statistics for the last..."
                // Yes, it is not really the same as the "ISP uptime", but it is close enough.
                $sshResponseRouterStartDateTime = $this->sshClientRouter->exec('cat /proc/uptime');
                $this->routerStartDateTime = new DateTime();
                $this->routerStartDateTime->setTimestamp((int)microtime(true) - (int)explode(' ', $sshResponseRouterStartDateTime)[0]);

                if ($this->config['repeater']['ip'] != '') {
                    $this->sshClientRepeater = $connectionToRepeater->getConnection($this->config['repeater']['ip'], $this->config['repeater']['login'], $this->config['repeater']['password'], $this->config['repeater']['port']);
                    if ($this->config['settings']['demo']) {
                        $this->config['repeater']['deviceName'] = 'Repeater';
                    } else {
                        $this->config['repeater']['deviceName'] = str_replace(["\n", "\r"], "", $this->sshClientRepeater->exec('nvram get wps_device_name'));
                    }
                    echo "Connected to repeater: " . $this->config['repeater']['deviceName'] . "\n";
                    if ($this->config['settings']['showDetailedDevicesData'] == 'Y') {
                        $this->hooksRepeater = new Hooks($this->config['repeater']['ip'], $this->config['repeater']['login'], $this->config['repeater']['password']);
                    }
                    if ($this->config['repeater']['addDefaultRouteOnUtilityStart'] !== '') {
                        echo "Checking, if default route is set in repeater... ";
                        $sshResponse = $this->sshClientRepeater->exec('ip route | grep default');
                        if (str_contains($sshResponse, 'default')) {
                            echo "It is set. No actions required.\n";
                        } else {
                            echo "It is not set. Adding [" . $this->config['repeater']['addDefaultRouteOnUtilityStart'] . "]... ";
                            $this->sshClientRepeater->exec($this->config['repeater']['addDefaultRouteOnUtilityStart']);
                            echo "Re-checking... ";
                            $sshResponse = $this->sshClientRepeater->exec('ip route | grep default');
                            if (str_contains($sshResponse, 'default')) {
                                echo "DONE!\n";
                            } else {
                                echo "FAILED!\n";
                            }
                        }
                    }
                }
                break;
            } catch (Exception $e) {
                echo "Something is wrong with the connection to either router or repeater. Waiting for 5 seconds to try again. Attempts left = " . ($attemptsLeft--) . ".\n";
                sleep(5);
                $this->configObject->updateParameter('globalStartDateTime', new DateTime());
            }
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

        $routerTotalTraffic = 0;

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
                    $routerTotalTraffic += (int)$rxRegexArray[1][0];
                }

                preg_match_all("/TX bytes:(\d+) /", $lineTrimmed, $txRegexArray);
                if (isset($txRegexArray[1][0])) {
                    $routerAdaptersList[$currentAdapter]['tx'] = $txRegexArray[1][0];
                    $routerTotalTraffic += (int)$txRegexArray[1][0];
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
        $this->totalRouterTraffic = $routerTotalTraffic;

        $sshResponseRepeater = $this->sshClientRepeater->exec('ifconfig -a');
        $sshResponseLinesRepeater = explode("\n", $sshResponseRepeater);

        $repeaterTotalTraffic = 0;

        foreach ($sshResponseLinesRepeater as $line) {

            $lineTrimmed = trim(str_replace(array("\n", "\r"), '', $line));
            if (strlen($lineTrimmed) > 0) {

                preg_match_all("/RX bytes:(\d+) /", $lineTrimmed, $rxRegexArray);
                if (isset($rxRegexArray[1][0])) {
                    $repeaterTotalTraffic += (int)$rxRegexArray[1][0];
                }

                preg_match_all("/TX bytes:(\d+) /", $lineTrimmed, $txRegexArray);
                if (isset($txRegexArray[1][0])) {
                    $repeaterTotalTraffic += (int)$txRegexArray[1][0];
                }

            }
        }

        $this->totalRepeaterTraffic = $repeaterTotalTraffic;
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
                    $providerData['RXbytesAccumulated'] = $providerData['RXbytesAccumulated'] ?? 0;
                    $providerData['TXbytesAccumulated'] = $providerData['TXbytesAccumulated'] ?? 0;
                    $providerData['isOffline'] = false;
                    $providerData['ip'] = '';
                    $providerData['ipChanges'] = -1; // Initially, the IP is empty string. So, we need to set it to -1 to avoid false positive IP changes detection.
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
        $this->providersData['TOTAL']['RXbytesAccumulated'] = 0;
        $this->providersData['TOTAL']['TXbytesAccumulated'] = 0;
        $this->providersData['TOTAL']['RXbytes'] = 0;
        $this->providersData['TOTAL']['TXbytes'] = 0;
        $this->providersData['TOTAL']['isOffline'] = false;
        $this->providersData['TOTAL']['ip'] = '';
        $this->providersData['TOTAL']['ipChanges'] = 0;
        $this->providersData['TOTAL']['idleRXcount'] = 0;
        $this->providersData['TOTAL']['idleTXcount'] = 0;

        foreach ($this->providersData as $providerData) {
            if ($providerData['vpnAdapterName'] != 'TOTAL') {
                $this->providersData['TOTAL']['RXbytesLast'] += $providerData['RXbytesLast'];
                $this->providersData['TOTAL']['TXbytesLast'] += $providerData['TXbytesLast'];
                $this->providersData['TOTAL']['RXbytes'] += $providerData['RXbytes'];
                $this->providersData['TOTAL']['TXbytes'] += $providerData['TXbytes'];
                $this->providersData['TOTAL']['RXbytesAccumulated'] += $providerData['RXbytesAccumulated'];
                $this->providersData['TOTAL']['TXbytesAccumulated'] += $providerData['TXbytesAccumulated'];
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
                    $providerData['RXbytesAccumulated'] += ($providerData['RXbytes'] - $providerData['RXbytesLast']);
                    $providerData['TXbytesAccumulated'] += ($providerData['TXbytes'] - $providerData['TXbytesLast']);

                    if ($providerData['ip'] != $routerAdapterData['ip']) {
                        $providerData['ipChanges']++;
                    }

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
        $this->providersData['TOTAL']['RXbytesAccumulated'] = 0;
        $this->providersData['TOTAL']['TXbytesAccumulated'] = 0;

        foreach ($this->providersData as $providerData) {
            if ($providerData['vpnAdapterName'] != 'TOTAL') {
                $this->providersData['TOTAL']['RXbytes'] += $providerData['RXbytes'];
                $this->providersData['TOTAL']['TXbytes'] += $providerData['TXbytes'];
                $this->providersData['TOTAL']['RXbytesAccumulated'] += $providerData['RXbytesAccumulated'];
                $this->providersData['TOTAL']['TXbytesAccumulated'] += $providerData['TXbytesAccumulated'];
                $this->providersData['TOTAL']['RXbytesLast'] += $providerData['RXbytesLast'];
                $this->providersData['TOTAL']['TXbytesLast'] += $providerData['TXbytesLast'];
            }
        }
    }


    public function refreshStats(): void
    {
        $timeDelta = $this->currentRefreshTime - $this->lastRefreshTime;

        // 1st step of statistics preparation (inits)
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

        // 2nd step of statistics preparation (primary calculations)
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
            if ((($providerData['maxRX'] == 0) || ($providerData['maxTX'] == 0)) || (($providerData['ip'] == '') && ($providerData['providerName'] != 'TOTAL'))) {
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
        $hardwareDataArray['router']['hooks'] = $this->hooksRouter;
        $hardwareDataArray['router']['cpuCores'] = $this->config['router']['cpuCores'];
        $hardwareDataArray['router']['deviceName'] = $this->config['router']['deviceName'];
        $hardwareDataArray['router']['totalTraffic'] = $this->totalRouterTraffic;
        $hardwareDataArray['router']['routerStartDateTime'] = $this->routerStartDateTime;

        $hardwareDataArray['repeater']['ssh'] = $this->sshClientRepeater;
        $hardwareDataArray['repeater']['hooks'] = $this->hooksRepeater;
        $hardwareDataArray['repeater']['cpuCores'] = $this->config['repeater']['cpuCores'];
        $hardwareDataArray['repeater']['deviceName'] = $this->config['repeater']['deviceName'];
        $hardwareDataArray['repeater']['totalTraffic'] = $this->totalRepeaterTraffic;

        foreach ($hardwareDataArray as $hardwareName => $hardwareData) {

            if ($hardwareData['ssh'] == null) {
                continue;
            }

            // Get temperatures
            $minTemp = PHP_INT_MAX;
            $maxTemp = 0;
            for ($i = 0; $i <= 11; $i++) {
                $sshResponse = (float)$hardwareData['ssh']->exec('cat /sys/class/thermal/thermal_zone' . $i . '/temp');
                if ($sshResponse > 1000) {
                    $sshResponse = round($sshResponse / 1000, 1);
                }
                if ($sshResponse > $maxTemp) {
                    $maxTemp = $sshResponse;
                }
                if (($sshResponse > 0) && ($sshResponse < $minTemp)) {
                    $minTemp = $sshResponse;
                }
            }

            $hardwareData['cpuTempMax'] = (float)$maxTemp;
            $hardwareData['cpuTempMin'] = (float)$minTemp;

            if ($hardwareData['cpuTempMin'] == $hardwareData['cpuTempMax']) {
                $hardwareData['cpuTempString'] = $hardwareData['cpuTempMin'];
            } else {
                $hardwareData['cpuTempString'] = $hardwareData['cpuTempMin'] . '-' . $hardwareData['cpuTempMax'];
            }

            $sshResponse = $hardwareData['ssh']->exec('uptime; cat /proc/uptime');

            preg_match_all("/^[\d,.]+\s+/imsu", $sshResponse, $upTimeArray, PREG_SET_ORDER);
            if (isset($upTimeArray[0][0])) {
                $hardwareData['uptime'] = round((double)$upTimeArray[0][0]);

                // Calculating D.H:I:S uptime
                $days = floor($hardwareData['uptime'] / 86400);
                $seconds = $hardwareData['uptime'] % 86400;
                $hours = floor($seconds / 3600);
                $seconds = $seconds % 3600;
                $minutes = floor($seconds / 60);
                $seconds = $seconds % 60;

                $hardwareData['uptimeD'] = str_pad($days, 2, '0', STR_PAD_LEFT);
                $hardwareData['uptimeH'] = str_pad($hours, 2, '0', STR_PAD_LEFT);
                $hardwareData['uptimeI'] = str_pad($minutes, 2, '0', STR_PAD_LEFT);
                $hardwareData['uptimeS'] = str_pad($seconds, 2, '0', STR_PAD_LEFT);
                $hardwareData['uptimePretty'] = $hardwareData['uptimeD'] . '.' . $hardwareData['uptimeH'] . ':' . $hardwareData['uptimeI'];
                $hardwareData['uptimePrettyLong'] = $hardwareData['uptimeD'] . '.' . $hardwareData['uptimeH'] . ':' . $hardwareData['uptimeI'] . ':' . $hardwareData['uptimeS'];
            } else {
                $hardwareData['uptime'] = 'n/a';
                $hardwareData['uptimeD'] = '-';
                $hardwareData['uptimeH'] = '-';
                $hardwareData['uptimeI'] = '-';
                $hardwareData['uptimeS'] = '-';
                $hardwareData['uptimePretty'] = '-.-:-';
                $hardwareData['uptimePrettyLong'] = '- d -:-:-';
            }

            preg_match_all("/load average:\s+([\d,.]+),\s+([\d,.]+),\s+([\d,.]+)/", $sshResponse, $loadAverageArray, PREG_SET_ORDER);

            if (isset($loadAverageArray[0][0])) {
                $hardwareData['loadAverage'] = $loadAverageArray[0][0];
                $hardwareData['loadAverage1i'] = $loadAverageArray[0][1];
                $hardwareData['loadAverage5i'] = $loadAverageArray[0][2];
                $hardwareData['loadAverage15i'] = $loadAverageArray[0][3];
                $hardwareData['loadAverageNow'] = 100 * round((float)$hardwareData['loadAverage1i'] / (float)$hardwareData['cpuCores'], 2);
                $hardwareData['loadAverage1iPerc'] = 100 * round((float)$hardwareData['loadAverage1i'] / (float)$hardwareData['cpuCores'], 2);
                $hardwareData['loadAverage5iPerc'] = 100 * round((float)$hardwareData['loadAverage5i'] / (float)$hardwareData['cpuCores'], 2);
                $hardwareData['loadAverage15iPerc'] = 100 * round((float)$hardwareData['loadAverage15i'] / (float)$hardwareData['cpuCores'], 2);
            } else {
                $hardwareData['loadAverage'] = 'n/a';
                $hardwareData['loadAverage1i'] = '-';
                $hardwareData['loadAverage5i'] = '-';
                $hardwareData['loadAverage15i'] = '-';
                $hardwareData['loadAverageNow'] = '-';
                $hardwareData['loadAverage1iPerc'] = '-';
                $hardwareData['loadAverage5iPerc'] = '-';
                $hardwareData['loadAverage15iPerc'] = '-';
            }

            if ($this->config['settings']['showDetailedDevicesData'] == 'Y') {
                $hooksRawResult = $hardwareData['hooks']->execApiCommands(['get_wan_lan_status()', 'get_allclientlist()', 'cpu_usage(appobj)', 'memory_usage(appobj)']);
                $hooksCleanResults = array();
                foreach ($hooksRawResult as $oneResultCommand => $oneResultData) {
                    $hooksCleanResults[$oneResultCommand] = json_decode($oneResultData['response'], true);
                }
                $hardwareData['hooksResults'] = $hooksCleanResults;

                // Clients count
                $clientsList = $hardwareData['hooksResults']['get_allclientlist()']['get_allclientlist'] ?? [];
                $clientsList = reset($clientsList);
                $hardwareData['clientsCount'] = count($clientsList['wired_mac'] ?? []);

                // CPU Loads
                $hardwareData['cpuLoadRAW'] = $hardwareData['hooksResults']['cpu_usage(appobj)']['cpu_usage'] ?? '[]';
                $minLoad = PHP_FLOAT_MAX;
                $maxLoad = PHP_FLOAT_MIN;
                $cpuLoads = [];

                foreach ($hardwareData['cpuLoadRAW'] as $loadKey => $loadValue) {
                    if (str_contains($loadKey, '_usage')) {
                        $cpuIndex = str_replace('_usage', '', $loadKey);
                        $totalKey = $cpuIndex . '_total';

                        if (isset($hardwareData['cpuLoadRAW'][$totalKey]) && $hardwareData['cpuLoadRAW'][$totalKey] > 0) {
                            $load = ($loadValue / $hardwareData['cpuLoadRAW'][$totalKey]) * 100;
                            $cpuLoads[$cpuIndex] = $load;

                            // Update min and max
                            if ($load < $minLoad) $minLoad = $load;
                            if ($load > $maxLoad) $maxLoad = $load;
                        }
                    }
                }

                $hardwareData['cpuCoresCount'] = count($cpuLoads);
                $hardwareData['cpuLoadsPerc'] = $cpuLoads;
                $hardwareData['cpuLoadMinPerc'] = number_format(round($minLoad, 1), '1', '.', '');
                $hardwareData['cpuLoadMaxPerc'] = number_format(round($maxLoad, 1), '1', '.', '');
                if ($hardwareData['cpuCoresCount'] == 1) {
                    $hardwareData['cpuLoadString'] = $hardwareData['cpuLoadMinPerc'];
                } else {
                    $hardwareData['cpuLoadString'] = $hardwareData['cpuLoadMinPerc'] . '-' . $hardwareData['cpuLoadMaxPerc'];
                }
            }

            // Memory usage
            $hardwareData['memoryUsageRAW'] = $hardwareData['hooksResults']['memory_usage(appobj)']['memory_usage'] ?? '[]';
            $hardwareData['memoryTotal'] = (int)(($hardwareData['memoryUsageRAW']['mem_total'] ?? 0) / 1024);
            $hardwareData['memoryUsed'] = (int)(($hardwareData['memoryUsageRAW']['mem_used'] ?? 0) / 1024);
            $hardwareData['memoryUsedPerc'] = number_format(round($hardwareData['memoryUsed'] * 100 / max($hardwareData['memoryTotal'], 1), 1), 1, '.', '');
            $hardwareData['memoryUsageString'] = $hardwareData['memoryUsedPerc'] . '% ' . $hardwareData['memoryUsed'] . 'MB';

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