<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class EmbyService
{
    protected $apiUrl;
    protected $apiKey;
    protected $serverUrl;
    protected $proxy;

    public function __construct()
    {
        // 直接使用 config() 获取配置
        $this->apiUrl = config('v2board.emby_api_url');
        $this->apiKey = config('v2board.emby_api_key');
        $this->serverUrl = config('v2board.emby_server_url');
        $this->proxy = config('v2board.proxy_server');

        // 注意：构造函数不再抛出异常，以防止在保存配置时产生死锁。
        // 校验逻辑已移动至 ensureConfigLoaded() 方法中。
    }

    /**
     * 内部校验方法：确保在执行业务前配置已就绪
     * * @throws Exception
     */
    protected function ensureConfigLoaded()
    {
        if (empty($this->apiUrl) || empty($this->apiKey) || empty($this->serverUrl)) {
            Log::error('Emby API 检查失败: Emby 服务地址 (URL)、API 密钥 (API Key) 或服务器地址未配置。');
            throw new Exception('Emby 服务配置不完整，请检查后台设置');
        }
    }

    /**
     * 检查 Emby API 是否可用
     *
     * @return bool true 如果 API 可达 (即使返回业务错误), false 如果连接失败或超时
     */
    public function isApiAvailable(): bool
    {
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            Log::error('Emby API 检查失败: Emby 服务地址 (URL) 或 API 密钥 (API Key) 未配置。');
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
                    // 使用随机参数防止可能的服务器端缓存
                    'type' => 'availability_check_' . Str::random(5)
                ]);

            // 1. 5xx 服务器端错误 -> 不可用
            if ($response->serverError()) {
                Log::warning('Emby API 检查失败: Emby 服务器返回错误 (5xx)。', ['status' => $response->status()]);
                return false;
            }

            // 2. 404 Not Found -> 端点错误
            if ($response->status() == 404) {
                Log::warning('Emby API 检查失败: 未找到端点 (404)，请检查 URL 配置。');
                return false;
            }

            // 3. 2xx (成功) 或其他 4xx (客户端错误) -> 均表示服务在线
            if ($response->successful() || $response->clientError()) {
                return true; // API 服务在线，静默返回 true
            }

            return false;
        } catch (Exception $e) {
            Log::warning('Emby API 检查失败: 无法连接到服务器。', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 调用上游 API 创建 Emby 账号
     *
     * @param User $user
     * @param Order $order
     * @return array|null 返回 ['username' => ..., 'password' => ..., 'server_url' => ..., 'expire_time' => ..., 'generated_username' => ...]
     * @throws Exception
     */
    public function createAccount(User $user, Order $order): ?array
    {
        // 动作前执行强校验
        $this->ensureConfigLoaded();

        // 1. 生成 Emby 用户名 (邮箱前缀 + 4位随机码)
        $emailPrefix = explode('@', $user->email)[0];
        if (empty($emailPrefix)) {
            Log::error("无法从用户邮箱 {$user->email} 提取前缀作为 Emby 用户名基础");
            throw new Exception('无法生成 Emby 用户名');
        }
        $randomSuffix = Str::random(4);
        $generatedEmbyUsername = $emailPrefix . $randomSuffix;

        // 2. 准备 API 请求数据 (将订单周期映射到 type_id)
        $typeId = $this->mapPeriodToTypeId($order->period);
        if ($typeId === null) {
            Log::error("无法将订单周期 '{$order->period}' 映射到有效的 Emby type_id");
            throw new Exception('无效的 Emby 购买周期');
        }

        $requestData = [
            'type' => 'create',
            'api_key' => $this->apiKey,
            'name' => $generatedEmbyUsername,
            'type_id' => $typeId,
        ];

        Log::info("准备调用 Emby API 创建账号", ['request_info' => "type=create, name={$generatedEmbyUsername}, type_id={$typeId}"]);

        try {
            // 3. 发送 HTTP POST 请求
            $response = Http::asForm()
                ->withOptions([
                    'timeout' => 20,
                    'proxy' => $this->proxy ?: null
                ])
                ->post($this->apiUrl, $requestData);

            // 4. 处理响应
            $responseData = $response->json();
            if (!$response->successful() || !isset($responseData['code']) || $responseData['code'] != 200) {
                Log::error("创建 Emby 账号失败 (用户: {$user->email})。API 状态码: " . $response->status() . ", 响应: " . $response->body());
                $errorMessage = $responseData['message'] ?? ('HTTP ' . $response->status());
                throw new Exception('调用 Emby API 创建账号失败: ' . $errorMessage);
            }

            // 确保返回的数据结构符合预期 (以 API 返回的 name 为准)
            if (!isset($responseData['data']['name']) || !isset($responseData['data']['password'])) {
                Log::error("创建 Emby 账号成功，但 API 响应数据格式不完整", ['response_data' => $responseData]);
                throw new Exception('Emby API 响应数据格式错误');
            }

            $accountDetails = [
                'username' => $responseData['data']['name'], // 以 API 返回的为准
                'password' => $responseData['data']['password'],
                'server_url' => $this->serverUrl,
                'expire_time' => $responseData['data']['expire_time'] ?? null,
                'generated_username' => $generatedEmbyUsername // 用于后续保存到 user 表的参考名
            ];

            Log::info("成功为用户 {$user->email} 创建 Emby 账号。API 返回用户名: {$accountDetails['username']}");
            return $accountDetails;

        } catch (Exception $e) {
            Log::error("处理 Emby 账号创建时发生异常 (用户: {$user->email}): " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 调用上游 API 启用 Emby 账号
     *
     * @param string $embyUsername 要启用的 Emby 用户名
     * @return bool 成功返回 true，失败返回 false
     */
    public function enableAccount(string $embyUsername): bool
    {
        return $this->setPolicyStatus($embyUsername, 'enable');
    }

    /**
     * 调用上游 API 禁用 Emby 账号
     *
     * @param string $embyUsername 要禁用的 Emby 用户名
     * @return bool 成功返回 true，失败返回 false
     */
    public function disableAccount(string $embyUsername): bool
    {
        return $this->setPolicyStatus($embyUsername, 'disable');
    }

    /**
     * 调用上游 API 设置用户策略状态（启用/禁用）
     *
     * @param string $embyUsername 用户名
     * @param string $status 状态：'enable' 或 'disable'
     * @return bool 成功返回 true，失败返回 false
     */
    private function setPolicyStatus(string $embyUsername, string $status): bool
    {
        try {
            $this->ensureConfigLoaded();

            $actionText = $status === 'enable' ? '启用' : '禁用';
            Log::info("准备调用 Emby API {$actionText}账号", ['username' => $embyUsername]);

            $response = Http::asForm()
                ->withOptions([
                    'timeout' => 10,
                    'proxy' => $this->proxy ?: null
                ])
                ->post($this->apiUrl, [
                    'type' => 'policy',
                    'api_key' => $this->apiKey,
                    'name' => $embyUsername,
                    'status' => $status,
                ]);

            $responseData = $response->json();
            if ($response->successful() && isset($responseData['code']) && $responseData['code'] == 200) {
                Log::info("成功{$actionText} Emby 账号: {$embyUsername}");
                return true;
            } else {
                Log::error("{$actionText} Emby 账号失败: {$embyUsername}。响应: " . $response->body());
                return false;
            }
        } catch (Exception $e) {
            Log::error("处理 Emby 账号状态变更时发生异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 将订单周期字符串映射到 Emby API 的 type_id
     * * @param string $period 订单周期 ('month_price', 'quarter_price', etc.)
     * @return int|null 对应的 type_id，如果无法映射则返回 null
     */
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
