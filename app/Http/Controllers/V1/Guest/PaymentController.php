<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Middleware\User;
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

        //type
        $hasPaidBefore = Order::where('user_id', $order->user_id)
            ->where('status', 3)
            ->exists();
        $types = [1 => "新购", 2 => "续费", 3 => "升级"];
        $type = $types[$order->type] ?? "未知";
        if ($type == "新购" && $hasPaidBefore){
            $type .= "(首购)";
        }

        // planName
        $plan = Plan::find($order->plan_id);
        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }
        $planName = $plan->name;

        // period
        // 定义英文到中文的映射关系
        $periodMapping = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '2年付',
            'three_year_price' => '3年付',
            'onetime_price' => '一次性付款'
        ];
        $period = $periodMapping[$order->period];


        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s\n套餐：%s\n类型：%s\n周期：%s",
            $order->total_amount / 100,
            $order->trade_no,
            $planName,
            $type,
            $period
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
