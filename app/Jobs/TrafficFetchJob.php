<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//    protected $u;
//    protected $d;
//    protected $userId;
    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
//        $this->u = $u;
//        $this->d = $d;
//        $this->userId = $userId;
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $attempt = 0;
        $maxAttempts = 3;
        while ($attempt < $maxAttempts) {
            try {
                DB::beginTransaction();
                foreach (array_keys($this->data) as $userId) {
                    $user = User::lockForUpdate()->find($userId);
                    if (!$user) continue;

                    // 获取用户的套餐 ID
                    $planId = $user->plan_id;
                    // 利用 Plan 模型查找对应的套餐
                    $plan = Plan::find($planId);

                    // 更新用户的时间戳和流量数据
                    $user->t = time();
                    $user->u += $this->data[$userId][0] * $this->server['rate'];
                    $user->d += $this->data[$userId][1] * $this->server['rate'];

                    // 如果套餐的 setup_price 字段不为空，则执行额外的扣费逻辑
                    if (!is_null($plan->setup_price)) {
                        $totalData = $this->data[$userId][0] + $this->data[$userId][1];
                        $rate = floatval($this->server['rate']);
                        $transferUnitPriceInCents = $plan->transfer_unit_price; // 每GB的流量单价，单位为分

                        // 计算这一分钟的费用，以分为单位
                        $costInCents = ($totalData / (1024.0 * 1024.0 * 1024.0)) * $rate * $transferUnitPriceInCents;

                        // 将这一分钟的费用累积到未结算费用中，确保未结算费用是以分为单位的整数
                        $user->unbilled_charges += (int)round($costInCents * 100); // 将费用转换为整数（分）

                        // 检查未结算费用是否至少有1分
                        if ($user->unbilled_charges >= 100) { // 因为现在是以分为单位，所以检查是否至少有100分（即1元）
                            // 从余额中扣除整数部分的未结算费用
                            $deductibleCharges = floor($user->unbilled_charges / 100); // 将分转换回元
                            $user->balance -= $deductibleCharges; // 从余额中扣除费用，余额也是以分为单位

                            // 更新未结算费用，只保留未扣除的分
                            $user->unbilled_charges %= 100; // 保留未扣除的分
                        }

                        // 确保余额不会变成负数
                        if ($user->balance < 0) {
                            $user->balance = 0;
                        }

                    }

                    // 保存用户数据，如果失败则记录日志
                    if (!$user->save()) {
                        info("流量更新失败\n未记录用户ID:{$userId}\n未记录上行:{$user->u}\n未记录下行:{$user->d}");
                    }


                }
                DB::commit();
                return;
            } catch (\Exception $e) {
                DB::rollback();
                if (strpos($e->getMessage(), '40001') !== false || strpos(strtolower($e->getMessage()), 'deadlock') !== false) {
                    $attempt++;
                    if ($attempt < $maxAttempts) {
                        sleep(5);
                        continue;
                    }
                }
                abort(500, '用户流量更新失败' . $e->getMessage());
            }
        }
    }
}
