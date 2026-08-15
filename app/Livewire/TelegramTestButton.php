<?php

namespace App\Livewire;

use App\Services\TelegramNotifier;
use Livewire\Component;

class TelegramTestButton extends Component
{
    public $message = '';

    public function render()
    {
        return view('livewire.telegram_test_button');
    }

    public function testTelegram(): void
    {
        $this->message = (string) app(TelegramNotifier::class)->sendMessage(
            auth()->user()->telegram_chat_id,
            'Uptime: Test message from '.config('app.name')
        );
    }
}
