<?php

namespace App\Console\Commands;

use App\Models\StatUser;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class sendTrafficStatisticsToGroup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:sendTrafficStatisticsToGroup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '统计每日用户流量，推送到用户群里';

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

        $info = "🕧 昨日流量排行\n———————————\n\n";

        $info = $info . $this->statTraffic();

        $this->notify($info);

    }

    private function statTraffic(): string
    {
        $message = "";

        // 用户流量统计
        $userStats = $this->getUserLastRank();
        $message .= "流量消耗前10的用户及其消耗数据:\n";
        foreach ($userStats['data'] as $user) {
            $message .= "#{$user['user_id']} | 消耗流量：{$user['total']} GB\n";
        }

        return $message;
    }

    private function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
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
            $statistics[$k]['total'] = round($statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824);
            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 10)
        ];

    }

    private function notify($text){
        $telegramService = new TelegramService();
        // 修改成你的TG群组的ID
        $chatID =config('v2board.telegram_group_id');
        $telegramService->sendMessage($chatID, $text,false,'markdown');
    }


}
