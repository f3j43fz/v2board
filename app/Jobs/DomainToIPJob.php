<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DomainToIPJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $item;
    protected $domain;
    protected $cacheKey;

    /**
     * Create a new job instance.
     *
     * @param  array  $item
     * @param  string  $domain
     * @param  string  $cacheKey
     * @return void
     */
    public function __construct(array $item, string $domain, string $cacheKey)
    {
        $this->onQueue('domain_to_ip');
        $this->item = $item;
        $this->domain = $domain;
        $this->cacheKey = $cacheKey;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ip = gethostbyname($this->domain);
        Cache::put($this->cacheKey, $ip, 60); // 缓存结果，有效期为 60 分钟
    }
}
