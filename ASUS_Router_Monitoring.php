<?php

namespace Sv\Network\VmsRtbw;

require __DIR__ . '/' . 'lib/vendor/autoload.php';
require __DIR__ . '/' . 'class/autoload.php';

use DateTime;
use Exception;
use phpseclib3\Net\SSH2;

// Getting all settings
$config = Config::getConfigData(__DIR__ . '/ASUS_Router_Monitoring.xml_sample');

if ($config['providers']['provider'][0]['providerName'] == 'Provider1')
{
    echo "Sorry, guys... :) Change the path in line 13th to your own config path.\n";
    $config = Config::getConfigData(__DIR__ . '/../' . 'ASUS_Router_Monitoring.xml');
}

// Preparing screen
$screen = new Screen($config);
$screen->detectScreenParameters();
$screen->clearScreen();
echo "Started. Screen width = " . $screen->getScreenWidth() . ", screen height = " . $screen->getScreenHeight() . ". Going to keep showing " . $screen->getStepsToShow() . " steps. Waiting for " . $config['settings']['refreshRate'] . " seconds to collect data...";

// Global loop
while (true) {
    $connectionToRouter = new Connection();
    $connectionToRepeater = new Connection();
    $router = new Router();
    $router->init($connectionToRouter, $connectionToRepeater, $config, $screen->getStepsToShow());
    $router->refreshAdaptersData();
    $router->initProvidersData();
    sleep($config['settings']['refreshRate']);
    try {
        // Refreshing data loop
        while (true) {
            $router->refreshAdaptersData();
            $router->refreshProvidersData();
            $router->refreshHardwareData();
            $router->refreshStats();
            $screen->drawScreen($router->getProvidersData(), $router->getHardwareData());
            sleep($config['settings']['refreshRate']);
        }
    } catch (Exception) {
        $screen->clearScreen();
        echo "Router seems to be offline. Waiting for " . $config['settings']['refreshRate'] . " seconds to try again...";
        sleep($config['settings']['refreshRate']);
    }
}