<?php

namespace App\Jobs;

use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $recordAt = strtotime(date('Y-m-d'));
        $currentTime = Carbon::now()->timestamp;

        if ($this->recordType === 'm') {
            // Add logic if needed for monthly records
        }

        $attempt = 0;
        $maxAttempts = 3; //最多重试3次
        $waitTime = 2; // seconds

        while ($attempt < $maxAttempts) {
            try {
                DB::beginTransaction();

                $updateData = [];
                $insertData = [];

                foreach(array_keys($this->data) as $userId) {
                    $userdata = StatUser::where('record_at', $recordAt)
                        ->where('server_rate', $this->server['rate'])
                        ->where('user_id', $userId)
                        ->lockForUpdate()->first();

                    if ($userdata) {
                        $updateData[] = [
                            'user_id' => $userId,
                            'server_rate' => $this->server['rate'],
                            'record_at' => $recordAt,
                            'u' => $userdata['u'] + $this->data[$userId][0],
                            'd' => $userdata['d'] + $this->data[$userId][1],
                            'updated_at' => $currentTime
                        ];
                    } else {
                        $insertData[] = [
                            'user_id' => $userId,
                            'server_rate' => $this->server['rate'],
                            'u' => $this->data[$userId][0],
                            'd' => $this->data[$userId][1],
                            'record_type' => $this->recordType,
                            'record_at' => $recordAt,
                            'created_at' => $currentTime,
                            'updated_at' => $currentTime
                        ];
                    }
                }

                if (!empty($updateData)) {
                    foreach ($updateData as $data) {
                        StatUser::where('user_id', $data['user_id'])
                            ->where('server_rate', $data['server_rate'])
                            ->where('record_at', $data['record_at'])
                            ->update(['u' => $data['u'], 'd' => $data['d'], 'updated_at' => $data['updated_at']]);
                    }
                }

                if (!empty($insertData)) {
                    StatUser::insert($insertData);
                }

                DB::commit();
                break;
            } catch (\Exception $e) {
                DB::rollback();
                if (str_contains($e->getMessage(), 'Deadlock found when trying to get lock')) {
                    $attempt++;
                    if ($attempt < $maxAttempts) {
                        sleep($waitTime);
                        continue;
                    }
                }
                abort(500, '用户统计数据失败: ' . $e->getMessage());
            }
        }
    }
}
