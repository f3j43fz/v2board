<?php

namespace App\Plugins\Telegram\Commands;

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
        $telegramService = $this->telegramService;
        if ($message->is_private) {
            $text = "请加群后获取";
            $telegramService->sendMessage($message->chat_id, $text, false, 'markdown');
            return;
        }

        $rate = $this->getExchangeRate();

        if ($rate === null) {
            $telegramService->sendMessage($message->chat_id, "无法获取汇率信息，请稍后再试。", false, 'markdown');
        } else {
            $text = "当前美元对人民币汇率为：`{$rate}`";
            $telegramService->sendMessage($message->chat_id, $text, false, 'markdown');
        }
    }

    private function getExchangeRate() {
        $cacheKey = CacheKey::get('USD_TO_CNY_RATE', 'global');
        $rate = Cache::get($cacheKey);

        if (!$rate) {
            try {
                $response = $this->client->get('https://cdn.moneyconvert.net/api/latest.json');
                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['rates']['CNY']) || !isset($data['rates']['USD'])) {
                    throw new \Exception("Required currency rates not found in the API response");
                }

                $rate = $data['rates']['CNY'] / $data['rates']['USD'];
                Cache::put($cacheKey, $rate, 3600); // Cache the rate
            } catch (GuzzleException $e) {
                Log::error($e->getMessage(), ['exception' => $e]);
                return null;
            } catch (\Exception $e) {
                Log::error($e->getMessage(), ['exception' => $e]);
                return null;
            }
        }

        return (float) $rate;
    }
}
