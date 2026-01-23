<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

    /**
     * 发送系统补偿通知 (支持延迟队列)
     *
     * @param User $user 用户对象
     * @param string $type 补偿类型 'time' 或 'traffic'
     * @param int $value 补偿数值
     * @param int $delaySeconds 延迟发送秒数
     */
    public function sendCompensationNotice(User $user, $type, $value, $delaySeconds = 0)
    {
        $userName = explode('@', $user->email)[0];
        $appName = config('v2board.app_name', 'V2Board');

        // 构建文案
        if ($type === 'time') {
            $content = "由于近期的网络波动影响了您的使用体验，我们深表歉意。\n\n作为补偿，系统已自动为您的当前订阅 **延长了 {$value} 天** 的有效期。\n\n更新后的到期时间为：" . date('Y-m-d H:i:s', $user->expired_at);
        } else {
            // 将 Byte 转换为 GB 并保留两位小数
            $currentTrafficGB = round($user->transfer_enable / (1024*1024*1024), 2);
            $content = "由于近期的网络波动影响了您的使用体验，我们深表歉意。\n\n作为补偿，系统已自动为您的当前订阅 **增加了 {$value} GB** 的高速流量。\n\n当前可用总流量为：" . $currentTrafficGB . " GB";
        }

        // 分发到 'send_email_mass' 队列，并设置延迟
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => "系统补偿通知 | {$appName}",
            'template_name' => 'notifyCompensation',
            'template_value' => [
                'name' => $appName,
                'url' => config('v2board.app_url'),
                'userName' => $userName,
                'content' => $content
            ]
        ])->onQueue('send_email_mass')->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * 提醒 Emby 服务即将到期
     * @param User $user
     * @param int $remindBeforeDays 提前几天提醒，默认为 3 天
     */
    public function remindEmbyExpire(User $user, $remindBeforeDays = 3)
    {
        // 检查用户是否有 Emby 到期时间
        if (empty($user->emby_expired_at)) {
            return;
        }

        $currentTime = time();
        $expireTime = $user->emby_expired_at;
        $remindTimestamp = $expireTime - ($remindBeforeDays * 86400); // 计算提醒的时间点

        // 检查是否在提醒时间范围内，并且尚未过期
        if ($currentTime >= $remindTimestamp && $currentTime < $expireTime) {
            // 检查缓存，防止重复发送
            $cacheKey = CacheKey::get('LAST_SEND_EMAIL_REMIND_EMBY_EXPIRE', $user->id);
            // 设置缓存有效期为 1 天，确保每天最多发送一次
            if (!Cache::add($cacheKey, 1, 86400)) {
                Log::info("Emby 到期提醒邮件已于24小时内发送给用户: {$user->email}");
                return; // 如果缓存存在，则不发送
            }

            $userName = explode('@', $user->email)[0];
            $expireDateFormatted = date('Y-m-d H:i:s', $expireTime); // 格式化到期时间

            Log::info("准备发送 Emby 到期提醒邮件给用户: {$user->email}, 到期时间: {$expireDateFormatted}");

            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('您的 Emby 服务即将到期 - :app_name', [ // 修改邮件主题
                    'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindEmbyExpire', // 新的模板名称
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'userName' => $userName,
                    'emby_expire_date' => $expireDateFormatted, // 传递格式化后的日期
                    'remind_days' => $remindBeforeDays // 传递提前提醒的天数
                ]
            ]);
        }
    }

    public function sendEmbyAccountDetails(User $user, array $embyAccountDetails)
    {
        $userName = explode('@', $user->email)[0];
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('您的 Emby 账号已开通 - :app_name', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'sendEmbyAccount', // 新的模板名称
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'userName' => $userName,
                'emby_username' => $embyAccountDetails['username'],
                'emby_password' => $embyAccountDetails['password'],
                'emby_server_url' => $embyAccountDetails['server_url'],
                'emby_expire_time' => $embyAccountDetails['expire_time'],
                // 可以传递其他需要的信息到模板
            ]
        ]);
    }

    /**
     * 发送 Emby 续费成功通知邮件
     *
     * @param User $user
     * @param \App\Models\Order $order
     */
    public function sendEmbyRenewalNotice(User $user, $order)
    {
        $userName = explode('@', $user->email)[0];

        // 获取 Emby 服务器地址
        $embyServerUrl = config('v2board.emby_server_url', 'http://emby.yourdomain.com:8096');

        // 格式化到期时间
        $expireTime = $user->emby_expired_at ? date('Y-m-d H:i:s', $user->emby_expired_at) : '未知';

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Emby 服务续费成功 - :app_name', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'embyRenewalNotice',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'userName' => $userName,
                'emby_username' => $user->emby_user_name,
                'emby_server_url' => $embyServerUrl,
                'emby_expire_time' => $expireTime,
            ]
        ]);
    }

    /**
     * 发送 Emby 服务过期通知邮件
     *
     * @param User $user
     */
    public function sendEmbyExpirationNotice(User $user)
    {
        $userName = explode('@', $user->email)[0];

        // 格式化过期时间
        $expireDate = $user->emby_expired_at ? date('Y-m-d H:i:s', $user->emby_expired_at) : '未知';

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Emby 服务已过期 - :app_name', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'embyExpirationNotice',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'userName' => $userName,
                'emby_expire_date' => $expireDate,
            ]
        ]);
    }
}
