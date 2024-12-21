<?php

namespace Sv\Network\VmsRtbw;

require __DIR__ . '/' . 'lib/vendor/autoload.php';
require __DIR__ . '/' . 'class/autoload.php';

use Exception;

// Getting all settings
$config = new Config();
$expectedCustomConfigPath = __DIR__ . '/../' . 'ASUS_Router_Monitoring.xml';
if (is_readable($expectedCustomConfigPath)) {
    $config->readConfigData($expectedCustomConfigPath);
} else {
    $config->readConfigData(__DIR__ . '/ASUS_Router_Monitoring.xml_sample');
}

if ($config->getConfigData()['providers']['provider'][0]['providerName'] == 'Provider1') {
    echo "Change the path in the 12th line to your own config path. Or at least edit data in 'ASUS_Router_Monitoring.xml_sample' :).\n";
    exit(-1);
}

// Hide IPs and providers' names in demo mode
if (isset($argv[1]) && $argv[1] == 'demo') {
    $config->updateNestedParameter('settings', 'demo', true);
} else {
    $config->updateNestedParameter('settings', 'demo', false);
}

// Preparing logger
$logger = new Logger($config);

// Preparing screen
$screen = new Screen($config, $logger);
$screen->detectScreenParameters();
$screen->clearScreen();

// Preparing worker
$worker = new Worker();

if ($config->getNestedParameter('settings', 'demo')) {
    echo "Demo mode activated. Provider's names and IPs are hidden.\n";
}

if ($config->getParameter('isAdmin')) {
    echo "Started in admin (root) mode. Network management is available (but not yet implemented :) ).\n";
} else {
    echo "Started in user mode. Network management is not available (no worries, it is not yet implemented :) ).\n";
}

if ($config->getNestedParameter('settings', 'checkForKeyboardEvents') !== 'Y') {
    echo "Keyboard events are disabled. Good.\n";
} else {
    echo "Keyboard events are enabled. The Hell will break loose now.\n";
}

if ($this->config['settings']['showDetailedDevicesData'] !== 'Y') {
    echo "Detailed devices data is disabled. It is recommended to enable (unless you have compatibility issues).\n";
} else {
    echo "Detailed devices data is enabled. Good.\n";
}

// Check and adjust log settings
$logger->checkSettings();

echo "Screen width = " . $screen->getScreenWidth() . ", screen height = " . $screen->getScreenHeight() . ". Going to keep showing " . $screen->getStepsToShow() . " steps.\n";
echo "Going to wait for " . $config->getNestedParameter('settings', 'refreshRate') . " seconds to collect data after establishing connection to device(s)...\n";

// We have to differentiate between "utility start" and ISP "failed & recovered" (or LAN adapter reset) situations
$utility_start = true;

// Global loop. With keyboard events processing considerations.
if ($config->getNestedParameter('settings', 'checkForKeyboardEvents') !== 'Y') {
    // Normal workflow without keyboard events processing

    while (true) {
        try {
            $worker->globalInit($router, $config, $screen, $utility_start);
            while (true) {
                $worker->globalStep($router, $config, $screen, $utility_start);
            }
        } catch (Exception) {
            $worker->refreshAfterException($screen, $config);
        }
    }

} else {
    // Experimental feature: keyboard events processing
    ob_implicit_flush(true);

    // Platform-specific setup for key detection
    if (PHP_OS_FAMILY !== 'Windows') {
        // Linux/macOS: Set terminal to raw mode for non-blocking key press detection
        system('stty -icanon -echo');
        $stdin = fopen("php://stdin", "r");
        stream_set_blocking($stdin, false);
    }


    while (true) {
        try {
            $worker->globalInit($router, $config, $screen, $utility_start);

            while (true) {

                if (PHP_OS_FAMILY === 'Windows') {
                    // Windows: Use 'choice' command to check for key press in a non-blocking way
                    $key = shell_exec('choice /c abcdefghijklmnopqrstuvwxyz /n /t 1 /d Z /m ""');
                } else {
                    // Linux/macOS: Read a single character from STDIN in non-blocking mode
                    system('stty -icanon -echo');
                    $stdin = fopen("php://stdin", "r");
                    stream_set_blocking($stdin, false);
                    $key = fread($stdin, 1);
                }

                if ((str_contains(($key ?? ''), 'Q')) || (str_contains(($key ?? ''), 'q'))) {
                    $screen->clearScreen();
                    echo "Quitting...\n";
                    exit(0);
                }

                if ((str_contains(($key ?? ''), 'I')) || (str_contains(($key ?? ''), 'i'))) {
                    $screen->clearScreen();
                    echo "Initializing...\n";
                    sleep(1);
                    $utility_start = true;
                    $worker->globalInit($router, $config, $screen, $utility_start);
                }

                if (PHP_OS_FAMILY !== 'Windows') {
                    system('stty sane');
                    fclose($stdin);
                }

                $worker->globalStep($router, $config, $screen, $utility_start);
            }
        } catch (Exception) {
            $worker->refreshAfterException($screen, $config);
        }
    }

}