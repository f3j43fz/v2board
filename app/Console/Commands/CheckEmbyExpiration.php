<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\EmbyService;
use Illuminate\Support\Facades\Log;

class CheckEmbyExpiration extends Command
{
    // ... (属性和构造函数不变) ...
    protected $signature = 'emby:check-expiration';
    protected $description = '检查 Emby 账户是否到期，并调用 API 删除过期的账户';
    protected $embyService;

    public function __construct(EmbyService $embyService)
    {
        parent::__construct();
        $this->embyService = $embyService;
    }


    public function handle()
    {
        $this->info('[' . date('Y-m-d H:i:s') . '] 开始检查 Emby 过期账户...');
        Log::info('开始执行 Emby 过期账户检查任务');

        $expiredCount = 0;
        $deletedCount = 0;
        $errorCount = 0;
        $skippedCount = 0; // 增加一个计数器，用于记录因为用户名为空而跳过的用户


        User::whereNotNull('emby_user_name')        // 确保字段不为 NULL
        ->where('emby_user_name', '!=', '')   // 确保字段不为空字符串
        ->whereNotNull('emby_expired_at')      // 确保字段不为 NULL
        ->where('emby_expired_at', '>', 0)     // 确保时间戳大于 0 (排除 NULL 或 0)
        ->where('emby_expired_at', '<', time()) // 确保时间戳已过期
        ->chunkById(100, function ($users) use (&$expiredCount, &$deletedCount, &$errorCount, &$skippedCount) { // 传递 skippedCount
            foreach ($users as $user) {
                $expiredCount++; // 计数所有查询到的理论上过期的用户

                // --- 新增：再次检查用户名是否为空 ---
                if (empty($user->emby_user_name)) {
                    $this->warn("跳过用户 {$user->email}：查询到记录但 Emby 用户名为空，数据可能不一致。");
                    Log::warning("跳过用户 {$user->email}：查询到过期记录但 Emby 用户名为空。");
                    $skippedCount++;
                    continue; // 跳过此用户
                }
                // --- 结束新增检查 ---

                $this->line("处理过期用户: {$user->email} (Emby 用户名: {$user->emby_user_name}, 到期时间: " . date('Y-m-d H:i:s', $user->emby_expired_at) . ")");
                Log::info("处理过期 Emby 用户: {$user->email}, Emby 用户名: {$user->emby_user_name}");

                try {
                    // 调用 EmbyService 删除账号
                    if ($this->embyService->deleteAccount($user->emby_user_name)) {
                        // 删除成功，清空用户的 Emby 信息
                        $user->emby_user_name = null;
                        $user->emby_expired_at = null;
                        if ($user->save()) {
                            $this->info("成功删除并清理用户 {$user->email} 的 Emby 账户信息。");
                            Log::info("成功删除并清理用户 {$user->email} 的 Emby 账户信息。");
                            $deletedCount++;
                        } else {
                            Log::error("删除 Emby 账户 {$user->emby_user_name} 成功，但清理用户 {$user->email} 信息失败。");
                            $errorCount++;
                        }
                    } else {
                        // 删除失败 (EmbyService 内部已记录日志)
                        $this->error("调用 API 删除用户 {$user->email} 的 Emby 账户 ({$user->emby_user_name}) 失败。");
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $this->error("处理用户 {$user->email} 时发生异常: " . $e->getMessage());
                    Log::error("处理 Emby 过期用户 {$user->email} 时发生异常: " . $e->getMessage());
                    $errorCount++;
                }
            }
        });

        // 更新总结信息
        $summary = "检查完成。查询到过期记录: {$expiredCount}，成功删除: {$deletedCount}，因用户名无效跳过: {$skippedCount}，处理失败/错误: {$errorCount}。";
        $this->info($summary);
        Log::info('Emby 过期账户检查任务执行完毕。' . $summary);

        return 0; // 返回 0 表示成功
    }
}
