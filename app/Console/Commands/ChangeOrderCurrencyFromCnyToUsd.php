<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ChangeOrderCurrencyFromCnyToUsd extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:ChangeOrderCNYtoUSD';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '将订单中涉及的金钱字段从人民币转换为美元';

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
        if (!$this->confirm("确定要将订单中涉及的金钱字段从人民币转换为美元吗？")) {
            return;
        }
        ini_set('memory_limit', -1);

        $orders = Order::all();
        foreach ($orders as $order) {
            // 下面列出的字段若大于0，则需要转换
            $fields = [
                'total_amount', 'balance_amount', 'handling_amount', 'discount_amount',
                'surplus_amount', 'refund_amount', 'commission_balance', 'actual_commission_balance'
            ];

            foreach ($fields as $field) {
                if ($order->$field > 0) {
                    $original = $order->$field / 100;
                    $this->info("订单号{$order->id}的{$field}人民币原值为：{$original}元");

                    // 转换金额到美元
                    $order->$field = floor($order->$field / 7.226);
                    $converted = $order->$field / 100;
                    $this->info("转换后，订单号{$order->id}的{$field}美元值为：{$converted}美元");

                    $this->info("\n");
                }
            }

            $order->save();
        }
    }
}
