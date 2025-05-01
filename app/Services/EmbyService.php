<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbyService
{
    protected $apiUrl;
    protected $apiKey;
    protected $serverUrl;

    public function __construct()
    {
        // 直接使用 config() 获取配置，并提供默认值
        $this->apiUrl = config(
            'v2board.emby_api_url',
            'http://152.53.xxx.xxx:5678/index.php' // 默认 API URL
        );

        $this->apiKey = config(
            'v2board.emby_api_key',
            'YOUR_DEFAULT_API_KEY' // API Key 占位符
        );

        $this->serverUrl = config(
            'v2board.emby_server_url',
            'http://emby.yourdomain.com:8096' // 默认 Emby 服务器地址
        );

        // 检查配置有效性 (可选但推荐)
        if ($this->apiKey === 'YOUR_DEFAULT_API_KEY' || empty($this->apiKey)) {
            Log::warning('Emby API Key 未在配置中设置或仍为默认占位符，请在 config/v2board.php 或 .env 中配置 v2board.emby_api_key / V2BOARD_EMBY_API_KEY');
            // throw new \Exception('Emby API Key 未配置'); // 如果需要强制配置
        }
        if (empty($this->apiUrl) || empty($this->serverUrl)) {
            Log::error('Emby API URL 或 Server URL 未配置。');
            throw new \Exception('Emby API URL 或 Server URL 未配置');
        }
    }

    /**
     * 调用上游 API 创建 Emby 账号
     *
     * @param User $user 需要创建账号的用户对象
     * @param Order $order 关联的订单对象，用于获取 period
     * @return array|null 返回包含账号信息的数组 (例如 ['username' => ..., 'password' => ..., 'server_url' => ..., 'expire_time' => ...])，失败则返回 null 或抛出异常
     * @throws \Exception API 调用失败或配置错误时抛出异常
     */
    public function createAccount(User $user, Order $order): ?array
    {
        // 检查 API Key 是否有效 (如果构造函数中没有抛出异常)
        if ($this->apiKey === 'YOUR_DEFAULT_API_KEY' || empty($this->apiKey)) {
            throw new \Exception('Emby API Key 未配置, 无法创建账号');
        }

        // 准备 API 请求数据
        $embyUsername = explode('@', $user->email)[0];
        if (empty($embyUsername)) {
            Log::error("无法从用户邮箱 {$user->email} 提取前缀作为 Emby 用户名");
            throw new \Exception('无法生成 Emby 用户名');
        }
        $typeId = $this->mapPeriodToTypeId($order->period);
        if ($typeId === null) {
            Log::error("无法将订单周期 '{$order->period}' 映射到有效的 Emby type_id");
            throw new \Exception('无效的 Emby 购买周期');
        }
        $requestData = [
            'type' => 'create',
            'api_key' => $this->apiKey,
            'name' => $embyUsername,
            'type_id' => $typeId,
        ];
        // 日志中不记录完整的请求数据，尤其是 api_key
        Log::info("准备调用 Emby API 创建账号", ['request_info' => "type=create, name={$embyUsername}, type_id={$typeId}"]);

        // 1. 获取代理配置
        $proxy = config('v2board.proxy_server', null); // 从配置中读取代理地址

        // 2. 准备 HTTP 客户端选项
        $httpOptions = [];
        if (!empty($proxy)) {
            // 直接将 SOCKS5 代理字符串赋值给 proxy 选项
            $httpOptions['proxy'] = $proxy;
            // 记录日志时，可以考虑隐藏密码部分，但这比较复杂，简单起见先记录完整信息或只记录类型和主机
            Log::info('Emby API 请求将使用代理', ['proxy_host' => parse_url($proxy, PHP_URL_HOST)]);
        }

        // 3. 发送 HTTP POST 请求 (使用 asForm 并添加代理选项)
        try {
            $response = Http::asForm()
                ->withOptions($httpOptions) // 应用代理等选项
                ->post($this->apiUrl, $requestData);

            // 处理响应
            $responseData = $response->json();
            if (!$response->successful() || !isset($responseData['code']) || $responseData['code'] != 200) {
                Log::error("创建 Emby 账号失败 (用户: {$user->email})。API 状态码: " . $response->status() . ", 响应: " . $response->body());
                $errorMessage = $responseData['message'] ?? ('HTTP ' . $response->status());
                throw new \Exception('调用 Emby API 创建账号失败: ' . $errorMessage);
            }
            if (!isset($responseData['data']['name']) || !isset($responseData['data']['password'])) {
                Log::error("创建 Emby 账号成功，但 API 响应数据格式不完整 (用户: {$user->email})", ['response_data' => $responseData]);
                throw new \Exception('Emby API 响应数据格式错误');
            }
            $accountDetails = [
                'username' => $responseData['data']['name'],
                'password' => $responseData['data']['password'],
                'server_url' => $this->serverUrl,
                'expire_time' => $responseData['data']['expire_time'] ?? null, // 获取到期时间
            ];
            Log::info("成功为用户 {$user->email} 创建 Emby 账号。用户名: {$accountDetails['username']}");
            return $accountDetails;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("调用 Emby API 时发生连接或请求异常 (用户: {$user->email}): " . $e->getMessage());
            // 如果异常信息包含 "cURL error 7: Failed to connect to..." 且设置了代理，可能是代理连接问题
            if (!empty($proxy) && str_contains($e->getMessage(), 'Failed to connect to')) {
                Log::error("请检查代理设置是否正确以及代理服务器是否可用: " . $proxy);
            }
            throw new \Exception('无法连接到 Emby API 服务');
        } catch (\Exception $e) {
            Log::error("处理 Emby 账号创建时发生异常 (用户: {$user->email}): " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 将订单周期字符串映射到 Emby API 的 type_id
     * @param string $period 订单周期 ('month_price', 'quarter_price', etc.)
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


    // 未来可以添加 deleteAccount 方法等
    // public function deleteAccount(string $embyUsername) { ... }
}
