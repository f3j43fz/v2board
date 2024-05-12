<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderService
{
    const STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        if ($order->refund_amount) {
            $this->user->balance = $this->user->balance + $order->refund_amount;
        }
        DB::beginTransaction();
        if ($order->surplus_order_ids) {
            try {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => 4
                ]);
            } catch (\Exception $e) {
                DB::rollback();
                abort(500, '开通失败');
            }
        }
        switch ((string)$order->period) {
            case 'onetime_price':
                $this->buyByOneTime($plan);
                break;
            case 'reset_price':
                $this->buyByResetTraffic();
                break;
            default:
                $this->buyByPeriod($order, $plan);
        }

        switch ((int)$order->type) {
            case 1:
                $this->openEvent(config('v2board.new_order_event_id', 0));
                break;
            case 2:
                $this->openEvent(config('v2board.renew_order_event_id', 0));
                break;
            case 3:
                $this->openEvent(config('v2board.change_order_event_id', 0));
                break;
        }

        $this->setSpeedLimit($plan->speed_limit);

        // 更新用户购买记录，区分新/老用户
        $this->updateHasPurchasedPlanStatus();

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindUpdateSub($this->user, $plan);//必须是这个参数
    }

    public function autoRenew()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        DB::beginTransaction();

        // 【按周期】续费套餐
        $this->buyByPeriod($order, $plan);
        // 类型是续费
        $this->openEvent(config('v2board.renew_order_event_id', 0));

        $this->setSpeedLimit($plan->speed_limit);

        // 更新用户购买记录，区分新/老用户
        $this->updateHasPurchasedPlanStatus();

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '自动续费失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '自动续费失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindOrderRenewed($this->user, $plan);//必须是这个参数
    }

    public function recharge()
    {
        DB::beginTransaction();
        // 管理员在后台设置的 充值优惠比例 以及 活动门槛
        // 路径：/config/v2board.php
        // 之后，记得修改管理员前端，方便后续修改

        // 门槛： 30 美元   不够30则没有优惠，即充多少是多少。
        $discountThreshold = config('v2board.discount_threshold', 30 * 100);
        // 优惠比例： 20%
        $discount = config('v2board.recharge_discount', 20) * 0.01;


        $order = $this->order;
        $this->user = User::find($order->user_id);
        $rechargeAmount = $order->total_amount;
        $rechargeAmountGotten = ($rechargeAmount >= $discountThreshold)? $rechargeAmount * (1 + $discount) : $rechargeAmount;
        $this->user->balance = $this->user->balance + $rechargeAmountGotten;

        //如果是 pay as you go 套餐的用户在余额用完后继续充值，那么：自动重置流量 + 重新分配可用流量
        if($this->user->is_PAGO == 1){
            $plan = Plan::find($this->user->plan_id);
            $this->buyByResetTraffic();
            $this->user->expired_at = NULL;
            $this->user->transfer_enable = round($this->user->balance / $plan->transfer_unit_price) * 1024 * 1024 * 1024;
        }

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '充值失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '充值失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindRechargeDone($this->user, $rechargeAmount, $rechargeAmountGotten, $this->user->balance);//必须是这个参数
        ////调用邮件提醒
    }

    public function openPayAsYouGo()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        DB::beginTransaction();

        $this->buyByPayAsYouGo($plan, $this->user);
        $this->setSpeedLimit($plan->speed_limit);

        // 更新用户购买记录，区分新/老用户
        $this->updateHasPurchasedPlanStatus();

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindUpdateSub($this->user, $plan);//必须是这个参数
        ////调用邮件提醒

    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        $plan = Plan::find($user->plan_id);
        if (!$plan) return;
        // 排除 Pay as you go 套餐
        if ($user->is_PAGO == 1) return;
        // 如果套餐是按流量卖的，没有过期时间，则直接按照剩余流量残值计算
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByTransfer($user, $order, $plan);
            return;
        }

        // 如果套餐是按周期卖的，先计算剩余时间残值，然后加上剩余流量残值
        $this->getSurplusValueByTime($user, $order, $plan);
        $this->getSurplusValueByTransfer($user, $order, $plan);
    }

    private function getSurplusValueByTime(User $user, Order $order, Plan $plan)
    {
        if (!$plan['daily_unit_price']) return;

        $timeLeftDays = ($user['expired_at'] - time()) / 86400;

        if (!$timeLeftDays) return;
        // 如果套餐剩余时长小于 31 天，则不计算时间残值
        if ($timeLeftDays < 31) return;

        // 如果套餐剩余时长大于 31 天，则只计算整月，剩余部分是按剩余流量残值计算
        $realTimeLeftDays = intval($timeLeftDays / 31 ) * 31;

        $dailyUnitPrice = $plan['daily_unit_price'] / 100;
        $order->surplus_amount = $order->surplus_amount + ($realTimeLeftDays * $dailyUnitPrice) * 100;
    }

    private function getSurplusValueByTransfer(User $user, Order $order, Plan $plan)
    {
        if (!$plan['transfer_unit_price']) return;
        $transferLeft = ($user['transfer_enable'] - ($user['u'] + $user['d'])) / 1073741824;
        if (!$transferLeft) return;
        // 如果套餐剩余流量为 0 或者负数，则不计算剩余流量残值
        if ($transferLeft <= 0) return;

        $transferUnitPrice = $plan['transfer_unit_price'] / 100;
        $order->surplus_amount = $order->surplus_amount + ($transferLeft * $transferUnitPrice) * 100;
    }

    public function paid(string $callbackNo)
    {
        $order = $this->order;
        if ($order->status !== 0) return true;
        $order->status = 1;
        $order->paid_at = time();
        $order->callback_no = $callbackNo;
        if (!$order->save()) return false;
        try {
            OrderHandleJob::dispatchNow($order->trade_no);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    public function cancel(): bool
    {
        $order = $this->order;
        DB::beginTransaction();
        $order->status = 2;
        if (!$order->save()) {
            DB::rollBack();
            return false;
        }
        if ($order->balance_amount) {
            $userService = new UserService();
            if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                DB::rollBack();
                return false;
            }
        }
        DB::commit();
        return true;
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();


        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }


        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
        $this->user->is_PAGO = 0;
    }

    private function buyByOneTime(Plan $plan)
    {
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
        $this->user->is_PAGO = 0;
    }

    private function buyByPayAsYouGo(Plan $plan, User $user)
    {
        $this->buyByResetTraffic();
        $this->user->transfer_enable = round($user->balance / $plan->transfer_unit_price) * 1024 * 1024 * 1024;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
        $this->user->is_PAGO = 1;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int)$eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function updateHasPurchasedPlanStatus()
    {
        //如果 $this->user->has_Purchased_Plan_Before 的值为 0，它会将其设置为 1；如果已经是 1，则保持不变。
        $this->user->has_Purchased_Plan_Before |= 1;
    }



}
