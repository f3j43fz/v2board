<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Traffic extends Telegram {
    public $command = '/my';
    public $description = '查询个人信息';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        // 获取货币单位
        $currency = config('v2board.currency') == 'USD' ? "美元" : "元";
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', false,'markdown');
            return;
        }



        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));
        $planID = $user->plan_id;
        $plan = Plan::find($planID);
        $planName = '';
        $expiredTime = null;
        if ($plan) {
            $planName = $plan->name;
            $expiredTime = ($plan->onetime_price > 0 || $plan->setup_price > 0)? "永不过期" : date('Y-m-d', $user->expired_at);
        }

        if ($user->is_PAGO == 1) {
            $balance= $user->balance / 100;
            $text = "🚥个人信息\n———————————————\n订阅计划：`{$planName}`\n到期时间：`{$expiredTime}`\n已用上行：`{$up}`\n已用下行：`{$down}`\n余额：`{$balance}` {$currency}";
        }else{
            $text = "🚥个人信息\n———————————————\n订阅计划：`{$planName}`\n到期时间：`{$expiredTime}`\n计划流量：`{$transferEnable}`\n已用上行：`{$up}`\n已用下行：`{$down}`\n剩余流量：`{$remaining}`";
        }
        $telegramService->sendMessage($message->chat_id, $text, false,'markdown');
    }
}
