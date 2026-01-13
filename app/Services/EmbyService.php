<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // 确保导入正确
use Exception;

class EmbyService
{
    protected $apiUrl;
    protected $apiKey;
    protected $serverUrl;
    protected $proxy;

    public function __construct()
    {
        // 1. 仅赋值，不抛异常。解决“保存配置时触发实例化导致死锁”的问题。
        $this->apiUrl = config('v2board.emby_api_url');
        $this->apiKey = config('v2board.emby_api_key');
        $this->serverUrl = config('v2board.emby_server_url');
        $this->proxy = config('v2board.proxy_server');
    }

    /**
     * 内部校验：仅在真正执行 API 调用前拦截
     */
    protected function ensureConfigLoaded()
    {
        if (empty($this->apiUrl) || empty($this->apiKey) || empty($this->serverUrl)) {
            $msg = 'Emby 动作终止：相关配置（API URL/Key/Server URL）项存在缺失。';
            Log::error($msg);
            throw new Exception($msg);
        }
    }

    /**
     * 检查 Emby API 是否可用 (由系统或监控调用)
     */
    public function isApiAvailable(): bool
    {
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->withOptions([
                    'timeout' => 5,
                    'connect_timeout' => 3,
                    'proxy' => $this->proxy ?: null
                ])
                ->post($this->apiUrl, [
                    'api_key' => $this->apiKey,
                    'type' => 'check_' . Str::random(4)
                ]);

            return $response->successful() || $response->clientError();
        } catch (Exception $e) {
            Log::debug('Emby API 连通性测试未通过: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建 Emby 账号 (核心逻辑)
     */
    public function createAccount(User $user, Order $order): ?array
    {
        // 动作前校验，确保此时配置已从数据库读取
        $this->ensureConfigLoaded();

        // 1. 生成用户名逻辑 (保留原样)
        $emailPrefix = explode('@', $user->email)[0];
        $generatedEmbyUsername = ($emailPrefix ?: 'user') . Str::random(4);

        // 2. 周期映射 (保留原样)
        $typeId = $this->mapPeriodToTypeId($order->period);
        if ($typeId === null) {
            throw new Exception("无法映射订单周期: {$order->period}");
        }

        try {
            // 3. 发起请求
            $response = Http::asForm()
                ->withOptions(['timeout' => 15, 'proxy' => $this->proxy ?: null])
                ->post($this->apiUrl, [
                    'type' => 'create',
                    'api_key' => $this->apiKey,
                    'name' => $generatedEmbyUsername,
                    'type_id' => $typeId,
                ]);

            $responseData = $response->json();

            // 4. 响应校验
            if (!$response->successful() || ($responseData['code'] ?? 0) != 200) {
                $errorMsg = $responseData['message'] ?? "HTTP 状态码: " . $response->status();
                throw new Exception("Emby API 报错: " . $errorMsg);
            }

            // 5. 结构化返回 (确保键名与你后续保存数据库的代码完全一致)
            return [
                'username' => $responseData['data']['name'], // 优先使用 API 返回的最终用户名
                'password' => $responseData['data']['password'],
                'server_url' => $this->serverUrl,
                'expire_time' => $responseData['data']['expire_time'] ?? null,
                'generated_username' => $generatedEmbyUsername // 备用
            ];

        } catch (Exception $e) {
            Log::error("Emby 创建账号流程异常 [User: {$user->email}]: " . $e->getMessage());
            throw $e; // 继续抛出让队列处理重试或记录失败
        }
    }

    public function enableAccount(string $embyUsername): bool
    {
        return $this->setPolicyStatus($embyUsername, 'enable');
    }

    public function disableAccount(string $embyUsername): bool
    {
        return $this->setPolicyStatus($embyUsername, 'disable');
    }

    private function setPolicyStatus(string $embyUsername, string $status): bool
    {
        try {
            $this->ensureConfigLoaded();

            $response = Http::asForm()
                ->withOptions(['timeout' => 10, 'proxy' => $this->proxy ?: null])
                ->post($this->apiUrl, [
                    'type' => 'policy',
                    'api_key' => $this->apiKey,
                    'name' => $embyUsername,
                    'status' => $status,
                ]);

            $res = $response->json();
            return $response->successful() && ($res['code'] ?? 0) == 200;
        } catch (Exception $e) {
            Log::error("Emby 策略设置异常 [{$embyUsername} -> {$status}]: " . $e->getMessage());
            return false;
        }
    }

    private function mapPeriodToTypeId(string $period): ?int
    {
        $mapping = [
            'month_price' => 1,
            'quarter_price' => 2,
            'half_year_price' => 3,
            'year_price' => 4,
        ];
        return $mapping[$period] ?? null;
    }
}
