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
if (is_readable($customConfigPath)) {
    $config->readConfigData($customConfigPath);
} else {
    $config->readConfigData($defaultConfigPath);
}

// Check for default configuration and exit if unchanged
if ($config->getConfigData()['providers']['provider'][0]['providerName'] === 'Provider1') {
    echo "Please update the configuration file at line 12 to your specific settings or modify 'ASUS_Router_Monitoring.xml_sample'.\n";
    exit(-1);
}

// Set demo mode based on argument
$demoMode = isset($argv[1]) && $argv[1] === 'demo';
$config->updateNestedParameter('settings', 'demo', $demoMode);

// Initialize logger and screen
$logger = new Logger($config);
$screen = new Screen($config, $logger);
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

// Check and adjust logger settings
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
            $worker->globalInit($router, $config, $screen, $utilityStart);
            while (true) {
                $worker->globalStep($router, $config, $screen);
            }
        } catch (Exception $e) {
            $worker->refreshAfterException($screen, $config);
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
            $worker->globalInit($router, $config, $screen, $utilityStart);

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
                    $worker->globalInit($router, $config, $screen, $utilityStart);
                }

                $worker->globalStep($router, $config, $screen, $utilityStart);
            }
        } catch (Exception $e) {
            $worker->refreshAfterException($screen, $config);
        }
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty sane');
        fclose($stdin);
    }
}
