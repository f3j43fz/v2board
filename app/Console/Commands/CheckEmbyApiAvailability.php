<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmbyService;
use App\Models\Plan;
use Illuminate\Support\Facades\Log;

class CheckEmbyApiAvailability extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emby:check-availability'; // 命令签名

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查 Emby API 可用性并相应地更新 Emby 套餐 (ID:10) 的购买限制';

    /**
     * Emby 服务实例.
     *
     * @var EmbyService
     */
    protected $embyService;

    /**
     * Create a new command instance.
     *
     * @param EmbyService $embyService
     * @return void
     */
    public function __construct(EmbyService $embyService) // 注入 EmbyService
    {
        parent::__construct();
        $this->embyService = $embyService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('[' . date('Y-m-d H:i:s') . '] 开始检查 Emby API 可用性...');
        Log::info('开始执行 Emby API 可用性检查任务');

        // 定义 Emby 套餐 ID
        $embyPlanId = 10;

        // 查找 Emby 套餐
        $plan = Plan::find($embyPlanId);
        if (!$plan) {
            $this->error("错误：找不到 ID 为 {$embyPlanId} 的 Emby 套餐。");
            Log::error("Emby API 可用性检查：找不到 ID 为 {$embyPlanId} 的套餐。");
            return 1; // 返回非 0 表示失败
        }

        // 检查 API 可用性
        $isAvailable = $this->embyService->isApiAvailable();

        if ($isAvailable) {
            $this->info('Emby API 检测结果：可用');
            Log::info('Emby API 可用性检查：API 可用。');
            // 如果 API 可用，且当前限制为 0，则恢复为 NULL (不限制)
            if ($plan->capacity_limit === 0) {
                $plan->capacity_limit = null;
                if ($plan->save()) {
                    $this->info("已将 Emby 套餐 (ID: {$embyPlanId}) 的 capacity_limit 恢复为 NULL (不限制)。");
                    Log::info("Emby API 可用性检查：已将套餐 ID {$embyPlanId} 的 capacity_limit 恢复为 NULL。");
                } else {
                    $this->error("尝试将 Emby 套餐 (ID: {$embyPlanId}) 的 capacity_limit 恢复为 NULL 时保存失败。");
                    Log::error("Emby API 可用性检查：尝试恢复套餐 ID {$embyPlanId} 的 capacity_limit 为 NULL 时保存失败。");
                }
            } else {
                $this->line("Emby 套餐 (ID: {$embyPlanId}) 当前购买状态正常 (capacity_limit 不为 0)，无需操作。");
            }
        } else {
            $this->error('Emby API 检测结果：不可用或连接失败');
            Log::warning('Emby API 可用性检查：API 不可用或连接失败。');
            // 如果 API 不可用，且当前限制不是 0，则设置为 0 (禁止购买)
            if ($plan->capacity_limit !== 0) {
                $plan->capacity_limit = 0;
                if ($plan->save()) {
                    $this->warn("由于 API 不可用，已将 Emby 套餐 (ID: {$embyPlanId}) 的 capacity_limit 设置为 0 (禁止购买)。");
                    Log::warning("Emby API 可用性检查：由于 API 不可用，已将套餐 ID {$embyPlanId} 的 capacity_limit 设置为 0。");
                } else {
                    $this->error("尝试将 Emby 套餐 (ID: {$embyPlanId}) 的 capacity_limit 设置为 0 时保存失败。");
                    Log::error("Emby API 可用性检查：尝试设置套餐 ID {$embyPlanId} 的 capacity_limit 为 0 时保存失败。");
                }
            } else {
                $this->line("Emby 套餐 (ID: {$embyPlanId}) 当前已禁止购买 (capacity_limit 为 0)，无需操作。");
            }
        }

        $this->info('[' . date('Y-m-d H:i:s') . '] Emby API 可用性检查完成。');
        Log::info('Emby API 可用性检查任务执行完毕。');
        return 0; // 返回 0 表示成功
    }
}
