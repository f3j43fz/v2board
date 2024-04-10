<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\StatUser;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;

class sendTrafficStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:sendTrafficStatistics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '今日用户流量统计';

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
        ini_set('memory_limit', -1);
        $telegramService = new TelegramService();
        $this->notify("开始播报今日流量统计");

        // 获取今日流量消耗前20名的用户数据
        // 20的数值可调
        $userTrafficRank = $this->getUserTodayRank(20);

        // 初始化消息字符串
        $message = "📈 今日流量消耗排行榜：\n";

        // 格式化消息
        foreach ($userTrafficRank['data'] as $userTraffic) {
            $message .= "#" . "`" . $userTraffic['user_id'] . "`" . " 上传：" . round($userTraffic['u'] / 1073741824, 2) . " GB 下载：" . round($userTraffic['d'] / 1073741824, 2) . " GB\n";
        }

        // 发送汇总的消息
        $this->notify($message);
        $this->notify("今日流量统计播报完毕");

    }

    public function getUserTodayRank($limit)
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
                $data[$index]['u'] += $statistics[$k]['u'];
                $data[$index]['d'] += $statistics[$k]['d'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, $limit)
        ];
    }


    private function notify($text){
        $telegramService = new TelegramService();
        // 修改成你的TG群组的ID
        $chatID =config('v2board.telegram_group_id');
        $telegramService->sendMessage($chatID, $text,false,'markdown');
    }
}
