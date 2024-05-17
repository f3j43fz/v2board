<?php

namespace App\Plugins\Telegram\Commands;

use App\Services\TelegramService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\Log;
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
            try {
                // Attempt to fetch data from the API
                $response = $this->client->get('https://cdn.moneyconvert.net/api/latest.json');
                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['rates']['CNY']) || !isset($data['rates']['USD'])) {
                    throw new \Exception("Required currency rates not found in the API response");
                }

                // Calculate the conversion rate
                $rate = $data['rates']['CNY'] / $data['rates']['USD'];
                Cache::put($cacheKey, $rate, 3600); // Cache the rate
            } catch (GuzzleException $e) {
                // Handle Guzzle HTTP client errors using the project's logging convention
                \Log::error($e->getMessage(), ['exception' => $e]);
                return null;
            } catch (\Exception $e) {
                // Handle other general exceptions using the project's logging convention
                \Log::error($e->getMessage(), ['exception' => $e]);
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
