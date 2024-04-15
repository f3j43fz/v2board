<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LogLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userID;
    protected $last_login_at;
    protected $last_login_ip;

    public $tries = 3;
    public $timeout = 60; // 增加 timeout 时间，适应批处理操作

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($userID, $time, $ip)
    {
        $this->userID = $userID;
        $this->last_login_at = $time;
        $this->last_login_ip = $ip;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $key = 'login_updates';
        $currentBatch = Cache::get($key, []);

        // 加入当前登录信息到批处理数组
        $currentBatch[] = [
            'user_id' => $this->userID,
            'last_login_at' => $this->last_login_at,
            'last_login_ip' => $this->last_login_ip
        ];

        // 检查是否达到批处理数量或时间限制
        if (count($currentBatch) >= 10) {
            $this->updateLoginRecords($currentBatch);
            Cache::forget($key); // 清空缓存
        } else {
            Cache::put($key, $currentBatch, 300); // 5分钟内有效
        }
    }

    /**
     * 批量更新登录记录到数据库
     * @param array $batch
     * @return void
     */
    private function updateLoginRecords($batch)
    {
        foreach ($batch as $login) {
            User::where('id', $login['user_id'])
                ->update([
                    'last_login_at' => $login['last_login_at'],
                    'last_login_ip' => $login['last_login_ip']
                ]);
        }
    }
}
