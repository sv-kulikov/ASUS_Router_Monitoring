<?php

namespace Sv\Network\VmsRtbw;

use DateTime;

/**
 * Class Screen provides methods to draw the main screen of the application.
 */
class Screen
{
    private int $screenWidth;
    private int $screenHeight;
    private int $stepsToShow;
    private int $oneProviderWidth;
    public const int TIME_STAMP_LENGTH_WITH_SPACE = 9;
    public const int SPEED_LENGTH_WITH_SPACE = 12;
    private array $config;
    private Config $configObject;
    private array $logData;
    private Logger $logger;

    public function __construct(Config $config, Logger $logger)
    {
        $this->configObject = $config;
        $this->config = $config->getConfigData();
        $this->logger = $logger;
    }

    public function detectScreenParameters(): void
    {
        $defaultScreenWidth = $this->config['settings']['screenWidth'];
        $defaultScreenHeight = $this->config['settings']['screenHeight'];
        $providersCount = count($this->config['providers']['provider']);

        // Trying to detect REAL screen width and height
        $screenInfo = shell_exec('MODE 2> NUL') ?? '';
        if (strlen($screenInfo) > 5) {
            preg_match('/CON.*:(\n[^|]+?){3}(?<cols>\d+)/', $screenInfo, $screenWidthInfoArray);
            preg_match('/CON.*:(\n[^|]+?){2}(?<lines>\d+)/', $screenInfo, $screenHeightInfoArray);
            $this->screenWidth = $screenWidthInfoArray['cols'] - 12 ?? $defaultScreenWidth;
            $this->screenHeight = $screenHeightInfoArray['lines'] ?? $defaultScreenHeight;

            // Patch for cases when scrollable (not visible) screen height is detected.
            if ($this->screenHeight > 2000) {
                $this->screenHeight = 36;
            }

            $this->stepsToShow = floor(($this->screenHeight - 22) / 2);
            $this->oneProviderWidth = floor($this->screenWidth / ($providersCount + 1));
        }
    }

    public function getColoredText(string $text, Color $color): string
    {
        if (!stream_isatty(STDOUT)) {
            return $text;
        } else {
            return $color->value . $text . "\033[0m";
        }
    }

    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        $tmpResult = round($bytes, $precision);

        while (strrpos($tmpResult, '.') >= strlen($tmpResult) - $precision) {
            $tmpResult .= '0';
        }

        return $tmpResult . ' ' . $units[$pow];
    }

    private function getArrowByValues(int|float $prevValue, int|float $currentValue): string
    {
        if ($prevValue < $currentValue) {
            return $this->getColoredText('↑', Color::LIGHT_GREEN);
        } elseif ($prevValue > $currentValue) {
            return $this->getColoredText('↓', Color::LIGHT_RED);
        } else {
            return $this->getColoredText('=', Color::WHITE);
        }
    }

    public function clearScreen(): void
    {
        echo "\033[H\033[J";
        // \033 (or \e in some environments) represents the escape character (ESC).
        // [H moves the cursor to the "home" position (top-left of the terminal).
        // [J clears the screen from the cursor position to the end of the display.
    }

    private function getBar($directionLetter, $speedValue, $globalMaxSpeed, $oneProviderWidth, $speedLengthWithSpace, $paddingSpaces, $color, $barColor = Color::DARK_GRAY): string
    {
        // If you want, change the symbols to: █ ▓ ▒

        $perc = $speedValue / $globalMaxSpeed;
        $labelToShow = $directionLetter . ' ' . str_pad($this->formatBytes($speedValue) . '/s', $speedLengthWithSpace);

        $graphSymbolsCountActive = floor($perc * ($oneProviderWidth - strlen($labelToShow) - 1));
        $graphSymbolsCountActive = max($graphSymbolsCountActive, 0);

        $lineToShow = $labelToShow . str_repeat('▒', $graphSymbolsCountActive);
        $blankSymbolsCount = max($oneProviderWidth - mb_strlen($lineToShow) - 1, 0);

        return $this->getColoredText($lineToShow, $color) .
            $this->getColoredText(str_repeat('░', $blankSymbolsCount), $barColor) .
            str_repeat(' ', $paddingSpaces);
    }

    private function getLineWithoutGraph($directionLetter, $speedValue, $speedLengthWithSpace, $paddingSpaces, $color): string
    {
        $labelToShow = $directionLetter . ' ' . str_pad($this->formatBytes($speedValue) . '/s', $speedLengthWithSpace);
        $labelToShow = str_pad($labelToShow, $this->oneProviderWidth);

        return $this->getColoredText($labelToShow, $color) .
            str_repeat(' ', $paddingSpaces);
    }

    private function getLineWithTotalTraffic($directionLetter, $trafficValue, $totalTrafficValue, $idleCount, $daysSinceStart, $speedLengthWithSpace, $paddingSpaces, $color): string
    {
        $labelToShow = $directionLetter . ' ' . str_pad($this->formatBytes($trafficValue, 3), $speedLengthWithSpace);

        $trafficPerDay = str_pad($this->formatBytes((int)($totalTrafficValue / $daysSinceStart), 3), 10);

        if (($trafficValue != $totalTrafficValue) && ($totalTrafficValue > 0)) {
            $perc = round(($trafficValue / $totalTrafficValue) * 100, 2);
            $labelToShow .= str_pad('(' . $perc . ' %)', 10);
            if ($idleCount != -1) {
                $labelToShow .= ' (idle ' . $idleCount . ')';
            }
        } else {
            $labelToShow .= '(' . $trafficPerDay . ' / day)';
        }
        $labelToShow = str_pad($labelToShow, $this->oneProviderWidth);

        return $this->getColoredText($labelToShow, $color) . str_repeat(' ', $paddingSpaces);
    }

    public function getScreenWidth(): int
    {
        return $this->screenWidth;
    }

    public function getScreenHeight(): int
    {
        return $this->screenHeight;
    }

    public function getStepsToShow(): int
    {
        return $this->stepsToShow;
    }

    private function getProvidersBar(array $providers, array $hardware): string
    {
        $providersBar = str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);

        foreach ($providers as $providerData) {

            if ($this->config['settings']['demo'] && $providerData['providerName'] != 'TOTAL') {
                $providerData['ip'] = '***.***.***.***';
                $providerData['providerName'] = 'Provider';
            }

            $providerNameWithData = $this->getColoredText($providerData['providerName'], Color::LIGHT_GREEN);

            if (!empty($providerData['ip'])) {
                $providerNameWithData .= $this->getColoredText(' (' . $providerData['ip'], Color::LIGHT_GREEN);
                if ($providerData['ddns'] ?? false) {
                    $providerNameWithData .= ', ' . $this->getColoredText('DDNS', Color::WHITE);
                }
                $providerNameWithData .= $this->getColoredText(')', Color::LIGHT_GREEN);
                $ipChangesColor = $providerData['ipChanges'] == 0 ? Color::LIGHT_GREEN : Color::LIGHT_YELLOW;
                $providerNameWithData .= $this->getColoredText(' {' . $providerData['ipChanges'] . '}', $ipChangesColor);
            }

            if ($providerData['providerName'] === 'TOTAL' && $this->config['settings']['showDetailedDevicesData'] === 'N') {
                $providerNameWithData = '';
                foreach ($hardware as $hardwareItem) {
                    $tempColor = $hardwareItem['cpu_temp_max'] >= 60 ? Color::LIGHT_YELLOW : Color::LIGHT_GREEN;
                    $loadColor = $hardwareItem['loadAverageNow'] >= 60 ? Color::LIGHT_YELLOW : Color::LIGHT_GREEN;
                    $providerNameWithData .= $this->getColoredText($hardwareItem['cpu_temp'] . '°C ', $tempColor);
                    $providerNameWithData .= $this->getColoredText($hardwareItem['loadAverageNow'] . '% ', $loadColor);
                    $providerNameWithData .= $this->getColoredText($hardwareItem['uptimePretty'], Color::WHITE);
                    $providerNameWithData .= $this->getColoredText(', ', Color::LIGHT_GRAY);
                }
                $providerNameWithData = rtrim($providerNameWithData, ', ');
            }

            $providerNameWithDataNoANSI = preg_replace('/\e\[[0-9;]*m/', '', $providerNameWithData);

            if ($providerData['isOffline']) {
                $providersBar .= $this->getColoredText(str_pad($providerNameWithDataNoANSI, $this->oneProviderWidth + 1, ' ', STR_PAD_BOTH), Color::LIGHT_RED);
            } else {
                $padSpacesCount = max(0, (int)(($this->oneProviderWidth + 1 - strlen($providerNameWithDataNoANSI)) / 2));
                $providersBar .= str_repeat(' ', $padSpacesCount) . $providerNameWithData . str_repeat(' ', $padSpacesCount);
            }
        }

        return $providersBar;
    }

    private function getProvidersRxTxSpeeds(array $providers): string
    {
        $speedsDataAsText = '';
        $haveLines = min(count($providers['TOTAL']['speedRX']), count($providers['TOTAL']['speedTX']));

        for ($i = 0; $i < $haveLines; $i++) {
            $speedsDataAsText .= $this->getColoredText(date('H:i:s', time() - (($haveLines - $i - 1) * $this->config['settings']['refreshRate'])), Color::LIGHT_GRAY) . ' ';
            foreach ($providers as $providerData) {
                if ($providerData['speedRX'][$i] > 0) {
                    $speedsDataAsText .= $this->getBar('R', $providerData['speedRX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA);
                } else {
                    if ($providerData['isOffline']) {
                        $speedsDataAsText .= $this->getBar('R', $providerData['speedRX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA, Color::LIGHT_RED);
                    } else {
                        $speedsDataAsText .= $this->getBar('R', $providerData['speedRX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA, Color::YELLOW);
                    }
                }
            }

            $speedsDataAsText .= "\n";

            $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE);
            foreach ($providers as $providerData) {
                if ($providerData['speedTX'][$i] > 0) {
                    $speedsDataAsText .= $this->getBar('T', $providerData['speedTX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_CYAN);
                } else {
                    if ($providerData['isOffline']) {
                        $speedsDataAsText .= $this->getBar('T', $providerData['speedTX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_CYAN, Color::LIGHT_RED);
                    } else {
                        $speedsDataAsText .= $this->getBar('T', $providerData['speedTX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_CYAN, Color::YELLOW);
                    }
                }
            }
            $speedsDataAsText .= "\n";
        }

        $speedsDataAsText .= str_repeat(' ', $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);
        return $speedsDataAsText;
    }

    private function getCurrentMinMaxAvgRxTxSpeeds(array $providers): string
    {
        $speedsDataAsText = $this->getColoredText(str_pad('MIN', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::RED);
        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['minRXlast'], $providerData['minRX']);
            $speedsDataAsText .= $this->getBar('R', $providerData['minRX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['minTXlast'], $providerData['minTX']);
            $speedsDataAsText .= $this->getBar('T', $providerData['minTX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= $this->getColoredText(str_pad('MAX', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::GREEN);
        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['maxRXlast'], $providerData['maxRX']);
            $speedsDataAsText .= $this->getBar('R', $providerData['maxRX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['maxTXlast'], $providerData['maxTX']);
            $speedsDataAsText .= $this->getBar('T', $providerData['maxTX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= $this->getColoredText(str_pad('AVG', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::WHITE);
        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['avgRXlast'], $providerData['avgRX']);
            $speedsDataAsText .= $this->getBar('R', $providerData['avgRX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['avgTXlast'], $providerData['avgTX']);
            $speedsDataAsText .= $this->getBar('T', $providerData['avgTX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

        $speedsDataAsText .= "\n";
        $speedsDataAsText .= str_repeat(' ', $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);
        $speedsDataAsText .= "\n";

        return $speedsDataAsText;
    }

    private function getStatsLabelLine(string $label): string
    {
        $statsLabelStripeLength = (int)$this->config['settings']['screenWidth'] - strlen($label);
        $statsLabelStripeLength = (int)($statsLabelStripeLength / 2);
        $statsLabelStripe = str_repeat('-', $statsLabelStripeLength);

        return $this->getColoredText($statsLabelStripe, Color::GREEN) .
            '  ' . $this->getColoredText($label, Color::LIGHT_GREEN) .
            '  ' . $this->getColoredText($statsLabelStripe, Color::GREEN);
    }

    private function getCumulativeMinMaxAvgRxTxSpeeds(array $providers): string
    {
        $currentDateTime = new DateTime();
        $diff = $this->config['globalStartDateTime']->diff($currentDateTime);
        $diffUtility = $this->configObject->getParameter('globalStartDateTime')->diff($currentDateTime);

        $speedsDataAsText = str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        $speedsDataAsText .= $this->getStatsLabelLine('Cumulative statistics for the last ' . $diffUtility->format('%a d %H:%I:%S') . " (since the utility start)");
        $speedsDataAsText .= "\n";

        $speedsDataAsText .= $this->getColoredText(str_pad('MIN', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::RED);

        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['globalMinRXLast'], $providerData['globalMinRX']);
            $speedsDataAsText .= $this->getLineWithoutGraph('R', $providerData['globalMinRX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_MAGENTA);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);

        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['globalMinTXLast'], $providerData['globalMinTX']);
            $speedsDataAsText .= $this->getLineWithoutGraph('T', $providerData['globalMinTX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_CYAN);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= $this->getColoredText(str_pad('MAX', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::GREEN);

        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['globalMaxRXLast'], $providerData['globalMaxRX']);
            $speedsDataAsText .= $this->getLineWithoutGraph('R', $providerData['globalMaxRX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_MAGENTA);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);

        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['globalMaxTXLast'], $providerData['globalMaxTX']);
            $speedsDataAsText .= $this->getLineWithoutGraph('T', $providerData['globalMaxTX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_CYAN);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= $this->getColoredText(str_pad('AVG', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::WHITE);

        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['globalAvgRXLast'], $providerData['globalAvgRX']);
            $speedsDataAsText .= $this->getLineWithoutGraph('R', $providerData['globalAvgRX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_MAGENTA);
        }

        $speedsDataAsText .= "\n";

        $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);

        foreach ($providers as $providerData) {
            $speedsDataAsText .= $this->getArrowByValues($providerData['globalAvgTXLast'], $providerData['globalAvgTX']);
            $speedsDataAsText .= $this->getLineWithoutGraph('T', $providerData['globalAvgTX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_CYAN);
        }

        $speedsDataAsText .= "\n";
        $speedsDataAsText .= str_repeat(' ', $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);
        $speedsDataAsText .= "\n";

        $speedsDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);

        return $speedsDataAsText;
    }

    private function getCumulativeTraffic(array $providers, array $hardware): string
    {
        $currentDateTime = new DateTime();
        $diffUtility = $this->configObject->getParameter('globalStartDateTime')->diff($currentDateTime);
        $diffRouter = $hardware['router']['routerStartDateTime']->diff($currentDateTime);

        $trafficDataAsText = $this->getStatsLabelLine('Cumulative traffic for the last ' . $diffUtility->format('%a d %H:%I:%S') . " (since the utility start) and " . $diffRouter->format('%a d %H:%I:%S') . " (since the router start)");

        $daysSinceUtilityStart = max(1, (int)$diffUtility->format('%a')); // max() is to make sure it is never == 0
        $daysSinceRouterStart = max(1, (int)$diffRouter->format('%a')); // max() is to make sure it is never == 0


        // Utility
        $trafficDataAsText .= "\n";

        $trafficDataAsText .= $this->getColoredText(str_pad('U.Recv.', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::LIGHT_MAGENTA);
        foreach ($providers as $providerData) {
            $trafficDataAsText .= $this->getLineWithTotalTraffic(' R', $providerData['RXbytesAccumulated'], $providers['TOTAL']['RXbytesAccumulated'], $providerData['idleRXcount'], $daysSinceUtilityStart, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        $trafficDataAsText .= "\n";

        $trafficDataAsText .= $this->getColoredText(str_pad('U.Trsm.', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::LIGHT_CYAN);
        foreach ($providers as $providerData) {
            $trafficDataAsText .= $this->getLineWithTotalTraffic(' T', $providerData['TXbytesAccumulated'], $providers['TOTAL']['TXbytesAccumulated'], $providerData['idleTXcount'], $daysSinceUtilityStart, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

        // Router
        $trafficDataAsText .= "\n";

        $trafficDataAsText .= $this->getColoredText(str_pad('R.Recv.', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::MAGENTA);
        foreach ($providers as $providerData) {
            $trafficDataAsText .= $this->getLineWithTotalTraffic(' R', $providerData['RXbytes'], $providers['TOTAL']['RXbytes'], -1, $daysSinceRouterStart, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::MAGENTA);
        }

        $trafficDataAsText .= "\n";

        $trafficDataAsText .= $this->getColoredText(str_pad('R.Trsm.', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::CYAN);
        foreach ($providers as $providerData) {
            $trafficDataAsText .= $this->getLineWithTotalTraffic(' T', $providerData['TXbytes'], $providers['TOTAL']['TXbytes'], -1, $daysSinceRouterStart, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::CYAN);
        }

        return $trafficDataAsText;
    }

    private function getDevicesData(array $hardware): string
    {
        $devicesDataAsText = "\n";
        $devicesDataAsText .= str_repeat(' ', $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);
        $devicesDataAsText .= "\n";
        $devicesDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        $devicesDataAsText .= $this->getStatsLabelLine('Devices information');
        $devicesDataAsText .= "\n";
        $devicesDataAsText .= str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);


        for ($i = 1; $i <= 3; $i++) {
            $devicesDataAsText .= $this->getColoredText('WAN' . $i . ' ', Color::LIGHT_GRAY);
        }

        $devicesDataAsText .= '   ';
        for ($i = 1; $i <= 8; $i++) {
            $devicesDataAsText .= $this->getColoredText('LAN' . $i . ' ', Color::LIGHT_GRAY);
        }

        $devicesDataAsText .= '   ';
        $devicesDataAsText .= $this->getColoredText('CLIENTS ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('TEMPERATURE ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('LOAD1 ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('LOAD5 ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('LOAD15 ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('UPTIME      ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('TRAFFIC  ', Color::LIGHT_GRAY);

        $devicesDataAsText .= "\n";

        $logData = [];

        // Print ports data
        foreach ($hardware as $hardwareData) {
            $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['deviceName'], Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1), Color::WHITE);

            $portsProcessed = 0;
            foreach ($hardwareData['hooksResults']['get_wan_lan_status()']['get_wan_lan_status']['portSpeed'] as $portName => $portSpeed) {
                if (!str_contains($portName, 'LAN')) {
                    $devicesDataAsText .= $this->portStateToColoredLabel($portSpeed) . ' ';
                    $portsProcessed++;
                    $logData[$hardwareData['deviceName'] . '_WAN_' . $portsProcessed] = $this->portStateToLabel($portSpeed);
                }
            }

            if ($portsProcessed < 3) {
                $devicesDataAsText .= str_repeat(' ', 5);
            }

            $devicesDataAsText .= '   ';

            $portsProcessed = 0;
            foreach ($hardwareData['hooksResults']['get_wan_lan_status()']['get_wan_lan_status']['portSpeed'] as $portName => $portSpeed) {
                if (str_contains($portName, 'LAN')) {
                    $devicesDataAsText .= $this->portStateToColoredLabel($portSpeed) . ' ';
                    $portsProcessed++;
                    $logData[$hardwareData['deviceName'] . '_LAN_' . $portsProcessed] = $this->portStateToLabel($portSpeed);
                }
            }

            $devicesDataAsText .= '   ';

            // Print clients data
            $clientsList = $hardwareData['hooksResults']['get_allclientlist()']['get_allclientlist'] ?? [];
            $clientsList = reset($clientsList);
            $clientsCount = count($clientsList['wired_mac'] ?? []);
            $devicesDataAsText .= $this->getColoredText(str_pad($clientsCount, 8), Color::WHITE);
            $logData[$hardwareData['deviceName'] . '_CLIENTS'] = $clientsCount;

            // Print temperature data
            if ($hardwareData['cpu_temp_max'] >= 60) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpu_temp'] . '°C', 13), Color::LIGHT_YELLOW);
            } else {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpu_temp'] . '°C', 13), Color::LIGHT_GREEN);
            }

            $logData[$hardwareData['deviceName'] . '_CPU_TEMP_MIN'] = $hardwareData['cpu_temp_min'];
            $logData[$hardwareData['deviceName'] . '_CPU_TEMP_MAX'] = $hardwareData['cpu_temp_max'];


            // Print load data
            if ($hardwareData['loadAverage1iPerc'] >= 60) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['loadAverage1iPerc'] . '%', 6), Color::LIGHT_YELLOW);
            } else {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['loadAverage1iPerc'] . '%', 6), Color::LIGHT_GREEN);
            }
            $logData[$hardwareData['deviceName'] . '_LOAD_1'] = $hardwareData['loadAverage1iPerc'];

            if ($hardwareData['loadAverage5iPerc'] >= 60) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['loadAverage5iPerc'] . '%', 6), Color::LIGHT_YELLOW);
            } else {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['loadAverage5iPerc'] . '%', 6), Color::LIGHT_GREEN);
            }
            $logData[$hardwareData['deviceName'] . '_LOAD_5'] = $hardwareData['loadAverage5iPerc'];

            if ($hardwareData['loadAverage15iPerc'] >= 60) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['loadAverage15iPerc'] . '%', 7), Color::LIGHT_YELLOW);
            } else {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['loadAverage15iPerc'] . '%', 7), Color::LIGHT_GREEN);
            }
            $logData[$hardwareData['deviceName'] . '_LOAD_15'] = $hardwareData['loadAverage15iPerc'];

            // Print uptime data
            $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['uptimePrettyLong'], 12), Color::WHITE);
            $logData[$hardwareData['deviceName'] . '_UPTIME'] = $hardwareData['uptimePrettyLong'];
            $logData[$hardwareData['deviceName'] . '_UPTIME_SECONDS'] = (int)$hardwareData['uptime'];

            // Print traffic
            $devicesDataAsText .= $this->getColoredText(str_pad($this->formatBytes($hardwareData['totalTraffic'], 2), 10), Color::WHITE);
            $logData[$hardwareData['deviceName'] . '_TRAFFIC'] = $hardwareData['totalTraffic'];

            $devicesDataAsText .= "\n";
        }

        $this->logData = $logData;
        return $devicesDataAsText;
    }

    public function drawScreen(array $providers, array $hardware): void
    {
        // Move cursor to the upper left corner
        echo chr(27) . chr(91) . 'H';

        // Print providers names
        echo $this->getProvidersBar($providers, $hardware);
        echo "\n";

        // Print providers RX/TX speeds
        echo $this->getProvidersRxTxSpeeds($providers);
        echo "\n";

        // Printing current MIN/MAX/AVG
        if ($this->config['settings']['showCurrentMinMaxAvg'] == 'Y') {
            echo $this->getCurrentMinMaxAvgRxTxSpeeds($providers);
        }

        // Printing overall MIN/MAX/AVG
        echo $this->getCumulativeMinMaxAvgRxTxSpeeds($providers);

        // Printing overall traffic
        echo $this->getCumulativeTraffic($providers, $hardware);

        // Printing devices data
        if ($this->config['settings']['showDetailedDevicesData'] == 'Y') {
            echo $this->getDevicesData($hardware);
        }

        // Logging data
        if ($this->config['settings']['logData'] == 'Y') {
            $this->logger->logData($providers, $this->logData);
        }
    }

    private function portStateToColoredLabel(string $portState): string
    {
        return match ($portState) {
            'M' => $this->getColoredText('100M', Color::GREEN),
            'G' => $this->getColoredText('1.0G', Color::LIGHT_GREEN),
            'Q' => $this->getColoredText('2.5G', Color::LIGHT_CYAN),
            'F' => $this->getColoredText('5.0G', Color::LIGHT_CYAN),
            'T' => $this->getColoredText('10.G', Color::LIGHT_CYAN),
            'X' => $this->getColoredText('----', Color::LIGHT_GRAY),
            default => $this->getColoredText('????', Color::LIGHT_YELLOW),
        };
    }

    private function portStateToLabel(string $portState): string
    {
        return match ($portState) {
            'M' => '100M',
            'G' => '1.0G',
            'Q' => '2.5G',
            'F' => '5.0G',
            'T' => '10.G',
            'X' => '----',
            default => '????',
        };
    }

    // For now ASUS routers most times fail with accurate clients description.
    // This method is for the future. Hopefully, someday with new firmware, this router's
    // feature will work.
    /*
    private function getClientsCount(array $clientsData): array
    {
        $totalClients = 0;
        $wiredClients = 0;
        $wifiClients = 0;
        foreach ($clientsData as $clientName => $clientData) {
            if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $clientName) === 1) {
                if ((int)($clientData['isOnline'] ?? 0) > 0) {
                    $totalClients++;
                    if ((int)($clientData['isWL'] ?? 0) > 0) {
                        $wiredClients++;
                        echo $clientData['vendor'];
                    }
                    if ((int)($clientData['isGN'] ?? 0) > 0) {
                        $wiredClients++;
                    }
                }
            }
        }

        return array('totalClients' => $totalClients, 'wiredClients' => $wiredClients, 'wifiClients' => $wifiClients);
    }
    */

}