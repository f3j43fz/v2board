<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RankUserCommission extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:gerUserCommission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '获取用户佣金排行';

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
        ini_set('memory_limit', -1);
        $users = User::where('is_admin', '!=', 1)
            ->orderBy('commission_balance', 'desc')
            ->take(20)
            ->get();

        $this->notify($users);
    }

    private function notify($users)
    {
        $telegramService = new TelegramService();
        $chatID = config('v2board.telegram_group_id');
        $text = "\n\n🪘今日佣金排行榜：\n\n";

        foreach ($users as $user) {
            $text .= "用户 #" . $user->id . "， 佣金：" . $user->commission_balance/100 . " 元\n";
        }

        $telegramService->sendMessage($chatID, $text, 'markdown');
    }

}
