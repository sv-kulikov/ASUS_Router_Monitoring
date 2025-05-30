<?php

namespace Sv\Network\VmsRtbw;

use DateTime;

/**
 * Class Screen provides methods to draw the main screen of the application.
 */
class Screen
{
    /**
     * @var int The width of the screen in characters.
     */
    private int $screenWidth;

    /**
     * @var int The height of the screen in characters.
     */
    private int $screenHeight;

    /**
     * @var int The number of steps to show on the screen.
     *
     * This is calculated based on the screen height and is used to determine how many 'refresh steps' can be displayed.
     */
    private int $stepsToShow;

    /**
     * @var int The width allocated for each provider on the screen.
     *
     * This is calculated based on the total screen width and the number of providers.
     */
    private int $oneProviderWidth;

    /**
     * Constants defining the timestamp length of each 'refresh step' on the screen.
     */
    public const int TIME_STAMP_LENGTH_WITH_SPACE = 9;

    /**
     * Constants defining the length of the speed display, including space for formatting.
     *
     * This is used to ensure that speed values are displayed consistently across the screen.
     */
    public const int SPEED_LENGTH_WITH_SPACE = 12;

    /**
     * @var array Configuration data.
     *
     * This includes settings such as screen width, height, and other display parameters.
     * It also contains general application settings and provider configurations.
     */
    private array $config;

    /**
     * @var Config The configuration object.
     *
     * This object is used to access configuration parameters and settings throughout the application.
     */
    private Config $configObject;

    /**
     * @var array Log data to be saved to log file.
     *
     * This array holds all data that needs to be saved to the log.
     */
    private array $logData;

    /**
     * @var Logger The logger object for logging messages and exceptions.
     *
     * This object is used to log messages, exceptions, and other important information during the application's execution.
     */
    private Logger $logger;

    /**
     * @var Telegram The Telegram object for sending messages and updates.
     *
     * This object is used to send messages to a Telegram chat, providing real-time updates and notifications.
     */
    private Telegram $telegram;

    /**
     * @var int The timestamp of the last Telegram update.
     *
     * This is used to track the last time an update was sent to Telegram, ensuring that updates are sent at appropriate intervals.
     */
    private int $lastTelegramUpdateTimestamp = 0;

    /**
     * @var int The message ID of the last statistics message sent to Telegram.
     *
     * This is used to update the message later, allowing for real-time updates without creating new messages.
     */
    private int $lastStatisticsMsgId = 0;

    /**
     * Screen constructor.
     *
     * Initializes the screen with configuration, logger, and Telegram objects.
     *
     * @param Config $config The configuration object containing application settings.
     * @param Logger $logger The logger object for logging messages and exceptions.
     * @param Telegram $telegram The Telegram object for sending messages and updates.
     */
    public function __construct(Config $config, Logger $logger, Telegram $telegram)
    {
        $this->configObject = $config;
        $this->config = $config->getConfigData();
        $this->logger = $logger;
        $this->telegram = $telegram;
        $this->lastTelegramUpdateTimestamp = time();
    }

    /**
     * Detects the screen parameters such as width and height.
     *
     * This method uses shell commands to determine the actual screen dimensions
     * and calculates the number of steps to show based on the screen height.
     * It also calculates the width allocated for each provider based on the total screen width.
     *
     * @return void
     */
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

    /**
     * Returns the colored text for terminal output.
     *
     * This method checks if the output is a TTY (terminal) and applies ANSI color codes
     * to the text based on the provided Color enum value.
     *
     * @param string $text The text to color.
     * @param Color $color The color to apply.
     * @return string The colored text, or plain text if not in a TTY.
     */
    public function getColoredText(string $text, Color $color): string
    {
        if (!stream_isatty(STDOUT)) {
            return $text;
        } else {
            return $color->value . $text . "\033[0m";
        }
    }

    /**
     * Formats bytes into a human-readable string with appropriate units.
     *
     * @param int|float $bytes The number of bytes to format.
     * @param int $precision The number of decimal places to include in the formatted output.
     * @return string Formatted string with the size and unit.
     */
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

    /**
     * Returns an arrow symbol with color based on the comparison of two values.
     *
     * This method compares the previous value with the current value and returns
     * an arrow symbol indicating whether the current value is higher, lower, or equal,
     * along with the appropriate color.
     *
     * @param int|float $prevValue The previous value to compare.
     * @param int|float $currentValue The current value to compare.
     * @return string The colored arrow symbol.
     */
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

    /**
     * Clears the terminal screen.
     *
     * This method sends escape sequences to the terminal to clear the screen
     * and move the cursor to the top-left corner.
     *
     * @return void
     */
    public function clearScreen(): void
    {
        echo "\033[H\033[J";
        // \033 (or \e in some environments) represents the escape character (ESC).
        // [H moves the cursor to the "home" position (top-left of the terminal).]
        // [J clears the screen from the cursor position to the end of the display.]
    }


    /**
     * Returns a bar graph representation of the speed for a given direction.
     *
     * This method generates a string that represents the speed as a bar graph,
     * including the direction letter, formatted speed value, and padding spaces.
     *
     * @param string $directionLetter The letter representing the direction (e.g., 'R' for RX, 'T' for TX).
     * @param int|float $speedValue The speed value to display.
     * @param int $globalMaxSpeed The maximum speed across all providers.
     * @param int $oneProviderWidth The width allocated for one provider on the screen.
     * @param int $speedLengthWithSpace The length of the speed value including space.
     * @param int $paddingSpaces The number of spaces to pad after the line.
     * @param Color $color The color to apply to the text.
     * @param Color $barColor The color of the bar background (default is dark-gray).
     * @return string The formatted bar graph line.
     */
    private function getBar(string $directionLetter, int|float $speedValue, int|float $globalMaxSpeed, int $oneProviderWidth, int $speedLengthWithSpace, int $paddingSpaces, Color $color, Color $barColor = Color::DARK_GRAY): string
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

    /**
     * Returns a line without a graph for the specified direction and speed.
     *
     * This method formats the speed value into a string with the direction letter
     * and pads it to fit within the allocated width for one provider.
     *
     * @param string $directionLetter The letter representing the direction (e.g., 'R' for RX, 'T' for TX).
     * @param int|float $speedValue The speed value to format.
     * @param int $speedLengthWithSpace The length of the speed value including space.
     * @param int $paddingSpaces The number of spaces to pad after the line.
     * @param Color $color The color to apply to the text.
     * @return string The formatted line without a graph.
     */
    private function getLineWithoutGraph(string $directionLetter, int|float $speedValue, int $speedLengthWithSpace, int $paddingSpaces, Color $color): string
    {
        $labelToShow = $directionLetter . ' ' . str_pad($this->formatBytes($speedValue) . '/s', $speedLengthWithSpace);
        $labelToShow = str_pad($labelToShow, $this->oneProviderWidth);

        return $this->getColoredText($labelToShow, $color) .
            str_repeat(' ', $paddingSpaces);
    }

    /**
     * Returns a line with total traffic for the specified direction.
     *
     * This method formats the traffic value into a string with the direction letter,
     * total traffic value, and additional information such as idle count and traffic per day.
     *
     * @param string $directionLetter The letter representing the direction (e.g., 'R' for RX, 'T' for TX).
     * @param float|int $trafficValue The traffic value to format.
     * @param float|int $totalTrafficValue The total traffic value to format.
     * @param int $idleCount The count of idle connections.
     * @param int $daysSinceStart The number of days since the start of the utility.
     * @param int $speedLengthWithSpace The length of the speed value including space.
     * @param int $paddingSpaces The number of spaces to pad after the line.
     * @param Color $color The color to apply to the text.
     * @return string The formatted line with total traffic.
     */
    private function getLineWithTotalTraffic(string $directionLetter, float|int $trafficValue, float|int $totalTrafficValue, int $idleCount, int $daysSinceStart, int $speedLengthWithSpace, int $paddingSpaces, Color $color): string
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

    /**
     * Returns the screen width.
     *
     * This method retrieves the width of the screen in characters, which is used for formatting output.
     *
     * @return int The width of the screen in characters.
     */
    public function getScreenWidth(): int
    {
        return $this->screenWidth;
    }

    /**
     * Returns the screen height.
     *
     * This method retrieves the height of the screen in characters, which is used for formatting output.
     *
     * @return int The height of the screen in characters.
     */
    public function getScreenHeight(): int
    {
        return $this->screenHeight;
    }

    /**
     * Returns the number of steps to show on the screen.
     *
     * This method retrieves the number of 'refresh steps' that can be displayed based on the screen height.
     *
     * @return int The number of steps to show on the screen.
     */
    public function getStepsToShow(): int
    {
        return $this->stepsToShow;
    }

    /**
     * Builds a visually formatted status bar line for all VPN providers and the TOTAL summary,
     * including optional hardware and telemetry diagnostics, for console output.
     *
     * This bar is designed to display information such as:
     * - Provider name and current IP
     * - IP change count (highlighted if changes occurred)
     * - DDNS status (if active)
     * - Hardware statistics (CPU temp, load, uptime) for the TOTAL row
     * - Telegram bot status (last failure/success timestamps)
     * - Highlights offline providers with red coloring
     *
     * Behavior varies depending on configuration flags:
     * - In `demo` mode: hides real IPs and provider names
     * - If `showDetailedDevicesData` is `N`, hardware stats are displayed for the TOTAL row
     * - If `telegramStatusEnabled` is `Y`, includes timestamps for Telegram status
     *
     * The function uses ANSI-colored strings for terminal display.
     * It also aligns and pads each provider block based on `$this->oneProviderWidth`.
     *
     * @param array $providers Array of provider data, including IP, status, traffic, and flags.
     * @param array $hardware Array of hardware metrics per device (router/repeater), used in TOTAL line.
     *
     * @return string Formatted, aligned, and colorized status bar string for display in terminal output.
     */
    private function getProvidersBar(array $providers, array $hardware): string
    {
        $providersBar = str_repeat(' ', Screen::TIME_STAMP_LENGTH_WITH_SPACE - 1);

        foreach ($providers as $providerData) {

            if ($this->config['settings']['demo'] && $providerData['providerName'] != 'TOTAL') {
                $providerData['ip'] = '***.***.***.***';
                $providerData['providerName'] = 'Provider';
            }

            $providerNameWithData = $this->getColoredText($providerData['providerName'] ?? '', Color::LIGHT_GREEN);

            if (!empty($providerData['ip'])) {
                $providerNameWithData .= $this->getColoredText(' (' . $providerData['ip'], Color::LIGHT_GREEN);
                if ($providerData['ddns'] ?? false) {
                    $providerNameWithData .= ', ' . $this->getColoredText('DDNS', Color::WHITE);
                }
                $providerNameWithData .= $this->getColoredText(')', Color::LIGHT_GREEN);
                $ipChangesColor = $providerData['ipChanges'] == 0 ? Color::LIGHT_GREEN : Color::LIGHT_YELLOW;
                $providerNameWithData .= $this->getColoredText(' {' . $providerData['ipChanges'] . '}', $ipChangesColor);
            }

            if ($providerData['providerName'] === 'TOTAL') {
                if ($this->config['settings']['showDetailedDevicesData'] === 'N') {
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
                if ($this->logger->getLastExceptionDateTimeAsString() !== '') {
                    $providerNameWithData .= $this->getColoredText(' (Exc: ' . $this->logger->getLastExceptionDateTimeAsString() . ')', Color::LIGHT_YELLOW);
                }
                if ($this->config['telegram']['telegramStatusEnabled'] === 'Y') {
                    if ($this->telegram->getLastFailureTimestamp() > 0) {
                        $failureDateTime = $this->getColoredText(date('d.m H:i', $this->telegram->getLastFailureTimestamp()), Color::LIGHT_RED);
                    } else {
                        $failureDateTime = $this->getColoredText('--.-- --:--', Color::LIGHT_GRAY);
                    }
                    if ($this->telegram->getLastSuccessTimestamp() > 0) {
                        $successDateTime = $this->getColoredText(date('d.m H:i', $this->telegram->getLastSuccessTimestamp()), Color::LIGHT_GREEN);
                    } else {
                        $successDateTime = $this->getColoredText('--.-- --:--', Color::LIGHT_GRAY);
                    }
                    $providerNameWithData .= " [" . $failureDateTime . "|" . $successDateTime . "]";
                }
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

    /**
     * Returns the formatted speeds for all providers.
     *
     * This method generates a string representation of the RX and TX speeds for each provider,
     * including timestamps and colored bars to indicate speed levels.
     *
     * @param array $providers The array containing provider data with RX and TX speeds.
     * @return string The formatted speeds data as text.
     */
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
                        $speedsDataAsText .= $this->getBar('R', $providerData['speedRX'][$i] ?? 0, $providers['TOTAL']['globalMaxSpeed'] ?? 0, $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA, Color::LIGHT_RED);
                    } else {
                        $speedsDataAsText .= $this->getBar('R', $providerData['speedRX'][$i] ?? 0, $providers['TOTAL']['globalMaxSpeed'] ?? 0, $this->oneProviderWidth, Screen::SPEED_LENGTH_WITH_SPACE, 2, Color::LIGHT_MAGENTA, Color::YELLOW);
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

    /**
     * Returns the current minimum, maximum, and average RX and TX speeds for all providers.
     *
     * This method generates a string representation of the current speeds, including
     * minimum, maximum, and average values for both RX and TX directions.
     *
     * @param array $providers The array containing provider data with min, max, and avg speeds.
     * @return string The formatted speeds data as text.
     */
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

    /**
     * Returns a formatted line with the label for statistics.
     *
     * This method generates a string that includes a label for statistics,
     * formatted with stripes on both sides and colored text.
     *
     * @param string $label The label to display in the statistics line.
     * @return string The formatted statistics label line.
     */
    private function getStatsLabelLine(string $label): string
    {
        $statsLabelStripeLength = (int)$this->config['settings']['screenWidth'] - strlen($label);
        $statsLabelStripeLength = (int)round($statsLabelStripeLength / 2);
        $statsLabelStripe = str_repeat('-', $statsLabelStripeLength);

        return $this->getColoredText($statsLabelStripe, Color::GREEN) .
            '  ' . $this->getColoredText($label, Color::LIGHT_GREEN) .
            '  ' . $this->getColoredText($statsLabelStripe, Color::GREEN);
    }

    /**
     * Returns cumulative statistics for the last period, including min, max, and average RX and TX speeds.
     *
     * This method generates a string representation of cumulative statistics for all providers,
     * including minimum, maximum, and average RX and TX speeds since the utility start.
     *
     * @param array $providers The array containing provider data with global min, max, and avg speeds.
     * @return string The formatted cumulative statistics data as text.
     */
    private function getCumulativeMinMaxAvgRxTxSpeeds(array $providers): string
    {
        $currentDateTime = new DateTime();
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

    /**
     * Returns cumulative traffic statistics for the last period, including RX and TX traffic.
     *
     * This method generates a string representation of cumulative traffic statistics for all providers,
     * including RX and TX traffic since the utility start and router start.
     *
     * @param array $providers The array containing provider data with cumulative RX and TX traffic.
     * @param array $hardware The array containing hardware data with router start date/time.
     * @return string The formatted cumulative traffic data as text.
     */
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

    /**
     * Returns a formatted string with devices' data.
     *
     * This method generates a string representation of devices' information, including WAN and LAN ports,
     * CPU temperature, CPU load, memory usage, uptime, and traffic statistics.
     *
     * @param array $hardware The array containing hardware data for devices.
     * @return string The formatted devices data as text.
     */
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
        $devicesDataAsText .= $this->getColoredText('CLS ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('CPUTEMP ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('CPULOAD         ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('MEMORY      ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('UPTIME      ', Color::LIGHT_GRAY);
        $devicesDataAsText .= $this->getColoredText('TRAFFIC   ', Color::LIGHT_GRAY);

        $devicesDataAsText = str_pad($devicesDataAsText, $this->screenWidth + Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1, '*', STR_PAD_RIGHT);

        $devicesDataAsText .= "\n";

        $logData = [];

        // Print ports data
        foreach ($hardware as $hardwareData) {
            $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['deviceName'], Screen::TIME_STAMP_LENGTH_WITH_SPACE + 1), Color::WHITE);

            $portsProcessed = 0;
            foreach ($hardwareData['hooksResults']['get_wan_lan_status()']['get_wan_lan_status']['portSpeed'] as $portName => $portSpeed) {
                if ((!str_contains($portName, 'LAN')) ||
                    (($portName == 'LAN 9') && (($hardwareData['hooksResults']['get_wan_lan_status()']['get_wan_lan_status']['portCount']['lanCount'] ?? 0) > 8))) {
                    $devicesDataAsText .= $this->portStateToColoredLabel($portSpeed) . ' ';
                    $portsProcessed++;
                    $logData[$hardwareData['deviceName'] . '_WAN_' . $portsProcessed] = $this->portStateToLabel($portSpeed);
                }
            }

            if ($portsProcessed < 3) {
                $devicesDataAsText .= str_repeat('---- ', 3 - $portsProcessed);
            }

            $devicesDataAsText .= '   ';

            $portsProcessed = 0;
            foreach ($hardwareData['hooksResults']['get_wan_lan_status()']['get_wan_lan_status']['portSpeed'] as $portName => $portSpeed) {
                if (str_contains($portName, 'LAN')) {
                    if (($portName == 'LAN 9') && (($hardwareData['hooksResults']['get_wan_lan_status()']['get_wan_lan_status']['portCount']['lanCount'] ?? 0) > 8)) {
                        continue;
                    }

                    $devicesDataAsText .= $this->portStateToColoredLabel($portSpeed) . ' ';
                    $portsProcessed++;
                    $logData[$hardwareData['deviceName'] . '_LAN_' . $portsProcessed] = $this->portStateToLabel($portSpeed);
                }
            }

            $devicesDataAsText .= '   ';

            // Print clients data
            $clientsCount = $hardwareData['clientsCount'];
            $devicesDataAsText .= $this->getColoredText(str_pad($clientsCount, 4), Color::WHITE);
            $logData[$hardwareData['deviceName'] . '_CLIENTS'] = $clientsCount;

            // Print temperature data
            if ($hardwareData['cpuTempMax'] >= 80) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpuTempString'] . '°C', 9), Color::LIGHT_RED);
            } elseif ($hardwareData['cpuTempMax'] >= 60) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpuTempString'] . '°C', 9), Color::LIGHT_YELLOW);
            } else {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpuTempString'] . '°C', 9), Color::LIGHT_GREEN);
            }

            $logData[$hardwareData['deviceName'] . '_CPU_TEMP_MIN'] = $hardwareData['cpuTempMin'];
            $logData[$hardwareData['deviceName'] . '_CPU_TEMP_MAX'] = $hardwareData['cpuTempMax'];

            // Print load data

            $devicesDataAsText .= $this->getColoredText($hardwareData['cpuCoresCount'] . ': ', Color::LIGHT_GREEN);

            if ($hardwareData['cpuLoadMaxPerc'] >= 80) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpuLoadString'] . '%', 13), Color::LIGHT_RED);
            } elseif ($hardwareData['cpuLoadMinPerc'] >= 60) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpuLoadString'] . '%', 13), Color::LIGHT_YELLOW);
            } else {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['cpuLoadString'] . '%', 13), Color::LIGHT_GREEN);
            }

            $logData[$hardwareData['deviceName'] . '_CPU_MIN_PERC'] = $hardwareData['cpuLoadMinPerc'];
            $logData[$hardwareData['deviceName'] . '_CPU_MAX_PERC'] = $hardwareData['cpuLoadMaxPerc'];

            // Print memory data
            if ($hardwareData['memoryUsedPerc'] >= 80) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['memoryUsageString'], 12), Color::LIGHT_RED);
            } elseif ($hardwareData['memoryUsedPerc'] >= 60) {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['memoryUsageString'], 12), Color::LIGHT_YELLOW);
            } else {
                $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['memoryUsageString'], 12), Color::LIGHT_GREEN);
            }

            $logData[$hardwareData['deviceName'] . '_MEMORY_PERC'] = $hardwareData['memoryUsedPerc'];
            $logData[$hardwareData['deviceName'] . '_MEMORY_MB'] = $hardwareData['memoryUsed'];


            // Print uptime data
            $devicesDataAsText .= $this->getColoredText(str_pad($hardwareData['uptimePrettyLong'], 12), Color::WHITE);
            $logData[$hardwareData['deviceName'] . '_UPTIME'] = $hardwareData['uptimePrettyLong'];
            $logData[$hardwareData['deviceName'] . '_UPTIME_SECONDS'] = (int)$hardwareData['uptime'];

            // Print traffic
            $devicesDataAsText .= $this->getColoredText(str_pad($this->formatBytes($hardwareData['totalTraffic'], 2), 11), Color::WHITE);
            $logData[$hardwareData['deviceName'] . '_TRAFFIC'] = $hardwareData['totalTraffic'];

            $devicesDataAsText .= "\n";
        }

        $this->logData = $logData;
        return $devicesDataAsText;
    }

    /**
     * Draws the screen with the current statistics.
     *
     * This method clears the screen, prints the providers' names, their RX/TX speeds,
     * current and cumulative statistics, and device data if configured.
     * It also handles logging and Telegram updates if enabled.
     *
     * @param array $providers Array of provider data, including IP, status, traffic, and flags.
     * @param array $hardware Array of hardware metrics per device (router/repeater), used in TOTAL line.
     */
    public function drawScreen(array $providers, array $hardware): void
    {
        // Move the cursor to the upper-left corner
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

        // Sending Telegram updates
        if ($this->telegram->isTelegramEnabled() &&
            $this->telegram->isTelegramRealtimeEnabled()) {

            $telegramDelay = (int)$this->config['telegram']['telegramStatusPeriod'] ?? 60;

            if ($this->lastStatisticsMsgId === 0 || (time() - $this->lastTelegramUpdateTimestamp > $telegramDelay)) {
                $this->lastTelegramUpdateTimestamp = time();
                $message = $this->logger->getPrettyTelegramLogData($providers, $hardware);
                if ($this->lastStatisticsMsgId === 0) {
                    $msgId = $this->telegram->sendMessage($message, "HTML");
                    if ($msgId !== false) {
                        $this->telegram->setRealtimeStatsMsgId($msgId);
                        $this->lastStatisticsMsgId = $msgId;
                    }
                } else {
                    $this->telegram->editMessage($this->lastStatisticsMsgId, $message, "HTML");
                }
            }
        }
    }

    /**
     * Converts a port state to a colored label string.
     *
     * @param string $portState The port state code.
     * @return string The corresponding colored label.
     */
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

    /**
     * Converts a port state to a label string.
     *
     * @param string $portState The port state code.
     * @return string The corresponding label.
     */
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

}