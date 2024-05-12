<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\MailService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Choujiang extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:Choujiang {start} {end}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '抽取活动期间订单的用户，并随机赠送余额';

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

        // 获取货币单位
        $currency = config('v2board.currency') == 'USD' ? "美元" : "元";

        $start = $this->argument('start');
        $end = $this->argument('end');
        $timestampStart = strtotime($start);
        $timestampEnd = strtotime($end);

        $this->info("功能：抽取活动期间订单的用户，随机赠送余额");

        // 直接通过数据库查询获取符合条件的唯一用户ID
        $userIds = Order::whereBetween('created_at', [$timestampStart, $timestampEnd])
            ->where('status', 3)
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        $totalUsers = $userIds->count();
        $numUsersToReceiveGift = ceil($totalUsers * 0.08);

        // 如果抽奖用户数大于0，则进行抽奖
        if ($numUsersToReceiveGift > 0) {
            $giftedUserIds = $userIds->random($numUsersToReceiveGift);
        } else {
            $this->info("没有足够的用户参与抽奖。");
            return;
        }

        $giftedUsers = User::whereIn('id', $giftedUserIds)->get();

        $userUpdates = [];
        $telegramMessage = "🥳五一赠金活动已开奖，恭喜以下用户中奖：\n\n";
        foreach ($giftedUsers as $user) {
            $randomMoney = mt_rand(300, 500);  // 3到5元之间
            $this->info("用户【#" . $user->id . "】的原始余额：" . ($user->balance / 100) . " $currency");
            $this->info("用户【#" . $user->id . "】的赠金为：" . ($randomMoney / 100) . " $currency");
            $this->info("用户【#" . $user->id . "】的最新余额：" . ($user->balance + $randomMoney) / 100 . " $currency");
            $userUpdates[$user->id] = $randomMoney;
            $telegramMessage .= "#{$user->id} 获赠 " . ($randomMoney / 100) . " $currency\n";
        }

        // 使用数据库事务处理用户余额更新
        try {
            \DB::beginTransaction();
            if (!empty($userUpdates)) {
                foreach ($userUpdates as $userId => $randomMoney) {
                    User::where('id', $userId)->increment('balance', $randomMoney);
                }
            }
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            $this->error("更新用户余额时发生错误：" . $e->getMessage());
            return;
        }

        // 异步发送邮件通知
        foreach ($giftedUsers as $user) {
            $randomMoney = $userUpdates[$user->id];
            $mailService = new MailService();
            $mailService->dispatchRemindGiftGotten($user, $randomMoney / 100);
        }

        $this->info("赠送余额任务完成！");

        // 发送TG通知
        $this->notify($telegramMessage);
    }

    private function notify($text)
    {
        $telegramService = new TelegramService();
        $chatID = config('v2board.telegram_group_id');
        $telegramService->sendMessage($chatID, $text, true, 'markdown');
    }
}
