<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class MailService
{
    public function remindTraffic (User $user)
    {
        if (!$user->remind_traffic) return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag)) return;
        if (!Cache::put($flag, 1, 24 * 3600)) return;
        $userName = explode('@', $user->email)[0];
        //used
        $u = ($user->u + $user->d) / (1024*1024*1024);
        //total
        $t = ($user->transfer_enable) / (1024*1024*1024);
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 95%', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'userName' => $userName,
                'u' => $u,
                't' => $t,
            ]
        ]);
    }

    public function remindExpire(User $user)
    {
        $currentTime = time();

        // 提醒过期不足一天
        if ($user->expired_at !== NULL && ($user->expired_at - 86400) < $currentTime && $user->expired_at > $currentTime) {
            $userName = explode('@', $user->email)[0];
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('The service in :app_name is about to expire', [
                    'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindExpire',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'userName' => $userName
                ]
            ]);
        }
    }

    public function remindExpire3(User $user)
    {
        $currentTime = time();
        // 提醒过期超过3天
        if ($user->expired_at !== NULL && ( ($currentTime - $user->expired_at) > 3 * 86400 && ($currentTime - $user->expired_at) <= 6 * 86400)   && $user->expired_at < $currentTime) {
            $userName = explode('@', $user->email)[0];
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('The service in :app_name has expired more than 3 days ago', [
                    'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindExpire3',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'userName' => $userName
                ]
            ]);
        }
    }

    public function remindExpire7(User $user)
    {
        $currentTime = time();
        // 提醒过期超过7天
        if ($user->expired_at !== NULL && ( ($currentTime - $user->expired_at) > 7 * 86400 && ($currentTime - $user->expired_at) <= 14 * 86400)   && $user->expired_at < $currentTime) {
            $userName = explode('@', $user->email)[0];
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('The service in :app_name has expired more than 7 days ago', [
                    'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindExpire7',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'userName' => $userName
                ]
            ]);
        }
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud) return false;
        if (!$transfer_enable) return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 95) return false;
        if ($percentage >= 100) return false;
        return true;
    }

    ////用户购买套餐后，发邮件提示更新订阅
    public function remindUpdateSub(User $user, Plan $plan)
    {
        $userName = explode('@', $user->email)[0];
        $planName = $plan->name;
        $traffic = $user->transfer_enable / (1024*1024*1024);
        $expiredTime = ($plan->onetime_price > 0)? "永不过期" : date('Y-m-d H:i', $user->expired_at);
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('您的服务已开通', [
                'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindUpdateSub',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'userName' => $userName,
                'planName' => $planName,
                'traffic' => $traffic,
                'expiredTime' => $expiredTime
            ]
        ]);
    }

    ////受邀用户购买套餐并达到指定时间后，发邮件给邀请用户，表示佣金到账了
    public function remindCommissionGotten(User $user, $commission)
    {
        $userName = explode('@', $user->email)[0];
        $withdrawLimit = config('v2board.commission_withdraw_limit',100);
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('佣金已到账~', [
                'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindCommissionGotten',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'rate' => config('v2board.invite_commission'),
                'commission' => $commission,
                'userName' => $userName,
                'withdrawLimit' => $withdrawLimit
            ]
        ]);
    }

    ////赠金提醒
    public function dispatchRemindGiftGotten(User $user, $moneyGift)
    {
        $userName = explode('@', $user->email)[0];
        $subject = '恭喜您中奖 | '. config('v2board.app_name', 'V2Board');
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => $subject,
            'template_name' => 'remindGiftGotten',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'moneyGift' => $moneyGift,
                'userName' => $userName
            ]
        ]);
    }

    // 充值成功提醒
    public function remindRechargeDone(User $user, $rechargeAmount, $rechargeAmountGotten, $balance)
    {
        $userName = explode('@', $user->email)[0];
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('充值成功', [
                'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindRechargeDone',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'rechargeAmount' => $rechargeAmount/100,
                'rechargeAmountGotten' => $rechargeAmountGotten/100,
                'balance' => $balance/100,
                'userName' => $userName
            ]
        ]);
    }

    // 余额不足提醒
    public function remindInsufficientBalance(User $user, $balance)
    {
        $userName = explode('@', $user->email)[0];
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('余额不足提醒', [
                'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindInsufficientBalance',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'balance' => $balance,
                'userName' => $userName
            ]
        ]);
    }

    public function remindOrderRenewed(User $user, User $plan)
    {
        $userName = explode('@', $user->email)[0];
        $planName = $plan->name;
        $traffic = $user->transfer_enable / (1024*1024*1024);
        $expiredTime = ($plan->onetime_price > 0)? "永不过期" : date('Y-m-d H:i', $user->expired_at);
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('您的服务已自动续费', [
                'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindOrderRenewed',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'userName' => $userName,
                'planName' => $planName,
                'traffic' => $traffic,
                'expiredTime' => $expiredTime

            ]
        ]);
    }

    public function remindTimeAdded($email)
    {
        $userName = explode('@', $email)[0];
        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => __('您已成功邀请好友注册并获得奖励', [
                'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindTimeAdded',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'userName' => $userName

            ]
        ]);

    }
}
