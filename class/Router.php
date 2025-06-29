<?php

namespace Sv\Network\VmsRtbw;

use DateTime;
use Exception;
use phpseclib3\Net\SSH2;

/**
 * Class Router provides methods to manage and retrieve data from a network router and repeater.
 *
 * This class handles SSH connections to the router and repeater, retrieves network statistics,
 * manages provider data, and integrates with Telegram for notifications.
 */
class Router
{
    /**
     * @var SSH2 SSH client for the router connection.
     */
    private SSH2 $sshClientRouter;

    /**
     * @var SSH2 SSH client for the repeater connection.
     */
    private SSH2 $sshClientRepeater;

    /**
     * @var Hooks Hooks object for the router to retrieve detailed data.
     */
    private Hooks $hooksRouter;

    /**
     * @var Hooks Hooks object for the repeater to retrieve detailed data.
     */
    private Hooks $hooksRepeater;

    /**
     * @var array Adapters data containing network interface statistics.
     *            Structure: [adapterName =>
     *                         [ip, rx, tx, rxPackets, txPackets,
     *                         rxPacketsDropped, txPacketsDropped, ddns]
     *                       ]
     */
    private array $adaptersData = array();

    /**
     * @var array Providers data containing traffic statistics for each provider.
     *            Structure: [providerKey =>
     *                         [providerName, vpnAdapterName, RXbytes, TXbytes,
     *                         RXbytesLast, TXbytesLast, RXbytesOnStart,
     *                         TXbytesOnStart, RXbytesAccumulated, TXbytesAccumulated,
     *                         isOffline, ip, ipChanges, idleRXcount, idleTXcount]
     *                       ]
     */
    private array $providersData = array();

    /**
     * @var array Hardware data containing detailed information about the router and repeater.
     *            Structure: [hardwareKey => [key => value, ...]]
     */
    private array $hardwareData = array();

    /**
     * @var array Configuration data containing application settings and parameters.
     *            Structure: [configKey => value]
     */
    private array $config;

    /**
     * @var Config Configuration object containing application settings.
     */
    private Config $configObject;

    /**
     * @var int Number of steps to show in statistics.
     *          This is used to limit the number of historical data points shown in the UI.
     */
    private int $stepsToShow;

    /**
     * @var float Last refresh time in seconds since the Unix epoch.
     *            This is used to calculate the time difference between data refreshes.
     */
    private float $lastRefreshTime = 0;

    /**
     * @var float Current refresh time in seconds since the Unix epoch.
     *            This is updated each time the data is refreshed.
     */
    private float $currentRefreshTime = 0;

    /**
     * @var int Total traffic in bytes for the router.
     *          This is the sum of RX and TX bytes for all adapters on the router.
     */
    private int $totalRouterTraffic;

    /**
     * @var int Total traffic in bytes for the repeater.
     *          This is the sum of RX and TX bytes for all adapters on the repeater.
     */
    private int $totalRepeaterTraffic;

    /**
     * @var DateTime Start date and time of the router.
     *               This is used to calculate the uptime of the router.
     */
    private DateTime $routerStartDateTime;

    /**
     * @var Telegram Telegram integration object for sending messages and notifications.
     *               This is used to send updates about provider IP changes and other events.
     */
    private Telegram $telegram;

    /**
     * @var array Clients data containing information about connected clients.
     *            Structure: [clientMac => [key => value, ...]]
     *            This is used to store detailed information about each connected client.
     */
    private array $clientsData = [];

    /**
     * @var array Combined clients data containing merged information from both router and repeater.
     *            Structure: [clientMac => [key => value, ...]]
     *            This is used to combine client data from both devices into a single array.
     */
    private array $combinedClientsData = [];

    /**
     * @var Logger Logger instance for logging events and messages.
     *            This is used to log important events, errors, and debug information.
     */
    private Logger $logger;

    private array $reliableOnlineStatuses = [];

    /**
     * Router constructor.
     *
     * Initializes the Router object with a Logger instance.
     *
     * @param Logger $logger Logger instance for logging events and messages.
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }


    /**
     * Initializes the Router object with connections to the router and repeater,
     * and sets up the configuration and Telegram integration.
     *
     * @param Connection $connectionToRouter Connection to the router.
     * @param Connection $connectionToRepeater Connection to the repeater.
     * @param Config $config Configuration object containing application settings.
     * @param int $stepsToShow Number of steps to show in statistics.
     * @param Telegram $telegram Telegram integration object.
     */
    public function init(Connection $connectionToRouter, Connection $connectionToRepeater, Config $config, int $stepsToShow, Telegram $telegram): void
    {
        $this->configObject = $config;
        $this->config = $config->getConfigData();
        $this->stepsToShow = $stepsToShow;
        $this->telegram = $telegram;

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
            } catch (Exception) {
                echo "Something is wrong with the connection to either router or repeater. Waiting for 5 seconds to try again. Attempts left = " . ($attemptsLeft--) . ".\n";
                sleep(5);
                $this->configObject->updateParameter('globalStartDateTime', new DateTime());
            }
        }
        $this->lastRefreshTime = microtime(true);
    }

    /**
     * Refreshes network adapter statistics by retrieving and parsing interface data via SSH
     * from both the main router and a repeater device.
     *
     * This method:
     * - Runs `ifconfig -a` on the router and repeater to collect adapter data.
     * - Parses each adapter's IP address, traffic stats (TX/RX bytes and packets), and dropped packets.
     * - Matches the router's current DDNS IP to identify the primary adapter.
     * - Filters out adapters not listed in the configuration.
     * - Updates the internal state with current traffic and adapter data:
     *     - `$this->adaptersData` stores adapter-level details.
     *     - `$this->totalRouterTraffic` and `$this->totalRepeaterTraffic` store traffic totals in bytes.
     *     - `$this->lastRefreshTime` and `$this->currentRefreshTime` track timing of data refresh.
     *
     * @return void
     */
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

    /**
     * Initializes traffic monitoring data for each configured provider.
     *
     * This method:
     * - Sets the current refresh timestamp.
     * - Iterates over known router adapters and matches them to providers defined in the configuration (`$this->config['providers']['provider']`).
     * - Initializes or resets key tracking fields for each matched provider:
     *     - RX/TX byte counters (current, last, on start, accumulated)
     *     - Online status, IP address, idle counters, and IP change tracking
     * - Stores all initialized provider data into `$this->providersData`.
     * - Aggregates traffic data across all providers into a special "TOTAL" entry:
     *     - Summing RX/TX values and counters to provide network-wide usage totals.
     *
     * This setup is intended to support tracking per-provider traffic over time,
     * detecting activity/inactivity, and calculating deltas between refreshes.
     *
     * @return void
     */
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

    /**
     * Updates real-time traffic and IP tracking data for each VPN provider and the total aggregate.
     *
     * This method:
     * - Iterates through all router adapters and matches them to their corresponding VPN providers.
     * - For each matched provider:
     *   - Updates byte counters (current and last) and accumulates differences for RX/TX traffic.
     *   - Detects IP changes, increments change counters, and sends Telegram notifications if enabled.
     *   - Handles demo mode masking for provider names and IPs in notifications.
     *   - Updates the provider's IP and DDNS status.
     * - Resets and recalculates traffic totals under the special "TOTAL" provider entry by summing
     *   values from all individual providers.
     *
     * Telegram messages (optional):
     * - Sent when a provider’s IP changes.
     * - Hidden or masked if demo mode is enabled in configuration.
     *
     * Fields Updated per Provider:
     * - `RXbytes`, `TXbytes`
     * - `RXbytesLast`, `TXbytesLast`
     * - `RXbytesAccumulated`, `TXbytesAccumulated`
     * - `ip`, `ipChanges`, `ddns`
     *
     * Fields Updated in TOTAL:
     * - All the above fields, summed from all active providers.
     *
     * @return void
     */
    public function refreshProvidersData(): void
    {
        $providerNumberForDemo = 1;
        foreach ($this->adaptersData as $routerAdapterName => $routerAdapterData) {

            foreach ($this->providersData as $providerKey => $providerData) {

                if ($providerData['vpnAdapterName'] == $routerAdapterName) {
                    $providerData['RXbytesLast'] = $providerData['RXbytes'] ?? 0;
                    $providerData['TXbytesLast'] = $providerData['TXbytes'] ?? 0;
                    $providerData['RXbytes'] = $routerAdapterData['rx'];
                    $providerData['TXbytes'] = $routerAdapterData['tx'];

                    // Fix attempt to deal with ISP reconnects.

                    // BEFORE:
                    // $providerData['RXbytesAccumulated'] += ($providerData['RXbytes'] - $providerData['RXbytesLast']);
                    // $providerData['TXbytesAccumulated'] += ($providerData['TXbytes'] - $providerData['TXbytesLast']);

                    // +++ Now we calculate deltas and handle counter resets +++
                    $rxDelta = $providerData['RXbytes'] - $providerData['RXbytesLast'];
                    $txDelta = $providerData['TXbytes'] - $providerData['TXbytesLast'];

                    if ($rxDelta < 0) {
                        // Counter reset detected: treat as fresh start
                        $rxDelta = $providerData['RXbytes'];
                    }
                    if ($txDelta < 0) {
                        $txDelta = $providerData['TXbytes'];
                    }

                    $providerData['RXbytesAccumulated'] += $rxDelta;
                    $providerData['TXbytesAccumulated'] += $txDelta;
                    // +++ End of fix attempt


                    if ($providerData['ip'] != $routerAdapterData['ip']) {
                        $providerData['ipChanges']++;

                        if ($this->telegram->isTelegramEnabled()) {

                            $localProviderName = $providerData['providerName'];
                            $localRouterAdapterDataIp = $routerAdapterData['ip'];
                            $localProviderIp = $providerData['ip'];
                            if ($this->config['settings']['demo']) {
                                $localProviderName = "Provider" . $providerNumberForDemo;
                                $localRouterAdapterDataIp = '***.***.***.***'; // In demo mode, we do not show real IPs.
                                $localProviderIp = '***.***.***.***'; // In demo mode, we do not show real IPs.
                                $providerNumberForDemo++;
                            }
                            $header = "*Provider '" . $localProviderName . "' IP Update*";
                            if ($this->config['settings']['demo']) {
                                $header .= " (Demo mode, IPs are hidden)";
                            }
                            if ($providerData['ip'] === '') {
                                $message = "$header\n" . date("Y.m.d H:i:s") . " IP has been set to \[" . $localRouterAdapterDataIp . "].";
                                $this->telegram->sendMessage($message, 'Markdown');
                                $this->logger->addInstantLogData($localProviderName . " IP has been set to [" . $localRouterAdapterDataIp . "].", Logger::INSTANT_LOG_EVENT_TYPE_INFO);
                            } elseif (!$this->config['settings']['demo']) {
                                $message = "$header\n" . date("Y.m.d H:i:s") . " IP has changed from \[" . $localProviderIp . "] to \[" . $localRouterAdapterDataIp . "].";
                                $this->telegram->sendMessage($message, 'Markdown');
                                $this->logger->addInstantLogData($localProviderName . " IP has changed from [" . $localProviderIp . "] to [" . $localRouterAdapterDataIp . "].", Logger::INSTANT_LOG_EVENT_TYPE_WARNING);
                            }

                        }
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

    /**
     * Calculates per-provider and global traffic statistics based on recent data samples.
     *
     * This method processes up-to-date RX/TX byte counters and derives:
     *  - Current traffic speeds (RX/TX per second)
     *  - Rolling average, min, and max speeds over a limited window (`$this->stepsToShow`)
     *  - Historical (global) min, max, and average speeds for each provider
     *  - Detection of offline providers based on traffic or missing IP
     *  - Global maximum observed speed across all providers
     *
     * Breakdown:
     * 1. Speed calculation. For each provider, calculates RX/TX speed since the last refresh,
     *    appends to history, and increments idle counters for zero-traffic periods.
     *
     * 2. Rolling statistics. Limits RX/TX speed history to `$this->stepsToShow` steps,
     *    then calculates:
     *    - `sumRX`, `sumTX`
     *    - `maxRX`, `maxTX`, `minRX`, `minTX`
     *    - `avgRX`, `avgTX`
     *
     * 3. Global historical statistics. Updates persistent global min/max/avg metrics:
     *    - `globalMaxRX`, `globalMinRX`, `globalAvgRX`
     *    - `globalMaxTX`, `globalMinTX`, `globalAvgTX`
     *    - Also stores `_last` versions for possible UI comparisons.
     *
     * 4. Offline detection. A provider is marked as offline if:
     *    - Both `maxRX` and `maxTX` are zero, or
     *    - Its IP is empty, and the provider is not the TOTAL aggregator.
     *
     * 5. Global peak speed tracking. Captures the maximum observed RX or TX speed
     *    across all providers and saves it in `TOTAL['globalMaxSpeed']`.
     *
     * Assumptions:
     * - This method requires `refreshAdaptersData()` and `refreshProvidersData()` to be called first.
     * - `stepsToShow` defines how many recent steps are used in rolling calculations.
     * - `currentRefreshTime` and `lastRefreshTime` must be set for accurate deltas.
     *
     * @return void
     */
    public function refreshStats(): void
    {
        $timeDelta = $this->currentRefreshTime - $this->lastRefreshTime;

        // 1st step of statistics preparation (inits)
        foreach ($this->providersData as $providerName => $providerData) {
            $speedRX = ($providerData['RXbytes'] - $providerData['RXbytesLast']) / $timeDelta;
            if ($speedRX < 0) $speedRX = 0;

            $speedTX = ($providerData['TXbytes'] - $providerData['TXbytesLast']) / $timeDelta;
            if ($speedTX < 0) $speedTX = 0;

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

    /**
     * Checks if a given adapter name is present in the configuration providers list.
     *
     * @param string $adapterName The name of the adapter to check.
     * @param array $configProvidersList The configuration providers list to search in.
     * @return bool Returns true if the adapter is found, false otherwise.
     */
    private function isAdapterInConfig(string $adapterName, array $configProvidersList): bool
    {
        foreach ($configProvidersList['provider'] as $provider) {
            if ($provider['vpnAdapterName'] == $adapterName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collects and updates hardware-level diagnostics and statistics for both router and repeater devices.
     *
     * This method:
     * * Initializes 'hardwareDataArray' for both router and repeater:
     *    - SSH clients, hooks, configured CPU cores, device name, traffic total, and router boot time.
     *
     * * For each device it detects:
     *   1. CPU Temperature:
     *      - Reads from '/sys/class/thermal/thermal_zone* /temp' to get min/max CPU temps.
     *      - Formats a readable temperature range ('cpuTempString').
     *
     *   2. Uptime:
     *      - Uses 'uptime' and '/proc/uptime' to calculate total seconds.
     *      - Converts to 'D.H:I:S' format ('uptimePretty', 'uptimePrettyLong').
     *
     *   3. Load Average:
     *      - Extracts 1/5/15-minute load averages.
     *      - Computes percentage-based CPU usage for each interval using CPU core count.
     *
     *   4. Optional Hook-Based Detailed Stats (if 'showDetailedDevicesData == 'Y''):
     *      - Executes API hook commands ('netdev', 'get_clients_fullinfo', etc.).
     *      - Logs hook responses and processes:
     *        - 'cpu_usage(appobj)' for per-core CPU load (min/max/range).
     *        - 'memory_usage(appobj)' for memory load (MB and %).
     *        - 'get_allclientlist()' for client counts.
     *
     *   5. Memory Usage:
     *      - Converts memory data to MB, calculates usage %, and creates a readable string.
     *
     * All collected data is stored in:
     *   - '$this->hardwareData['router']'
     *   - '$this->hardwareData['repeater']'
     *
     * @return void
     */
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
                $hooksRawResult = $hardwareData['hooks']->execApiCommands(['get_wan_lan_status()', 'get_allclientlist()', 'get_clientlist()', 'cpu_usage(appobj)', 'memory_usage(appobj)']);

                $hooksCleanResults = array();
                foreach ($hooksRawResult as $oneResultCommand => $oneResultData) {
                    $hooksCleanResults[$oneResultCommand] = json_decode($oneResultData['response'], true);
                }
                $hardwareData['hooksResults'] = $hooksCleanResults;

                /* Deprecated. Saved for reference and backward compatibility.
                   Clients count OLD approach. Rather simple, yet not too accurate (ASUSWRT behaves strangely).

                   $clientsList = $hardwareData['hooksResults']['get_allclientlist()']['get_allclientlist'] ?? [];
                   $clientsList = reset($clientsList);
                   $hardwareData['clientsCount'] = count($clientsList['wired_mac'] ?? []);
                */

                // Clients count NEW approach. More accurate, yet more complex.
                $this->updateAndCollectCleanedClientsData($hardwareData['hooksResults']['get_clientlist()']['get_clientlist'], $hardwareName);
                $cleanedClientsData = $this->getCleanedClientsData($hardwareName);
                $hardwareData['clientsCount'] = $cleanedClientsData['totalOnline'] ?? 0;
                $hardwareData['clientsCountWired'] = $cleanedClientsData['wiredOnline'] ?? 0;
                $hardwareData['clientsCountWifi'] = $cleanedClientsData['wifiOnline'] ?? 0;
                $hardwareData['clientsCountOffline'] = $cleanedClientsData['totalOffline'] ?? 0;
                $hardwareData['clientsList'] = $cleanedClientsData['beautifiedClients'] ?? [];

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

    /**
     * Returns the collected data for providers.
     *
     * @return array An associative array containing provider data.
     */
    public function getProvidersData(): array
    {
        return $this->providersData;
    }

    /**
     * Returns the collected hardware data for router and repeater.
     *
     * @return array An associative array containing hardware data for both devices.
     */
    public function getHardwareData(): array
    {
        return $this->hardwareData;
    }

    /**
     * Cleans and formats client data from the raw router/repeated data array.
     *
     * This method processes raw client data, categorizing clients by their connection type
     * (wired or wireless), counting online and offline clients, and formatting the output
     * for easier consumption.
     *
     * @param array $clients An associative array of clients with MAC addresses as keys.
     * @param string $hardwareName
     * @return void An array containing counts of online clients and a formatted list of clients.
     */
    public function updateAndCollectCleanedClientsData(array $clients, string $hardwareName): void
    {
        // Initialize counters for stats
        $totalOnline = 0;
        $wiredOnline = 0;
        $wifiOnline = 0;
        $totalOffline = 0;

        $beautifiedClients = [];

        foreach ($clients as $macAddress => $info) {

            // Skip invalid MACs (not in format AA:BB:CC:DD:EE:FF)
            if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $macAddress)) {
                continue;
            }

            // Determine connection type (WiFi/Wired/Unknown)
            $connectionType = $this->detectClientConnectionType((int)($info['isWL'] ?? -1));

            // Load existing client data, if any
            $beautifiedClient = $this->combinedClientsData[$macAddress] ?? [];

            // Merge in new or updated data
            $beautifiedClient = array_merge($beautifiedClient, [
                'Hardware' => $hardwareName,
                'MAC' => $macAddress,
                'IP' => $info['ip'] ?? 'N/A',
                'Connection' => $connectionType['real_type'],
                'ConnectionRaw' => $info['isWL'] ?? -1,
                'Vendor' => $info['vendor'] ?? 'N/A',
                'Name' => $info['name'] ?? 'N/A',
                'NickName' => $info['nickName'] ?? 'N/A',
                'Type' => $this->detectClientType((int)($info['type'] ?? -1)),
                'TypeRaw' => $info['type'] ?? -1,
                'isOnline' => isset($info['isOnline']) && (bool)$info['isOnline'],
                'isWiFi' => $connectionType['is_wifi'],
                'isWired' => $connectionType['is_wired'],
                'isGuest' => $info['isGuest'] ?? false,
                'RSSI' => $info['rssi'] ?? 'N/A',
                'WiFiRX' => $info['curRx'] ?? 'N/A',
                'WiFiTX' => $info['curTx'] ?? 'N/A',
                'WiFiConnectionTime' => $info['wlConnectTime'] ?? 'N/A',
            ]);

            // Heuristic to correct wrongly classified wired clients that look like WiFi
            if (
                (int)$beautifiedClient['RSSI'] !== 0 ||
                (int)$beautifiedClient['WiFiRX'] !== 0 ||
                (int)$beautifiedClient['WiFiTX'] !== 0 ||
                str_contains($beautifiedClient['WiFiConnectionTime'], ":")
            ) {
                if ($beautifiedClient['isWired']) {
                    $beautifiedClient['isWiFi'] = true;
                    $beautifiedClient['isWired'] = false;
                    $beautifiedClient['Connection'] = 'WiFiS'; // Adjusted to probable WiFi
                }
            }

            // As router sometimes does not update WiFi time for offline clients,
            // we assume online status based on other WiFi parameters.
            if (
                (int)$beautifiedClient['RSSI'] !== 0 ||
                (int)$beautifiedClient['WiFiRX'] !== 0 ||
                (int)$beautifiedClient['WiFiTX'] !== 0
            ) {
                $beautifiedClient['isOnline'] = true;
            }


            // Preserve original router-reported online status
            $beautifiedClient['isOnlineByRouter'] = $beautifiedClient['isOnline'];

            // Try the magic of using external online statuses provider
            if (!empty($this->reliableOnlineStatuses)) {

                $isOnlineByReliableSource = false;

                foreach ($this->reliableOnlineStatuses as $status) {
                    if ((strtolower($beautifiedClient['MAC']) == strtolower($status['mac'])) ||
                        (strtolower($beautifiedClient['IP']) == strtolower($status['ip']))) {
                        $isOnlineByReliableSource = true;
                        break;
                    }
                }

                $beautifiedClient['isOnline'] = $isOnlineByReliableSource;

                if ($beautifiedClient['isWiFi']) {
                    if ($this->parseHMSToSeconds($beautifiedClient['WiFiConnectionTime']) > (int)$status['seen']) {
                        $beautifiedClient['WiFiConnectionTime'] = $this->formatSecondsToHMS($status['seen'] ?? 0);
                    }
                }

            }


            // Append this hardware to the client's known hardware list
            if (!in_array($hardwareName, ($beautifiedClient['HardwareList'] ?? []))) {
                $beautifiedClient['HardwareList'][] = $hardwareName;
            }

            // Tally the counters (using router data)
            if ($beautifiedClient['isOnlineByRouter']) {
                $totalOnline++;
                if ($beautifiedClient['isWiFi']) {
                    $wifiOnline++;
                } elseif ($beautifiedClient['isWired']) {
                    $wiredOnline++;
                }
            } else {
                $totalOffline++;
            }

            // Store updated client data
            $beautifiedClients[$beautifiedClient['MAC']] = $beautifiedClient;
            $this->combinedClientsData[$beautifiedClient['MAC']] = $beautifiedClient;
        }

        // Persist final statistics and full client list for this hardware
        $this->clientsData[$hardwareName] = [
            'totalOnline' => $totalOnline,
            'wiredOnline' => $wiredOnline,
            'wifiOnline' => $wifiOnline,
            'totalOffline' => $totalOffline,
            'beautifiedClients' => $beautifiedClients,
        ];
    }

    public function refreshBeautifiedClients(): void
    {
        // Retrieve predefined client actions from config
        $configClientActions = $this->config['clientsBasedActions']['client'] ?? [];

        // Retrieve substitute names from config
        $configSubstituteNames = $this->config['substituteNames']['client'] ?? [];

        foreach ($this->combinedClientsData as $beautifiedClient) {

            // Prepare online/offline time tracking structure
            $onlineStatusChangesArray = $beautifiedClient['OnlineStatusChanges'] ?? [
                'firstSeenOnline' => -1,
                'firstSeenOffline' => -1,
                'lastSeenOnline' => -1,
                'lastSeenOffline' => -1,
                'offlineFor' => -1,
                'onlineFor' => -1,
                'offlineActionsPerformedAt' => -1,
                'onlineActionsPerformedAt' => -1,
            ];

            // Try to find actions configured for this client
            $clientActions = $this->findClientActions(
                $configClientActions,
                $beautifiedClient['MAC'],
                $beautifiedClient['IP'],
                $beautifiedClient['Name'],
                $beautifiedClient['NickName']
            );

            // Try to find substitute name for this client
            $clientSubstituteName = $this->findClientSubstituteName($configSubstituteNames, $beautifiedClient['MAC'], $beautifiedClient['IP']);
            if ($clientSubstituteName !== '') {
                $beautifiedClient['Name'] = $clientSubstituteName;
                $beautifiedClient['NickName'] = $clientSubstituteName;
            }

            // Apply any found actions
            if (!empty($clientActions)) {
                if ($beautifiedClient['Name'] === '') {
                    $beautifiedClient['Name'] = $clientActions['name'];
                }

                if ($beautifiedClient['NickName'] === '') {
                    $beautifiedClient['NickName'] = $clientActions['name'];
                }

                // Optional ping test to verify online status
                if (($clientActions['clarifyOnlineStatusByPing'] ?? '') === 'Y') {
                    $beautifiedClient['isOnline'] = $this->isDeviceOnlineByPing($beautifiedClient['IP']);

                    // Adjust WiFi time if the online duration exceeds the router's report
                    if ($beautifiedClient['isWiFi'] && $onlineStatusChangesArray['firstSeenOnline'] > 0) {
                        $actualOnline = time() - $onlineStatusChangesArray['firstSeenOnline'];
                        $routerReported = $this->parseHMSToSeconds($beautifiedClient['WiFiConnectionTime'] ?? '0:00:00');

                        if ($actualOnline > $routerReported) {
                            $beautifiedClient['WiFiConnectionTime'] = $this->formatSecondsToHMS($actualOnline);
                        }
                    }
                }

                // Force connection type override
                if (($clientActions['forceConnectionType'] ?? '') === 'wireless') {

                    $beautifiedClient['isWiFi'] = true;
                    $beautifiedClient['isWired'] = false;
                    $beautifiedClient['Connection'] = 'WiFiS';

                    // Generate synthetic WiFi connection time if missing
                    if (($beautifiedClient['WiFiConnectionTime'] ?? '') === '') {
                        $currentDateTime = new DateTime();
                        $supposedOnlineTime = time() - ($onlineStatusChangesArray['firstSeenOnline'] ?? time());
                        $routerOnlineTime = $currentDateTime->getTimestamp() - ($this?->routerStartDateTime?->getTimestamp() ?? 0);
                        if ($supposedOnlineTime > $routerOnlineTime) {
                            $supposedOnlineTime = $routerOnlineTime;
                        }
                        $beautifiedClient['WiFiConnectionTime'] = $this->formatSecondsToHMS($supposedOnlineTime);
                    }
                } elseif (($clientActions['forceConnectionType'] ?? '') === 'wired') {
                    $beautifiedClient['isWiFi'] = false;
                    $beautifiedClient['isWired'] = true;
                    $beautifiedClient['Connection'] = 'Wired';
                }
            }

            // Track online/offline durations
            if ($beautifiedClient['isOnline']) {
                if ($onlineStatusChangesArray['firstSeenOnline'] === -1) {
                    $onlineStatusChangesArray['firstSeenOnline'] = time();
                }

                $onlineStatusChangesArray['lastSeenOnline'] = time();
                $onlineStatusChangesArray['onlineFor'] = time() - $onlineStatusChangesArray['firstSeenOnline'];

                // Reset offline tracking
                $onlineStatusChangesArray['offlineFor'] = -1;
                $onlineStatusChangesArray['firstSeenOffline'] = -1;
                $onlineStatusChangesArray['lastSeenOffline'] = -1;
            } else {
                if ($onlineStatusChangesArray['firstSeenOffline'] === -1) {
                    $onlineStatusChangesArray['firstSeenOffline'] = time();
                }

                $onlineStatusChangesArray['lastSeenOffline'] = time();
                $onlineStatusChangesArray['offlineFor'] = time() - $onlineStatusChangesArray['firstSeenOffline'];

                // Reset online tracking
                $onlineStatusChangesArray['onlineFor'] = -1;
                $onlineStatusChangesArray['firstSeenOnline'] = -1;
                $onlineStatusChangesArray['lastSeenOnline'] = -1;
            }

            $beautifiedClient['OnlineStatusChanges'] = $onlineStatusChangesArray;


            $this->combinedClientsData[$beautifiedClient['MAC']] = $beautifiedClient;
        }
    }


    /**
     * Updates the action time for a client based on the action type (online/offline).
     *
     * This method sets the action time to the current timestamp or resets it to -1
     * if requested. It updates the combined clients data structure accordingly.
     *
     * @param string $mac The MAC address of the client.
     * @param string $actionType The type of action ('online' or 'offline').
     * @param bool $resetActionTime Whether to reset the action time to -1.
     */
    public function updateClientActionTime(string $mac, string $actionType, bool $resetActionTime): void
    {
        $actionTime = time();
        if ($resetActionTime) {
            $actionTime = -1; // Reset action time to -1 if requested
        }

        if ($actionType == 'online') {
            $this->combinedClientsData[$mac]['OnlineStatusChanges']['onlineActionsPerformedAt'] = $actionTime;
        } elseif ($actionType == 'offline') {
            $this->combinedClientsData[$mac]['OnlineStatusChanges']['offlineActionsPerformedAt'] = $actionTime;
        }
    }

    /**
     * Detects the type of client connection based on the isWL value.
     *
     * @param int $isWL The isWL value indicating the type of connection.
     * @return array An associative array containing the real type and flags for wired and WiFi connections.
     */
    private function detectClientConnectionType(int $isWL): array
    {
        return match ($isWL) {
            0 => ['real_type' => 'Wired', 'is_wired' => true, 'is_wifi' => false],
            1 => ['real_type' => 'WiFiS', 'is_wired' => false, 'is_wifi' => true], // WiFi 2/5
            2 => ['real_type' => 'WiFi6', 'is_wired' => false, 'is_wifi' => true],
            3 => ['real_type' => 'Guest WiFi', 'is_wired' => false, 'is_wifi' => true],
            default => ['real_type' => 'Unknown', 'is_wired' => false, 'is_wifi' => false],
        };
    }

    /**
     * Detects the type of client based on the provided type code.
     *
     * @param int $type The type code representing the client type.
     * @return string A string describing the client type.
     */
    private function detectClientType(int $type): string
    {
        return match ($type) {
            0 => 'PC / VM / Generic Device',
            1 => 'PC (Windows/Linux/Mac)',
            2 => 'Router / Repeater',
            4 => 'NAS / Network Storage',
            5 => 'NVR / Surveillance System',
            6 => 'Smart TV',
            9 => 'Android Device (Phone/Tablet)',
            11 => 'Game Console',
            14 => 'IP Camera',
            17 => 'Network Storage (NAS)',
            22 => 'IoT Device / Virtual Machine (Linux)',
            30 => 'Laptop / Custom / Static Assignment',
            34 => 'Virtual Machine (Windows)',
            default => 'Unknown Type',
        };
    }

    /**
     * Returns the collected data for clients.
     *
     * @return array An associative array containing client data.
     */
    public function getCleanedClientsData(string $hardwareName): array
    {
        return $this->clientsData[$hardwareName] ?? [
            'totalOnline' => 0,
            'wiredOnline' => 0,
            'wifiOnline' => 0,
            'totalOffline' => 0,
            'beautifiedClients' => [],
        ];
    }

    /**
     * Returns the combined clients data across all hardware devices.
     *
     * This method aggregates client data from both router and repeater,
     * allowing access to a unified view of all clients.
     *
     * @return array An associative array containing combined client data.
     */
    public function getCombinedClientsData(): array
    {
        return $this->combinedClientsData;
    }

    /**
     * Checks if a given IP address is online by pinging it.
     *
     * This method uses the system's ping command to check if the specified IP address
     * is reachable. It returns true if the ping is successful, indicating that the
     * device is online, and false otherwise.
     *
     * @param string $ip The IP address to check.
     * @return bool True if the device is online, false otherwise.
     */
    private function isDeviceOnlineByPing(string $ip): bool
    {
        $output = shell_exec("ping -n 1 -w 1000 $ip");
        if (str_contains(strtolower($output), 'TTL=')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Finds client actions based on MAC, IP, name, or nickname.
     *
     * This method searches through the configured client actions to find a match
     * based on the provided MAC address, IP address, name, or nickname.
     *
     * @param array $clientsActionsFromConfig The list of client actions from the configuration.
     * @param string $mac The MAC address of the client.
     * @param string $ip The IP address of the client.
     * @param string $name The name of the client.
     * @param string $nickName The nickname of the client.
     * @return array The matched client actions or an empty array if not found.
     */
    public function findClientActions(array $clientsActionsFromConfig, string $mac, string $ip, string $name, string $nickName): array
    {
        $mac = strtolower(trim($mac));
        $ip = trim($ip);
        $name = strtolower(trim($name));
        $nickName = strtolower(trim($nickName));

        if ($name == '') {
            $name = 'unknown';
        }

        if ($nickName == '') {
            $nickName = 'unknown';
        }

        // 1. Try to find by MAC
        foreach ($clientsActionsFromConfig as $client) {
            if (strtolower($client['mac']) == $mac) {
                return $client;
            }
        }

        // 2. Try to find by IP
        foreach ($clientsActionsFromConfig as $client) {
            if (strtolower($client['ip']) == $ip) {
                return $client;
            }
        }

        // 3. Try to find by name or nickname
        foreach ($clientsActionsFromConfig as $client) {
            if ((strtolower($client['name']) == $name) || (strtolower($client['name']) == $nickName)) {
                return $client;
            }
        }

        // Not found
        return [];
    }


    /**
     * Finds a substitute name for a client based on MAC or IP.
     *
     * This method searches through the configured fixed client names to find a match
     * based on the provided MAC address or IP address. If a match is found, it returns
     * the corresponding name; otherwise, it returns an empty string.
     *
     * @param array $fixClientsNamesFromConfig The list of fixed client names from the configuration.
     * @param string $mac The MAC address of the client.
     * @param string $ip The IP address of the client.
     * @return string The substitute name if found, otherwise an empty string.
     */
    public function findClientSubstituteName(array $fixClientsNamesFromConfig, string $mac, string $ip): string
    {
        $mac = strtolower(trim($mac));
        $ip = trim($ip);

        // 1. Try to find by MAC
        foreach ($fixClientsNamesFromConfig as $client) {
            if (strtolower($client['mac']) == $mac) {
                return $client['name'] ?? '';
            }
        }

        // 2. Try to find by IP
        foreach ($fixClientsNamesFromConfig as $client) {
            if ($client['ip'] == $ip) {
                return $client['name'] ?? '';
            }
        }

        return '';
    }


    /**
     * Formats seconds into a "router-like" string in the format "H:M:S"
     * for $client['WiFiConnectionTime'], e.g., 999:23:56.
     *
     * @param int $seconds The number of seconds to format.
     * @return string A formatted string representing the time in hours, minutes, and seconds.
     */
    private function formatSecondsToHMS(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Parses a string in the format "H:M:S" and converts it to total seconds.
     *
     * @param string $hms The time string in the format "H:M:S".
     * @return int The total number of seconds represented by the input string.
     */
    private function parseHMSToSeconds(string $hms): int
    {
        // Trim and split, removing any empty or non-numeric parts
        $parts = array_map('trim', explode(':', $hms));
        $parts = array_reverse($parts); // Start from seconds

        // Initialize time parts
        $seconds = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : 0;
        $minutes = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;
        $hours = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : 0;

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    /**
     * Refreshes the reliable online statuses from configured online devices detectors.
     *
     * This method iterates through the configured online devices detectors, checking if they are enabled
     * and if enough time has passed since the last refresh. If so, it queries the online status provider
     * and updates the reliable online statuses if successful.
     */
    public function refreshReliableOnlineStatuses(): void
    {
        // Reference so updates persist between calls
        $detectors = &$this->config['onlineDevicesDetectors']['detector'];

        foreach ($detectors as &$detector) {

            if (($detector['isEnabled'] ?? '') !== 'Y') {
                continue;
            }

            $ip = $detector['ip'] ?? '';
            $port = $detector['port'] ?? '';
            $refreshRate = (int)($detector['refreshRate'] ?? 60);
            $lastRefresh = (int)($detector['lastRefreshTime'] ?? 0);

            if ($ip === '' || $port === '') {
                continue;
            }

            if (time() - $lastRefresh <= $refreshRate) {
                // Top-priority detector is up-to-date -> rely on cached data.
                // DO NOT continue to next detectors.
                return;
            }

            $online = $this->queryOnlineStatusProvider($ip, $port);

            if (!empty($online)) {
                $this->reliableOnlineStatuses = $online;
                $detector['lastRefreshTime'] = time();
                return;
            }

            $this->logger->addInstantLogData(
                "Failed to refresh online statuses from ({$ip}:{$port}).",
                Logger::INSTANT_LOG_EVENT_TYPE_ERROR
            );
        }
    }


    /**
     * Queries the online status provider for device statuses.
     *
     * This method sends a GET request to the specified IP and port, expecting a JSON response
     * containing device statuses. It returns an array of entries if successful, or an empty array
     * if there was an error or the response was not valid JSON.
     *
     * @param string $ip The IP address of the online status provider.
     * @param string $port The port number of the online status provider.
     * @return array An array of device statuses or an empty array on failure.
     */
    private function queryOnlineStatusProvider(string $ip, string $port): array
    {

        $url = "http://" . $ip . ":" . $port . "/";

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        /* allow_url_fopen must be ON in php.ini */
        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) {
            // timed out or connection error
            return [];
        }

        try {
            $jsonDataAsArry = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->logException($e);
            return [];
        }

        if (empty($jsonDataAsArry["entries"])) {
            $this->logger->addInstantLogData("No entries found in the response from {$url}.", Logger::INSTANT_LOG_EVENT_TYPE_WARNING);
            return [];
        }

        // ARP table often skips "self machine", so we need to check if we have it
        $selfFound = false;
        foreach ($jsonDataAsArry["entries"] as $entry) {
            if (($entry['ip'] ?? '') == $ip) {
                $selfFound = true;
                break;
            }
        }

        if (!$selfFound) {
            $jsonDataAsArry["entries"][] = [
                'mac' => '00:00:00:00:00:00', // Placeholder MAC for "self machine"
                'ip' => $ip,
                'kind' => 'unicast_private',
                'seen' => time() - $this->routerStartDateTime->getTimestamp()
            ];
        }

        return $jsonDataAsArry["entries"] ?? [];

    }

}