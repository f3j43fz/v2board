<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use App\Models\Tokenrequest;
use App\Services\MailService;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use GeoIp2\Database\Reader;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

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
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        if ((int)config('v2board.commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->where('status', 3)
                ->where('updated_at', '<=', strtotime('-1 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

//    public function autoPayCommission()
//    {
//        $orders = Order::where('commission_status', 1)
//            ->where('invite_user_id', '!=', NULL)
//            ->get();
//        foreach ($orders as $order) {
//            DB::beginTransaction();
//            if (!$this->payHandle($order->invite_user_id, $order)) {
//                DB::rollBack();
//                continue;
//            }
//            $order->commission_status = 2;
//            if (!$order->save()) {
//                DB::rollBack();
//                continue;
//            }
//            DB::commit();
//        }
//    }

    public function autoPayCommission()
    {
        $orders = Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->get();

        foreach ($orders as $order) {
            DB::beginTransaction();

            $inviteUserId = $order->invite_user_id;
            $orderIp = $order->user_ip;

            // 查询邀请人最近的请求订阅IP
            $requestedIPs = Tokenrequest::where('user_id', $inviteUserId)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->pluck('ip')
                ->toArray();

            $invalidInvite = false;
            foreach ($requestedIPs as $requestedIP) {
                // 判断IP是否来自中国，这里假设有一个函数 isFromChina() 可以判断IP是否来自中国
                if ($requestedIP === $orderIp && $this->isFromChina($requestedIP)) {
                    $invalidInvite = true;
                    break;
                }
            }

            if ($invalidInvite) {
                $order->commission_status = 3;
            } else {
                $order->commission_status = 2;
            }

            if (!$this->payHandle($inviteUserId, $order)) {
                DB::rollBack();
                continue;
            }

            if (!$order->save()) {
                DB::rollBack();
                continue;
            }

            DB::commit();
        }
    }

    private function isFromChina($ip): bool
    {

        // 创建一个Reader对象，用于查询IP地址的地理位置
        $reader = new Reader(storage_path('app/geoip/GeoLite2-Country.mmdb'));

        try {
            // 查询IP地址的地理位置信息
            $record = $reader->country($ip);

            // 判断是否来自中国
            if ($record->country->isoCode === 'CN') {
                //输出 “有人白嫖”
                dump("有人白嫖");
                echo "有人白嫖\n";
                return true;
            } else {
                //输出 “没人白嫖”
                dump("没人白嫖");
                echo "没人白嫖\n";
                return false;
            }
        } catch (GeoIp2\Exception\AddressNotFoundException $e) {
            // 处理IP地址未找到的情况
            return false;
        } catch (GeoIp2\Exception\GeoIp2Exception $e) {
            // 处理其他异常
            return false;
        }
    }



    public function payHandle($inviteUserId, Order $order)
    {
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
            $commissionBalance = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$commissionBalance) continue;
            if ((int)config('v2board.withdraw_close_enable', 0)) {
                $inviter->balance = $inviter->balance + $commissionBalance;
                //发邮件给 inviter //blance是余额 commission_balance是佣金
                $mailService = new MailService();
                $mailService->remindCommissionGotten($inviter,$commissionBalance/100);
            } else {
                $inviter->commission_balance = $inviter->commission_balance + $commissionBalance;
            }
            if (!$inviter->save()) {
                DB::rollBack();
                return false;
            }
            if (!CommissionLog::create([
                'invite_user_id' => $inviteUserId,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ])) {
                DB::rollBack();
                return false;
            }
            $inviteUserId = $inviter->invite_user_id;
            // update order actual commission balance
            $order->actual_commission_balance = $order->actual_commission_balance + $commissionBalance;
        }
        return true;
    }

}
