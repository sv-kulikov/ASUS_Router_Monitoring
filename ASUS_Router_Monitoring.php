<?php

namespace Sv\Network\VmsRtbw;

require __DIR__ . '/lib/vendor/autoload.php';
require __DIR__ . '/class/autoload.php';

use Exception;

// Load configuration
$config = new Config();
$customConfigPath = __DIR__ . '/../ASUS_Router_Monitoring.xml';
$defaultConfigPath = __DIR__ . '/ASUS_Router_Monitoring.xml_sample';

// Load custom or default configuration
try {
    if (is_readable($customConfigPath)) {
        $config->readConfigData($customConfigPath);
    } else {
        $config->readConfigData($defaultConfigPath);
    }
} catch (Exception $e) {
    echo "Error reading configuration file: " . $e->getMessage() . "\n";
    exit(-1);
}

// Check for default configuration and exit if unchanged
if ($config->getConfigData()['providers']['provider'][0]['providerName'] === 'Provider1') {
    echo "Please update the configuration file at line 12 to your specific settings or modify 'ASUS_Router_Monitoring.xml_sample'.\n";
    exit(-1);
}

// Process command line parameters
$config->processCommandLineParameters($argv);
$demoMode = $config->isDemo();

// Initialize logger and exception handler
$logger = new Logger($config);
set_exception_handler([$logger, 'logException']);

// Initialize Telegram integration
$telegram = new Telegram($config);

// Initialize screen
$screen = new Screen($config, $logger, $telegram);
$screen->detectScreenParameters();
$screen->clearScreen();

// Initialize worker
$worker = new Worker();

// Display demo mode status
if ($demoMode) {
    echo "Demo mode activated. Providers' names and IPs are hidden.\n";
}

// Display access mode
if ($config->getParameter('isAdmin')) {
    echo "Admin (root) mode activated. Network management might be available (but not implemented yet).\n";
} else {
    echo "User mode activated. Network management is not available (no worries, it is not yet implemented).\n";
}

// Display keyboard event handling status
$keyboardEvents = $config->getNestedParameter('settings', 'checkForKeyboardEvents') === 'Y';
if ($keyboardEvents) {
    echo "Keyboard events are enabled. All the Hell will break loose now :).\n";
} else {
    echo "Keyboard events are disabled. Good choice.\n";
}

// Display detailed devices data status
$detailedDevicesData = $config->getNestedParameter('settings', 'showDetailedDevicesData') === 'Y';
if ($detailedDevicesData) {
    echo "Detailed devices data is enabled. Good choice.\n";
} else {
    echo "Detailed devices data is disabled. Consider enabling it unless compatibility issues arise.\n";
}

// Display if Telegram messaging is enabled
if ($telegram->isTelegramEnabled()) {
    echo "Telegram messaging is enabled. Good choice.\n";
} else {
    echo "Telegram messaging is disabled. You can enable it in the configuration if needed.\n";
}

// Check and show settings for logging
$logger->checkSettings();

// Display screen parameters and refresh rate
echo "Screen width: {$screen->getScreenWidth()}, height: {$screen->getScreenHeight()}. Displaying {$screen->getStepsToShow()} steps.\n";
echo "Refresh rate: {$config->getNestedParameter('settings', 'refreshRate')} seconds.\n";
echo "Going to wait for {$config->getNestedParameter('settings', 'refreshRate')} seconds to collect data after establishing connection to device(s)...\n";

$utilityStart = true;

// Main loop
if (!$keyboardEvents) {
    // Workflow without keyboard events
    while (true) {
        try {
            $worker->globalInit($router, $config, $screen, $telegram, $utilityStart);
            while (true) {
                $worker->globalStep($router, $config, $screen);
            }
        } catch (Exception $e) {
            $utilityStart = true;
            $worker->refreshAfterException($screen, $config);
            $logger->logException($e);
        }
    }
} else {
    // Workflow with keyboard events
    ob_implicit_flush(true);

    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty -icanon -echo');
        $stdin = fopen("php://stdin", "r");
        stream_set_blocking($stdin, false);
    }

    while (true) {
        try {
            $worker->globalInit($router, $config, $screen, $telegram, $utilityStart);

            while (true) {
                // Platform-specific key press handling
                if (PHP_OS_FAMILY === 'Windows') {
                    $key = shell_exec('choice /c abcdefghijklmnopqrstuvwxyz /n /t 1 /d Z /m ""');
                } else {
                    $key = fread($stdin, 1);
                }

                // Handle keypress actions
                if (isset($key) && str_contains(strtolower($key), 'q')) {
                    $screen->clearScreen();
                    echo "Quitting...\n";
                    exit(0);
                } elseif (isset($key) && str_contains(strtolower($key), 'i')) {
                    $screen->clearScreen();
                    echo "Reinitializing...\n";
                    sleep(1);
                    $utilityStart = true;
                    $worker->globalInit($router, $config, $screen, $telegram, $utilityStart);
                }

                $worker->globalStep($router, $config, $screen);
            }
        } catch (Exception $e) {
            $worker->refreshAfterException($screen, $config);
            $logger->logException($e);
        }
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty sane');
        fclose($stdin);
    }
}
