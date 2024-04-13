<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Services\TelegramService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AutoRenewPlan extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:autoRenew';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '为【按周期】套餐的用户自动续费（排除：【按流量】 和 【随用随付】 套餐）';

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
        // 设定每批处理100个用户
        $chunkSize = 100;

        User::chunk($chunkSize, function ($users) {
            foreach ($users as $user) {
                //跳过【未开启自动续费】的用户
                //跳过封禁的用户
                //跳过余额为0的用户
                //跳过【随用随付，Pay as you go】的套餐的用户
                if (!$user->auto_renew || $user->banned || $user->balance == 0 || $user->is_PAGO == 1) {
                    continue;
                }

                //跳过【按流量】的套餐的用户
                $plan = Plan::find($user->plan_id);
                if (!$plan || $plan->onetime_price > 0) {
                    continue;
                }

                // 检查【按周期】套餐的用户是否已经过期
                if ($user->expired_at != NULL && $user->expired_at < time()) {
                    if ($plan->month_price > 0 && $user->balance >= $plan->month_price) {
                        if ($this->startAutoRenew($user, $plan)) {
                            $telegramService = new TelegramService();
                            $message = sprintf(
                                "💰自动续费提醒\n———————————————\n邮箱： `%s`\n套餐： %s\n续费金额：%s 元",
                                $user->mail,
                                $plan->name,
                                $plan->month_price / 100
                            );
                            $telegramService->sendMessageWithAdmin($message);
                        }
                    }
                }
            }
        });
    }


    private function startAutoRenew(User $user, Plan $plan): bool
    {
        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return false;
        }

        DB::beginTransaction();
        $order = new Order();
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = 'month_price';
        $order->trade_no = Helper::guid();

        //余额 - 套餐一个月的费用
        if (!$userService->addBalance($user->id, - $plan->month_price)) {
            DB::rollBack();
            abort(500, '余额减扣失败');
        }
        $order->total_amount = 0;
        $order->balance_amount = $plan->month_price;
        //设定类型为：续费
        $order->type = 2;
        //设为开通中
        $order->status = 1;
        //付款时间为未来的10秒
        $order->paid_at = time() + 10;
        //回调单号为 'auto_renew' (因为不需要付款，没有回调一说)
        $order->callback_no = 'auto_renew';

        if (!$order->save()) {
            DB::rollback();
            abort(500, '订单创建失败');
        }
        DB::commit();

        try {
            OrderHandleJob::dispatchNow($order->trade_no);
        } catch (\Exception $e) {
            return false;
        }
        return true;

    }

}
