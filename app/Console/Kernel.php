<?php

namespace App\Console;

use App\Models\Tokenrequest;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // v2board
        $schedule->command('v2board:statistics')->dailyAt('0:10');
        // check
        $schedule->command('check:order')->everyMinute();
        $schedule->command('check:commission')->everyMinute();
        $schedule->command('check:ticket')->everyMinute();
        // reset
        $schedule->command('reset:traffic')->daily();
        $schedule->command('reset:log')->daily();
        // send
        $schedule->command('send:remindMail')->dailyAt('11:30');
        $schedule->command('send:remindMail3')->cron('40 11 */3 * *');
        $schedule->command('send:remindMail7')->cron('50 11 */7 * *');
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        // custom function
        $schedule->command('customFunction:replenish 2')->dailyAt('0:0'); //一次性套餐补货 2 个
        $schedule->command('clear:inviteCode')->dailyAt('2:25'); //删除邀请码
        //$schedule->command('changePort:vmess 1')->dailyAt('2:30'); //VMess节点ID 1 更换端口
        //$schedule->command('customFunction:addCoupon 108 3')->dailyAt('12:00'); // ID 108 优惠券补充 1-5 张
        $schedule->command('customFunction:tongjiMoney')->dailyAt('0:11'); //推送前一天各支付商的订单情况
        $schedule->command('customFunction:kick 7')->dailyAt('23:45'); //移除过期超过 7 天的用户
        $schedule->command('customFunction:gerUserCommission')->dailyAt('0:05'); //群通知：统计佣金

        //delete user token request more than 3 days ago
        $schedule->call(function () {
            $hourAgo = time() - 86400 * 3; // 3天前前的时间
            TokenRequest::where('requested_at', '<', $hourAgo)->delete();
        })->everyFiveMinutes();


    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
