<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Compensate extends Command
{
    /**
     * 命令名称和参数
     */
    protected $signature = 'customFunction:Compensate {days} {traffic}';

    /**
     * 命令描述
     */
    protected $description = '根据用户套餐类型进行补偿，并分批延迟发送邮件通知';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        ini_set('memory_limit', -1);

        $days = (int) $this->argument('days');
        $trafficGB = (int) $this->argument('traffic');

        // 参数计算
        $addTimeSeconds = $days * 86400;
        $addTrafficBytes = $trafficGB * 1024 * 1024 * 1024;

        // === 邮件防封控配置 (参考您的代码) ===
        $batchSize = 800;      // 每批次 800 人
        $delayMinutes = 10;    // 每批次间隔 10 分钟
        // ===================================

        $this->info("========================================");
        $this->info("🚀 开始执行系统补偿任务 (带邮件流控)");
        $this->info("----------------------------------------");
        $this->info("📅 周期补偿: {$days} 天 | 💾 流量补偿: {$trafficGB} GB");
        $this->info("📧 邮件策略: 每 {$batchSize} 封为一批，批次间隔 {$delayMinutes} 分钟");
        $this->info("========================================");

        // 获取用户及套餐 (必须确保 User 模型有 plan() 方法)
        $users = User::whereNotNull('plan_id')->with('plan')->get();

        $totalUsers = $users->count();
        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();

        $successCount = 0;
        $failCount = 0;
        $mailService = new MailService();

        foreach ($users as $user) {
            $plan = $user->plan;

            // 过滤无效用户：无套餐、被封禁
            if (!$plan || $user->banned) {
                $bar->advance();
                continue;
            }

            $actionType = null;
            $logDetail = "";

            DB::beginTransaction();
            try {
                // --- 核心补偿逻辑 ---
                // 1. 周期套餐
                if ($plan->month_price > 0) {
                    if ($user->expired_at > time()) {
                        $user->expired_at += $addTimeSeconds;
                        $actionType = 'time';
                        $logDetail = "有效期 +{$days}天";
                    }
                }
                // 2. 流量套餐
                elseif ($plan->onetime_price > 0) {
                    if (($user->u + $user->d) < $user->transfer_enable) {
                        $user->transfer_enable += $addTrafficBytes;
                        $actionType = 'traffic';
                        $logDetail = "流量 +{$trafficGB}GB";
                    }
                }

                if ($actionType) {
                    $user->save();
                    DB::commit();

                    // --- 邮件延迟计算逻辑 ---
                    // 计算当前是第几批用户 (从0开始)
                    // 例如：第 1-800 人 batchIndex=0 (无延迟)
                    //       第 801-1600 人 batchIndex=1 (延迟10分钟)
                    $batchIndex = intval($successCount / $batchSize);
                    $delaySeconds = $batchIndex * $delayMinutes * 60;

                    $successCount++;

                    // 控制台日志
                    $bar->clear();
                    $this->line(" <info>✔</info> ID:{$user->id} | {$logDetail} | 邮件延迟: " . ($delaySeconds/60) . "分");
                    $bar->display();

                    // 发送邮件 (带延迟参数)
                    try {
                        $value = ($actionType === 'time') ? $days : $trafficGB;
                        $mailService->sendCompensationNotice($user, $actionType, $value, $delaySeconds);
                    } catch (\Exception $e) {
                        $this->error("   ⚠ 邮件入队失败 ID:{$user->id}: " . $e->getMessage());
                    }

                } else {
                    DB::rollBack();
                }

            } catch (\Exception $e) {
                DB::rollBack();
                $failCount++;
                $bar->clear();
                $this->error(" ❌ ID:{$user->id} 数据库错误: " . $e->getMessage());
                $bar->display();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ 任务完成！成功补偿: {$successCount} 人，失败: {$failCount} 人。");
        $this->info("📧 提示: 邮件已进入 send_email_mass 队列，请确保您的队列处理器正在运行。");
    }
}
