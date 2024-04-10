<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BillingService;
use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class TrafficFetchJob2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    public function handle()
    {
        $billingService = new BillingService();
        $rate = floatval($this->server['rate']);

        $attempt = 0;
        $maxAttempts = 3;
        while ($attempt < $maxAttempts) {
            try {
                // 开始数据库事务
                DB::beginTransaction();
                User::whereIn('id', array_keys($this->data))
                    ->chunkById(20, function ($users) use ($billingService, $rate) {
                        foreach ($users as $user) {
                            // 悲观锁定用户记录
                            $user = User::lockForUpdate()->find($user->id);
                            if (!$user) continue;
                            $userId = $user->id;
                            $upstream = $this->data[$userId][0];
                            $downstream = $this->data[$userId][1];

                            $balanceChange = 0;
                            $unbilledCharges = $user->unbilled_charges;

                            if ($user->is_PAGO == 1) {
                                $costInCents = $billingService->calculateCost($user, $upstream, $downstream, $rate);
                                $unbilledCharges += (int)round($costInCents * 10000);
                                if ($unbilledCharges >= 10000) {
                                    $deductibleCharges = floor($unbilledCharges / 10000);
                                    $balanceChange = -$deductibleCharges;
                                    $unbilledCharges %= 10000;
                                }
                                // 确保余额不会变成负数
                                if ($user->balance + $balanceChange < 0) {
                                    $balanceChange = -$user->balance; // 只扣除到0
                                    // 可以在这里发送余额不足的通知
                                    $mailService = new MailService();
                                    $mailService->remindInsufficientBalance($user->email, ($user->balance + $balanceChange) / 100);
                                }
                            }

                            // 更新用户记录
                            $user->update([
                                'u' => DB::raw("`u` + " . ($upstream * $rate)),
                                'd' => DB::raw("`d` + " . ($downstream * $rate)),
                                't' => time(),
                                'balance' => DB::raw("GREATEST(`balance` + {$balanceChange}, 0)"),
                                'unbilled_charges' => $unbilledCharges,
                            ]);
                        }
                    });

                // 提交事务
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
