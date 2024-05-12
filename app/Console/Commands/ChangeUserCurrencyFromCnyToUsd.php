<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeUserCurrencyFromCnyToUsd extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ccustomFunction:ChangeUserCNYtoUSD';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '将所有涉及到金钱的字段的值都从人民币转成美元';

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
        if (!$this->confirm("确定要将所有涉及到金钱的字段的值都从人民币转成美元吗？")) {
            return;
        }
        ini_set('memory_limit', -1);
        $users = User::all();
        foreach ($users as $user)
        {
            // $user->balance 和 $user->commission_balance 分别表示用户的余额和佣金。在数据库中用整数 int 来存储。
            // 举例，某个用户的余额为 2.56 元(人民币)，在数据库中存储为 256

            if($user->balance <= 0 && $user->commission_balance <= 0){
                continue;
            }

            $balance = $user->balance / 100;
            $this->info("用户{$user->email}的人民币余额为： ". $balance . "元");

            $user->balance = floor($user->balance / 7.226);
            $balance = $user->balance / 100;
            $this->info("转换后，用户{$user->email}的美元余额为： ". $balance . "美元");

            $this->info("\n\n");


            $commission_balance = $user->commission_balance /100;
            $this->info("用户{$user->email}的人民币佣金为： ". $commission_balance . "元");

            $user->commission_balance = floor($user->commission_balance / 7.226);
            $commission_balance = $user->commission_balance /100;
            $this->info("转换后，用户{$user->email}的美元佣金为： ". $commission_balance . "美元");

            $this->info("\n\n");


            $user->save();

        }
    }
}
