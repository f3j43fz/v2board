<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tradeNo;
    public $tries = 3;
    public $timeout = 5;

    /**
     * Create a new job instance.
     *
     * @param string $tradeNo 交易编号
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->tradeNo = $tradeNo;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // 从数据库中查询并锁定订单
        $order = Order::where('trade_no', $this->tradeNo)
            ->lockForUpdate()
            ->first();

        if (!$order) {
            return;
        }

        // 提前处理取消订单的逻辑
        if ($order->status == 0 && $order->created_at <= (time() - 3600 * 2)) {
            $orderService = new OrderService($order);
            $orderService->cancel();
            return;
        }

        $orderService = new OrderService($order);

        if ($order->plan_id == 100) {
            // 充值余额
            $this->handleRecharge($order, $orderService);
        } else {
            // 正常购买套餐
            $this->handlePurchase($order, $orderService);
        }
    }

    private function handleRecharge(Order $order, OrderService $orderService)
    {
        if ($order->status == 1) {
            $orderService->recharge();
        }
    }

    private function handlePurchase(Order $order, OrderService $orderService)
    {
        if ($order->status != 1) {
            return;
        }

        if ($order->period == "setup_price") {
            // 开通【随用随付】
            $orderService->openPayAsYouGo();
        } elseif ($order->callback_no == 'auto_renew') {
            // 自动续费【按周期】套餐
            $orderService->autoRenew();
        } else {
            // 正常开通：【按周期】、【按流量】套餐
            $orderService->open();
        }
    }
}
