<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadSubscribeRecordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $records;

    public $tries = 3;
    public $timeout = 30;

    public function __construct(array $records)
    {
        $this->onQueue('ip_sentinel');
        $this->records = $records;
    }

    public function handle()
    {
        $sentinelUrl = config('v2board.ip_sentinel_url');
        $apiKey = config('v2board.ip_sentinel_api_key');

        if (empty($sentinelUrl) || empty($apiKey)) {
            return;
        }

        try {
            $client = new Client(['timeout' => 15]);
            $client->post(rtrim($sentinelUrl, '/') . '/api/records/batch', [
                'headers' => [
                    'X-API-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => ['records' => $this->records],
            ]);
        } catch (\Exception $e) {
            Log::error('[IP Sentinel] 上传记录失败: ' . $e->getMessage());
            throw $e; // 抛出异常触发重试
        }
    }
}
