<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Console\Command;


class choujiang extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:choujiang';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'choujinag';

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
    public function handle()//php artisan customFunction:choujiang
    {
        ini_set('memory_limit', -1);
        $this->info("功能：抽取幸运观众，赠送余额赠金\n");

        $timestamp = strtotime('2023-10-27 00:00:00');
        $orders = Order::where('created_at', '>=', $timestamp)
            ->where('status', 3)
            ->with('user') // Eager load the user relationship
            ->get();

        $totalOrders = $orders->count();
        $numUsersToReceiveGift = ceil($totalOrders * 0.05);

        $randomOrders = $orders->random($numUsersToReceiveGift);

        $userUpdates = [];
        $giftedUsers = [];

        foreach ($randomOrders as $order) {
            $randomMoney = mt_rand(500, 1500);
            $user = $order->user;

            if ($user) {
                $this->info("用户【". $user->id . "】的原始余额：" . ($user->balance/100));
                $this->info("用户【". $user->id . "】的最新余额：" . ($user->balance + $randomMoney)/100);
                $userUpdates[$user->id] = $randomMoney;
                $giftedUsers[] = $user;
            }
        }

        // Batch update user balances
        if (!empty($userUpdates)) {
            foreach ($userUpdates as $userId => $randomMoney) {
                User::where('id', $userId)->increment('balance', $randomMoney);
            }
        }

        // Send email notifications asynchronously
        foreach ($giftedUsers as $user) {
            $randomMoney = $userUpdates[$user->id];

            // Queue the email notification
            $mailService = new MailService();
            $mailService->dispatchRemindGiftGotten($user, $randomMoney / 100);
        }

        $this->info("赠送余额赠金完成！");
    }

}
