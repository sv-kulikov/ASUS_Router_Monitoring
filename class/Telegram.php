<?php

namespace Sv\Network\VmsRtbw;

/**
 * Class Telegram handles sending messages to a Telegram chat using the Bot API.
 *
 * This class provides methods to send messages, edit existing messages, and manage
 * Telegram messaging settings such as enabling/disabling messaging and real-time updates.
 */
class Telegram
{
    /**
     * @var bool Whether Telegram messaging is enabled.
     *
     * This setting is used to control whether the application should send
     * messages to a Telegram chat, such as notifications or alerts.
     */
    private bool $telegramEnabled;

    /**
     * @var bool Whether to send statistics to Telegram in real time.
     *
     * This setting is used to control whether the application should send
     * periodic updates to Telegram, such as hardware statistics.
     */
    private bool $telegramRealtimeEnabled;

    /**
     * @var string Telegram Bot Token.
     *
     * This token is used to authenticate the bot with Telegram's Bot API.
     * It can be obtained by creating a bot via BotFather on Telegram.
     */
    private string $telegramBotToken;

    /**
     * @var string Telegram Chat ID (can be a user ID or group ID).
     *
     * To retrieve the chat ID:
     *   1. Send a message to your bot.
     *   2. Visit: https://api.telegram.org/bot<token>/getUpdates
     *   3. Look for the "chat.id" field in the response.
     */
    private string $telegramChatId;

    /**
     * @var int Timestamp of the last successful message sent.
     *
     * This is used to track the last successful message sent to Telegram.
     */
    private int $lastSuccessTimestamp = 0;

    /**
     * @var int Timestamp of the last unsuccessful message sent.
     *
     * This is used to track the last time a message failed to send to Telegram.
     */
    private int $lastFailureTimestamp = 0;

    /**
     * @var string The name of the log file for Telegram log.
     *
     * If set, failed attempts will be logged to this file.
     */
    private string $logFileName = '';

    /**
     * @var int The message ID of the last statistics message sent to Telegram.
     *
     * This is used to update the message later, allowing for real-time updates without creating new messages.
     */
    private int $lastStatisticsMsgId = 0;

    /**
     * @var int The timestamp of the last Telegram update.
     *
     * This is used to track the last time an update was sent to Telegram, ensuring that updates are sent at appropriate intervals.
     */
    private int $lastTelegramUpdateTimestamp = 0;

    /**
     * @var Logger The logger object for logging messages.
     *
     * This object is used to log messages related to Telegram operations, such as sending messages or errors.
     */
    private Logger $logger;

    /**
     * @var array Configuration settings.
     *
     * This array holds the configuration settings for Telegram, such as whether messaging is enabled,
     * the bot token, chat ID, and other related settings.
     */
    private array $config = [];

    /**
     * Constructor for the Telegram class.
     *
     * Initializes the Telegram messaging settings based on the provided configuration object.
     *
     * @param array $config
     * @param Logger $logger
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->lastTelegramUpdateTimestamp = time();

        $this->telegramEnabled = ($config['telegram']['telegramEnabled'] ?? 'N') === 'Y';
        $this->telegramRealtimeEnabled = ($config['telegram']['telegramRealtimeEnabled'] ?? 'N') === 'Y';
        $this->telegramBotToken = $config['telegram']['telegramBotToken'] ?? '';
        $this->telegramChatId = $config['telegram']['telegramChatId'] ?? '';
        $this->logFileName = $config['telegram']['telegramLogFile'] ?? '';
        if ($this->logFileName != '') {
            $this->logFileName = str_replace("\\", "/", rtrim(realpath(__DIR__ . '/../'), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . ltrim($this->logFileName, DIRECTORY_SEPARATOR));
        }
    }

    /**
     * Sends a message to the specified Telegram chat.
     *
     * This method uses Telegram's Bot API to send a message to a chat.
     * It returns the message ID if successful, or false if Telegram messaging is disabled
     * or if the bot token or chat ID is not set.
     *
     * @param string $message The message text to be sent.
     * @param string $parseMode The parse mode for the message (default is 'Markdown').
     * @return bool|int Returns the message ID on success, false on failure.
     */
    public function sendMessage(string $message, string $parseMode = 'Markdown'): bool|int
    {
        if (!$this->telegramEnabled || empty($this->telegramBotToken) || empty($this->telegramChatId)) {
            return false;
        }

        $url = "https://api.telegram.org/bot" . $this->telegramBotToken . "/sendMessage";

        $postData = [
            'chat_id' => $this->telegramChatId,
            'text' => $message,
            'parse_mode' => $parseMode,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $postData,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        $messageId = $data['result']['message_id'] ?? 0;

        if ($httpCode === 200 && !empty($messageId)) {
            $this->lastSuccessTimestamp = time();
            return $messageId;
        } else {
            $this->lastFailureTimestamp = time();
            if ($this->logFileName != '') {
                $logMessage = date('Y.m.d H:i:s') . " - Failed to send message, response is: $response\n";
                file_put_contents($this->logFileName, $logMessage, FILE_APPEND);
            }
            return false;
        }

    }

    /**
     * Edits an existing message in the Telegram chat.
     *
     * This method allows you to update the text of a previously sent message.
     * It returns true if the message was edited successfully, or false if Telegram messaging is disabled
     * or if the bot token or chat ID is not set.
     *
     * @param int $messageId The ID of the message to be edited.
     * @param string $newText The new text for the message.
     * @param string $parseMode The parse mode for the message (default is 'Markdown').
     * @return bool True if the message was edited successfully, false otherwise.
     */
    public function editMessage(int $messageId, string $newText, string $parseMode = 'Markdown'): bool
    {
        if (!$this->telegramEnabled || empty($this->telegramBotToken) || empty($this->telegramChatId)) {
            return false;
        }

        $url = "https://api.telegram.org/bot" . $this->telegramBotToken . "/editMessageText";

        $postData = [
            'chat_id' => $this->telegramChatId,
            'message_id' => $messageId,
            'text' => $newText,
            'parse_mode' => $parseMode,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $postData,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $this->lastSuccessTimestamp = time();
            return true;
        } else {
            $this->lastFailureTimestamp = time();
            if ($this->logFileName != '') {
                $logMessage = date('Y.m.d H:i:s') . " - Failed to edit message, response is: $response\n";
                file_put_contents($this->logFileName, $logMessage, FILE_APPEND);
            }
            return false;
        }

    }

    /**
     * Checks whether Telegram messaging is enabled.
     *
     * @return bool True if Telegram messaging is enabled, false otherwise.
     */
    public function isTelegramEnabled(): bool
    {
        return $this->telegramEnabled;
    }

    /**
     * Checks whether realtime Telegram updates are enabled.
     *
     * @return bool True if realtime updates are enabled, false otherwise.
     */
    public function isTelegramRealtimeEnabled(): bool
    {
        return $this->telegramRealtimeEnabled;
    }

    /**
     * Gets the last success timestamp.
     *
     * This method returns the timestamp of the last successful message sent to Telegram.
     *
     * @return int The timestamp of the last successful message.
     */
    public function getLastSuccessTimestamp(): int
    {
        return $this->lastSuccessTimestamp;
    }

    /**
     * Gets the last failure timestamp.
     *
     * This method returns the timestamp of the last unsuccessful message sent to Telegram.
     *
     * @return int The timestamp of the last failed message.
     */
    public function getLastFailureTimestamp(): int
    {
        return $this->lastFailureTimestamp;
    }

    /**
     * Makes updates to Telegram with the current statistics.
     *
     * This method checks if Telegram is enabled and if real-time updates are allowed.
     * If so, it sends or edits a message with the current statistics at specified intervals.
     *
     * Important: this action is dependent on $providers and $hardware data,
     * so it MUST be called after drawScreen(), so all data is prepared.
     *
     * @param array $providers Array of provider data, including IP, status, traffic, and flags.
     * @param array $hardware Array of hardware metrics per device (router/repeater).
     */
    public function makeTelegramUpdates(array $providers, array $hardware, array $cleanedClientsList, array $MTProtoList): void
    {
        if ($this->isTelegramEnabled() &&
            $this->isTelegramRealtimeEnabled()) {

            $telegramDelay = (int)$this->config['telegram']['telegramStatusPeriod'] ?? 60;

            $this->logger->setAliveMTProtoCount($MTProtoList['alive_count'] ?? -1);
            $this->logger->setDeadMTProtoCount($MTProtoList['dead_count'] ?? -1);

            if ($this->lastStatisticsMsgId === 0 || (time() - $this->lastTelegramUpdateTimestamp > $telegramDelay)) {
                $this->lastTelegramUpdateTimestamp = time();
                $message = $this->logger->getPrettyTelegramLogData($providers, $hardware, $cleanedClientsList, $MTProtoList);
                if ($this->lastStatisticsMsgId === 0) {
                    $msgId = $this->sendMessage($message, "HTML");
                    if ($msgId !== false) {
                        $this->lastStatisticsMsgId = $msgId;
                    }
                } else {
                    $this->editMessage($this->lastStatisticsMsgId, $message, "HTML");
                }
            }
        }
    }

    /**
     * Formats a given text as a MarkdownV2 code block.
     *
     * This method takes a string and wraps it in triple backticks to format it
     * as a code block in MarkdownV2. It also allows specifying the language for syntax highlighting.
     *
     * @param string $text The text to be formatted as a code block.
     * @return array The formatted MarkdownV2 code block, number of lines in the text.
     */
    public function getMarkdownReadyLog(string $text): array
    {
        $text = trim($this->filterAndTrimLog($text), " \n.\t\r");
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("```", "``\u{200B}`", $text);
        return ['text' => "```\n" . $text . "\n```", 'lines' => substr_count($text, "\n") + 1];
    }

    /**
     * Filters and trims a raw log string to include only relevant lines and limits its length.
     *
     * This method processes the input log string to retain only lines containing specific keywords
     * related to network connectivity. It also trims the log to ensure it does not exceed a specified
     * maximum length, while maintaining UTF-8 integrity.
     *
     * @param string $logRaw The raw log string to be filtered and trimmed.
     * @param int $maxLen The maximum length of the resulting log string (default is 3000 characters).
     * @return string The filtered and trimmed log string.
     */
    private function filterAndTrimLog(string $logRaw, int $maxLen = 3000): string
    {
        $keywords = ['link', 'wan', 'connection', 'modem'];
        $keywordsToSkip = ['HTTP/1.1 200'];
        $lines = preg_split("/\r\n|\r|\n/", $logRaw);
        $result = [];
        $skipping = false;

        foreach ($lines as $line) {
            if ($line === '') continue;

            $match = false;
            foreach ($keywords as $kw) {
                if (stripos($line, $kw) !== false) {
                    $match = true;
                    break;
                }
            }

            foreach ($keywordsToSkip as $kw) {
                if (stripos($line, $kw) !== false) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                if ($skipping) {
                    $result[] = '...';
                    $skipping = false;
                }
                $result[] = $line;
            } else {
                $skipping = true;
            }
        }

        if ($skipping && (empty($result) || end($result) !== '...')) {
            $result[] = '...';
        }

        $text = implode("\n", $result);

        // Trim to last $maxLen characters (UTF-8 safe)
        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            $text = mb_substr($text, -$maxLen, null, 'UTF-8');
            // Avoid starting mid-line
            $pos = mb_strpos($text, "\n", 0, 'UTF-8');
            if ($pos !== false) {
                $text = mb_substr($text, $pos + 1, null, 'UTF-8');
            }
            $text = "...\n" . $text;
        }

        return $text;
    }


}