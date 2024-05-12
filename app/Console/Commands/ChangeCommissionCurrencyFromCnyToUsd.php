<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ChangeCommissionCurrencyFromCnyToUsd extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:ChangeCommissionCNYtoUSD';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '将佣金记录中的金额从人民币转换为美元';

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
        if (!$this->confirm("确定要将佣金记录中的金额从人民币转换为美元吗？")) {
            return;
        }
        ini_set('memory_limit', -1);

        $commissionLogs = CommissionLog::all();
        foreach ($commissionLogs as $log) {
            $fields = ['order_amount', 'get_amount'];

            foreach ($fields as $field) {
                if ($log->$field > 0) {
                    $original = $log->$field / 100;
                    $this->info("佣金记录ID{$log->id}的{$field}人民币原值为：{$original}元");

                    // 转换金额到美元
                    $log->$field = floor($log->$field / 7.226);
                    $converted = $log->$field / 100;
                    $this->info("转换后，佣金记录ID{$log->id}的{$field}美元值为：{$converted}美元");

                    $this->info("\n");
                }
            }

            $log->save();
        }
    }
}
