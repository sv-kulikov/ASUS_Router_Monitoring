<?php

namespace Sv\Network\VmsRtbw;

use DateTime;

class Screen
{
    private int $screenWidth;
    private int $screenHeight;
    private int $stepsToShow;
    private int $oneProviderWidth;
    public const int TIME_STAMP_LENGTH_WITH_SPACE = 9;
    public const int SPEED_LENGTH_WITH_SPACE = 12;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
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

            $this->stepsToShow = floor(($this->screenHeight - 20) / 2);
            $this->oneProviderWidth = floor($this->screenWidth / ($providersCount + 1));
        }
    }

    private function getColoredText(string $text, Color $color): string
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
        echo chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J';
    }


    private function getBar($directionLetter, $speedValue, $globalMaxSpeed, $oneProviderWidth, $speedLengthWithSpace, $paddingSpaces, $color, $barColor = Color::DARK_GRAY): string
    {
        $perc = $speedValue / $globalMaxSpeed;
        $labelToShow = $directionLetter . ' ' . str_pad($this->formatBytes($speedValue) . '/s', $speedLengthWithSpace);
        $graphSymbolsCountActive = floor($perc * ($oneProviderWidth - strlen($labelToShow) - 1));
        $graphSymbolsCountActive = max($graphSymbolsCountActive, 0);
        $lineToShow = $labelToShow . str_repeat('▒', $graphSymbolsCountActive);
        $blankSymbolsCount = max($oneProviderWidth - mb_strlen($lineToShow) - 1, 0);
        return $this->getColoredText($lineToShow, $color) .
            $this->getColoredText(str_repeat('░', $blankSymbolsCount), $barColor) .
            str_repeat(' ', $paddingSpaces);

        // █ ▓ ▒
    }

    function getLineWithoutGraph($directionLetter, $speedValue, $speedLengthWithSpace, $paddingSpaces, $color): string
    {
        $labelToShow = $directionLetter . ' ' . str_pad($this->formatBytes($speedValue) . '/s', $speedLengthWithSpace);
        $labelToShow = str_pad($labelToShow, $this->oneProviderWidth);
        return $this->getColoredText($labelToShow, $color) .
            str_repeat(' ', $paddingSpaces);
    }

    function getLineWithTotalTraffic($directionLetter, $trafficValue, $totalTrafficValue, $idleCount, $daysSinceStart, $speedLengthWithSpace, $paddingSpaces, $color): string
    {
        $labelToShow = $directionLetter . ' ' . str_pad($this->formatBytes($trafficValue, 3), $speedLengthWithSpace);

        $trafficPerDay = str_pad((string)$this->formatBytes((int)($totalTrafficValue / $daysSinceStart), 3), 10);

        if (($trafficValue != $totalTrafficValue) && ($totalTrafficValue > 0)) {
            $perc = round(($trafficValue / $totalTrafficValue) * 100, 2);
            $labelToShow .= str_pad('(' . $perc . ' %)', 10, ' ') . ' (idle ' . $idleCount . ')';
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

    public function drawScreen(array $providers, array $hardware): void
    {
        // Move cursor to the upper left corner
        echo chr(27) . chr(91) . 'H';

        // Print providers names
        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {

            if ($this->config['settings']['demo'] && $providerData['providerName'] != 'TOTAL') {
                $providerData['ip'] = '***.***.***.***';
                $providerData['providerName'] = 'Provider';
            }

            if ($providerData['ip'] != '') {
                $providerNameWithData = $providerData['providerName'];
                $providerNameWithData .= ' (' . $providerData['ip'];
                if ($providerData['ddns'] ?? false) {
                    $providerNameWithData .= ', DDNS';
                }
                $providerNameWithData .= ')';
                $providerNameWithData .= ' {' . $providerData['ipChanges'] . '}';
            } else {
                $providerNameWithData = $providerData['providerName'];
            }

            if ($providerData['providerName'] == 'TOTAL') {
                $providerNameWithData = 'TOTAL (';
                foreach ($hardware as $hardwareItem) {
                    $providerNameWithData .= $hardwareItem['cpu_temp'] . '°C, ';
                }
                $providerNameWithData = substr($providerNameWithData, 0, -2) . ')';
            }

            if ($providerData['isOffline']) {
                echo $this->getColoredText(str_pad($providerNameWithData, $this->oneProviderWidth, ' ', STR_PAD_BOTH), Color::LIGHT_RED);
            } else {
                echo $this->getColoredText(str_pad($providerNameWithData, $this->oneProviderWidth, ' ', STR_PAD_BOTH), Color::LIGHT_GREEN);
            }
        }
        echo "\n";

        // Print providers RX/TX speeds
        $haveLines = min(count($providers['TOTAL']['speedRX']), count($providers['TOTAL']['speedTX']));

        for ($i = 0; $i < $haveLines; $i++) {
            echo $this->getColoredText(date('H:i:s', time() - (($haveLines - $i - 1) * $this->config['settings']['refreshRate'])), Color::LIGHT_GRAY) . ' ';
            foreach ($providers as $providerData) {
                if ($providerData['speedRX'][$i] > 0) {
                    echo $this->getBar('R', $providerData['speedRX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA, Color::DARK_GRAY);
                } else {
                    if ($providerData['isOffline']) {
                        echo $this->getBar('R', $providerData['speedRX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA, Color::LIGHT_RED);
                    } else {
                        echo $this->getBar('R', $providerData['speedRX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA, Color::YELLOW);
                    }
                }
            }

            echo "\n";

            echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE);
            foreach ($providers as $providerData) {
                if ($providerData['speedTX'][$i] > 0) {
                    echo $this->getBar('T', $providerData['speedTX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_CYAN, Color::DARK_GRAY);
                } else {
                    if ($providerData['isOffline']) {
                        echo $this->getBar('T', $providerData['speedTX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_CYAN, Color::LIGHT_RED);
                    } else {
                        echo $this->getBar('T', $providerData['speedTX'][$i], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_CYAN, Color::YELLOW);
                    }
                }
            }
            echo "\n";
        }

        echo str_repeat(' ', $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);
        echo "\n";

        // Printing current MIN/MAX/AVG
        echo $this->getColoredText(str_pad('MIN', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::RED);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['minRXlast'], $providerData['minRX']);
            echo $this->getBar('R', $providerData['minRX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        echo "\n";

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['minTXlast'], $providerData['minTX']);
            echo $this->getBar('T', $providerData['minTX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

        echo "\n";

        echo $this->getColoredText(str_pad('MAX', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::GREEN);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['maxRXlast'], $providerData['maxRX']);
            echo $this->getBar('R', $providerData['maxRX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        echo "\n";

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['maxTXlast'], $providerData['maxTX']);
            echo $this->getBar('T', $providerData['maxTX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

        echo "\n";

        echo $this->getColoredText(str_pad('AVG', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::WHITE);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['avgRXlast'], $providerData['avgRX']);
            echo $this->getBar('R', $providerData['avgRX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        echo "\n";

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['avgTXlast'], $providerData['avgTX']);
            echo $this->getBar('T', $providerData['avgTX'], $providers['TOTAL']['globalMaxSpeed'], $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

        echo "\n";
        echo str_repeat(' ', $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);
        echo "\n";

        // Printing overall MIN/MAX/AVG
        $currentDateTime = new DateTime();
        $diff = $this->config['globalStartDateTime']->diff($currentDateTime);

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $ignored) {
            echo $this->getColoredText(str_pad('For the last ' . $diff->format('%D d %H:%I:%S'), $this->oneProviderWidth + 1, ' ', STR_PAD_BOTH), Color::LIGHT_GREEN);
        }

        echo "\n";

        echo $this->getColoredText(str_pad('MIN', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::RED);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['globalMinRXLast'], $providerData['globalMinRX']);
            echo $this->getLineWithoutGraph('R', $providerData['globalMinRX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_MAGENTA);
        }

        echo "\n";

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['globalMinTXLast'], $providerData['globalMinTX']);
            echo $this->getLineWithoutGraph('T', $providerData['globalMinTX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_CYAN);
        }

        echo "\n";

        echo $this->getColoredText(str_pad('MAX', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::GREEN);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['globalMaxRXLast'], $providerData['globalMaxRX']);
            echo $this->getLineWithoutGraph('R', $providerData['globalMaxRX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_MAGENTA);
        }

        echo "\n";

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['globalMaxTXLast'], $providerData['globalMaxTX']);
            echo $this->getLineWithoutGraph('T', $providerData['globalMaxTX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_CYAN);
        }

        echo "\n";

        echo $this->getColoredText(str_pad('AVG', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::WHITE);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['globalAvgRXLast'], $providerData['globalAvgRX']);
            echo $this->getLineWithoutGraph('R', $providerData['globalAvgRX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_MAGENTA);
        }

        echo "\n";

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $providerData) {
            echo $this->getArrowByValues($providerData['globalAvgTXLast'], $providerData['globalAvgTX']);
            echo $this->getLineWithoutGraph('T', $providerData['globalAvgTX'], Screen::SPEED_LENGTH_WITH_SPACE, 0, Color::LIGHT_CYAN);
        }

        echo "\n";
        echo str_repeat(' ', $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1);
        echo "\n";

        echo str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);
        foreach ($providers as $ignored) {
            echo $this->getColoredText(str_pad('Traffic for the last ' . $diff->format('%D d %H:%I:%S'), $this->oneProviderWidth + 1, ' ', STR_PAD_BOTH), Color::LIGHT_GREEN);
        }

        $daysSinceStart = max(1, (int) $diff->format('%D')); // max() is to make sure it is never == 0

        echo "\n";

        $currentTotalTrafficValueRx = $providers['TOTAL']['RXbytes'] - $providers['TOTAL']['RXbytesOnStart'];
        $currentTotalTrafficValueTx = $providers['TOTAL']['TXbytes'] - $providers['TOTAL']['TXbytesOnStart'];

        echo $this->getColoredText(str_pad('Recv.', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::LIGHT_MAGENTA);
        foreach ($providers as $providerData) {
            echo $this->getLineWithTotalTraffic(' R', $providerData['RXbytes'] - $providerData['RXbytesOnStart'], $currentTotalTrafficValueRx, $providerData['idleRXcount'], $daysSinceStart, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_MAGENTA);
        }

        echo "\n";

        echo $this->getColoredText(str_pad('Trsm.', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1), Color::LIGHT_CYAN);
        foreach ($providers as $providerData) {
            echo $this->getLineWithTotalTraffic(' T', $providerData['TXbytes'] - $providerData['TXbytesOnStart'], $currentTotalTrafficValueTx, $providerData['idleTXcount'], $daysSinceStart, Screen::SPEED_LENGTH_WITH_SPACE, 1, Color::LIGHT_CYAN);
        }

    }

}