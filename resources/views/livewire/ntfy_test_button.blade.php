<span>
    <button wire:click="testNtfy()" class="btn btn-info">
        <span wire:loading>
            Sending message...
        </span>
        <span wire:loading.remove>
            Test ntfy Topic
        </span>
    </button>
    <div>{{ $message }}</div>
</span>
