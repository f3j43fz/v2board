<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
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
        ini_set('memory_limit', -1);
        // 设定每批处理100个用户
        $chunkSize = 100;

        User::chunk($chunkSize, function ($users) {
            $currency = config('v2board.currency') == 'USD' ? "美元" : "元";
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

                //跳过【已关闭续费】的套餐
                //与 OrderController::save() 里的手动续费拦截保持一致：
                //  if (!$plan->renew && $user->plan_id == $plan->id && period !== 'reset_price') -> 禁止续费
                //自动续费天然满足 $user->plan_id == $plan->id 且 period 恒为 month_price，
                //故此处只需判断 renew。套餐一旦关闭续费，任何路径都不得再为其下单扣款。
                if (!$plan->renew) {
                    continue;
                }

                // 【按周期】套餐过期 且 余额大于月付价格
                if ($user->expired_at != NULL && $user->expired_at < time()) {
                    if ($plan->month_price > 0 && $user->balance >= $plan->month_price) {
                        if ($this->startAutoRenew($user, $plan)) {
                            $telegramService = new TelegramService();
                            $message = sprintf(
                                "💰自动续费提醒\n———————————————\n邮箱： `%s`\n套餐： %s\n续费金额：%s $currency",
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
        //设置成待支付
        $order->status= 0;

        if (!$order->save()) {
            DB::rollback();
            abort(500, '订单创建失败');
        }
        DB::commit();

        $orderService = new OrderService($order);
        if (!$orderService->paid('auto_renew')) {
            return false;
        }
        return true;
    }

}
