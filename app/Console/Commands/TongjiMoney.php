<?php

namespace App\Console\Commands;

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

        $orders = Order::whereIn('status', [3, 4])
            ->orderBy('created_at', 'ASC')
            ->get();

        $paymentTotals = []; // 存储每个支付方式的订单支付金额

        foreach ($orders as $order) {
            $money = ($order->total_amount + $order->discount_amount + $order->balance_amount) / 100;
            $paymentID = $order->payment_id;
            $payment = Payment::where('id', $paymentID)->first();
            $paymentName = $payment->name;

            // 累加每个支付方式的订单支付金额
            if (isset($paymentTotals[$paymentName])) {
                $paymentTotals[$paymentName] += $money;
            } else {
                $paymentTotals[$paymentName] = $money;
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
