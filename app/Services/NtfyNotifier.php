<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NtfyNotifier
{
    public function isConfigured(): bool
    {
        return !empty(config('services.ntfy.server'));
    }

    /**
     * Publish a message to a topic, returning null on success or the error
     * detail on failure. Uses ntfy's JSON publishing so titles can carry emoji.
     */
    public function sendMessage(?string $topic, string $text, ?string $title = null, int $priority = 3): ?string
    {
        if (!$this->isConfigured()) {
            return 'ntfy server is not configured.';
        }

        if (empty($topic)) {
            return 'ntfy topic is not set.';
        }

        $request = Http::asJson();

        if ($token = config('services.ntfy.token')) {
            $request = $request->withToken($token);
        }

        $payload = [
            'topic' => $topic,
            'message' => $text,
            'priority' => $priority,
        ];

        if ($title) {
            $payload['title'] = $title;
        }

        $response = $request->post(rtrim(config('services.ntfy.server'), '/'), $payload);

        if ($response->failed()) {
            Log::channel('daily')->error('ntfy notification failed: '.$response->body());

            return $response->body() ?: 'HTTP '.$response->status();
        }

        return null;
    }

    public function send(?string $topic, string $text, ?string $title = null, int $priority = 3): bool
    {
        return is_null($this->sendMessage($topic, $text, $title, $priority));
    }
}
