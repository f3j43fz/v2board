<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            die(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        // type
        $types = [1 => "新购", 2 => "续费", 3 => "变更" , 4 => "流量包"];
        $type = $types[$order->type] ?? "未知";

        // planName
        $planName = "";
        $plan = Plan::find($order->plan_id);
        if ($plan) {
            $planName = $plan->name;
        }

        // period
        // 定义英文到中文的映射关系
        $periodMapping = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '2年付',
            'three_year_price' => '3年付',
            'onetime_price' => '一次性付款',
            'setup_price' => '设置费',
            'reset_price' => '流量重置包'
        ];
        $period = $periodMapping[$order->period];

        // email
        $userEmail = "";
        $user = User::find($order->user_id);
        if ($user){
            $userEmail = $user->email;
        }

        // inviterEmail  inviterCommission
        $inviterEmail = '';
        $getAmount = 0; // 本次佣金
        $anotherInfo = "";

        if (!empty($order->invite_user_id)) {
            $inviter = User::find($order->invite_user_id);
            if ($inviter) {
                $inviterEmail = $inviter->email;
                $getAmount = $this->getCommission($inviter->id, $order); // 本次佣金

                if ((int)config('v2board.withdraw_close_enable', 0)) {
                    $inviterBalance = $inviter->balance / 100 + $getAmount; // 总余额 （关闭提现）
                    $anotherInfo = "邀请人总余额： " . $inviterBalance;
                } else {
                    $inviterCommissionBalance = $inviter->commission_balance / 100 + $getAmount; // 总佣金 （允许提现）
                    $anotherInfo = "邀请人总佣金： " . $inviterCommissionBalance;

                }
            }
        }


        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：`%s`\n邮箱： `%s`\n套餐：%s\n类型：%s\n周期：%s\n邀请人邮箱： `%s`\n本次佣金：%s元\n%s元",
            $order->total_amount / 100,
            $order->trade_no,
            $userEmail,
            $planName,
            $type,
            $period,
            $inviterEmail,
            $getAmount,
            $anotherInfo
        );
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message,true);
        return true;
    }

    private function getCommission($inviteUserId, $order)
    {
        $getAmount = 0;
        $level = 3;
        if ((int)config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            if (!$inviter) continue;
            if (!isset($commissionShareLevels[$l])) continue;
            $getAmount = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$getAmount) continue;
        }
        return $getAmount / 100;
    }
}
