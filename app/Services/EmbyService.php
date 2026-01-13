<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        //删除
//        if (empty($this->apiUrl) || empty($this->serverUrl)) {
//            Log::error('Emby API URL 或 Server URL 未配置。');
//            throw new \Exception('Emby API URL 或 Server URL 未配置');
//        }
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

        // 定义 HTTP 客户端选项
        $httpOptions = [
            'timeout' => 5,         // 总超时
            'connect_timeout' => 3, // 连接超时
        ];
        // 动态添加代理配置
        $proxy = config('v2board.proxy_server');
        if (!empty($proxy)) {
            $httpOptions['proxy'] = $proxy;
        }

        try {

            $response = Http::asForm()
                ->withOptions($httpOptions)
                ->post($this->apiUrl, [
                    'api_key' => $this->apiKey,
                    // 使用随机参数防止可能的服务器端缓存
                    'type' => 'availability_check' . Str::random(5)
                ]);

            $statusCode = $response->status();

            // 1. 5xx 服务器端错误 -> 不可用
            if ($response->serverError()) {
                Log::warning('Emby API 检查失败: Emby 服务器返回了一个错误 (5xx)。', ['status' => $statusCode]);
                return false;
            }

            // 2. 404 Not Found -> 端点错误，不可用
            if ($statusCode == 404) {
                Log::warning('Emby API 检查失败: 请求的端点未找到 (404)，请检查 Emby URL 配置。');
                return false;
            }

            // 3. 2xx (成功) 或其他 4xx (客户端错误，如 401/403) -> 均表示服务在线，这是“成功”状态，无需记录日志
            if ($response->successful() || $response->clientError()) {
                return true; // API 服务在线，静默返回 true
            }

            // 4. 其他所有未预期的状态码 -> 视为不可靠，记录日志
            Log::warning('Emby API 检查失败: 收到未预期的 HTTP 状态码。', ['status' => $statusCode]);
            return false;

        } catch (ConnectionException $e) {
            // 连接异常 (DNS解析失败, 连接被拒绝等) -> 不可用
            Log::warning('Emby API 检查失败: 无法连接到 Emby 服务器。', ['message' => $e->getMessage()]);
            return false;
        } catch (RequestException $e) {
            // 请求异常，重点是超时
            if (Str::contains($e->getMessage(), ['timed out', 'timeout'])) {
                Log::warning('Emby API 检查失败: 连接 Emby 服务器超时。', ['message' => $e->getMessage()]);
            } else {
                Log::warning('Emby API 检查失败: 发生请求异常。', ['message' => $e->getMessage()]);
            }
            return false;
        } catch (\Exception $e) {
            // 捕获其他任何意外的异常，这可能是代码或环境问题
            Log::error('Emby API 检查时发生意外的严重异常。', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return false;
        }
    }


    /**
     * 调用上游 API 创建 Emby 账号
     *
     * @param User $user
     * @param Order $order
     * @return array|null 返回 ['username' => ..., 'password' => ..., 'server_url' => ..., 'expire_time' => ..., 'generated_username' => ...]
     * @throws \Exception
     */
    public function createAccount(User $user, Order $order): ?array
    {
        if ($this->apiKey === 'YOUR_DEFAULT_API_KEY' || empty($this->apiKey)) {
            throw new \Exception('Emby API Key 未配置, 无法创建账号');
        }

        // 1. 生成 Emby 用户名 (邮箱前缀 + 4位随机码)
        $emailPrefix = explode('@', $user->email)[0];
        if (empty($emailPrefix)) {
            Log::error("无法从用户邮箱 {$user->email} 提取前缀作为 Emby 用户名基础");
            throw new \Exception('无法生成 Emby 用户名');
        }
        $randomSuffix = Str::random(4); // 生成4位随机字母数字
        $generatedEmbyUsername = $emailPrefix . $randomSuffix; // << 新生成的用户名

        // 2. 准备 API 请求数据
        $typeId = $this->mapPeriodToTypeId($order->period);
        if ($typeId === null) {
            Log::error("无法将订单周期 '{$order->period}' 映射到有效的 Emby type_id");
            throw new \Exception('无效的 Emby 购买周期');
        }
        $requestData = [
            'type' => 'create',
            'api_key' => $this->apiKey,
            'name' => $generatedEmbyUsername, // << 使用新生成的用户名
            'type_id' => $typeId,
        ];
        Log::info("准备调用 Emby API 创建账号", ['request_info' => "type=create, name={$generatedEmbyUsername}, type_id={$typeId}"]);

        // 3. 获取代理配置
        $proxy = config('v2board.proxy_server', null); // << 确认这里使用了你配置的正确键名

        // 4. 准备 HTTP 客户端选项
        $httpOptions = [];
        if (!empty($proxy)) {
            $httpOptions['proxy'] = $proxy;
            Log::info('Emby API 请求将使用代理', ['proxy_host' => parse_url($proxy, PHP_URL_HOST)]);
        }

        // 5. 发送 HTTP POST 请求
        try {
            $response = Http::asForm()
                ->withOptions($httpOptions)
                ->post($this->apiUrl, $requestData);

            // 6. 处理响应
            $responseData = $response->json();
            if (!$response->successful() || !isset($responseData['code']) || $responseData['code'] != 200) {
                Log::error("创建 Emby 账号失败 (用户: {$user->email}, 请求用户名: {$generatedEmbyUsername})。API 状态码: " . $response->status() . ", 响应: " . $response->body());
                $errorMessage = $responseData['message'] ?? ('HTTP ' . $response->status());
                throw new \Exception('调用 Emby API 创建账号失败: ' . $errorMessage);
            }
            // 确保返回的数据结构符合预期 (API 返回的用户名可能与我们生成的不一样，以 API 返回为准)
            if (!isset($responseData['data']['name']) || !isset($responseData['data']['password'])) {
                Log::error("创建 Emby 账号成功，但 API 响应数据格式不完整 (用户: {$user->email}, 请求用户名: {$generatedEmbyUsername})", ['response_data' => $responseData]);
                throw new \Exception('Emby API 响应数据格式错误');
            }

            $accountDetails = [
                'username' => $responseData['data']['name'], // 以 API 返回的为准
                'password' => $responseData['data']['password'],
                'server_url' => $this->serverUrl,
                'expire_time' => $responseData['data']['expire_time'] ?? null,
                'generated_username' => $generatedEmbyUsername // << 返回我们生成的用户名，用于后续保存到 user 表
            ];
            Log::info("成功为用户 {$user->email} 创建 Emby 账号。API 返回用户名: {$accountDetails['username']}，密码：{$accountDetails['password']}");
            return $accountDetails;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("调用 Emby API 时发生连接或请求异常 (用户: {$user->email}): " . $e->getMessage());
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
        if ($this->apiKey === 'YOUR_DEFAULT_API_KEY' || empty($this->apiKey)) {
            Log::error("无法{$status} Emby 账号：API Key 未配置");
            return false;
        }
        if (empty($this->apiUrl)) {
            Log::error("无法{$status} Emby 账号：API URL 未配置");
            return false;
        }

        $actionText = $status === 'enable' ? '启用' : '禁用';

        // 1. 准备 API 请求数据
        $requestData = [
            'type' => 'policy',
            'api_key' => $this->apiKey,
            'name' => $embyUsername,
            'status' => $status,
        ];
        Log::info("准备调用 Emby API {$actionText}账号", ['username' => $embyUsername, 'status' => $status]);

        // 2. 获取代理配置
        $proxy = config('v2board.proxy_server', null);

        // 3. 准备 HTTP 客户端选项
        $httpOptions = [];
        if (!empty($proxy)) {
            $httpOptions['proxy'] = $proxy;
            Log::info("Emby API {$actionText}请求将使用代理", ['proxy_host' => parse_url($proxy, PHP_URL_HOST)]);
        }

        // 4. 发送 HTTP POST 请求
        try {
            $response = Http::asForm()
                ->withOptions($httpOptions)
                ->post($this->apiUrl, $requestData);

            // 5. 处理响应
            $responseData = $response->json();
            if ($response->successful() && isset($responseData['code']) && $responseData['code'] == 200) {
                Log::info("成功{$actionText} Emby 账号: {$embyUsername}");
                return true;
            } else {
                Log::error("{$actionText} Emby 账号失败: {$embyUsername}。API 状态码: " . $response->status() . ", 响应: " . $response->body());
                return false;
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("调用 Emby API {$actionText}账号时发生连接或请求异常 (用户名: {$embyUsername}): " . $e->getMessage());
            if (!empty($proxy) && str_contains($e->getMessage(), 'Failed to connect to')) {
                Log::error("请检查代理设置是否正确以及代理服务器是否可用: " . $proxy);
            }
            return false;
        } catch (\Exception $e) {
            Log::error("处理 Emby 账号{$actionText}时发生异常 (用户名: {$embyUsername}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * 调用上游 API 删除 Emby 账号
     * 注意：此方法已弃用，保留用于紧急情况
     *
     * @param string $embyUsername 要删除的 Emby 用户名
     * @return bool 成功返回 true，失败返回 false
     */
    // public function deleteAccount(string $embyUsername): bool
    // {
    //     if ($this->apiKey === 'YOUR_DEFAULT_API_KEY' || empty($this->apiKey)) {
    //         Log::error('无法删除 Emby 账号：API Key 未配置');
    //         return false;
    //     }
    //     if (empty($this->apiUrl)) {
    //         Log::error('无法删除 Emby 账号：API URL 未配置');
    //         return false;
    //     }

    //     // 1. 准备 API 请求数据
    //     $requestData = [
    //         'type' => 'delete',
    //         'api_key' => $this->apiKey,
    //         'name' => $embyUsername,
    //     ];
    //     Log::info("准备调用 Emby API 删除账号", ['username' => $embyUsername]);

    //     // 2. 获取代理配置
    //     $proxy = config('v2board.proxy_server', null); // << 确认这里使用了你配置的正确键名

    //     // 3. 准备 HTTP 客户端选项
    //     $httpOptions = [];
    //     if (!empty($proxy)) {
    //         $httpOptions['proxy'] = $proxy;
    //         Log::info('Emby API 删除请求将使用代理', ['proxy_host' => parse_url($proxy, PHP_URL_HOST)]);
    //     }

    //     // 4. 发送 HTTP POST 请求
    //     try {
    //         $response = Http::asForm()
    //             ->withOptions($httpOptions)
    //             ->post($this->apiUrl, $requestData);

    //         // 5. 处理响应
    //         $responseData = $response->json();
    //         // 假设删除成功的 code 也是 200，如果不是需要根据 API 文档调整
    //         if ($response->successful() && isset($responseData['code']) && $responseData['code'] == 200) {
    //             Log::info("成功删除 Emby 账号: {$embyUsername}");
    //             return true;
    //         } else {
    //             Log::error("删除 Emby 账号失败: {$embyUsername}。API 状态码: " . $response->status() . ", 响应: " . $response->body());
    //             return false;
    //         }
    //     } catch (\Illuminate\Http\Client\RequestException $e) {
    //         Log::error("调用 Emby API 删除账号时发生连接或请求异常 (用户名: {$embyUsername}): " . $e->getMessage());
    //         if (!empty($proxy) && str_contains($e->getMessage(), 'Failed to connect to')) {
    //             Log::error("请检查代理设置是否正确以及代理服务器是否可用: " . $proxy);
    //         }
    //         return false;
    //     } catch (\Exception $e) {
    //         Log::error("处理 Emby 账号删除时发生异常 (用户名: {$embyUsername}): " . $e->getMessage());
    //         return false;
    //     }
    // }


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
}
