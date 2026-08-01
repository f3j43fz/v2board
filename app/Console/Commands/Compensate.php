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
    protected $signature = 'customFunction:Compensate {days : 补偿天数} {traffic : 补偿流量(GB)} {reason : 补偿原因，作为邮件正文原样发送，必填}';

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

        // 1. 获取参数并取绝对值，防止手误输入负数导致扣除资产
        $days = abs((int) $this->argument('days'));
        $trafficGB = abs((int) $this->argument('traffic'));
        $reason = trim((string) $this->argument('reason'));

        if ($reason === '') {
            $this->error('补偿原因不能为空：请在第三个参数中提供本次补偿的邮件正文。');
            return 1;
        }

        // 基础参数计算
        $addTimeSeconds = $days * 86400;
        $addTrafficBytes = $trafficGB * 1024 * 1024 * 1024;

        // 邮件流控配置
        $batchSize = 800;      // 每批次 800 人
        $delayMinutes = 10;    // 每批次间隔 10 分钟

        // 获取拥有套餐的用户
        // 注意：请确保 User 模型中已添加 plan() 关联方法
        $users = User::whereNotNull('plan_id')->with('plan')->get();
        $totalUsers = $users->count();

        // ============================================
        // 🛑 安全确认：执行前的最后一道防线
        // ============================================
        $this->info("========================================");
        $this->info("🚨 危险操作确认 🚨");
        $this->info("========================================");
        $this->info("即将对 [ {$totalUsers} ] 位用户执行补偿操作：");
        $this->info("----------------------------------------");
        $this->info("1. 📅 周期套餐用户: 有效期增加 {$days} 天");
        $this->info("2. 💾 流量套餐用户: 流量增加 {$trafficGB} GB");
        $this->info("----------------------------------------");
        $this->info("📧 邮件策略: 每 {$batchSize} 封为一批，批次间隔 {$delayMinutes} 分钟");
        $this->info("📝 邮件正文(原样发送): {$reason}");

        if (!$this->confirm('请核对上述信息，确认立即执行吗？(yes/no)')) {
            $this->warn('操作已取消。');
            return;
        }

        // 开始执行
        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();

        $successCount = 0;
        $failCount = 0;
        $mailService = new MailService();

        foreach ($users as $user) {
            $plan = $user->plan;

            // 过滤无效用户
            if (!$plan || $user->banned) {
                $bar->advance();
                continue;
            }

            $actionType = null;
            $logDetail = "";

            DB::beginTransaction();
            try {
                // --- 逻辑判断核心区域 ---

                // 规则 1: 按周期套餐 (月/季/半年/年/两年/三年任一周期价格 > 0)
                // 且在有效期内 (expired_at > 当前时间)；days 传 0 时该类用户整体跳过（不落库、不发邮件）
                if ($plan->month_price > 0 || $plan->quarter_price > 0 || $plan->half_year_price > 0
                    || $plan->year_price > 0 || $plan->two_year_price > 0 || $plan->three_year_price > 0) {
                    if ($days > 0 && $user->expired_at > time()) {
                        $user->expired_at += $addTimeSeconds;
                        $actionType = 'time';
                        $logDetail = "有效期 +{$days}天";
                    }
                }
                // 规则 2: 按流量套餐 (onetime_price > 0)
                // 且流量未用完 (u + d < transfer_enable)；traffic 传 0 时该类用户整体跳过（不落库、不发邮件）
                elseif ($plan->onetime_price > 0) {
                    if ($trafficGB > 0 && ($user->u + $user->d) < $user->transfer_enable) {
                        $user->transfer_enable += $addTrafficBytes;
                        $actionType = 'traffic';
                        $logDetail = "流量 +{$trafficGB}GB";
                    }
                }
                // 规则 3: Pay as you go (setup_price > 0) -> 不补偿

                // 如果符合补偿条件，提交数据库更新
                if ($actionType) {
                    $user->save();
                    DB::commit(); // 数据库落库成功

                    // --- 邮件逻辑 ---
                    $batchIndex = intval($successCount / $batchSize);
                    $delaySeconds = $batchIndex * $delayMinutes * 60;
                    $successCount++;

                    // 日志输出
                    $bar->clear();
                    $this->line(" <info>✔</info> ID:{$user->id} | {$logDetail} | 邮件延迟: " . ($delaySeconds/60) . "分");
                    $bar->display();

                    // 发送邮件 (放在 try-catch 中，避免邮件服务挂掉影响主流程)
                    try {
                        $mailService->sendCompensationNotice($user, $reason, $delaySeconds);
                    } catch (\Exception $e) {
                        // 仅记录错误，不回滚数据库
                        $this->error("   ⚠ 邮件入队失败 ID:{$user->id}: " . $e->getMessage());
                    }

                } else {
                    // 不需要补偿
                    DB::rollBack();
                }

            } catch (\Exception $e) {
                DB::rollBack(); // 发生严重错误，回滚数据库
                $failCount++;
                $bar->clear();
                $this->error(" ❌ ID:{$user->id} 处理异常: " . $e->getMessage());
                $bar->display();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ 任务完成！成功补偿: {$successCount} 人，失败: {$failCount} 人。");
        $this->info("📧 提示: 邮件已推送到 send_email_mass 队列，请确保 Worker 正在运行。");
    }
}
