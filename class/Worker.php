<?php

namespace Sv\Network\VmsRtbw;

/**
 * Class Worker
 *
 * This class is responsible for managing global initialization, periodic steps,
 * and handling exceptions for the VMS RTBW system.
 */
class Worker
{
    /**
     * Global initialization method.
     *
     * Initializes the router with the necessary connections and configurations.
     * If $utility_start is true, providers' data is also initialized.
     *
     * @param Router|null $router         The router object to initialize (passed by reference).
     * @param Config $config         Configuration object containing application settings (passed by reference).
     * @param Screen $screen         The screen object for UI-related actions (passed by reference).
     * @param bool $utility_start         Indicates whether to initialize providers' data.
     * @param Telegram $telegram The telegram object for Telegram integration.
     * @return void
     */
    public function globalInit(?Router &$router, Config $config, Screen $screen, Telegram $telegram, bool &$utility_start): void
    {
        // Create new connections to router and repeater
        $connectionToRouter = new Connection();
        $connectionToRepeater = new Connection();

        // Initialize the router with the necessary parameters
        $router = new Router();
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
     * @param Router $router         The router object to update (passed by reference).
     * @param Config $config         Configuration object containing application settings (passed by reference).
     * @param Screen $screen         The screen object for UI-related actions (passed by reference).
     *
     * @return void
     */
    public function globalStep(Router $router, Config $config, Screen $screen): void
    {
        // Refresh various data sources in the router
        $router->refreshAdaptersData();
        $router->refreshProvidersData();
        $router->refreshHardwareData();
        $router->refreshStats();

        // Update the screen with the refreshed data
        $screen->drawScreen($router->getProvidersData(), $router->getHardwareData());

        // Pause execution for the configured refresh rate
        $sleepTime = $config->getNestedParameter('settings', 'refreshRate');
        if ($config->getNestedParameter('settings', 'checkForKeyboardEvents') === 'Y') {
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
     * @param Screen $screen  The screen object to clear and display messages.
     * @param Config $config  Configuration object containing application settings.
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
}
