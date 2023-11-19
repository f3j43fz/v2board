<?php

namespace App\Console\Commands;

use DateTime;
use DateTimeZone;
use App\Models\Order;
use App\Models\Payment;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class TongjiMoney extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:tongjiMoney';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '统计各个支付通道当天的收款总额';

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

        // 设置时区，这一步很重要！！
        // 可以通过打开： /www/server/php/74/etc/php.ini 文件，搜索：date.timezone 来查看时区
        // 查出来是中国，所以可以设置为上海
        $timezone = new DateTimeZone('Asia/Shanghai');
        $yesterday = new DateTime('first sat of november 2023', $timezone);
        $startOfDay = $yesterday->setTime(0, 0, 0)->getTimestamp();
        $endOfDay = $yesterday->setTime(23, 59, 59)->getTimestamp();

        $orders = Order::whereIn('status', [3, 4])
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->orderBy('created_at', 'ASC')
            ->get();

        $paymentTotals = []; // 存储每个支付方式的订单支付金额
        $paymentCounts = []; // 存储每个支付方式的订单数量

        foreach ($orders as $order) {
            $money = $order->total_amount / 100;
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

        // 构建消息内容
        $message = '';
        foreach ($paymentTotals as $paymentName => $totalMoney) {
            $paymentCount = $paymentCounts[$paymentName];
            $message .= "通过【{$paymentName}】收款 {$paymentCount} 笔，共计： {$totalMoney} 元\n\n";
        }

        // 输出结果，通知 Telegram
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message);

    }
}
