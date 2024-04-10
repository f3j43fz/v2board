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
        $text = "\n\n📈今日佣金排行榜：\n\n";

        $maxUserIdLength = max(array_map('strlen', $users->pluck('id')->toArray()));

        foreach ($users as $user) {
            $userId = "用户 #" . str_pad($user->id, $maxUserIdLength, ' ', STR_PAD_RIGHT);
            $spaces = str_repeat(' ', $maxUserIdLength - strlen($user->id) + 1); // Calculate the number of spaces needed
            $commissionFormatted = number_format($user->commission_balance/100, 2); // Format commission balance with 2 decimal places
            $text .= "{$userId}{$spaces}， 佣金：" . $commissionFormatted . " 元\n";
        }

        $telegramService->sendMessage($chatID, $text, false,'markdown');
    }


}
