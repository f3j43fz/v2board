<?php

namespace App\Console\Commands;

use App\Models\InviteCode;
use App\Services\TelegramService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Plan;

class ClearInviteCode extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clear:inviteCode';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '删除无效用户的邀请码';

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
        $telegramService = new TelegramService();
        $this->info("功能：删除过期7天以上的【按周期付费用户】的邀请码 以及 7天以上不使用流量的【一次性套餐用户】的邀请码");
        $telegramService->sendMessageWithAdmin("开始删除特定用户的邀请码，指：过期 7 天以上的【按周期付费用户】和 7 天以上不使用流量的【一次性套餐用户】");
        $daysToExpire = 7;
        $daysWithoutTraffic = 7;

        $users = User::all();
        $plans = Plan::all();
        $oneTimePlans = [];
        $trafficPlans = [];
        $counts =0;
        foreach ($plans as $plan) {
            if ($plan->onetime_price !== null && $plan->onetime_price !== 0) {
                $oneTimePlans[] = $plan->id;
            }else{
                $trafficPlans[] = $plan->id;
            }
        }

        foreach ($users as $user) {
            // Delete invitation code for users expired for 7 days
            // Delete invitation code for users who haven't used traffic for 5 days
            if ((in_array($user->plan_id, $oneTimePlans) && (time() - $user->updated_at) > ($daysWithoutTraffic * 86400)) ||
                (in_array($user->plan_id, $trafficPlans) && (time() - $user->expired_at) > ($daysToExpire * 86400))) {
                if (InviteCode::where('user_id', $user->id)->delete()) {
                    $counts++;
                    $this->info("已删除用户ID为{$user->id}的邀请码");
                }
            } else {
                $this->info("当前用户（ID为{$user->id}）正常，邀请码将被保存");
            }
        }

        $telegramService->sendMessageWithAdmin("每日例行检查用户有效性，删除了{$counts}个邀请码");
    }
}
