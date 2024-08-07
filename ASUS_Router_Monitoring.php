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

if ($config->getNestedParameter('settings', 'demo')) {
    echo "Demo mode activated. Provider's names and IPs are hidden.\n";
}

if ($config->getParameter('isAdmin')) {
    echo "Started in admin (root) mode. Network management is available (but not yet implemented :) ).\n";
} else {
    echo "Started in user mode. Network management is not available (no worries, it is not yet implemented :) ).\n";
}

// Check and adjust log settings
$logger->checkSettings();

echo "Screen width = " . $screen->getScreenWidth() . ", screen height = " . $screen->getScreenHeight() . ". Going to keep showing " . $screen->getStepsToShow() . " steps.\n";
echo "Going to wait for " . $config->getNestedParameter('settings', 'refreshRate') . " seconds to collect data after establishing connection to device(s)...\n";
// Global loop
while (true) {
    $connectionToRouter = new Connection();
    $connectionToRepeater = new Connection();
    $router = new Router();
    $router->init($connectionToRouter, $connectionToRepeater, $config, $screen->getStepsToShow());
    $router->refreshAdaptersData();
    $router->initProvidersData();
    sleep($config->getNestedParameter('settings', 'refreshRate'));
    try {
        // Refreshing data loop
        while (true) {
            $router->refreshAdaptersData();
            $router->refreshProvidersData();
            $router->refreshHardwareData();
            $router->refreshStats();
            $screen->drawScreen($router->getProvidersData(), $router->getHardwareData());
            sleep($config->getNestedParameter('settings', 'refreshRate'));
        }
    } catch (Exception) {
        $screen->clearScreen();
        echo "Router seems to be offline. Waiting for " . $config->getNestedParameter('settings', 'refreshRate') . " seconds to try again...\n";
        sleep($config->getNestedParameter('settings', 'refreshRate'));
    }
}