<?php

namespace App\Utils\Log;

use App\F4;

//Класс оповещает об ошибках в slack и телеграмм бота
class LogNotifier
{

    public static function notify(string $message, int $level): void
    {
        if ($level < LogLevel::ERROR) {
            return;
        }

        $f4 = F4::instance();
        $telegramToken = $f4->g('log_notifier.bot_token',null);
        $telegramChatId = $f4->g('log_notifier.chat_id',null);
        $slackWebhookUrl = $f4->g('log_notifier.slack_webhook_url',null);

        if (!empty($telegramToken) && !empty($telegramChatId)) {
            self::sendToTelegram($message, $telegramToken, $telegramChatId);
        }

        if (!empty($slackWebhookUrl)) {
            self::sendToSlack($message, $slackWebhookUrl);
        }
    }

    protected static function sendToTelegram(string $message, string $telegramToken, string $telegramChatId): void
    {
        $url = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
        $payload = [
            'chat_id' => $telegramChatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        self::httpPost($url, $payload);
    }

    protected function sendToSlack(string $message, string $slackWebhookUrl): void
    {
        $payload = ['text' => $message];
        self::httpPost($slackWebhookUrl, $payload);
    }

    protected function httpPost(string $url, array $payload): void
    {
        try {
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-type: application/json",
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'timeout' => 2,
                ]
            ];
            file_get_contents($url, false, stream_context_create($options));
        } catch (\Exception $e) {
            // Логгер не должен падать на ошибке уведомления
        }
    }
}
