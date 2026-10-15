<?php

namespace App\Livewire;

use App\Services\NtfyNotifier;
use Livewire\Component;

class NtfyTestButton extends Component
{
    public $message = '';

    public function render()
    {
        return view('livewire.ntfy_test_button');
    }

    public function testNtfy(): void
    {
        $this->message = (string) app(NtfyNotifier::class)->sendMessage(
            auth()->user()->ntfy_topic,
            'Test message from '.config('app.name'),
            'Uptime'
        );
    }
}
