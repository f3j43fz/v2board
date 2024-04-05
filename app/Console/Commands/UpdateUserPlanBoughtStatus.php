<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Order; // 确保你有这个模型
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateUserPlanBoughtStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customFunction:UpdateUserPlanBoughtStatus';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '判断用户历史上是否购买过套餐，从而设置字段 has_Purchased_Plan_Before 的值，便于后续区分新/老用户';

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
        $users = User::all();
        foreach ($users as $user) {
            // 判断逻辑
            $hasPurchased = Order::where('status', 3)
                ->where('user_id', $user->id)
                ->exists();

            // 如果用户买过套餐，则设置 has_Purchased_Plan_Before 字段的值为 1
            $user->has_Purchased_Plan_Before = $hasPurchased ? 1 : 0;

            // 保存用户信息
            $user->save();

            // 可选：输出当前用户的处理状态
            $this->info("Updated user: {$user->id} with status: {$user->has_Purchased_Plan_Before}");
        }

        $this->info('All users have been processed.');
    }
}
