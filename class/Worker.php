<?php

namespace Sv\Network\VmsRtbw;

/**
 * Class Worker
 *
 * Handles global system operations such as initialization, updates,
 * exception handling, and Telegram messaging integration.
 *
 * This class manages the coordination between core components like Router,
 * Config, and Screen, while also supporting Telegram-based notifications.
 */
class Worker
{

    /**
     * @var Router The router object for managing network connections and data.
     *
     * This object is responsible for handling the router's connections, adapters,
     * providers, and hardware data.
     */
    private Router $router;

    /**
     * @var Config The configuration object containing application settings.
     *
     * This object is used to access various configuration parameters and settings
     * required for the application's operation.
     */
    private Config $config;

    /**
     * @var Screen The screen object for UI-related actions.
     *
     * This object is responsible for drawing the user interface, displaying data,
     * and handling user interactions on the screen.
     */
    private Screen $screen;

    /**
     * @var Telegram The Telegram object for sending messages and updates.
     *
     * This object is used to interact with Telegram's Bot API for sending notifications
     * and updates related to the system.
     */
    private Telegram $telegram;

    /**
     * @var Logger The logger object for logging messages and exceptions.
     *
     * This object is used to log various events, errors, and informational messages
     * throughout the application's lifecycle.
     */
    private Logger $logger;

    /**
     * Worker constructor.
     *
     * Initializes the Worker with a Logger instance for logging purposes.
     *
     * @param Logger $logger The logger object for logging messages and exceptions.
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }


    /**
     * Global initialization method.
     *
     * Initializes the router with the necessary connections and configurations.
     * If $utility_start is true, providers' data is also initialized.
     *
     * @param Router|null $router The router object to initialize (passed by reference).
     * @param Config $config Configuration object containing application settings (passed by reference).
     * @param Screen $screen The screen object for UI-related actions (passed by reference).
     * @param bool $utility_start Indicates whether to initialize providers' data.
     * @param Telegram $telegram The telegram object for Telegram integration.
     * @return void
     */
    public function globalInit(?Router &$router, Config $config, Screen $screen, Telegram $telegram, bool &$utility_start): void
    {
        // Initialize objects
        $this->config = $config;
        $this->screen = $screen;
        if ($router !== null) {
            // If router is already initialized, use it
            $this->router = $router;
        }
        $this->telegram = $telegram;

        // Create new connections to router and repeater
        $connectionToRouter = new Connection();
        $connectionToRepeater = new Connection();

        // Initialize the router with the necessary parameters
        $router = new Router($this->logger);
        $router->init($connectionToRouter, $connectionToRepeater, $config, $screen->getStepsToShow(), $telegram);
        $router->refreshAdaptersData();

        // If utility_start is enabled, initialize providers' data
        if ($utility_start) {
            $router->initProvidersData();
            $utility_start = false; // Disable utility_start after initialization
        }

        // Pause execution for the configured refresh rate
        sleep($config->getNestedParameter('settings', 'refreshRate'));
    }

    /**
     * Global step method.
     *
     * Executes periodic tasks such as refreshing data and updating the screen.
     *
     * @param Router|null $router The router object to update (passed by reference).
     * @return void
     */
    public function globalStep(?Router $router): void
    {
        if ($router !== null) {
            $this->router = $router;
        }

        // Refresh various data sources in the router
        $this->router->refreshAdaptersData();
        $this->router->refreshProvidersData();
        $this->router->refreshHardwareData();
        $this->router->refreshStats();
        $this->router->refreshReliableOnlineStatuses();
        $this->router->refreshBeautifiedClients();

        $providersData = $this->router->getProvidersData();
        $hardwareData = $this->router->getHardwareData();

        // Update the screen with the refreshed data
        $this->screen->drawScreen($providersData, $hardwareData);

        // Process Telegram updates
        $this->telegram->makeTelegramUpdates($providersData, $hardwareData, $this->router->getCombinedClientsData());

        // Process client-specific actions if any
        $this->processClientSpecificActions();

        if ($this->logger->getLastDateOfDailyLogProcessing() !== date('Y.m.d')) {
            $routerLogData = $this->router->getDeviceInnerLog($this->router::DEVICE_ROUTER, -1, 0);
            $repeaterLogData = $this->router->getDeviceInnerLog($this->router::DEVICE_REPEATER, -1, 0);
            $this->logger->processDailyDevicesLogSaving($routerLogData['forFile'] ?? '', $repeaterLogData['forFile'] ?? '', $routerLogData['totalLines'] ?? 0, $repeaterLogData['totalLines'] ?? 0);
        }

        // Pause execution for the configured refresh rate
        $sleepTime = $this->config->getNestedParameter('settings', 'refreshRate');
        if ($this->config->getNestedParameter('settings', 'checkForKeyboardEvents') === 'Y') {
            $sleepTime -= 3; // Reduce sleep time as keyboard events and other actions may take time
        } else {
            $sleepTime -= 2; // Reduce sleep time as communication with the routers takes time
        }
        $sleepTime = max($sleepTime, 1); // Ensure sleep time is at least 1 second
        sleep($sleepTime);
    }

    /**
     * Handles exceptions by refreshing the screen and waiting.
     *
     * Clears the screen and displays a message to indicate the router is offline,
     * then pauses execution for the configured refresh rate.
     *
     * @param Screen $screen The screen object to clear and display messages.
     * @param Config $config Configuration object containing application settings.
     *
     * @return void
     */
    public function refreshAfterException(Screen $screen, Config $config): void
    {
        // Clear the screen and display an error message
        $screen->clearScreen();
        echo "Router seems to be offline. Waiting for " . $config->getNestedParameter('settings', 'refreshRate') . " seconds to try again...\n";

        // Pause execution for the configured refresh rate
        sleep($config->getNestedParameter('settings', 'refreshRate'));
    }

    /**
     * Processes client-specific actions based on the configuration.
     *
     * This method checks the configuration for client-specific actions and performs
     * actions such as sending Telegram messages or locking the workstation based on
     * the online/offline status of clients.
     *
     * @return void
     */
    private function processClientSpecificActions(): void
    {
        $configClientActions = $this->config->getConfigData()['clientsBasedActions']['client'] ?? [];
        if (empty($configClientActions)) {
            return;
        }

        foreach ($this->router->getCombinedClientsData() as $client) {
            $clientActions = $this->router->findClientActions($configClientActions,
                $client['MAC'] ?? '',
                $client['IP'] ?? '',
                $client['Name'] ?? '',
                $client['NickName'] ?? ''
            );

            if (!empty($clientActions)) {

                $isOnline = (bool)($client['isOnline'] ?? false);

                // Online actions
                if ($isOnline && ((int)$client['OnlineStatusChanges']['onlineActionsPerformedAt'] < 0)) {
                    if ((int)$client['OnlineStatusChanges']['onlineFor'] >= (int)$clientActions['online']['timeout']) {
                        $this->router->updateClientActionTime($client['MAC'], 'online', false);
                        $this->router->updateClientActionTime($client['MAC'], 'offline', true); // Reset offline action time

                        if (($clientActions['online']['telegramMessage'] ?? '') != '') {
                            if ($this->config->isDemo()) {
                                $message = "Online action is hidden in demo mode.";
                            } else {
                                $message = str_replace("{time}", $this->formatSecondsToDHIS((int)$client['OnlineStatusChanges']['onlineFor']), $clientActions['online']['telegramMessage']);
                            }

                            $message = str_replace("{name}", $clientActions['name'], $message);
                            $this->logger->addInstantLogData($message, Logger::INSTANT_LOG_EVENT_TYPE_INFO);
                            $this->telegram->sendMessage(date('Y.m.d H:i:s') . " " . $message);
                        }

                        if (($clientActions['online']['lockWorkstation'] ?? '') == 'Y') {
                            if ($this->lockWindowsPc()) {
                                if ($this->config->isDemo()) {
                                    $message = "Online action is hidden in demo mode.";
                                } else {
                                    $message = "Locking PC.";
                                }

                                $this->logger->addInstantLogData($message, Logger::INSTANT_LOG_EVENT_TYPE_INFO);
                                $this->telegram->sendMessage(date('Y.m.d H:i:s') . " " . $message);
                            }
                        }
                    }
                }

                // Offline actions
                if (!$isOnline && ((int)$client['OnlineStatusChanges']['offlineActionsPerformedAt'] < 0)) {
                    if ((int)$client['OnlineStatusChanges']['offlineFor'] >= (int)$clientActions['offline']['timeout']) {

                        $this->router->updateClientActionTime($client['MAC'], 'offline', false);
                        $this->router->updateClientActionTime($client['MAC'], 'online', true); // Reset online action time

                        if (($clientActions['offline']['telegramMessage'] ?? '') != '') {
                            if ($this->config->isDemo()) {
                                $message = "Offline action is hidden in demo mode.";
                            } else {
                                $message = str_replace("{time}", $this->formatSecondsToDHIS((int)$client['OnlineStatusChanges']['offlineFor']), $clientActions['offline']['telegramMessage']);
                            }

                            $message = str_replace("{name}", $clientActions['name'], $message);
                            $this->logger->addInstantLogData($message, Logger::INSTANT_LOG_EVENT_TYPE_INFO);
                            $this->telegram->sendMessage(date('Y.m.d H:i:s') . " " . $message);
                        }

                        if (($clientActions['offline']['lockWorkstation'] ?? '') == 'Y') {
                            if ($this->lockWindowsPc()) {
                                if ($this->config->isDemo()) {
                                    $message = date('Y.m.d H:i:s') . " Offline action is hidden in demo mode.";
                                } else {
                                    $message = date('Y.m.d H:i:s') . " Locking PC.";
                                }
                                $this->telegram->sendMessage($message);
                                $this->logger->addInstantLogData("Locking PC.", Logger::INSTANT_LOG_EVENT_TYPE_INFO);
                            }
                        }

                    }
                }
            }
        }
    }

    /**
     * Locks the Windows PC if it is currently unlocked.
     *
     * This method uses a PowerShell script to check the lock status of the PC
     * and locks it if it is not already locked.
     *
     * @return bool
     */
    private function lockWindowsPc(): bool
    {
        $output = shell_exec('quser');

        if (str_contains(strtolower($output), 'active')) {
            shell_exec('rundll32.exe user32.dll,LockWorkStation');
            return true;
        } else {
            return false;
        }
    }

    /**
     * Formats seconds into a string in the format "D.HH:MM:SS".
     *
     * @param int $seconds The number of seconds to format.
     * @return string The formatted time string.
     */
    private function formatSecondsToDHIS(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%d.%02d:%02d:%02d', $days, $hours, $minutes, $secs);
    }


}
