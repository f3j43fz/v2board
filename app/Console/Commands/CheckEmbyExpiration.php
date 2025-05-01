<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\EmbyService;
use Illuminate\Support\Facades\Log;

class CheckEmbyExpiration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // 命令签名，用于在命令行调用，例如: php artisan emby:check-expiration
    protected $signature = 'emby:check-expiration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查 Emby 账户是否到期，并调用 API 删除过期的账户';

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
    public function __construct(EmbyService $embyService) // 通过构造函数注入 EmbyService
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
        $this->info('[' . date('Y-m-d H:i:s') . '] 开始检查 Emby 过期账户...');
        Log::info('开始执行 Emby 过期账户检查任务');

        // 查找所有设置了 emby_user_name 且 emby_expired_at 不为空且已过期的用户
        // 使用 chunkById 避免一次性加载过多用户数据
        $expiredCount = 0;
        $deletedCount = 0;
        $errorCount = 0;

        User::whereNotNull('emby_user_name')
            ->whereNotNull('emby_expired_at')
            ->where('emby_expired_at', '<', time()) // 到期时间戳 < 当前时间戳
            ->chunkById(100, function ($users) use (&$expiredCount, &$deletedCount, &$errorCount) {
                foreach ($users as $user) {
                    $expiredCount++;
                    $this->line("发现过期用户: {$user->email} (Emby 用户名: {$user->emby_user_name}, 到期时间: " . date('Y-m-d H:i:s', $user->emby_expired_at) . ")");
                    Log::info("发现过期 Emby 用户: {$user->email}, Emby 用户名: {$user->emby_user_name}");

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

        $summary = "检查完成。发现过期账户: {$expiredCount}，成功删除: {$deletedCount}，处理失败/错误: {$errorCount}。";
        $this->info($summary);
        Log::info('Emby 过期账户检查任务执行完毕。' . $summary);

        return 0; // 返回 0 表示成功
    }
}
