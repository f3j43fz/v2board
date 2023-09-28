<?php

namespace App\Console\Commands;

use App\Models\InviteCode;
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
        $this->info("功能：删除过期7天以上的按周期付费用户的邀请码 以及 5天以上不使用流量的一次性套餐用户的邀请码");
        $users = User::all();
        $plans = Plan::all();
        $timePlans = [];
        foreach ($plans as $plan) {
            if ($plan->onetime_price !== null && $plan->onetime_price !== 0) {
                $timePlans[] = $plan->id;
            }
        }

        foreach ($users as $user) {
            if (in_array($user->plan_id, $timePlans) && (time() - $user->updated_at) > 432000) {
                // Delete invitation code for users with plan_id in $timePlans
                // Delete invitation code for users who haven't used traffic for 5 days
                if (InviteCode::where('user_id', $user->id)->delete()) {
                    $this->info("已删除用户(拥有长期套餐)ID为{$user->id}的邀请码");
                }
            } else {
                // Delete invitation code for users who have been expired for 7 days or more
                if((time() - $user->expired_at) > 604800){
                    if (InviteCode::where('user_id', $user->id)->delete()) {
                        $this->info("已删除用户ID为{$user->id}的邀请码");
                    }
                }
            }
        }

    }
}
