<?php

namespace Sv\Network\VmsRtbw;

/**
 * Telegram is a utility class for sending messages via Telegram's Bot API.
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
     * @var int The message ID of the last sent realtime statistics message.
     *
     * This is used to update the message later.
     */
    private int $realtimeStatsMsgId = 0;

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
     * Constructor for the Telegram class.
     *
     * Initializes the Telegram messaging settings based on the provided configuration object.
     *
     * @param Config $configObject Configuration object containing Telegram settings.
     */
    public function __construct(Config $configObject)
    {
        $config = $configObject->getConfigData();

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
     * Sends a message to the configured Telegram chat.
     *
     * This method uses Telegram's Bot API to send a message to the specified chat ID.
     * It returns true if the message was sent successfully, or false if Telegram messaging is disabled
     * or if the bot token or chat ID is not set.
     *
     * @param string $message The message to be sent.
     * @param string $parseMode The parse mode for the message (default is 'Markdown').
     * @return bool True if the message was sent successfully, false otherwise.
     */
    public function sendMessage(string $message, string $parseMode = 'Markdown'): bool|int
    {
        if (!$this->telegramEnabled || empty($this->telegramBotToken) || empty($this->telegramChatId)) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage";

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
            if ($this->logFileName !='') {
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

        $url = "https://api.telegram.org/bot{$this->telegramBotToken}/editMessageText";

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
            if ($this->logFileName !='') {
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
    public function isTelegramEnabled() : bool
    {
        return $this->telegramEnabled;
    }

    /**
     * Checks whether realtime Telegram updates are enabled.
     *
     * @return bool True if realtime updates are enabled, false otherwise.
     */
    public function isTelegramRealtimeEnabled() : bool
    {
        return $this->telegramRealtimeEnabled;
    }

    /**
     * Gets the id of the realtime update message.
     *
     * This method returns the message ID of the realtime statistics message
     * previously sent to Telegram, which can be used for updating or deleting the message.
     *
     * @return int The id of the realtime update message.
     */
    public function getRealtimeStatsMsgId(): int
    {
        return $this->realtimeStatsMsgId;
    }

    /**
     * Sets the id of the realtime update message.
     *
     * This method is used to store the message ID of the realtime statistics message
     * sent to Telegram, allowing for future updates or deletions.
     *
     * @param int $realtimeStatsMsgId The id of the realtime update message.
     */
    public function setRealtimeStatsMsgId(int $realtimeStatsMsgId): void
    {
        $this->realtimeStatsMsgId = $realtimeStatsMsgId;
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
}