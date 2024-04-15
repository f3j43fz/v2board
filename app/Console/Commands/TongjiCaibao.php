<?php

namespace App\Console\Commands;

use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Services\PlanService;
use DateTime;
use DateTimeZone;
use App\Models\Order;
use App\Models\Payment;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class TongjiCaibao extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:tongjicaibao';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '统计每日财报综合数据';

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

        $info = "📋 每日财报\n———————————\n\n";

        $info = $info . $this->statMoney();

        $info = $info . $this->statUser();

        $info = $info . $this->statTraffic();


        // 输出最终的结果，通知 Telegram
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($info, false, true);

    }

    private function statMoney(): string
    {
        // 设置时区
        $timezone = new DateTimeZone('Asia/Shanghai');
        $yesterday = new DateTime('yesterday', $timezone);
        $startOfDay = $yesterday->setTime(0, 0, 0)->getTimestamp();
        $endOfDay = $yesterday->setTime(23, 59, 59)->getTimestamp();

        // 获取符合条件的订单
        $orders = Order::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->orderBy('created_at', 'ASC')
            ->get();

        $paymentTotals = []; // 存储每个支付方式的订单支付金额
        $paymentCounts = []; // 存储每个支付方式的订单数量
        $totalIncome = 0; // 总收入
        $totalOrders = 0; // 支付订单数
        $cancelledOrders = 0; // 取消订单数

        foreach ($orders as $order) {
            $money = $order->total_amount / 100;
            if ($order->status == 2) {
                $cancelledOrders++; // 统计取消的订单
            } else if (in_array($order->status, [3, 4])) {
                $totalIncome += $money; // 累加到总收入
                $totalOrders++; // 计入支付订单数

                $paymentID = $order->payment_id;
                $payment = Payment::where('id', $paymentID)->first();
                if ($payment) {
                    $paymentName = $payment->name;

                    // 累加每个支付方式的订单支付金额
                    if (isset($paymentTotals[$paymentName])) {
                        $paymentTotals[$paymentName] += $money;
                    } else {
                        $paymentTotals[$paymentName] = $money;
                    }

                    // 累加每个支付方式的订单数量
                    if (isset($paymentCounts[$paymentName])) {
                        $paymentCounts[$paymentName]++;
                    } else {
                        $paymentCounts[$paymentName] = 1;
                    }
                }
            }
        }

        // 构建消息内容
        $message = "1）财务：\n\n";
        $message .= "总收入： {$totalIncome} 元 | 支付订单数： {$totalOrders} 个 | 取消订单数： {$cancelledOrders} 个\n\n";
        foreach ($paymentTotals as $paymentName => $totalMoney) {
            $paymentCount = $paymentCounts[$paymentName];
            $message .= "通过【{$paymentName}】收款 {$paymentCount} 笔，共计： {$totalMoney} 元\n\n";
        }

        return $message;
    }

    private function statUser(): string
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $yesterday = new DateTime('yesterday', $timezone);
        $startOfDay = $yesterday->setTime(0, 0, 0)->getTimestamp();
        $endOfDay = $yesterday->setTime(23, 59, 59)->getTimestamp();


        // 新注册用户数
        $newUsers = User::whereBetween('created_at', [$startOfDay, $endOfDay])->get();
        $newUsersCount = $newUsers->count();

        // 新注册用户的ID
        $newUserIds = $newUsers->pluck('id');

        // 下单新用户数
        $orderingNewUsersCount = Order::whereIn('user_id', $newUserIds)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereIn('status', [3, 4])
            ->distinct()
            ->count('user_id');

        // 下单老用户数
        $orderingOldUsersCount = Order::whereNotIn('user_id', $newUserIds)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereIn('status', [3, 4])
            ->distinct()
            ->count('user_id');

        // 总有效套餐的用户数
        $totalActiveUsers = $this->countTotalActiveUsers();

        // 构建消息内容
        $message = "2）用户：\n\n";
        $message .= "总用户： {$totalActiveUsers} 人 | 新注册用户： {$newUsersCount} 人 | 下单老用户： {$orderingOldUsersCount} 人 | 下单新用户： {$orderingNewUsersCount} 人\n\n";

        return $message;
    }

    // 统计总的有效套餐用户数
    private function countTotalActiveUsers(): int
    {
        $counts = PlanService::countActiveUsers();
        $totalActiveUsers = 0;
        foreach ($counts as $count) {
            $totalActiveUsers += $count->count;
        }
        return $totalActiveUsers;
    }

    private function statTraffic(): string
    {
        $message = "3）流量统计：\n\n";

        // 服务器流量统计
        $serverStats = $this->getServerLastRank();
        $message .= "流量消耗前10的服务器及其消耗数据:\n";
        foreach ($serverStats['data'] as $server) {
            $message .= "服务器名称：{$server['server_name']} | 消耗流量：{$server['total']} GB\n";
        }
        $message .= "\n";

        // 用户流量统计
        $userStats = $this->getUserLastRank();
        $message .= "流量消耗前15的用户及其消耗数据:\n";
        foreach ($userStats['data'] as $user) {
            $message .= "用户邮箱：{$user['email']} | 消耗流量：{$user['total']} GB\n";
        }

        return $message;
    }

    private function getServerLastRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'hysteria' => ServerHysteria::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(10)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824; // 转换为GB
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => array_slice($statistics, 0, 10)
        ];
    }

    private function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 10)
        ];

    }
}
