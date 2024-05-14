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
            $rate = $rate ?? config('v2board.default_usd_to_cny_rate', 7.22);
            $money = round($money * $rate, 2);
        }

        $name = $order['trade_no'] . "订单问题请发邮件联系：support@v2pass.net 其他联系方式均无效！";

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

}
