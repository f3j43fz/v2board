<?php

namespace App\Plugins\Telegram\Commands;

use App\Services\TelegramService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Plugins\Telegram\Telegram;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class GetExchangeRate extends Telegram {
    public $command = '/rate';
    public $description = '获取实时美元对人民币汇率';

    private $client;

    public function __construct() {
        $this->client = new Client();
    }

    public function handle($message, $match = []) {

        if ($message->is_private) {
            abort(500, '请在我们的群组中发送本命令噢~');
        }

        $rate = $this->get_usd_to_cny_rate();

        if ($rate === null) {
            $this->notify("无法获取汇率信息，请稍后再试。");
        } else {
            $text = "当前美元对人民币汇率为：`{$rate}`";
            $this->notify($text);
        }
    }

    private function get_usd_to_cny_rate()
    {
        $cacheKey = CacheKey::get('USD_TO_CNY_RATE', 'global');
        $rate = Cache::get($cacheKey);

        if (!$rate) {
            // 尝试从两个不同的API源获取汇率
            $urls = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json',
                'https://latest.currency-api.pages.dev/v1/currencies/usd.json'
            ];

            foreach ($urls as $url) {
                try {
                    $response = $this->client->get($url);
                    $data = json_decode($response->getBody()->getContents(), true);

                    // 检查API响应中是否包含CNY汇率
                    if (isset($data['usd']['cny'])) {
                        $rate = $data['usd']['cny'];
                        Cache::put($cacheKey, $rate, 3600); // 缓存汇率
                        break; // 成功获取到汇率后跳出循环
                    }
                } catch (GuzzleException $e) {
                    // 处理Guzzle HTTP客户端错误
                    \Log::error("Attempt to fetch from $url failed: " . $e->getMessage(), ['exception' => $e]);
                    continue; // 尝试下一个URL
                } catch (\Exception $e) {
                    // 处理其他异常
                    \Log::error("Error during rate fetching from $url: " . $e->getMessage(), ['exception' => $e]);
                    continue; // 尝试下一个URL
                }
            }

            if (!$rate) {
                \Log::error("Failed to retrieve USD to CNY rate from all sources.");
                return null; // 如果所有源都失败，则返回null
            }
        }

        return (float) $rate;
    }


    private function notify($text){
        $telegramService = new TelegramService();
        // 修改成你的TG群组的ID
        $chatID =config('v2board.telegram_group_id');
        $telegramService->sendMessage($chatID, $text,false,'markdown');
    }

}
