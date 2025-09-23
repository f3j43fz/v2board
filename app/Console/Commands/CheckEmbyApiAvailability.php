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
        $embyPlanId = 10;

        $plan = Plan::find($embyPlanId);
        if (!$plan) {
            $errorMessage = "错误：找不到 ID 为 {$embyPlanId} 的 Emby 套餐。";
            $this->error($errorMessage);
            Log::error("Emby API 检查: " . $errorMessage);
            return 1; // 返回非 0 表示失败
        }

        $isAvailable = $this->embyService->isApiAvailable();

        if ($isAvailable) {
            // API 可用, 检查是否需要从“禁止购买”状态恢复
            if ($plan->capacity_limit === 0) {
                $plan->capacity_limit = null; // 恢复为不限制
                if ($plan->save()) {
                    $successMessage = "Emby API 已恢复，已将套餐 (ID: {$embyPlanId}) 的购买限制解除。";
                    $this->info($successMessage);
                    Log::info("Emby API 检查: " . $successMessage);
                } else {
                    $errorMessage = "尝试解除套餐 (ID: {$embyPlanId}) 的购买限制时，数据库保存失败。";
                    $this->error($errorMessage);
                    Log::error("Emby API 检查: " . $errorMessage);
                }
            }
            // 如果 capacity_limit 不为 0, 说明状态正常, 无需任何操作和日志
        } else {
            // API 不可用, 检查是否需要设置为“禁止购买”
            if ($plan->capacity_limit !== 0) {
                $plan->capacity_limit = 0; // 设置为禁止购买
                if ($plan->save()) {
                    $warningMessage = "Emby API 不可用或连接失败，已将套餐 (ID: {$embyPlanId}) 设置为禁止购买。";
                    $this->warn($warningMessage);
                    Log::warning("Emby API 检查: " . $warningMessage);
                } else {
                    $errorMessage = "尝试将套餐 (ID: {$embyPlanId}) 设置为禁止购买时，数据库保存失败。";
                    $this->error($errorMessage);
                    Log::error("Emby API 检查: " . $errorMessage);
                }
            }
            // 如果 capacity_limit 已经为 0, 说明已经是禁止购买状态, 无需任何操作和日志
        }

        return 0; // 返回 0 表示成功
    }
}
