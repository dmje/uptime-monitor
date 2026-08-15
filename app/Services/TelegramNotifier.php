<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    public function isConfigured(): bool
    {
        return !empty(config('services.telegram_notifier.token'));
    }

    /**
     * Send a message, returning null on success or the error detail on failure.
     */
    public function sendMessage(?string $chatId, string $text): ?string
    {
        if (!$this->isConfigured()) {
            return 'Telegram notifier token is not configured.';
        }

        if (empty($chatId)) {
            return 'Telegram chat ID is not set.';
        }

        $endpoint = 'https://api.telegram.org/bot'.config('services.telegram_notifier.token').'/sendMessage';

        $response = Http::post($endpoint, [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);

        $responseBody = json_decode($response->body());

        if ($response->failed() || ($responseBody->ok ?? false) == false) {
            Log::channel('daily')->error('Telegram notification failed: '.$response->body());

            return $response->body();
        }

        return null;
    }

    public function send(?string $chatId, string $text): bool
    {
        return is_null($this->sendMessage($chatId, $text));
    }
}
