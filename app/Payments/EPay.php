<?php

namespace App\Payments;
use App\Utils\CacheKey;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;

class EPay {
    private $config;
    private $client;

    public function __construct($config)
    {
        $this->config = $config;
        $this->client = new Client();
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        // 默认情况下，$money 为人民币
        $money = $order['total_amount'] / 100;

        // 如果是美元，则按照汇率换算成人民币
        if(config('v2board.currency') === 'USD'){
            $rate = $this->get_usd_to_cny_rate();
            $rate = $rate ?? config('v2board.default_usd_to_cny_rate', 7.20); // api 出错后，默认7.20
            // 上浮 3毛
            $money = round($money * ($rate + 0.3), 2);
        }

        $name = "使用QQ/微信很危险，如有订单问题请发邮件联系：support@v2pass.net 其他联系方式均无效！";

        $params = [
            'money' => $money,
            'name' => $name,
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (isset($this->config['type'])){
            $params['type']=$this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        if ($sign !== md5($str)) {
            return false;
        }
        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
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


}
