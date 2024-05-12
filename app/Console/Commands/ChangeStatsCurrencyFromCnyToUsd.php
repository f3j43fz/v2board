<?php

namespace App\Console\Commands;

use App\Models\Stat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ChangeStatsCurrencyFromCnyToUsd extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:ChangeStatsCNYtoUSD';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '将数据统计表中的金额从人民币转换为美元';

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
        if (!$this->confirm("确定要将数据统计表中的金额从人民币转换为美元吗？")) {
            return;
        }
        ini_set('memory_limit', -1);

        $stats = Stat::all();
        foreach ($stats as $stat) {
            $fields = ['paid_total', 'commission_total'];

            foreach ($fields as $field) {
                if ($stat->$field > 0) {
                    $original = $stat->$field / 100;
                    $this->info("统计记录ID{$stat->id}的{$field}人民币原值为：{$original}元");

                    // 转换金额到美元
                    $stat->$field = floor($stat->$field / 7.226);
                    $converted = $stat->$field / 100;
                    $this->info("转换后，统计记录ID{$stat->id}的{$field}美元值为：{$converted}美元");

                    $this->info("\n");
                }
            }

            $stat->save();
        }
    }
}
