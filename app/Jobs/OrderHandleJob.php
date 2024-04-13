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
    protected $order;

    public $tries = 3;
    public $timeout = 5;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->order = Order::where('trade_no', $tradeNo)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
//    public function handle1()
//    {
//        if (!$this->order) return;
//        $orderService = new OrderService($this->order);
//        if($this->order->plan_id == 100){
//            // id等于100，为充值的逻辑
//            switch ($this->order->status) {
//                // cancel
//                case 0:
//                    if ($this->order->created_at <= (time() - 3600 * 2)) {
//                        $orderService->cancel();
//                    }
//                    break;
//                case 1:
//                    $orderService->recharge();
//                    break;
//            }
//        } else {
//            // id不等于100，则为购买套餐的逻辑
//            switch ($this->order->status) {
//                // cancel
//                case 0:
//                    if ($this->order->created_at <= (time() - 3600 * 2)) {
//                        $orderService->cancel();
//                    }
//                    break;
//                case 1:
//                    if ($this->order->period == "setup_price"){
//                        $orderService->openPayAsYouGo();
//                    } else {
//                        if($this->order->callback_no == 'auto_renew'){
//                            $orderService->autoRenew();
//                        }else{
//                            $orderService->open();
//                        }
//                    }
//                    break;
//            }
//        }
//
//    }


    public function handle()
    {
        if (!$this->order) {
            return;
        }

        // 提前处理取消订单的逻辑
        if ($this->order->status == 0 && $this->order->created_at <= (time() - 3600 * 2)) {
            $orderService = new OrderService($this->order);
            $orderService->cancel();
            return;
        }

        $orderService = new OrderService($this->order);

        if ($this->order->plan_id == 100) {
            //充值余额
            $this->handleRecharge($orderService);
        } else {
            //正常购买套餐
            $this->handlePurchase($orderService);
        }
    }

    private function handleRecharge(OrderService $orderService)
    {
        if ($this->order->status == 1) {
            $orderService->recharge();
        }
    }

    private function handlePurchase(OrderService $orderService)
    {
        if ($this->order->status != 1) {
            return;
        }

        if ($this->order->period == "setup_price") {
            // 开通【随用随付】
            $orderService->openPayAsYouGo();
        } elseif ($this->order->callback_no == 'auto_renew') {
            // 自动续费【按周期】套餐
            $orderService->autoRenew();
        } else {
            // 正常开通：【按周期】、【按流量】套餐
            $orderService->open();
        }
    }


}
