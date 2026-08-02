<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use App\Models\Tokenrequest;
use App\Services\MailService;
use App\Services\TelegramService;
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
     * 本单待发送的佣金通知。累积起来，等 DB::commit() 之后再统一发送，
     * 避免把同步阻塞的 Telegram / 邮件调用留在事务内拉长持锁时间。
     *
     * @var array
     */
    private $pendingNotifications = [];

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
        $this->autoCheck(); //设置成发放中
        $this->autoPayCommission(); //立马检查有效还是无效
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
    public function autoPayCommission()
    {
        $orders = Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->get();

        if ($orders->isEmpty()) return;

        // 去重，避免同一用户被重复查询
        $inviteUserIds = $orders->pluck('invite_user_id')->unique()->values()->toArray();
        $userIds       = $orders->pluck('user_id')->unique()->values()->toArray();

        // 按 user_id 分桶，每人最多 5 条最近订阅 IP（仅 30 天内）
        $sinceTimestamp   = strtotime('-30 days');
        $inviterIpsByUser = $this->loadRecentIpsByUser($inviteUserIds, $sinceTimestamp);
        $invitedIpsByUser = $this->loadRecentIpsByUser($userIds,       $sinceTimestamp);

        foreach ($orders as $order) {
            // 每单独立 try-catch：任意异常只跳过该单并回滚，绝不拖垮整批佣金发放。
            // 通知已移出事务，因此不会再出现"TG 挂了导致佣金回滚、下一轮重算"的情况。
            $this->pendingNotifications = [];
            try {
                DB::beginTransaction();

                $inviteUserId = $order->invite_user_id;
                $orderIp      = $order->user_ip;

                // 只比对【本订单】的邀请人 vs 下单用户，不与批次内其他订单串场
                $inviterIps = $inviterIpsByUser[$inviteUserId] ?? [];
                $invitedIps = $invitedIpsByUser[$order->user_id] ?? [];

                $invalidInvite = $this->checkIPs($inviterIps, $invitedIps, $orderIp);

                if ($invalidInvite) {
                    $order->commission_status = 3;
                } else {
                    $order->commission_status = 2;

                    if (!$this->payHandle($inviteUserId, $order)) {
                        DB::rollBack();
                        continue;
                    }
                }

                if (!$order->save()) {
                    DB::rollBack();
                    continue;
                }

                DB::commit();

                // 通知必须在 commit 之后发：TelegramService 用 \Curl\Curl 同步阻塞请求
                // api.telegram.org 且未设超时，留在事务内会让邀请人那一行的写窗口跨越
                // 一次境外 HTTP 调用（可达数秒）。
                $this->flushNotifications();
            } catch (\Throwable $e) {
                // Laravel 8 的 DB::rollBack() 在事务层级为 0 时是 no-op，故与 payHandle 内部已有的 rollBack 不冲突
                DB::rollBack();
                \Log::error('CheckCommission 单订单处理失败 trade_no=' . $order->trade_no . ' : ' . $e->getMessage());
                continue;
            }
        }
    }

    /**
     * 拉取每个 user_id 的最近 5 条订阅 IP（30 天内），按 id 降序
     * 返回 [user_id => [ip1, ip2, ip3, ip4, ip5]]
     */
    private function loadRecentIpsByUser(array $userIds, int $sinceTimestamp): array
    {
        if (empty($userIds)) return [];

        $records = Tokenrequest::whereIn('user_id', $userIds)
            ->where('requested_at', '>=', $sinceTimestamp)
            ->orderBy('user_id')
            ->orderBy('id', 'desc')
            ->get(['user_id', 'ip']);

        $result = [];
        foreach ($records as $rec) {
            if (!isset($result[$rec->user_id])) {
                $result[$rec->user_id] = [];
            }
            if (count($result[$rec->user_id]) < 5) {
                $result[$rec->user_id][] = $rec->ip;
            }
        }
        return $result;
    }

    private function checkIPs(array $inviterIps, array $invitedIps, $orderIp): bool
    {
        // 1) 下单 IP 与【该订单邀请人】最近订阅 IP 重合（同一人下单 + 拉订阅）
        if (!empty($orderIp)) {
            foreach ($inviterIps as $ip) {
                if ($ip === $orderIp && $this->isFromChina($ip)) {
                    return true;
                }
            }
        }

        // 2) 【该订单下单用户】订阅 IP 与【该订单邀请人】订阅 IP 重合
        foreach ($invitedIps as $ip) {
            if (in_array($ip, $inviterIps, true) && $this->isFromChina($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isFromChina($ip): bool
    {
        // 用 \Throwable 兜住一切异常：GeoIP 库文件缺失、IP 不在库中、IP 格式非法、Reader 构造失败等，
        // 一律视为"非中国 IP"。地理位置查询失败绝不能拖垮整个佣金发放流程。
        // 注意：new Reader 必须放在 try 内（库文件缺失会在构造时抛异常）；
        //       原代码 catch 写成 GeoIp2\Exception\...（缺前导反斜杠）会被解析成
        //       App\Console\Commands\GeoIp2\Exception\... 不存在的类，根本抓不住真实异常。
        try {
            $reader = new Reader(storage_path('app/geoip/GeoLite2-Country.mmdb'));
            $record = $reader->country($ip);
            return isset($record->country->isoCode) && $record->country->isoCode === 'CN';
        } catch (\Throwable $e) {
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

            // 原子自增：`SET col = col + ?` 交给数据库算，不存在"先读旧值再写回"的丢失更新。
            // 旧写法在 User::find() 与 save() 之间还夹着一次同步 Telegram 调用和一封邮件，
            // 读写窗口可达数秒；邀请人只要在这期间并发调用 /user/transfer 把佣金转成余额，
            // 这里的写回就会用陈旧快照把已经转走的佣金整笔复活。
            //
            // 用带显式 where 的查询构造器形式，而不是 $inviter->increment()：
            // 后者在模型 exists 为 false 时会退化成不带 where 的全表自增。
            $column = (int)config('v2board.withdraw_close_enable', 0) ? 'balance' : 'commission_balance';
            User::where('id', $inviter->id)->increment($column, $commissionBalance);

            // 只有走佣金（可提现）这条路才通知，与原逻辑一致；实际发送推迟到事务提交后
            if ($column === 'commission_balance') {
                $this->pendingNotifications[] = [
                    'invite_user_id' => $inviteUserId,
                    'inviter' => $inviter,
                    'amount' => $commissionBalance / 100,
                    'is_admin' => (bool)$inviter->is_admin
                ];
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

    /**
     * 发送本单积压的佣金通知。只应在 DB::commit() 之后调用。
     *
     * 佣金此时已经落库，通知失败不能影响已发放的结果，因此逐条兜住异常仅记录日志
     * —— 这与旧行为相反：旧代码里 TG 超时会把整单佣金一起回滚。
     */
    private function flushNotifications()
    {
        foreach ($this->pendingNotifications as $item) {
            try {
                if (!$item['is_admin']) {
                    $this->notify($item['invite_user_id'], $item['amount']);
                }
                $mailService = new MailService();
                $mailService->remindCommissionGotten($item['inviter'], $item['amount']);
            } catch (\Throwable $e) {
                \Log::error('CheckCommission 佣金通知发送失败 invite_user_id=' . $item['invite_user_id'] . ' : ' . $e->getMessage());
            }
        }
        $this->pendingNotifications = [];
    }

    private function notify($userID,$commissionBalance){
        $telegramService = new TelegramService();
        $chatID =config('v2board.telegram_group_id');
        $rate=config('v2board.invite_commission');
        $limit = config('v2board.commission_withdraw_limit');
        $currency = config('v2board.currency') == 'USD' ? "美元" : "元";
        $text = "#佣金发放\n\n"
            . "🎉用户 #$userID 邀请朋友购买订阅，获得佣金：`{$commissionBalance}` {$currency}\n\n"
            . "当前佣金比例：`{$rate}%`\n\n"
            . "满 `{$limit}` {$currency}后可提现";
        $telegramService->sendMessage($chatID, $text,false,'markdown');
    }

}
