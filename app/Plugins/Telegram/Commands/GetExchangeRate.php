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
            $url = 'https://www.okx.com/v3/c2c/tradingOrders/books?quoteCurrency=CNY&baseCurrency=USDT&side=sell&paymentMethod=aliPay&userType=all&receivingAds=false&quoteMinAmountPerOrder=100&t=' . time();

            try {
                $response = $this->client->get($url);
                $data = json_decode($response->getBody()->getContents(), true);

                // 获取第一个卖家的汇率价格 (sell-0)
                if (isset($data['data']['sell'][0]['price'])) {
                    $rate = $data['data']['sell'][0]['price'];
                    Cache::put($cacheKey, $rate, 60); // 缓存汇率
                } else {
                    \Log::error("Failed to retrieve USD to CNY rate from the API response.");
                    return null;
                }
            } catch (GuzzleException $e) {
                \Log::error("Attempt to fetch from $url failed: " . $e->getMessage(), ['exception' => $e]);
                return null;
            } catch (\Exception $e) {
                \Log::error("Error during rate fetching from $url: " . $e->getMessage(), ['exception' => $e]);
                return null;
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
