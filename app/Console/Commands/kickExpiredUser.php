<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class kickExpiredUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:kick
                            {expired_days_go : day}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '从群组中移除套餐过期 7 天以上的用户，并发送通知';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $expiredDaysAgo = $this->argument('expired_days_go');
        $telegramService = new TelegramService();
        $deletedUsers = $telegramService->removeExpiredUsersFromGroup(config('v2board.telegram_group_id'), $expiredDaysAgo);

        foreach ($deletedUsers as $deletedUser) {
            $this->info("Deleted User ID: " . $deletedUser['id'] . ", Telegram ID: " . $deletedUser['telegram_id'] . "\n");
        }

    }
}
