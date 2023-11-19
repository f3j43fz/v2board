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


        $timezone = new DateTimeZone('Asia/Shanghai');
        $yesterday = new DateTime('yesterday', $timezone);
        $startOfDay = $yesterday->setTime(0, 0, 0)->getTimestamp();
        $endOfDay = $yesterday->setTime(23, 59, 59)->getTimestamp();

        $orders = Order::whereIn('status', [3, 4])
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->orderBy('created_at', 'ASC')
            ->get();

        $paymentTotals = []; // 存储每个支付方式的订单支付金额

        foreach ($orders as $order) {
            $money = ($order->total_amount + $order->discount_amount + $order->balance_amount) / 100;
            $orderID = $order->id;
            $this->info("订单{$orderID} 金额：{$money}\n");
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
            }
        }

        // 构建消息内容
        $message = '';
        foreach ($paymentTotals as $paymentName => $totalMoney) {
            $message .= "{$paymentName} 共收款： {$totalMoney} 元\n";
        }

        // 输出结果，通知 Telegram
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message);

    }
}
