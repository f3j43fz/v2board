<?php

namespace App\Jobs;

use App\Services\UserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class LogLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userID;
    protected $last_login_at;
    protected $last_login_ip;

    public $tries = 3;
    public $timeout = 60;

    public function __construct($userID, $time, $ip)
    {
        $this->userID = $userID;
        $this->last_login_at = $time;
        $this->last_login_ip = $ip;
    }

    public function handle(UserService $userService)
    {
        $key = CacheKey::get('LOGIN_UPDATES');
        $currentBatch = Cache::get($key, []);

        $currentBatch[] = [
            'user_id' => $this->userID,
            'last_login_at' => $this->last_login_at,
            'last_login_ip' => $this->last_login_ip
        ];

        if (count($currentBatch) >= 10) {
            $userService->updateLoginRecords($currentBatch);
            Cache::forget($key);
        } else {
            Cache::put($key, $currentBatch, 300); // 5分钟内有效
        }
    }
}
