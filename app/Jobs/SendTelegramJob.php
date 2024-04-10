<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected $pin = false;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, bool $pin)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->pin = $pin;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $telegramService = new TelegramService();
        $telegramService->sendMessage($this->telegramId, $this->text, $this->pin,'markdown');
    }
}
